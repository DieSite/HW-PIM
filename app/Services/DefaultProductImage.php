<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * The admin-configured "geen afbeelding beschikbaar" image
 * (core config key product_images.settings.general.default_image).
 */
class DefaultProductImage
{
    public function url(): ?string
    {
        $path = core()->getConfigData('product_images.settings.general.default_image');

        if (! $path) {
            return null;
        }

        $disk = Storage::disk(config('filesystems.default'));

        return $disk->exists($path) ? $disk->url($path) : null;
    }
}
