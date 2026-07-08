<?php

namespace App\Services\ProductImageEditor;

use Illuminate\Support\Facades\Storage;

/**
 * Resolves the raw bytes of the admin-configured HW icon
 * (core config key image_editor.settings.general.hw_icon).
 */
class HwIconResolver
{
    public function contents(): ?string
    {
        $path = core()->getConfigData('image_editor.settings.general.hw_icon');

        if (! $path) {
            return null;
        }

        $disk = config('filesystems.default');

        return Storage::disk($disk)->exists($path) ? Storage::disk($disk)->get($path) : null;
    }
}
