<?php

namespace App\Services\ProductImageEditor;

use App\Services\ProductImageEditor\Concerns\HandlesDamValues;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Repositories\AssetResourceMappingRepository;
use Webkul\Product\Models\Product;

/**
 * Orchestrates compositing of the primary product image on save.
 *
 * Produces two derived DAM assets from the uploaded source rug:
 *   - the configured "primary" attribute  -> resize + padding + HW icon
 *   - the configured "no logo" attribute   -> resize + padding, no icon
 *
 * The source asset is left untouched in the DAM library, and the transform is
 * recorded on the product so the editor can be re-opened against the original.
 */
class PrimaryImageEditorService
{
    use HandlesDamValues;

    public const OUTCOME_REAPPLIED = 'reapplied';

    public const OUTCOME_STRIPPED = 'stripped';

    public const OUTCOME_NO_RING = 'skipped_no_ring';

    public const OUTCOME_MANUAL = 'manual';

    public const OUTCOME_NOT_MASKED = 'not_masked';

    public function __construct(
        private ImageCompositor $compositor,
        private EditedAssetWriter $writer,
        private HwIconResolver $iconResolver,
        private AssetResourceMappingRepository $assetResourceMappingRepository,
    ) {}

    /**
     * Apply the editor transform submitted with the product form.
     *
     * @param  array<string, mixed>  $input  The decoded `__image_edit` payload.
     */
    public function apply(Product $product, array $input): void
    {
        if (! config('product_image_editor.enabled')) {
            return;
        }

        $config = config('product_image_editor');

        $primaryCode = $config['primary_attribute'];
        $noLogoCode = $config['no_logo_attribute'];

        $values = $this->normalizeValues($product->values);

        $sourceAssetId = $this->resolveSourceAssetId($input, $values, $primaryCode);

        if (! $sourceAssetId) {
            return;
        }

        $source = Asset::find($sourceAssetId);

        if (! $source || ! Storage::disk($config['asset_disk'])->exists($source->path)) {
            return;
        }

        $sourceContents = Storage::disk($config['asset_disk'])->get($source->path);
        $iconContents = $this->iconResolver->contents();
        $transform = $this->normalizeTransform($input);
        $transform['rect'] = $this->resolveShapeRect($transform['shape']);
        $quality = (int) ($config['quality'] ?? 90);

        $primaryAsset = $this->writer->store(
            $source,
            (string) $this->compositor->render($sourceContents, $transform, $iconContents, true)->toJpeg($quality),
            'hw',
        );

        $noLogoAsset = $this->writer->store(
            $source,
            (string) $this->compositor->render($sourceContents, $transform, $iconContents, false)->toJpeg($quality),
            'zonder-logo',
        );

        $primaryIds = $this->replaceMainAsset($values['common'][$primaryCode] ?? null, (int) $primaryAsset->id);
        $noLogoIds = $this->replaceMainAsset($values['common'][$noLogoCode] ?? null, (int) $noLogoAsset->id);

        $values['common'][$primaryCode] = implode(',', $primaryIds);
        $values['common'][$noLogoCode] = implode(',', $noLogoIds);
        $product->values = $values;

        $record = $transform;
        unset($record['rect']);

        $additional = is_array($product->additional) ? $product->additional : [];
        $additional['primary_image_editor'] = array_merge($record, [
            'source_asset_id'   => (int) $sourceAssetId,
            'edited_asset_id'   => $primaryAsset->id,
            'no_logo_asset_id'  => $noLogoAsset->id,
        ]);
        $product->additional = $additional;

        $product->saveQuietly();

        $this->assetResourceMappingRepository->createProductAssetMappings($primaryIds, $product->id, $primaryCode);
        $this->assetResourceMappingRepository->createProductAssetMappings($noLogoIds, $product->id, $noLogoCode);
    }

