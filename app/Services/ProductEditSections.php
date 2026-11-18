<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Webkul\Product\Models\Product;

/**
 * Splits the editable attributes of a product into the sections of the edit
 * screen (config/product_edit_sections.php) instead of the attribute
 * family's groups, which put nearly everything in one long "General" card.
 * A family can have its own layout (e.g. cushions) under `families`.
 */
class ProductEditSections
{
    /**
     * @return array{
     *     sections: list<array{key: string, label: string, attributes: Collection<int, mixed>}>,
     *     collapsed: ?array{key: string, label: string, attributes: Collection<int, mixed>}
     * }
     */
    public function for(Product $product): array
    {
        $remaining = collect();

        foreach ($product->attribute_family->familyGroups()->orderBy('position')->get() as $group) {
            $label = $group->name ?: "[{$group->code}]";

            foreach ($product->getEditableAttributes($group) as $attribute) {
                $remaining->put($attribute->code, ['attribute' => $attribute, 'group' => $label]);
            }
        }

        $layout = $this->layoutFor($product);
        $sections = [];

        foreach ((array) ($layout['sections'] ?? []) as $key => $section) {
            $attributes = $this->take($remaining, (array) $section['attributes']);

            if ($attributes->isNotEmpty()) {
                $sections[] = ['key' => (string) $key, 'label' => (string) $section['label'], 'attributes' => $attributes];
            }
        }

        $collapsedConfig = (array) ($layout['collapsed'] ?? []);
        $collapsedAttributes = $this->take($remaining, (array) ($collapsedConfig['attributes'] ?? []));

        foreach ($remaining->groupBy('group', preserveKeys: true) as $group => $fields) {
            $sections[] = [
                'key'        => 'groep-'.str($group)->slug(),
                'label'      => (string) $group,
                'attributes' => $fields->pluck('attribute')->values(),
            ];
        }

        return [
            'sections'  => $sections,
            'collapsed' => $collapsedAttributes->isEmpty() ? null : [
                'key'        => 'meer-velden',
                'label'      => (string) ($collapsedConfig['label'] ?? 'Meer velden'),
                'attributes' => $collapsedAttributes,
            ],
        ];
    }

    /**
     * The section layout for the product's family: its own entry under
     * `families` when there is one, the default (rug) layout otherwise.
     *
     * @return array{sections?: array<string, array{label: string, attributes: list<string>}>, collapsed?: array{label?: string, attributes?: list<string>}}
     */
    private function layoutFor(Product $product): array
    {
        $family = (string) $product->attribute_family?->code;

        return (array) (config("product_edit_sections.families.{$family}") ?? [
            'sections'  => config('product_edit_sections.sections', []),
            'collapsed' => config('product_edit_sections.collapsed', []),
        ]);
    }

    /**
     * Pull the listed attributes out of $remaining, in the listed order.
     *
     * @param  Collection<string, array{attribute: mixed, group: string}>  $remaining
     * @param  list<string>  $codes
     * @return Collection<int, mixed>
     */
    private function take(Collection $remaining, array $codes): Collection
    {
        $taken = collect();

        foreach ($codes as $code) {
            if ($remaining->has($code)) {
                $taken->push($remaining->pull($code)['attribute']);
            }
        }

        return $taken;
    }
}
