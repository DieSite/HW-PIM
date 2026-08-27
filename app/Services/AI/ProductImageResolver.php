<?php

namespace App\Services\AI;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Throwable;
use Webkul\DAM\Models\Asset;

/**
 * Fetches the product photo the model gets to look at.
 *
 * The photo is what makes each text unique: within a collection the spec sheets
 * are genuinely identical, so the visible design is the only distinguishing
 * signal available.
 *
 * Prefers the logo-free render, since the HW watermark is not part of the rug.
 *
 * @phpstan-type ImagePart array{bytes:string, mime:string}
 */
class ProductImageResolver
{
    /**
     * Attributes to try, in order of preference.
     */
    private const SOURCES = ['afbeelding_zonder_logo', 'afbeelding'];

    /**
     * Longest edge sent to the model. A rug photo carries all the design
     * information it needs well below full resolution, and this keeps the
     * request comfortably inside the provider's payload limit.
     */
    private const MAX_EDGE = 1024;

    public function __construct(private readonly ImageManager $imageManager) {}

    /**
     * @return ImagePart|null
     */
    public function resolve(Product $product): ?array
    {
        foreach (self::SOURCES as $code) {
            $assetId = $this->firstAssetId($product, $code);

            if ($assetId === null) {
                continue;
            }

            $image = $this->load($assetId);

            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    /**
     * @return ImagePart|null
     */
    private function load(int $assetId): ?array
    {
        $asset = Asset::find($assetId);

        if (! $asset || ! $asset->path) {
            return null;
        }

        try {
            if (! Storage::disk('private')->exists($asset->path)) {
                return null;
            }

            $bytes = Storage::disk('private')->get($asset->path);

            if ($bytes === null || $bytes === '') {
                return null;
            }

            return [
                'bytes' => $this->downscale($bytes),
                'mime'  => 'image/jpeg',
            ];
        } catch (Throwable $exception) {
            Log::warning("AI: kon DAM-asset [{$assetId}] niet lezen: {$exception->getMessage()}");

            return null;
        }
    }

    private function downscale(string $bytes): string
    {
        return $this->imageManager
            ->read($bytes)
            ->scaleDown(self::MAX_EDGE, self::MAX_EDGE)
            ->toJpeg(85)
            ->toString();
    }

    /**
     * Asset attributes hold either a comma-joined string of DAM ids or a JSON
     * array; both shapes exist in production, so parse defensively.
     */
    private function firstAssetId(Product $product, string $code): ?int
    {
        $values = $product->values;

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        $raw = is_array($values) ? ($values['common'][$code] ?? null) : null;

        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }

        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $first = trim(explode(',', (string) $raw)[0]);

        return is_numeric($first) ? (int) $first : null;
    }
}
