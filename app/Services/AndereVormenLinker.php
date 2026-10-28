<?php

namespace App\Services;

use App\Models\Product;
use App\Observers\AndereVormenMirror;
use Illuminate\Support\Collection;
use Webkul\Product\Type\AbstractType;

/**
 * Vormen van hetzelfde kleed aan elkaar knopen.
 *
 * Elke vorm is een eigen hoofdproduct: "Diamante 01", "Diamante 01 Rond",
 * "Diamante 01 Eye". De koppeling loopt via `andere_vormen`, los van de
 * kleuren in `cross_sells`, zodat de webshop ze anders kan tonen (een knop
 * naar de ronde versie bij het maatmenu).
 *
 * Net als bij de kleuren is de lijst symmetrisch en bevat hij het product
 * zelf. Op SKU valt de groep niet af te leiden (KP00217 / KP00217.Rond, maar
 * ERG002 / ERG3002.R), dus de naam beslist: zonder het vormwoord en zonder
 * een kleurnaam achter " - ", plus het merk.
 */
class AndereVormenLinker
{
    /** Product meta the WooCommerce export writes the other shapes to. */
    public const SHOP_META_KEY = '_hw_andere_vormen';

    public function __construct(
        private readonly ProductService $products,
    ) {}

    /**
     * De naam zonder vorm plus het merk, of null als er geen naam is.
     */
    public function groupKey(Product $product): ?string
    {
        $common = $this->products->commonValues($product);

        $name = $this->normalise((string) ($common['productnaam'] ?? ''));
        // "Vernon 13 - Linnen Grey" heet in het rond "Vernon 13 Rond".
        $name = (string) preg_replace('/\s+-\s+.*$/', '', $name);

        $vorm = $this->normalise((string) ($common['vorm'] ?? ''));

        if ($vorm !== '') {
            $name = $this->normalise((string) preg_replace('/(^|\s)'.preg_quote($vorm, '/').'(\s|$)/u', ' ', $name));
        }

        if ($name === '') {
            return null;
        }

        return $name.'|'.$this->normalise((string) ($common['merk'] ?? ''));
    }

    public function vorm(Product $product): string
    {
        return $this->normalise((string) ($this->products->commonValues($product)['vorm'] ?? ''));
    }

    /**
     * Alle hoofdproducten in groepen van hetzelfde kleed in meerdere vormen.
     * Een groep waarin een vorm twee keer voorkomt is niet eenduidig en komt
     * in `ambiguous`, om met de hand te koppelen.
     *
     * @return array{groups: list<Collection<int, Product>>, ambiguous: list<Collection<int, Product>>}
     */
    public function groups(): array
    {
        $byKey = [];

        Product::query()
            ->where('type', 'configurable')
            ->whereNull('parent_id')
            ->orderBy('sku')
            ->each(function (Product $product) use (&$byKey): void {
                $key = $this->groupKey($product);

                if ($key !== null) {
                    $byKey[$key][] = $product;
                }
            });

        $groups = [];
        $ambiguous = [];

        foreach ($byKey as $members) {
            $members = collect($members);
            $vormen = $members->map(fn (Product $product): string => $this->vorm($product));

            if ($vormen->unique()->count() < 2) {
                continue;
            }

            if ($vormen->duplicates()->isNotEmpty()) {
                $ambiguous[] = $members;
            } else {
                $groups[] = $members;
            }
        }

        return ['groups' => $groups, 'ambiguous' => $ambiguous];
    }

    /**
     * De opgeslagen groep van een product (inclusief zichzelf), of een lege lijst.
     *
     * @return list<string>
     */
    public function current(Product $product): array
    {
        return self::listFrom($product->values);
    }

    /**
     * Een values-kolom als array, ook als hij (dubbel) als JSON-string is opgeslagen.
     *
     * @return array<string, mixed>
     */
    public static function decode(mixed $values): array
    {
        for ($depth = 0; is_string($values) && $depth < 5; $depth++) {
            $values = json_decode($values, true);
        }

        return is_array($values) ? $values : [];
    }

    /**
     * De groep uit een values-kolom: gesorteerd, zonder lege of dubbele SKU's.
     *
     * @return list<string>
     */
    public static function listFrom(mixed $values): array
    {
        $list = self::decode($values)[AbstractType::ASSOCIATION_VALUES_KEY][AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY] ?? [];

        return collect(is_array($list) ? $list : [])
            ->filter(fn (mixed $sku): bool => is_string($sku) && trim($sku) !== '')
            ->map(fn (string $sku): string => trim($sku))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * De andere vormen zoals de shop ze krijgt: de SKU's zonder het product
     * zelf, met komma's gescheiden, of een lege string.
     *
     * @param  array<string, mixed>  $item  Een product zoals Exporter::formatData() het in handen heeft
     */
    public static function shopValue(array $item): string
    {
        $skus = array_diff(self::listFrom($item['values'] ?? []), [(string) ($item['sku'] ?? '')]);

        return implode(',', array_values($skus));
    }

    /**
     * Legt de groep vast op alle leden die hem nog niet hebben.
     *
     * @param  Collection<int, Product>  $members
     * @return int Het aantal producten dat veranderde
     */
    public function connect(Collection $members): int
    {
        $skus = $members->pluck('sku')->map(fn ($sku): string => (string) $sku)->unique()->sort()->values()->all();

        // Every member gets the group here, so the mirror has nothing to add.
        return AndereVormenMirror::paused(function () use ($members, $skus): int {
            $changed = 0;

            foreach ($members as $product) {
                if ($this->current($product) !== $skus) {
                    $this->products->setAssociation($product, AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY, $skus);
                    $changed++;
                }
            }

            return $changed;
        });
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }
}
