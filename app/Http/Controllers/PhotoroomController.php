<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyPhotoroomTransformationJob;
use Illuminate\Http\JsonResponse;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Repositories\ProductRepository;

class PhotoroomController extends Controller
{
    public function __construct(
        protected ProductRepository $productRepository,
        protected AttributeRepository $attributeRepository,
    ) {}

    /**
     * Dispatch the AI text removal job for a product attribute.
     */
    public function transform(int $productId, string $attributeCode): JsonResponse
    {
        $product = $this->productRepository->find($productId);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $attribute = $this->attributeRepository->findOneByField('code', $attributeCode);

        if (! $attribute || ! $attribute->ai_transformation_from) {
            return response()->json(['message' => 'Attribute not configured for AI transformation.'], 422);
        }

        ApplyPhotoroomTransformationJob::dispatch($productId, $attributeCode);

        return response()->json(['message' => 'AI transformation job queued successfully.']);
    }
}
