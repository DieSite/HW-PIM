<?php

namespace App\Mcp\Tools;

use App\Mcp\AdminPermission;
use App\Services\Mcp\ProductCatalogReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-products')]
#[Description('Find products by SKU list, SKU prefix, parent SKU, family, type or an exact common attribute value (e.g. merk = "Eurogros"). Returns a page of product summaries; use get-products for the full values.')]
#[IsReadOnly]
class SearchProductsTool extends Tool
{
    public function __construct(private ProductCatalogReader $reader) {}

    public function handle(Request $request): Response
    {
        if (! AdminPermission::allows($request->user(), 'catalog.products')) {
            return Response::error('Your admin role lacks the catalog.products permission.');
        }

        $filters = $request->validate([
            'skus'            => ['nullable', 'array', 'max:'.ProductCatalogReader::MAX_PAGE_SIZE],
            'skus.*'          => ['string'],
            'sku_prefix'      => ['nullable', 'string', 'min:2'],
            'parent_sku'      => ['nullable', 'string'],
            'family'          => ['nullable', 'string'],
            'type'            => ['nullable', 'in:simple,configurable'],
            'attribute'       => ['nullable', 'array'],
            'attribute.code'  => ['required_with:attribute', 'string', 'regex:/^[a-z0-9_]+$/'],
            'attribute.value' => ['required_with:attribute', 'string'],
            'limit'           => ['nullable', 'integer', 'min:1', 'max:'.ProductCatalogReader::MAX_PAGE_SIZE],
            'page'            => ['nullable', 'integer', 'min:1'],
        ]);

        return Response::json($this->reader->search($filters));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'skus'       => $schema->array()->items($schema->string())->description('Exact SKUs to look up.'),
            'sku_prefix' => $schema->string()->description('SKU prefix, e.g. "ERG96" (at least 2 characters).'),
            'parent_sku' => $schema->string()->description('Only the variants of this configurable product.'),
            'family'     => $schema->string()->description('Attribute family code, e.g. "hw".'),
            'type'       => $schema->string()->enum(['simple', 'configurable'])->description('Product type. Variants are "simple" products with a parent.'),
            'attribute'  => $schema->object([
                'code'  => $schema->string()->required(),
                'value' => $schema->string()->required(),
            ])->description('Exact match on a common attribute value, e.g. {"code": "merk", "value": "Eurogros"}.'),
            'limit' => $schema->integer()->min(1)->max(ProductCatalogReader::MAX_PAGE_SIZE)->default(50),
            'page'  => $schema->integer()->min(1)->default(1),
        ];
    }
}
