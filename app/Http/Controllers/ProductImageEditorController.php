<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\DAM\Models\Asset;

/**
 * Serves source asset data to the primary image editor (front-end positioning UI).
 */
class ProductImageEditorController extends Controller
{
    /**
     * Return the source image dimensions and a streaming URL for the editor canvas.
     */
    public function source(Asset $asset): JsonResponse
    {
        if (! Storage::disk($this->disk())->exists($asset->path)) {
            return response()->json(['message' => 'Asset not found.'], 404);
        }

        $size = @getimagesizefromstring(Storage::disk($this->disk())->get($asset->path));

        if ($size === false) {
            return response()->json(['message' => 'Asset is not an image.'], 422);
        }

        return response()->json([
            'id'     => $asset->id,
            'width'  => $size[0],
            'height' => $size[1],
            'url'    => route('admin.product_image_editor.image', ['asset' => $asset->id]),
        ]);
    }

    /**
     * Stream the original asset bytes for use as the editor canvas image.
     */
    public function image(Asset $asset): StreamedResponse
    {
        abort_unless(Storage::disk($this->disk())->exists($asset->path), 404);

        return Storage::disk($this->disk())->response($asset->path);
    }

    private function disk(): string
    {
        return config('product_image_editor.asset_disk', 'private');
    }
}
