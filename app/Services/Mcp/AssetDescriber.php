<?php

namespace App\Services\Mcp;

use App\Models\AssetLogoVariant;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;
use Webkul\DAM\Models\Asset;

/**
 * Turns DAM asset ids into what an AI client needs to recognise a photo: file
 * name, public URL, size and, for HW logo variants, the photo it was made
 * from. Also renders small JPEG previews the client can look at.
 */
class AssetDescriber
{
    public const PREVIEW_WIDTH = 600;

    public function __construct(private ImageManager $imageManager) {}

    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, file_name: ?string, url: ?string, file_size?: int, mime_type?: string, logo_variant_of?: int, missing?: true}>
     */
    public function describe(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $assets = Asset::query()->whereIn('id', $ids)->get()->keyBy('id');

        $logoSources = AssetLogoVariant::query()
            ->whereIn('variant_asset_id', $ids)
            ->whereColumn('variant_asset_id', '!=', 'source_asset_id')
            ->pluck('source_asset_id', 'variant_asset_id');

        $described = [];

        foreach ($ids as $id) {
            $asset = $assets->get($id);

            if (! $asset) {
                $described[$id] = ['id' => $id, 'file_name' => null, 'url' => null, 'missing' => true];

                continue;
            }

            $described[$id] = array_filter([
                'id'              => $id,
                'file_name'       => $asset->file_name,
                'url'             => $this->url($asset),
                'file_size'       => (int) $asset->file_size,
                'mime_type'       => $asset->mime_type,
                'logo_variant_of' => isset($logoSources[$id]) ? (int) $logoSources[$id] : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $described;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function describeList(array $ids): array
    {
        $described = $this->describe($ids);

        return array_map(fn (int $id): array => $described[$id], array_map('intval', $ids));
    }

    public function url(Asset $asset): string
    {
        return Storage::disk(config('product_image_editor.asset_disk', 'private'))->url($asset->path);
    }

    /**
     * The asset's file bytes, or null when the file is gone.
     */
    public function contents(Asset $asset): ?string
    {
        $contents = Storage::disk(config('product_image_editor.asset_disk', 'private'))->get($asset->path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * A JPEG scaled down to PREVIEW_WIDTH, small enough to hand to the client.
     */
    public function preview(string $contents): ?string
    {
        try {
            return (string) $this->imageManager->decode($contents)
                ->scaleDown(width: self::PREVIEW_WIDTH)
                ->encodeUsingFormat(Format::JPEG, quality: 80);
        } catch (Throwable) {
            return null;
        }
    }
}