    /**
     * Remove the black shape outline from a product's primary images.
     *
     * Prefers a lossless re-composite from the recorded editor transform and
     * the original source asset; falls back to pixel-level stripping of the
     * current composite when no usable transform/source exists.
     *
     * @return array{outcome: string, reason?: string, shape?: string}
     */
    public function removeOutline(Product $product, bool $dryRun = false): array
    {
        $config = config('product_image_editor');

        $shape = $this->resolveShapeFromProduct($product);

        if ($shape === null || $shape === $config['default_shape']) {
            return ['outcome' => self::OUTCOME_NOT_MASKED];
        }

        $additional = is_array($product->additional) ? $product->additional : [];
        $record = $additional['primary_image_editor'] ?? null;

        if (is_array($record) && array_key_exists('outline', $record) && ! $record['outline']) {
            return ['outcome' => self::OUTCOME_NO_RING, 'shape' => $shape, 'reason' => 'transform already records outline=false'];
        }

        if (is_array($record) && $this->sourceAssetIsUsable($record, $config['asset_disk'])) {
            if (($record['icon'] ?? true) && $this->iconResolver->contents() === null) {
                return ['outcome' => self::OUTCOME_MANUAL, 'shape' => $shape, 'reason' => 'HW icon not configured; re-compositing would lose the logo'];
            }

            if (! $dryRun) {
                $this->apply($product, array_merge($record, ['outline' => false, 'shape' => $shape]));
            }

            return ['outcome' => self::OUTCOME_REAPPLIED, 'shape' => $shape];
        }

        return $this->stripOutlineFromComposites($product, $shape, $dryRun);
    }

