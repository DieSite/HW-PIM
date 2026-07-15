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
