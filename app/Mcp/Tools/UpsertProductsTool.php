<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProductUpsertException;
use App\Services\Mcp\ProductUpsertService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('upsert-products')]
#[Description('Create or update up to 100 products in one call. An existing SKU is patched: only the attributes you send change, null clears one, categories are replaced when sent. A new SKU is created as a configurable parent (type + family + super_attributes), a variant (parent_sku + the variant axis values in values.common) or a simple product (type + family). A variant can move to another axis value (e.g. another maatgroep) when no sibling has that combination. values.associations.cross_sells replaces the cross-sell list of a parent (other parent SKUs, e.g. the other colours of the same design; products in the same batch may be referred to). values.associations.andere_vormen does the same for the same rug in its other shapes (e.g. the round version), kept mutual on every product in the group. Deleting is done with delete-products. Each item succeeds or fails on its own. Use dry_run first to see the exact before/after changes without saving. Saved changes are synced to WooCommerce and, for Bol-enabled products, to Bol.com.')]
#[IsIdempotent]
#[IsDestructive(false)]
class UpsertProductsTool extends Tool
{
    public function __construct(private ProductUpsertService $upsertService) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'items'   => ['required', 'array', 'min:1', 'max:'.ProductUpsertService::MAX_ITEMS],
            'items.*' => ['array'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->upsertService->upsert(
                array_values($validated['items']),
                $request->user(),
                (bool) ($validated['dry_run'] ?? false),
            );
        } catch (ProductUpsertException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($result);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $attributeMap = fn (string $description): Type => $schema->object()->description($description);

        return [
            'items' => $schema->array()->max(ProductUpsertService::MAX_ITEMS)->required()->items(
                $schema->object([
                    'sku'              => $schema->string()->required(),
                    'type'             => $schema->string()->enum(['simple', 'configurable'])->description('Only when creating a product without parent_sku.'),
                    'family'           => $schema->string()->description('Family code; only when creating a product without parent_sku.'),
                    'parent_sku'       => $schema->string()->description('Only when creating a variant: the SKU of its configurable parent (may be created earlier in the same batch).'),
                    'super_attributes' => $schema->array()->items($schema->string())->description('Only when creating a configurable product: its variant axes, e.g. ["onderkleed", "maatgroep", "afwerking_beschikbaar"].'),
                    'status'           => $schema->boolean()->description('Enabled or disabled.'),
                    'values'           => $schema->object([
                        'common'                  => $attributeMap('attribute_code => value, for attributes that are not locale or channel specific.'),
                        'locale_specific'         => $attributeMap('locale_code => {attribute_code => value}.'),
                        'channel_specific'        => $attributeMap('channel_code => {attribute_code => value}.'),
                        'channel_locale_specific' => $attributeMap('channel_code => {locale_code => {attribute_code => value}}.'),
                        'categories'              => $schema->array()->items($schema->string())->description('Category codes (see get-categories); replaces the current categories.'),
                        'associations'            => $schema->object([
                            'cross_sells' => $schema->array()->items($schema->string())->description('Parent SKUs; replaces the current list.'),
                            'andere_vormen' => $schema->array()->items($schema->string())->description('Parent SKUs of the same rug in its other shapes (rond, organic, ...); replaces the list and is kept mutual: every SKU in it gets the same group, a SKU taken out loses it. Empty list unlinks the product.'),
                        ])->description('Only on parent/standalone products.'),
                    ])->description('The attributes to set, grouped by the section get-family-attributes reports for each attribute.'),
                ])
            ),
            'dry_run' => $schema->boolean()->default(false)->description('Validate and report the changes without saving or syncing anything.'),
        ];
    }
}
