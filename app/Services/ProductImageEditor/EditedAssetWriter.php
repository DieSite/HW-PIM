<?php

namespace App\Services\ProductImageEditor;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\DAM\Models\Asset;

/**
 * Persists a composited image as a new DAM asset, stored alongside the source
 * asset and linked to the same DAM directories so it shows up in the library.
 */
class EditedAssetWriter
{
    /**
     * Create a new DAM asset from raw image bytes.
     *
     * @param  Asset  $source  The asset the edited image was derived from.
     * @param  string  $contents  Raw JPEG bytes of the composited image.
     * @param  string  $suffix  Filename suffix distinguishing the variant (e.g. "hw", "zonder-logo").
     */
    public function store(Asset $source, string $contents, string $suffix): Asset
    {
        $disk = config('product_image_editor.asset_disk', 'private');

        $directory = trim((string) pathinfo($source->path, PATHINFO_DIRNAME), '/');
        $stem = Str::slug(pathinfo($source->file_name, PATHINFO_FILENAME)) ?: 'image';
        $fileName = sprintf('%s-%s-%s.jpg', $stem, $suffix, Str::lower(Str::random(6)));

        $path = $directory !== '' ? $directory.'/'.$fileName : $fileName;

        Storage::disk($disk)->put($path, $contents);

        $asset = Asset::create([
            'file_name' => $fileName,
            'file_type' => 'image',
            'file_size' => strlen($contents),
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'path'      => $path,
        ]);

        $directoryIds = $source->directories()->pluck('dam_directories.id')->all();

        if (! empty($directoryIds)) {
            $asset->directories()->syncWithoutDetaching($directoryIds);
        }

        return $asset;
    }
}
