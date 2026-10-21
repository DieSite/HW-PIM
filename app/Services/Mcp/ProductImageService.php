<?php

namespace App\Services\Mcp;

use App\Clients\DropboxClient;
use App\Exceptions\DropboxException;
use App\Exceptions\ProductUpsertException;
use App\Mcp\AdminPermission;
use App\Models\AssetLogoVariant;
use App\Services\ProductImageEditor\Concerns\HandlesDamValues;
use App\Services\ProductImageEditor\GalleryLogoService;
use App\Services\ProductImageEditor\HwIconResolver;
use App\Services\ProductImageEditor\PrimaryImageEditorService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\UriInterface;
use Throwable;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Repositories\AssetResourceMappingRepository;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

/**
 * Photo management for the MCP server: uploads photos into the DAM and sets
 * the ordered photo list (afbeelding) of products, the first being the main
 * photo.
 *
 * A product save goes the same way as in the admin: the list is stored, the
 * primary image editor composites the main photo into afbeelding (HW icon) and
 * afbeelding_zonder_logo (no icon), the gallery logo service stamps the HW
 * icon on the other photos, and the usual WooCommerce/Bol sync follows.
 *
 * A dry run stores nothing: it renders the composites in memory and returns
 * them as previews, next to the before/after photo lists.
 */
class ProductImageService
{
    use HandlesDamValues;

    public const MAX_ITEMS = 10;

    public const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

    public const ROTATIONS = [0, 90, 180, 270];

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    private const EDIT_KEYS = ['source_asset_id', 'shape', 'scale', 'offset_x', 'offset_y', 'rotation', 'resize', 'padding', 'icon', 'outline'];

    private const COMPOSITE_NAME_PATTERN = '/-(hw|zonder-logo|geen-rand)-[a-z0-9]{6}\.jpe?g$/i';

    public function __construct(
        private PrimaryImageEditorService $editor,
        private GalleryLogoService $galleryLogo,
        private AssetResourceMappingRepository $assetResourceMappingRepository,
        private ProductSyncDispatcher $syncDispatcher,
        private AssetDescriber $describer,
        private HwIconResolver $iconResolver,
        private DropboxClient $dropbox,
    ) {}

    /**
     * Stores a photo from a URL, base64 content or Dropbox as a new DAM asset.
     * A photo whose bytes already exist as an asset is not stored again; the
     * existing asset is returned instead.
     *
     * @param  array{url?: ?string, content_base64?: ?string, dropbox?: ?string, file_name?: ?string}  $input
     * @return array{result: array<string, mixed>, preview: ?string}
     */
    public function upload(array $input, Admin $admin, bool $dryRun = false): array
    {
        $this->authorize($admin);

        $url = trim((string) ($input['url'] ?? ''));
        $base64 = (string) ($input['content_base64'] ?? '');
        $dropbox = trim((string) ($input['dropbox'] ?? ''));

        if (count(array_filter([$url, $base64, $dropbox], fn (string $source): bool => $source !== '')) !== 1) {
            throw new ProductUpsertException('Give exactly one of "url", "content_base64" or "dropbox".');
        }

        if ($url !== '' && DropboxClient::isSharedLink($url)) {
            [$dropbox, $url] = [$url, ''];
        }

        if ($dropbox !== '') {
            $file = $this->downloadFromDropbox($dropbox);
            $contents = $file['contents'];
            $input['file_name'] = ($input['file_name'] ?? null) ?: $file['name'];
        } else {
            $contents = $url !== '' ? $this->download($url) : $this->decodeBase64($base64);
        }

        $size = @getimagesizefromstring($contents);
        $extension = self::MIME_EXTENSIONS[$size['mime'] ?? ''] ?? null;

        if ($size === false || $extension === null) {
            throw new ProductUpsertException('The file is not a JPEG, PNG or WebP image.');
        }

        $fileName = $this->fileName($input['file_name'] ?? null, $url, $extension);
        $identical = $this->findIdentical($contents);

        $result = [
            'dry_run' => $dryRun,
            'width'   => $size[0],
            'height'  => $size[1],
        ];

        if ($identical !== null) {
            $result += [
                'action' => 'reused',
                'note'   => 'This exact photo is already in the DAM; use the existing asset id.',
                'asset'  => $this->describer->describe([(int) $identical->id])[(int) $identical->id],
            ];
        } elseif ($dryRun) {
            $result += [
                'action' => 'would_create',
                'asset'  => ['id' => null, 'file_name' => $fileName, 'file_size' => strlen($contents), 'mime_type' => $size['mime']],
            ];
        } else {
            $asset = $this->storeAsset($contents, $fileName, $size['mime'], $extension);

            $source = match (true) {
                $dropbox !== '' => 'dropbox:'.($file['path'] ?? $dropbox),
                $url !== ''     => $url,
                default         => 'base64',
            };

            Log::channel('mcp')->info('asset uploaded', ['admin' => $admin->email, 'asset_id' => $asset->id, 'file_name' => $fileName, 'source' => $source]);

            $result += [
                'action' => 'created',
                'asset'  => $this->describer->describe([(int) $asset->id])[(int) $asset->id],
            ];
        }

        return ['result' => $result, 'preview' => $this->describer->preview($contents)];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{result: array{dry_run: bool, summary: array<string, int>, results: list<array<string, mixed>>}, previews: list<array{label: string, image: string}>}
     */
    public function setImages(array $items, Admin $admin, bool $dryRun = false, bool $withPreviews = true): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new ProductUpsertException('A batch holds at most '.self::MAX_ITEMS.' products; split it into smaller calls.');
        }

