<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CrossSellLinker;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use Webkul\Product\Type\AbstractType;

/**
 * De knop "Kleurvarianten koppelen" op de productpagina.
 *
 * Twee stappen, want de koppeling schrijft op meerdere producten tegelijk:
 * eerst een voorstel dat de redacteur in het venster kan bijstellen, daarna een
 * bevestiging die precies de aangevinkte producten vastlegt.
 */
class CrossSellController extends Controller
{
    public function __construct(
        private readonly CrossSellLinker $linker,
        private readonly ProductService $products,
    ) {}

    /**
     * De producten die volgens de naam dezelfde kleed in een andere kleur zijn.
     */
    public function candidates(int $productId): JsonResponse
    {
        $product = Product::find($productId);

        if ($product === null) {
            return response()->json(['message' => "Product [{$productId}] bestaat niet."], 404);
        }

        if ($this->linker->groupKey($product) === null) {
            return response()->json([
                'message' => 'In de productnaam zit geen kleurcode, dus er valt geen kleurgroep uit af te leiden. Koppel de kleuren in dat geval handmatig via het blok "Cross Sells".',
            ], 422);
        }

        $current = $this->currentCrossSells($product);

        return response()->json([
            'product'    => $this->describe($product, true, in_array($product->sku, $current, true)),
            'candidates' => $this->linker->candidates($product)
                ->map(fn (Product $candidate): array => $this->describe(
                    $candidate,
                    true,
                    in_array($candidate->sku, $current, true),
                ))
                ->values(),
        ]);
    }

    /**
     * Legt de aangevinkte producten wederzijds vast.
     */
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'skus'   => ['required', 'array', 'min:2'],
            'skus.*' => ['required', 'string'],
        ]);

        try {
            $connected = $this->linker->connect($validated['skus']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'connected' => $connected->count(),
            'message'   => $connected->count().' producten aan elkaar gekoppeld.',
        ]);
    }

    /**
     * @return array{sku:string, naam:string, merk:string, vorm:string, selected:bool, already_linked:bool}
     */
    private function describe(Product $product, bool $selected, bool $alreadyLinked): array
    {
        $common = $this->products->commonValues($product);

        return [
            'sku'            => (string) $product->sku,
            'naam'           => (string) ($common['productnaam'] ?? $product->sku),
            'merk'           => (string) ($common['merk'] ?? ''),
            'vorm'           => (string) ($common['vorm'] ?? ''),
            'selected'       => $selected,
            'already_linked' => $alreadyLinked,
        ];
    }

    /**
     * @return list<string>
     */
    private function currentCrossSells(Product $product): array
    {
        $values = $this->products->productValues($product);

        $crossSells = $values[AbstractType::ASSOCIATION_VALUES_KEY][AbstractType::CROSS_SELLS_ASSOCIATION_KEY] ?? [];

        return is_array($crossSells) ? array_values(array_filter($crossSells, 'is_string')) : [];
    }
}
