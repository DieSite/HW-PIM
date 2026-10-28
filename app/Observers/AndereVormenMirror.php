<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\AndereVormenLinker;
use App\Services\ProductService;
use Webkul\Product\Models\Product as BaseProduct;
use Webkul\Product\Type\AbstractType;

/**
 * Houdt `andere_vormen` wederzijds, waar de lijst ook vandaan komt: het
 * bewerkscherm, MCP, de API of een import.
 *
 * Wie aan Diamante 01 de ronde versie toevoegt, geeft Diamante 01 Rond ook
 * Diamante 01. Iedereen in de groep krijgt dezelfde lijst, inclusief
 * zichzelf. Wie een vorm weghaalt, haalt de groep ook bij die vorm weg.
 *
 * Een model-observer en geen listener op catalog.product.update.after:
 * alleen bij het opslaan is de oude lijst nog bekend, en alleen daaruit valt
 * af te leiden wie eruit is gehaald.
 */
class AndereVormenMirror
{
    /** @var array<int, list<string>> Oude lijst per product-id, tussen saving en saved. */
    private static array $before = [];

    private static bool $paused = false;

    /**
     * Voert $callback uit zonder te spiegelen: voor wie de hele groep zelf
     * al op elk lid zet.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function paused(callable $callback): mixed
    {
        $wasPaused = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $wasPaused;
        }
    }

    public function saving(BaseProduct $product): void
    {
        if (self::$paused) {
            return;
        }

        self::$before[spl_object_id($product)] = AndereVormenLinker::listFrom($product->getOriginal('values'));
    }

    public function saved(BaseProduct $product): void
    {
        $key = spl_object_id($product);
        $before = self::$before[$key] ?? [];
        unset(self::$before[$key]);

        if (self::$paused || $product->sku === null) {
            return;
        }

        $sku = (string) $product->sku;
        $after = AndereVormenLinker::listFrom($product->values);

        if ($after === $before) {
            return;
        }

        $group = $after === [] ? [] : collect($after)->push($sku)->unique()->sort()->values()->all();

        if (count($group) < 2) {
            $group = [];
        }

        self::paused(function () use ($product, $sku, $before, $group): void {
            // The product itself, quietly: whoever saved it fires its own
            // catalog.product.update.after with this same instance.
            if (AndereVormenLinker::listFrom($product->values) !== $group) {
                $values = AndereVormenLinker::decode($product->values);
                $values[AbstractType::ASSOCIATION_VALUES_KEY][AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY] = $group;
                $product->values = $values;
                $product->saveQuietly();
            }

            $others = array_values(array_diff(array_unique(array_merge($before, $group)), [$sku]));

            foreach (Product::query()->whereIn('sku', $others)->get() as $other) {
                $theirs = AndereVormenLinker::listFrom($other->values);

                if (in_array($other->sku, $group, true)) {
                    $wanted = $group;
                } else {
                    // Taken out of the group: it keeps whatever is left of
                    // its own list once the group is gone from it.
                    $left = array_values(array_diff($theirs, $group, [$sku]));
                    $wanted = count($left) < 2 ? [] : $left;
                }

                if ($theirs !== $wanted) {
                    app(ProductService::class)->setAssociation($other, AbstractType::ANDERE_VORMEN_ASSOCIATION_KEY, $wanted);
                }
            }
        });
    }
}
