<?php

namespace App\Services;

use App\Models\Product;
use App\Services\WooCommerce\WooCommerceSyncEventRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Webkul\Product\Type\AbstractType;
use Webkul\WooCommerce\Listeners\SerializedProcessProductsToWooCommerce;

/**
 * Kleurvarianten aan elkaar knopen.
 *
 * Eenzelfde kleed verschijnt in de catalogus als losse hoofdproducten per
 * kleur — Anaheim 3243, Anaheim 3434, Anaheim 4248 — die alleen in hun
 * kleurcode van elkaar verschillen. De koppeling loopt via `cross_sells`, de
 * associatie die de WooCommerce-export als upsells naar de shop stuurt en die
 * de AI-briefing al leest als "verkrijgbaar in N kleuren".
 *
 * De lijst is symmetrisch en bevat het product zelf, zoals de bestaande
 * gegevens in dit systeem: elk lid van de groep draagt dezelfde lijst.
 */
class CrossSellLinker
{
    /**
     * Een kleurcode zoals die in productnamen staat: 3243, 01, 1000, 141.132,
     * 3802-203, BE600.
     */
    private const COLOUR_CODE = '/^[a-z]{0,3}\d+([.-]\d+)*$/i';

    public function __construct(
        private readonly ProductService $products,
        private readonly WooCommerceSyncEventRecorder $syncEventRecorder,
    ) {}

    /**
     * De andere kleuren van hetzelfde kleed, op naam gesorteerd.
     *
     * @return Collection<int, Product>
     */
    public function candidates(Product $product): Collection
    {
        $key = $this->groupKey($product);

        if ($key === null) {
            return collect();
        }

        $common = $this->products->commonValues($product);
        $prefix = $this->parseName((string) ($common['productnaam'] ?? ''))['prefix'] ?? '';

        return Product::query()
            ->where('type', 'configurable')
            ->whereNull('parent_id')
            ->whereKeyNot($product->id)
            // Grof voorfilter op de naam; de precieze vergelijking gebeurt
            // hieronder in PHP. Bewust op de hele kolom en niet op een
            // `values->common->productnaam`-pad, want dat pad slaat de dubbel
            // gecodeerde rijen stilzwijgend over.
            ->where('values', 'like', '%'.addcslashes($prefix, '%_\\').'%')
            ->get()
            ->filter(fn (Product $candidate): bool => $this->groupKey($candidate) === $key)
            ->sortBy(fn (Product $candidate): string => (string) ($this->products->commonValues($candidate)['productnaam'] ?? $candidate->sku))
            ->values();
    }

    /**
     * De sleutel waarop kleuren bij elkaar horen: de naam zonder kleurcode,
     * plus merk en vorm. Merk telt mee omdat een naam als "Galaxy" bij meerdere
     * merken voorkomt; vorm omdat een ronde en een rechthoekige uitvoering
     * verschillende producten zijn.
     */
    public function groupKey(Product $product): ?string
    {
        $common = $this->products->commonValues($product);

        $parsed = $this->parseName((string) ($common['productnaam'] ?? ''));

        if ($parsed === null) {
            return null;
        }

        return implode('|', [
            $parsed['base'],
            $this->normalise((string) ($common['merk'] ?? '')),
            $this->normalise((string) ($common['vorm'] ?? '')),
        ]);
    }

    /**
     * Splitst "Anaheim 3243 Rond" in het deel vóór de kleurcode, de kleurcode
     * zelf en wat erachter staat. Een naam zonder kleurcode levert null op —
     * daar valt geen kleurgroep uit af te leiden.
     *
     * @return array{prefix:string, code:string, suffix:string, base:string}|null
     */
    public function parseName(string $productnaam): ?array
    {
        // Een staart achter " - " is een kleurnaam ("Vernon 15 - Fall Grey") en
        // hoort dus, net als de kleurcode, niet bij de basisnaam.
        $name = (string) preg_replace('/\s+-\s+.*$/', '', trim((string) preg_replace('/\s+/', ' ', $productnaam)));

        if ($name === '') {
            return null;
        }

        $tokens = explode(' ', $name);

        $codeIndex = null;

        foreach ($tokens as $index => $token) {
            if (preg_match(self::COLOUR_CODE, $token) === 1) {
                $codeIndex = $index;
            }
        }

        if ($codeIndex === null || $codeIndex === 0) {
            return null;
        }

        $prefix = implode(' ', array_slice($tokens, 0, $codeIndex));
        $suffix = implode(' ', array_slice($tokens, $codeIndex + 1));

        return [
            'prefix' => $prefix,
            'code'   => $tokens[$codeIndex],
            'suffix' => $suffix,
            'base'   => $this->normalise(trim($prefix.' '.$suffix)),
        ];
    }

    /**
     * Legt de gekozen producten wederzijds vast: iedereen krijgt dezelfde
     * lijst, inclusief zichzelf. De shop gaat altijd mee — een koppeling die
     * alleen in de PIM staat doet in de winkel niets.
     *
     * @param  list<string>  $skus
     * @return Collection<int, Product>
     */
    public function connect(array $skus): Collection
    {
        $skus = collect($skus)
            ->map(fn ($sku): string => trim((string) $sku))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($skus->count() < 2) {
            throw new RuntimeException('Kies minstens twee producten om aan elkaar te koppelen.');
        }

        $products = Product::query()->whereIn('sku', $skus->all())->get();

        $missing = $skus->diff($products->pluck('sku'));

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Onbekende SKU(\'s): '.$missing->implode(', ').'.');
        }

        foreach ($products as $product) {
            $values = $this->products->productValues($product);
            $values[AbstractType::ASSOCIATION_VALUES_KEY][AbstractType::CROSS_SELLS_ASSOCIATION_KEY] = $skus->all();

            $product->values = $values;
            $product->save();

            Event::dispatch('catalog.product.update.after', $product);
        }

        // Pas synchroniseren als de hele groep is weggeschreven: de export leest
        // het product opnieuw uit de database, en anders vertrekt de eerste met
        // een halve groep. Alleen het hoofdproduct, want de varianten dragen
        // niets van de koppeling.
        foreach ($products as $product) {
            $this->syncEventRecorder->queued($product);
            SerializedProcessProductsToWooCommerce::dispatch($product);
        }

        return $products;
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }
}
