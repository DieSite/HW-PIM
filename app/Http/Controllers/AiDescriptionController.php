<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AI\AiSettings;
use App\Services\AI\ProductDescriptionGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The generate button on the product create/edit page.
 *
 * Synchronous on purpose: the texts go back into the form for review and the
 * editor still has to press Save, so nothing is written until a human agrees.
 * That mirrors the existing "Meta velden genereren" flow.
 */
class AiDescriptionController extends Controller
{
    public function __construct(
        private readonly ProductDescriptionGenerator $generator,
        private readonly AiSettings $settings,
    ) {}

    public function generate(Request $request): JsonResponse
    {
        if (! $this->settings->enabled()) {
            return response()->json(['message' => 'AI-teksten staan uit in de configuratie.'], 422);
        }

        $validated = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'values'     => ['nullable', 'array'],
            'fields'     => ['nullable', 'array'],
            'fields.*'   => ['string', Rule::in(array_keys((array) config('ai.fields')))],
        ]);

        try {
            $result = $this->result($validated);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'texts'      => $result['texts'],
            'problems'   => $result['problems'],
            'similarity' => $result['similarity'],
            'model'      => $result['model'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function result(array $validated): array
    {
        $fields = $validated['fields'] ?? null;

        /**
         * What the editor currently has on screen. It wins over what is stored:
         * a product created a minute ago has almost nothing saved yet, and on
         * an edit the point is usually to describe the change just made.
         */
        $values = $validated['values'] ?? [];

        if (! empty($validated['product_id'])) {
            $product = Product::findOrFail((int) $validated['product_id']);

            /**
             * Descriptions belong to the parent: the shop reads them there and
             * every descriptive attribute on a variant is null.
             */
            if ($product->parent_id) {
                $product = Product::findOrFail((int) $product->parent_id);
            }

            return $this->generator->generate($product, $fields, $values);
        }

        return $this->generator->generateFromValues($values, $fields);
    }
}
