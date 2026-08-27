<?php

namespace App\Services\AI;

use App\Models\AiDescriptionDraft;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Supplies the texts a new description has to differ from.
 *
 * "Sibling" means the same brand and collection, because that is where the spec
 * sheets are genuinely identical — 393 products in De Munk "Endless" share only
 * 165 distinct colour/shape/pattern combinations. Already generated drafts count
 * too, otherwise a bulk run drifts back towards one text per collection.
 */
class SiblingTextRepository
{
    /**
     * Cached per collection key within one request or job, so a batch that
     * walks a collection does not re-query for every product.
     *
     * @var array<string, list<string>>
     */
    private array $cache = [];

    /**
     * Long descriptions of siblings, newest generated drafts first.
     *
     * @return list<string>
     */
    public function fullTexts(Product $product, int $limit = 6): array
    {
        $key = $this->collectionKey($product);

        if ($key === null) {
            return [];
        }

        if (! isset($this->cache[$key])) {
            $this->cache[$key] = $this->query($product, $key, $limit);
        }

        return $this->cache[$key];
    }

    /**
     * The opening sentence of each sibling text, which is what the model is
     * told not to reuse. Full texts would eat context without adding signal.
     *
     * @return Collection<int, string>
     */
    public function openings(Product $product): Collection
    {
        $limit = (int) config('ai.sibling_examples', 3);

        return collect($this->fullTexts($product))
            ->map($this->firstSentence(...))
            ->filter()
            ->unique()
            ->take($limit)
            ->values();
    }

    /**
     * Drop the cache between products when a long-running job would otherwise
     * keep stale drafts around.
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return list<string>
     */
    private function query(Product $product, string $key, int $limit): array
    {
        [$merk, $collectie] = explode('|', $key, 2);

        $drafts = AiDescriptionDraft::query()
            ->whereIn('status', ['pending', 'approved', 'applied'])
            ->where('product_id', '!=', $product->id)
            ->whereHas('product', function ($query) use ($merk, $collectie) {
                $query->where('values->common->merk', $merk)
                    ->where('values->common->collectie', $collectie);
            })
            ->latest('id')
            ->limit($limit)
            ->pluck('fields')
            ->map(fn ($fields) => is_array($fields) ? ($fields['beschrijving_l'] ?? null) : null)
            ->filter()
            ->values()
            ->all();

        if (count($drafts) >= $limit) {
            return $drafts;
        }

        $existing = Product::query()
            ->where('type', 'configurable')
            ->where('id', '!=', $product->id)
            ->where('values->common->merk', $merk)
            ->where('values->common->collectie', $collectie)
            ->whereRaw("`values`->>'$.common.beschrijving_l' IS NOT NULL")
            ->limit($limit - count($drafts))
            ->pluck('values')
            ->map(function ($values) {
                if (is_string($values)) {
                    $values = json_decode($values, true);
                }

                return is_array($values) ? ($values['common']['beschrijving_l'] ?? null) : null;
            })
            ->filter()
            ->values()
            ->all();

        return [...$drafts, ...$existing];
    }

    private function firstSentence(string $html): ?string
    {
        $plain = trim(html_entity_decode(strip_tags($html)));

        if ($plain === '') {
            return null;
        }

        if (preg_match('/^.{20,240}?[.!?](\s|$)/u', $plain, $match)) {
            return trim($match[0]);
        }

        return mb_substr($plain, 0, 160);
    }

    private function collectionKey(Product $product): ?string
    {
        $values = $product->values;

        if (is_string($values)) {
            $values = json_decode($values, true);
        }

        $common = is_array($values) ? ($values['common'] ?? []) : [];

        $merk = $common['merk'] ?? null;
        $collectie = $common['collectie'] ?? null;

        if (! is_string($merk) || ! is_string($collectie) || $merk === '' || $collectie === '') {
            return null;
        }

        return "{$merk}|{$collectie}";
    }
}