        $this->authorize($admin);

        $results = [];
        $previews = [];

        /** @var array<int, array{product: Product, action: string, changed: list<string>}> $touched */
        $touched = [];

        foreach (array_values($items) as $item) {
            $sku = is_array($item) && is_string($item['sku'] ?? null) ? trim($item['sku']) : null;

            try {
                if (! $sku) {
                    throw new ProductUpsertException('Every item needs a "sku" string.');
                }

                $outcome = $dryRun ? $this->planItem($sku, $item) : $this->applyItem($sku, $item);
            } catch (ProductUpsertException $e) {
                $results[] = ['sku' => $sku, 'action' => 'error', 'errors' => [$e->getMessage()]];

                continue;
            } catch (Throwable $e) {
                report($e);

                $results[] = ['sku' => $sku, 'action' => 'error', 'errors' => ['Unexpected error: '.$e->getMessage()]];

                continue;
            }

            if ($withPreviews) {
                foreach ($outcome['previews'] as $label => $bytes) {
                    if ($bytes !== null) {
                        $previews[] = ['label' => "{$sku}: {$label}", 'image' => $bytes];
                    }
                }
            }

            if (! $dryRun && $outcome['result']['action'] === 'updated') {
                $touched[$outcome['product']->id] = [
                    'product' => $outcome['product'],
                    'action'  => 'updated',
                    'changed' => array_keys($outcome['result']['changes']),
                ];
            }

            $results[] = $outcome['result'];
        }

        if (! $dryRun) {
            foreach ($touched as $entry) {
                Log::channel('mcp')->info('product images updated', [
                    'admin'   => $admin->email,
                    'sku'     => $entry['product']->sku,
                    'changed' => $entry['changed'],
                ]);
            }

            $this->syncDispatcher->dispatch($touched);
        }

