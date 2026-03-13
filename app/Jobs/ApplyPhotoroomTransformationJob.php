<?php

namespace App\Jobs;

use App\Services\PhotoroomService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DAM\Models\Asset;
use Webkul\Product\Repositories\ProductRepository;

class ApplyPhotoroomTransformationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 120;

    public function __construct(
        public readonly int $productId,
        public readonly string $targetAttributeCode,
    ) {}

    public function handle(
        PhotoroomService $photoroomService,
        ProductRepository $productRepository,
        AttributeRepository $attributeRepository,
    ): void {
        $product = $productRepository->find($this->productId);

        if (! $product) {
            throw new RuntimeException("Product [{$this->productId}] not found.");
        }

        $targetAttribute = $attributeRepository->findOneByField('code', $this->targetAttributeCode);

        if (! $targetAttribute) {
            throw new RuntimeException("Attribute [{$this->targetAttributeCode}] not found.");
        }

        $sourceAttributeCode = $targetAttribute->ai_transformation_from;

        if (! $sourceAttributeCode) {
            throw new RuntimeException("Attribute [{$this->targetAttributeCode}] has no ai_transformation_from configured.");
        }

        $sourceAttribute = $attributeRepository->findOneByField('code', $sourceAttributeCode);

        if (! $sourceAttribute) {
            throw new RuntimeException("Source attribute [{$sourceAttributeCode}] not found.");
        }

        $productValues = $product->values ?? [];
        $sourceValue = $sourceAttribute->getValueFromProductValues($productValues, '', '');

        $rawValue = is_array($sourceValue) ? ($sourceValue[0] ?? null) : $sourceValue;

        $rawValue = explode(',', $rawValue)[0];

        if (! $rawValue) {
            throw new RuntimeException("No source image found in attribute [{$sourceAttributeCode}] for product [{$this->productId}].");
        }

        [$storagePath, $sourceDamAsset] = $this->resolveStoragePath($rawValue);

        if (! Storage::disk('private')->exists($storagePath)) {
            throw new RuntimeException("Source image file does not exist at path: {$storagePath}");
        }

        $imageContent = Storage::disk('private')->get($storagePath);
        $filename = basename($storagePath);

        $processedImageContent = $photoroomService->removeText($imageContent, $filename);

        $targetFilename = pathinfo($filename, PATHINFO_FILENAME).'_no_logo.png';
        $targetStoragePath = dirname($storagePath).'/'.$targetFilename;

        Storage::disk('private')->put($targetStoragePath, $processedImageContent);

        // Write a debug copy for local inspection.
        Storage::disk('local')->put('photoroom-debug/'.$targetFilename, $processedImageContent);

        $damAsset = $this->createDamAsset($targetStoragePath, $targetFilename, strlen($processedImageContent), $sourceDamAsset);

        Storage::disk('private')->delete('thumbnails/'.$targetStoragePath);

        $targetAttribute->setProductValue((string) $damAsset->id, $productValues);

        $product->values = $productValues;
        $product->save();

        Log::info("Photoroom transformation complete for product [{$this->productId}], target attribute [{$this->targetAttributeCode}], DAM asset [{$damAsset->id}], path [{$targetStoragePath}].");
    }

    /**
     * Resolve a raw attribute value (DAM asset ID or file path) to a storage path.
     *
     * @return array{0: string, 1: object|null}
     */
    private function resolveStoragePath(string $rawValue): array
    {
        if (is_numeric($rawValue)) {
            $damAsset = DB::table('dam_assets')->where('id', $rawValue)->first();

            if ($damAsset && ! empty($damAsset->path)) {
                return [$damAsset->path, $damAsset];
            }
        }

        return [$rawValue, null];
    }

    /**
     * Create or update the dam_assets record for the processed image.
     */
    private function createDamAsset(string $storagePath, string $filename, int $fileSize, ?object $sourceAsset): Asset
    {
        return Asset::updateOrCreate(
            ['path' => $storagePath],
            [
                'file_name' => $filename,
                'file_type' => 'image',
                'file_size' => $fileSize,
                'mime_type' => 'image/png',
                'extension' => 'png',
            ]
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Photoroom transformation failed for product [{$this->productId}], attribute [{$this->targetAttributeCode}]: {$exception->getMessage()}");
    }
}