    /**
     * Resolve the shape of a product's primary image: the shape recorded by the
     * editor when available, otherwise the "vorm" attribute matched against the
     * configured shape keys, labels and aliases (mirrors the editor's blade).
     */
    public function resolveShapeFromProduct(Product $product): ?string
    {
        $shapes = config('product_image_editor.shapes', []);

        $additional = is_array($product->additional) ? $product->additional : [];
        $recorded = $additional['primary_image_editor']['shape'] ?? null;

        if (is_string($recorded) && isset($shapes[$recorded])) {
            return $recorded;
        }

        $values = $this->normalizeValues($product->values);
        $vorm = mb_strtolower(trim((string) ($values['common']['vorm'] ?? '')));

        if ($vorm === '') {
            return null;
        }

        foreach ($shapes as $shapeKey => $shape) {
            $candidates = array_merge([$shapeKey, $shape['label'] ?? ''], $shape['aliases'] ?? []);

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && mb_strtolower((string) $candidate) === $vorm) {
                    return $shapeKey;
                }
            }
        }

        return null;
    }

    /**
     * Pixel-level outline removal on the current composited assets (primary +
     * no-logo variants), used when the original source image is unavailable.
     *
     * @return array{outcome: string, reason?: string, shape?: string}
     */
    private function stripOutlineFromComposites(Product $product, string $shape, bool $dryRun): array
    {
        $config = config('product_image_editor');
        $disk = $config['asset_disk'];
        $quality = (int) ($config['quality'] ?? 90);

        $values = $this->normalizeValues($product->values);

        $primaryAsset = $this->firstAssetWithFile($values['common'][$config['primary_attribute']] ?? null, $disk);

        if ($primaryAsset === null) {
            return ['outcome' => self::OUTCOME_MANUAL, 'shape' => $shape, 'reason' => 'primary image asset or file missing'];
        }

        $primaryContents = Storage::disk($disk)->get($primaryAsset->path);
        $detected = $this->compositor->detectShapeOutline($primaryContents, $shape);

        if ($detected === null) {
            return ['outcome' => self::OUTCOME_MANUAL, 'shape' => $shape, 'reason' => 'image does not match the composite frame geometry'];
        }

        if ($detected === false) {
            return ['outcome' => self::OUTCOME_NO_RING, 'shape' => $shape, 'reason' => 'no outline detected on primary image'];
        }

        if ($dryRun) {
            return ['outcome' => self::OUTCOME_STRIPPED, 'shape' => $shape];
        }

        foreach ([$config['primary_attribute'], $config['no_logo_attribute']] as $attributeCode) {
            $asset = $attributeCode === $config['primary_attribute']
                ? $primaryAsset
                : $this->firstAssetWithFile($values['common'][$attributeCode] ?? null, $disk);

            if ($asset === null) {
                continue;
            }

            $contents = $asset->id === $primaryAsset->id ? $primaryContents : Storage::disk($disk)->get($asset->path);

            if ($this->compositor->detectShapeOutline($contents, $shape) !== true) {
                continue;
            }

            $stripped = $this->writer->store(
                $asset,
                (string) $this->compositor->removeShapeOutline($contents, $shape)->toJpeg($quality),
                'geen-rand',
            );

            $ids = $this->replaceMainAsset($values['common'][$attributeCode] ?? null, (int) $stripped->id);
            $values['common'][$attributeCode] = implode(',', $ids);

            $this->assetResourceMappingRepository->createProductAssetMappings($ids, $product->id, $attributeCode);
        }

        $product->values = $values;

        $additional = is_array($product->additional) ? $product->additional : [];
        $additional['primary_image_editor'] = array_merge(
            is_array($additional['primary_image_editor'] ?? null) ? $additional['primary_image_editor'] : [],
            ['outline' => false, 'shape' => $shape],
        );
        $product->additional = $additional;

        $product->saveQuietly();

        return ['outcome' => self::OUTCOME_STRIPPED, 'shape' => $shape];
    }

    /**
     * Whether a recorded transform points at a source asset whose file still
     * exists on disk, i.e. a lossless re-composite is possible.
     *
     * @param  array<string, mixed>  $record
     */
    private function sourceAssetIsUsable(array $record, string $disk): bool
    {
        $sourceAssetId = (int) ($record['source_asset_id'] ?? 0);

        if ($sourceAssetId <= 0) {
            return false;
        }

        $source = Asset::find($sourceAssetId);

        return $source !== null && Storage::disk($disk)->exists($source->path);
    }

    /**
     * The first asset of a DAM value whose file actually exists on disk.
     */
    private function firstAssetWithFile(mixed $value, string $disk): ?Asset
    {
        $ids = $this->assetIdList($value);

        if ($ids === []) {
            return null;
        }

        $asset = Asset::find($ids[0]);

        if (! $asset || ! Storage::disk($disk)->exists($asset->path)) {
            return null;
        }

        return $asset;
    }

    /**
     * Replace the main (first) asset in a comma-separated list with the newly
     * generated asset while preserving every other image in the list. The
     * primary image editor only ever edits the main image, so the rest of the
     * gallery must survive the save untouched.
     *
     * @return array<int, int>
     */
    private function replaceMainAsset(mixed $current, int $newAssetId): array
    {
        $ids = $this->assetIdList($current);

        if ($ids === []) {
            return [$newAssetId];
        }

        $ids[0] = $newAssetId;

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $values
     */
    private function resolveSourceAssetId(array $input, array $values, string $primaryCode): ?int
    {
        $sourceAssetId = (int) ($input['source_asset_id'] ?? 0);

        if ($sourceAssetId > 0) {
            return $sourceAssetId;
        }

        $current = $values['common'][$primaryCode] ?? null;

        if (is_array($current)) {
            $current = implode(',', $current);
        }

        $first = (int) trim((string) explode(',', (string) $current)[0]);

        return $first > 0 ? $first : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{scale: float, offset_x: int, offset_y: int, rotation: float, resize: bool, padding: bool, icon: bool, outline: bool, shape: string}
     */
    private function normalizeTransform(array $input): array
    {
        return [
            'scale'    => (float) ($input['scale'] ?? 1.0),
            'offset_x' => (int) round((float) ($input['offset_x'] ?? 0)),
            'offset_y' => (int) round((float) ($input['offset_y'] ?? 0)),
            'rotation' => fmod((float) ($input['rotation'] ?? 0.0), 360.0),
            'resize'   => filter_var($input['resize'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'padding'  => filter_var($input['padding'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'icon'     => filter_var($input['icon'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'outline'  => filter_var($input['outline'] ?? config('product_image_editor.outline.enabled', true), FILTER_VALIDATE_BOOLEAN),
            'shape'    => $this->resolveShapeKey($input['shape'] ?? null),
        ];
    }

    /**
     * Resolve a submitted shape key to a known shape, falling back to the default.
     */
    private function resolveShapeKey(mixed $shape): string
    {
        $shapes = config('product_image_editor.shapes', []);
        $shape = is_string($shape) ? $shape : '';

        return isset($shapes[$shape]) ? $shape : config('product_image_editor.default_shape', 'rechthoek');
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    private function resolveShapeRect(string $shape): array
    {
        return config("product_image_editor.shapes.$shape.rect")
            ?? config('product_image_editor.rug_rect');
    }
}
