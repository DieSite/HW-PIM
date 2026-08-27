<?php

namespace App\Http\Controllers;

use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webkul\Product\Models\Product;
use Webkul\WooCommerce\Services\WooCommerceService;

class ProductHelperController extends Controller
{
    public function __construct(private readonly ProductService $productService) {}

    public function redirectToFrontend(Product $product)
    {
        $connector = app(WooCommerceService::class);

        if ($product->parent !== null) {
            $product = $product->parent;
        }

        $products = $connector->requestApiAction(
            'getProductWithSku',
            [],
            ['sku' => $product->sku]
        );

        if (isset($products[0])) {
            return redirect($products[0]['permalink']);
        }

        abort(418, 'External product not found.');
    }

    /**
     * Open the admin edit screen of the product with the given SKU.
     */
    public function redirectToEditBySku(string $sku): RedirectResponse
    {
        $product = Product::query()->where('sku', $sku)->firstOrFail();

        return redirect()->route('admin.catalog.products.edit', ['id' => $product->id]);
    }

    public function metaFields(Request $request)
    {
        $sku = $request->input('sku');

        $product = Product::where('sku', $sku)->first();
        $naam = $request->input('title', $product->values['common']['productnaam']);
        $merk = $request->input('merk', $product->values['common']['merk']);

        $metaTitle = $this->productService->generateMetaTitle($naam, $merk);
        $metaDescription = $this->productService->generateMetaDescription($naam);

        return response()->json(['meta_title' => $metaTitle, 'meta_description' => $metaDescription]);
    }

    /**
     * De afgeleide prijs en adviesverkoopprijs van een met-onderkleed-variant.
     *
     * De adviesverkoopprijs is null wanneer de kale variant er zelf geen heeft;
     * de knop laat dat veld dan ongemoeid.
     *
     * Elke uitkomst waarbij er niets te berekenen valt krijgt een `error`, en
     * elke berekening die op een aanname leunt een `warning`. Zonder die twee
     * eindigde de knop bij een ontbrekende tegenvariant of een onbekende maat
     * in een bevestiging met een prijs die nergens op sloeg, of deed hij
     * ogenschijnlijk niets.
     */
    public function price(Request $request): JsonResponse
    {
        $sku = (string) $request->input('sku');

        $product = Product::where('sku', $sku)->first();

        if ($product === null) {
            return $this->priceResponse(error: "Geen product gevonden met SKU '{$sku}'.");
        }

        $common = $this->productService->commonValues($product);
        $size = $common['maat'] ?? '';
        $size = is_string($size) && trim($size) !== '' ? trim($size) : '(geen maat)';

        if (mb_strtolower(trim((string) ($common['onderkleed'] ?? ''))) !== 'met onderkleed') {
            return $this->priceResponse(error: "Product '{$sku}' staat niet op 'Met onderkleed'; er valt geen bundelprijs af te leiden.");
        }

        $withoutOnderkleed = $this->productService->getUnderrugAlternative($product);

        if ($withoutOnderkleed === null) {
            return $this->priceResponse(error: $this->missingCounterpartMessage($product, $size));
        }

        $base = $this->productService->commonValues($withoutOnderkleed);

        $warning = null;

        if ($this->productService->underrugSurcharge($product) === null) {
            $warning = "Let op: er staat geen onderkleedtoeslag in de tarieventabel voor maat '{$size}'. De prijs hieronder is die van de kale variant, zonder toeslag.";
        }

        return $this->priceResponse(
            price: $this->productService->calculateMetOnderkleedPrice($product),
            originalPrice: isset($base['prijs']['EUR']) ? (string) $base['prijs']['EUR'] : null,
            adviesPrice: $this->productService->calculateMetOnderkleedAdviesPrice($product),
            originalAdviesPrice: isset($base['adviesverkoopprijs']['EUR']) ? (string) $base['adviesverkoopprijs']['EUR'] : null,
            warning: $warning,
        );
    }

    /**
     * Waarom er geen kale tegenvariant is, met de maten die het hoofdproduct
     * wél kaal verkoopt erbij — dat wijst meteen naar de variant die scheef
     * staat (verkeerd onderkleed, ontbrekende of afwijkend gespelde maat).
     */
    private function missingCounterpartMessage(Product $product, string $size): string
    {
        $message = "Geen 'Zonder onderkleed'-variant in maat '{$size}' gevonden onder hetzelfde hoofdproduct; er is geen prijs om van af te leiden.";

        $parent = $product->parent;

        if ($parent === null) {
            return $message.' Dit product hangt niet onder een hoofdproduct.';
        }

        $siblings = $parent->variants
            ->reject(fn (Product $variant): bool => $variant->id === $product->id)
            ->map(fn (Product $variant): array => [
                'sku'        => $variant->sku,
                'maat'       => trim((string) ($this->productService->commonValues($variant)['maat'] ?? '')),
                'onderkleed' => mb_strtolower(trim((string) ($this->productService->commonValues($variant)['onderkleed'] ?? ''))),
            ]);

        // Een variant zonder maat of zonder onderkleed valt buiten elke
        // koppeling; die noemen we bij naam, want dat is doorgaans het veld dat
        // hersteld moet worden.
        $incomplete = $siblings
            ->filter(fn (array $sibling): bool => $sibling['maat'] === '' || $sibling['onderkleed'] === '')
            ->map(fn (array $sibling): string => $sibling['sku'].' (geen '.($sibling['maat'] === '' ? 'maat' : "'Onderkleed'").')')
            ->values();

        $sizes = $siblings
            ->filter(fn (array $sibling): bool => $sibling['onderkleed'] === 'zonder onderkleed')
            ->pluck('maat')
            ->filter()
            ->unique()
            ->values();

        $message .= $sizes->isEmpty()
            ? ' Dit hoofdproduct heeft geen enkele variant op \'Zonder onderkleed\' staan.'
            : ' Kale varianten bestaan wel in: '.$sizes->implode(', ').'.';

        if ($incomplete->isNotEmpty()) {
            $message .= ' Onvolledige varianten: '.$incomplete->implode(', ').'.';
        }

        return $message;
    }

    /**
     * Altijd dezelfde sleutels, zodat de knop nooit op een ontbrekend veld stuit.
     */
    private function priceResponse(
        ?string $price = null,
        ?string $originalPrice = null,
        ?string $adviesPrice = null,
        ?string $originalAdviesPrice = null,
        ?string $warning = null,
        ?string $error = null,
    ): JsonResponse {
        return response()->json([
            'price'                 => $price,
            'original_price'        => $originalPrice,
            'advies_price'          => $adviesPrice,
            'original_advies_price' => $originalAdviesPrice,
            'warning'               => $warning,
            'error'                 => $error,
        ]);
    }

    public function sku(Request $request)
    {
        $data = $request->validate([
            'brand' => 'required|string|in:ERG,DES,KP,DMC',
        ]);
        $brand = $data['brand'];

        // SELECT  FROM products WHERE sku LIKE 'ERG%' AND sku NOT LIKE '%.%'

        $value = Product::where('sku', 'like', $brand.'%')->where('type', 'configurable')->rawValue("MAX(CAST(REPLACE(sku, '$brand', '') as UNSIGNED))");
        $value++;

        // Ensure the SKU doesn't exist already
        while (Product::where('sku', $brand.$value)->exists()) {
            $value++;
        }

        return response()->json(['sku' => $brand.$value]);
    }
}
