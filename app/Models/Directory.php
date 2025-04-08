<?php

namespace App\Models;

use Illuminate\Support\Facades\Storage;

class Directory extends \Webkul\DAM\Models\Directory
{
    const ASSETS_DIRECTORY = 'uploads';

    public function isWritable(string $path): bool
    {
        return Storage::disk(self::ASSETS_DISK)->exists($path);
    }
}
