<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyPhotoroomTransformationJob;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Webkul\Attribute\Models\Attribute;

class PhotoroomController extends Controller
{
    /**
     * Dispatch the AI text removal job for a product attribute.
     */
    public function transform(int $productId, string $attributeCode): JsonResponse
    {
        $product = Product::find($productId);

        if (! $product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $attribute = Attribute::where('code', $attributeCode)->first();

        if (! $attribute || ! $attribute->ai_transformation_from) {
            return response()->json(['message' => 'Attribute not configured for AI transformation.'], 422);
        }

        ApplyPhotoroomTransformationJob::dispatch($productId, $attributeCode);

        return response()->json(['message' => 'AI transformation job queued successfully.']);
    }
}