        return [
            'result' => [
                'dry_run' => $dryRun,
                'summary' => array_count_values(array_column($results, 'action')) + ['total' => count($results)],
                'results' => $results,
            ],
            'previews' => $previews,
        ];
    }

    /**
     * Works out what a save would do, without writing anything.
     *
     * @param  array<string, mixed>  $item
     * @return array{result: array<string, mixed>, product: Product, previews: array<string, ?string>}
     */
    private function planItem(string $sku, array $item): array
    {
        [$product, $plan] = $this->prepare($sku, $item);

        $before = $this->currentLists($product);
        $after = $before;
        $after['afbeelding'] = $plan['ids'];

        $previews = [];
        $stamp = ['stamped' => [], 'reused' => [], 'skipped' => []];
        $describedAfter = [];

        if ($plan['edit'] !== null) {
            $rendered = $this->editor->render($product, $plan['edit']);

            if ($rendered === null) {
                throw new ProductUpsertException("The main photo (asset {$plan['edit']['source_asset_id']}) could not be read for editing.");
            }

            $previews['hoofdafbeelding (afbeelding, with HW icon)'] = $this->describer->preview($rendered['primary']);
            $previews['afbeelding_zonder_logo'] = $this->describer->preview($rendered['no_logo']);

            $pending = fn (string $suffix): array => [
                'id'             => null,
                'file_name'      => '(new) '.(Str::slug(pathinfo((string) $rendered['source']->file_name, PATHINFO_FILENAME)) ?: 'image')."-{$suffix}-xxxxxx.jpg",
                'generated_from' => (int) $rendered['source']->id,
            ];

            $describedAfter['afbeelding'] = [$pending('hw'), ...$this->describer->describeList(array_slice($plan['ids'], 1))];
            $describedAfter['afbeelding_zonder_logo'] = [$pending('zonder-logo'), ...$this->describer->describeList(array_slice($before['afbeelding_zonder_logo'], 1))];

            $stamp = $this->galleryDryRun($product, array_slice($plan['ids'], 1));
        } else {
            if ($plan['ids'] === []) {
                $after['afbeelding_zonder_logo'] = [];
            }

            if ($plan['ids'] !== []) {
                $main = Asset::find($plan['ids'][0]);
                $contents = $main ? $this->describer->contents($main) : null;
                $previews['hoofdafbeelding (unchanged by the editor)'] = $contents !== null ? $this->describer->preview($contents) : null;
            }

            $stamp = $this->galleryDryRun($product, $plan['ids']);
        }

        $describedAfter += [
            'afbeelding'             => $this->describer->describeList($after['afbeelding']),
            'afbeelding_zonder_logo' => $this->describer->describeList($after['afbeelding_zonder_logo']),
        ];

        $changes = $this->changes($before, $describedAfter);

        $result = [
            'sku'     => $sku,
            'action'  => $changes === [] ? 'unchanged' : 'updated',
            'changes' => $changes,
        ];

        if ($plan['edit'] !== null) {
            $result['primary_edit'] = $this->editSummary($plan['edit']);
        }

        if ($stamp['stamped'] !== [] || $stamp['reused'] !== []) {
            $result['hw_icon_will_be_added_to'] = array_values(array_merge($stamp['stamped'], $stamp['reused']));
        }

        if ($plan['warnings'] !== []) {
            $result['warnings'] = $plan['warnings'];
        }

        return ['result' => $result, 'product' => $product, 'previews' => $previews];
    }

    /**
     * Saves the photo list and runs the editor and gallery logo, as the
     * admin's product save does.
     *
     * @param  array<string, mixed>  $item
     * @return array{result: array<string, mixed>, product: Product, previews: array<string, ?string>}
     */
    private function applyItem(string $sku, array $item): array
    {
        [$product, $plan] = $this->prepare($sku, $item);

        $primaryCode = config('product_image_editor.primary_attribute');
        $noLogoCode = config('product_image_editor.no_logo_attribute');

        $before = $this->currentLists($product);

        if ($plan['ids'] === $before['afbeelding'] && $plan['edit'] === null) {
            return ['result' => ['sku' => $sku, 'action' => 'unchanged', 'changes' => []], 'product' => $product, 'previews' => []];
        }

        DB::transaction(function () use ($product, $plan, $primaryCode, $noLogoCode): void {
            $values = $this->normalizeValues($product->values);

            if ($plan['ids'] === []) {
                unset($values['common'][$primaryCode], $values['common'][$noLogoCode]);
            } else {
                $values['common'][$primaryCode] = implode(',', $plan['ids']);
            }

            $product->values = $values;
            $product->save();

            if ($plan['edit'] !== null) {
                $this->editor->apply($product, $plan['edit']);
                $product->refresh();
            }
        });

        try {
            $this->galleryLogo->apply($product);
            $product->refresh();
        } catch (Throwable $e) {
            report($e);
        }

        $after = $this->currentLists($product);

        $this->assetResourceMappingRepository->createProductAssetMappings($after['afbeelding'], $product->id, $primaryCode);
        $this->assetResourceMappingRepository->createProductAssetMappings($after['afbeelding_zonder_logo'], $product->id, $noLogoCode);

        $describedAfter = [
            'afbeelding'             => $this->describer->describeList($after['afbeelding']),
            'afbeelding_zonder_logo' => $this->describer->describeList($after['afbeelding_zonder_logo']),
        ];

        $result = [
            'sku'     => $sku,
            'action'  => 'updated',
            'changes' => $this->changes($before, $describedAfter),
        ];

        if ($plan['edit'] !== null) {
            $result['primary_edit'] = $this->editSummary($plan['edit']);
        }

        if ($plan['warnings'] !== []) {
            $result['warnings'] = $plan['warnings'];
        }

        $previews = [];

        foreach (['hoofdafbeelding (afbeelding)' => $after['afbeelding'][0] ?? null, 'afbeelding_zonder_logo' => $after['afbeelding_zonder_logo'][0] ?? null] as $label => $id) {
            $asset = $id ? Asset::find($id) : null;
            $contents = $asset ? $this->describer->contents($asset) : null;
            $previews[$label] = $contents !== null ? $this->describer->preview($contents) : null;
        }

        return ['result' => $result, 'product' => $product->refresh(), 'previews' => $previews];
    }

    /**
     * Validates an item and decides the new photo list and whether the main
     * photo goes through the editor.
     *
     * The editor runs when primary_edit is given, or when the main photo
     * changes and primary_edit is not false. When the main photo is the
     * product's current composite, editing starts from the recorded original
     * and its recorded transform, like re-opening the editor in the admin.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: Product, 1: array{ids: list<int>, edit: ?array<string, mixed>, warnings: list<string>}}
     */
    private function prepare(string $sku, array $item): array
    {
        if ($unknown = array_diff(array_keys($item), ['sku', 'images', 'primary_edit'])) {
            throw new ProductUpsertException('Unknown field(s): '.implode(', ', $unknown).'. Allowed: sku, images, primary_edit.');
        }

        $product = Product::query()->where('sku', $sku)->first();

        if (! $product) {
            throw new ProductUpsertException("SKU {$sku} does not exist. Create it with upsert-products first.");
        }

        $current = $this->currentLists($product)['afbeelding'];
        $ids = array_key_exists('images', $item) && $item['images'] !== null ? $this->validIds($item['images']) : $current;
        $editInput = $item['primary_edit'] ?? null;

        if ($editInput !== null && $editInput !== false && ! is_array($editInput)) {
            throw new ProductUpsertException('"primary_edit" must be an object, or false to skip the editor.');
        }

        $warnings = [];

        if ($ids === [] || $editInput === false) {
            if (is_array($editInput)) {
                throw new ProductUpsertException('primary_edit needs a main photo; "images" is empty.');
            }

            if ($ids !== [] && ($ids[0] ?? null) !== ($current[0] ?? null)) {
                $warnings[] = 'The main photo changed without the editor, so afbeelding_zonder_logo still shows the previous main photo.';
            }

            return [$product, ['ids' => $ids, 'edit' => null, 'warnings' => $warnings]];
        }

        $recorded = $product->additional['primary_image_editor'] ?? null;
        $recorded = is_array($recorded) ? $recorded : [];
        $isCurrentComposite = (int) ($recorded['edited_asset_id'] ?? 0) === $ids[0];

        if ($editInput === null && ($isCurrentComposite || $ids[0] === ($current[0] ?? null))) {
            return [$product, ['ids' => $ids, 'edit' => null, 'warnings' => $warnings]];
        }

        $editInput = $this->validEdit($editInput ?? []);

        if ($isCurrentComposite && ! empty($recorded['source_asset_id'])) {
            $base = array_intersect_key($recorded, array_flip(self::EDIT_KEYS));
        } else {
            $base = ['source_asset_id' => $this->originalOf($ids[0]), 'shape' => $this->editor->resolveShapeFromProduct($product)];
        }

        $edit = array_merge($base, $editInput);

        $source = Asset::find((int) $edit['source_asset_id']);

        if (! $source) {
            throw new ProductUpsertException("primary_edit.source_asset_id {$edit['source_asset_id']} does not exist.");
        }

        if (! $isCurrentComposite && ! isset($editInput['source_asset_id']) && preg_match(self::COMPOSITE_NAME_PATTERN, (string) $source->file_name)) {
            throw new ProductUpsertException("The main photo (asset {$source->id}, {$source->file_name}) is already an edited composite. Pass primary_edit.source_asset_id with the original photo, or primary_edit: false to use it as it is.");
        }

        if (($edit['icon'] ?? true) !== false && $this->iconResolver->contents() === null) {
            $warnings[] = 'The HW icon (Configuration → Hoofdafbeelding) could not be loaded, so afbeelding gets no icon and the other photos are not stamped.';
        }

        return [$product, ['ids' => $ids, 'edit' => $edit, 'warnings' => $warnings]];
    }

    /**
     * The original of an HW logo variant, so the editor never composites a
     * photo that already carries the icon.
     */
    private function originalOf(int $assetId): int
    {
        $source = AssetLogoVariant::query()
            ->where('variant_asset_id', $assetId)
            ->whereColumn('source_asset_id', '!=', 'variant_asset_id')
            ->value('source_asset_id');

        return $source ? (int) $source : $assetId;
    }

    /**
     * @return list<int>
     */
    private function validIds(mixed $images): array
    {
        if (! is_array($images) || ! array_is_list($images)) {
            throw new ProductUpsertException('"images" must be a list of asset ids; the first is the main photo.');
        }

        $ids = [];

        foreach ($images as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new ProductUpsertException('"images" must contain asset ids (integers), got '.json_encode($id).'.');
            }

            $ids[] = (int) $id;
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw new ProductUpsertException('"images" contains the same asset id twice.');
        }

        $found = Asset::query()->whereIn('id', $ids)->pluck('file_type', 'id');

        if ($missing = array_diff($ids, $found->keys()->all())) {
            throw new ProductUpsertException('Unknown asset id(s): '.implode(', ', $missing).'. Upload photos with upload-product-image first.');
        }

        if ($notImages = $found->filter(fn (?string $type): bool => $type !== 'image')->keys()->all()) {
            throw new ProductUpsertException('Not an image: asset '.implode(', ', $notImages).'.');
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validEdit(array $input): array
    {
        if ($unknown = array_diff(array_keys($input), self::EDIT_KEYS)) {
            throw new ProductUpsertException('Unknown primary_edit field(s): '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::EDIT_KEYS).'.');
        }

        $shapes = array_keys(config('product_image_editor.shapes', []));

        $errors = array_filter([
            isset($input['shape']) && ! in_array($input['shape'], $shapes, true)
                ? 'primary_edit.shape must be one of: '.implode(', ', $shapes).'.' : null,
            isset($input['scale']) && (! is_numeric($input['scale']) || $input['scale'] < 0.1 || $input['scale'] > 10)
                ? 'primary_edit.scale must be a number between 0.1 and 10.' : null,
            isset($input['rotation']) && ! in_array((int) $input['rotation'], self::ROTATIONS, true)
                ? 'primary_edit.rotation must be 0, 90, 180 or 270.' : null,
            isset($input['offset_x']) && ! is_numeric($input['offset_x']) ? 'primary_edit.offset_x must be a number.' : null,
            isset($input['offset_y']) && ! is_numeric($input['offset_y']) ? 'primary_edit.offset_y must be a number.' : null,
            isset($input['source_asset_id']) && ! is_numeric($input['source_asset_id']) ? 'primary_edit.source_asset_id must be an asset id.' : null,
        ]);

        foreach (['resize', 'padding', 'icon', 'outline'] as $flag) {
            if (isset($input[$flag]) && ! is_bool($input[$flag])) {
                $errors[] = "primary_edit.{$flag} must be true or false.";
            }
        }

        if ($errors) {
            throw new ProductUpsertException(implode(' ', $errors));
        }

        return $input;
    }

    /**
     * Gallery photos that would get the HW icon, without stamping anything.
     *
     * @param  list<int>  $ids
     * @return array{stamped: array<int, int>, reused: array<int, int>, skipped: array<int, int>}
     */
    private function galleryDryRun(Product $product, array $ids): array
    {
        $probe = $product->replicate();
        $probe->additional = $product->additional;
        $values = $this->normalizeValues($product->values);
        $values['common'][config('product_image_editor.primary_attribute')] = implode(',', $ids);
        $probe->values = $values;

        return $this->galleryLogo->apply($probe, dryRun: true);
    }

    /**
     * @return array{afbeelding: list<int>, afbeelding_zonder_logo: list<int>}
     */
    private function currentLists(Product $product): array
    {
        $values = $this->normalizeValues($product->values);

        return [
            'afbeelding'             => $this->assetIdList($values['common'][config('product_image_editor.primary_attribute')] ?? null),
            'afbeelding_zonder_logo' => $this->assetIdList($values['common'][config('product_image_editor.no_logo_attribute')] ?? null),
        ];
    }

    /**
     * @param  array{afbeelding: list<int>, afbeelding_zonder_logo: list<int>}  $before
     * @param  array{afbeelding: list<array<string, mixed>>, afbeelding_zonder_logo: list<array<string, mixed>>}  $after
     * @return array<string, array{before: list<array<string, mixed>>, after: list<array<string, mixed>>}>
     */
    private function changes(array $before, array $after): array
    {
        $changes = [];

        foreach ([config('product_image_editor.primary_attribute') => 'afbeelding', config('product_image_editor.no_logo_attribute') => 'afbeelding_zonder_logo'] as $code => $key) {
            $afterIds = array_column($after[$key], 'id');

            if ($afterIds !== $before[$key]) {
                $changes["common.{$code}"] = [
                    'before' => $this->describer->describeList($before[$key]),
                    'after'  => $after[$key],
                ];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $edit
     * @return array<string, mixed>
     */
    private function editSummary(array $edit): array
    {
        return [
            'source_asset_id' => (int) $edit['source_asset_id'],
            'shape'           => $edit['shape'] ?? config('product_image_editor.default_shape'),
            'scale'           => (float) ($edit['scale'] ?? 1),
            'offset_x'        => (int) ($edit['offset_x'] ?? 0),
            'offset_y'        => (int) ($edit['offset_y'] ?? 0),
            'rotation'        => (int) ($edit['rotation'] ?? 0),
            'resize'          => (bool) ($edit['resize'] ?? true),
            'padding'         => (bool) ($edit['padding'] ?? true),
            'icon'            => (bool) ($edit['icon'] ?? true),
            'outline'         => (bool) ($edit['outline'] ?? config('product_image_editor.outline.enabled')),
        ];
    }

    private function storeAsset(string $contents, string $fileName, string $mimeType, string $extension): Asset
    {
        $directory = Directory::query()->whereNull('parent_id')->orderBy('id')->first();
        $directoryPath = $directory ? Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath() : Directory::ASSETS_DIRECTORY;
        $path = $directoryPath.'/'.$fileName;

        Storage::disk(Directory::ASSETS_DISK)->put($path, $contents);

        $asset = Asset::create([
            'file_name' => $fileName,
            'file_type' => 'image',
            'file_size' => strlen($contents),
            'mime_type' => $mimeType,
            'extension' => $extension,
            'path'      => $path,
        ]);

        if ($directory) {
            $asset->directories()->syncWithoutDetaching([$directory->id]);
        }

        return $asset;
    }

    /**
     * A URL-safe file name with a random suffix, so the shop's image URLs
     * never need encoding and two uploads never collide.
     */
    private function fileName(?string $requested, string $url, string $extension): string
    {
        $name = $requested ?: ($url !== '' ? rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) : '');
        $stem = Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'image';

        return sprintf('%s-%s.%s', Str::limit($stem, 120, ''), Str::lower(Str::random(6)), $extension);
    }

    /**
     * An existing asset with exactly these bytes. Only assets of the same
     * size are read, so this costs a handful of reads at most.
     */
    private function findIdentical(string $contents): ?Asset
    {
        $hash = sha1($contents);

        $candidates = Asset::query()
            ->where('file_size', strlen($contents))
            ->where('file_type', 'image')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        foreach ($candidates as $candidate) {
            $existing = $this->describer->contents($candidate);

            if ($existing !== null && sha1($existing) === $hash) {
                return $candidate;
            }
        }

        return null;
    }

    private function decodeBase64(string $base64): string
    {
        $base64 = preg_replace('/^data:[^;]+;base64,/', '', trim($base64));
        $contents = base64_decode((string) $base64, true);

        if ($contents === false || $contents === '') {
            throw new ProductUpsertException('"content_base64" is not valid base64.');
        }

        if (strlen($contents) > self::MAX_UPLOAD_BYTES) {
            throw new ProductUpsertException('The photo is larger than '.(self::MAX_UPLOAD_BYTES / 1024 / 1024).' MB.');
        }

        return $contents;
    }

    /**
     * @return array{contents: string, name: string, path: ?string, size: int}
     */
    private function downloadFromDropbox(string $reference): array
    {
        try {
            return $this->dropbox->download($reference, self::MAX_UPLOAD_BYTES);
        } catch (DropboxException $e) {
            throw new ProductUpsertException($e->getMessage());
        }
    }

    /**
     * Downloads a photo from a public http(s) URL. Hosts that resolve to a
     * private or reserved address are refused, also after a redirect.
     */
    private function download(string $url): string
    {
        $this->assertPublicUrl($url);

        try {
            $response = Http::timeout(30)
                ->withOptions(['allow_redirects' => [
                    'max'         => 3,
                    'protocols'   => ['http', 'https'],
                    'on_redirect' => fn ($request, $response, UriInterface $uri) => $this->assertPublicUrl((string) $uri),
                ]])
                ->get($url);
        } catch (ConnectionException $e) {
            throw new ProductUpsertException("Could not download {$url}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw new ProductUpsertException("Downloading {$url} failed with HTTP {$response->status()}.");
        }

        $contents = $response->body();

        if ($contents === '') {
            throw new ProductUpsertException("{$url} returned an empty file.");
        }

        if (strlen($contents) > self::MAX_UPLOAD_BYTES) {
            throw new ProductUpsertException('The photo is larger than '.(self::MAX_UPLOAD_BYTES / 1024 / 1024).' MB.');
        }

        return $contents;
    }

    private function assertPublicUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ProductUpsertException("Only http(s) URLs can be downloaded, got \"{$url}\".");
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            throw new ProductUpsertException("The host {$host} cannot be resolved.");
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ProductUpsertException("The host {$host} is not a public address.");
            }
        }
    }

    private function authorize(Admin $admin): void
    {
        if (! AdminPermission::allows($admin, 'catalog.products.edit')) {
            throw new ProductUpsertException('Your admin role lacks the catalog.products.edit permission.');
        }
    }
}
