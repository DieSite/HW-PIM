<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CompetitorPrice extends Model
{
    protected $fillable = [
        'sku',
        'product_id',
        'shop',
        'price',
        'url',
        'scraped_at',
    ];

    /**
     * Prices nobody took out of the analysis. An exclusion matches on sku,
     * shop and the exact amount, so a changed competitor price counts again.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotExcluded(Builder $query): void
    {
        $query->whereNotExists(fn ($exclusions) => $exclusions->selectRaw('1')
            ->from('competitor_price_exclusions')
            ->whereColumn('competitor_price_exclusions.sku', 'competitor_prices.sku')
            ->whereColumn('competitor_price_exclusions.shop', 'competitor_prices.shop')
            ->whereColumn('competitor_price_exclusions.price', 'competitor_prices.price'));
    }

    /**
     * Prices someone took out of the analysis, at their current amount.
     *
     * @param  Builder<self>  $query
     */
    public function scopeExcluded(Builder $query): void
    {
        $query->whereExists(fn ($exclusions) => $exclusions->selectRaw('1')
            ->from('competitor_price_exclusions')
            ->whereColumn('competitor_price_exclusions.sku', 'competitor_prices.sku')
            ->whereColumn('competitor_price_exclusions.shop', 'competitor_prices.shop')
            ->whereColumn('competitor_price_exclusions.price', 'competitor_prices.price'));
    }

    /**
     * The prices that set our selling price: real, recent enough
     * (`max_price_age_days`) and not excluded.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCounting(Builder $query): void
    {
        $query->where('price', '>', 0)
            ->where(fn (Builder $query) => $query
                ->whereNull('scraped_at')
                ->orWhere('scraped_at', '>=', now()->subDays(self::maxAgeDays())))
            ->notExcluded();
    }

    public static function maxAgeDays(): int
    {
        return (int) config('competitor_pricing.max_price_age_days', 14);
    }

    /**
     * Whether this price is too old to set our price.
     */
    public function isStale(): bool
    {
        return $this->scraped_at !== null && $this->scraped_at->lt(now()->subDays(self::maxAgeDays()));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price'      => 'decimal:2',
            'scraped_at' => 'datetime',
        ];
    }
}
