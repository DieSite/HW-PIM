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

#[Name('get-products')]
#[Description('Get the full stored values of up to 100 products by SKU, plus family, parent, Bol/sync status and, for configurable products, their variant axes and variants.')]
#[IsReadOnly]
class GetProductsTool extends Tool
{
    public function __construct(private ProductCatalogReader $reader) {}

    public function handle(Request $request): Response
    {
        if (! AdminPermission::allows($request->user(), 'catalog.products')) {
            return Response::error('Your admin role lacks the catalog.products permission.');
        }

        $validated = $request->validate([
            'skus'             => ['required', 'array', 'min:1', 'max:'.ProductCatalogReader::MAX_SKUS],
            'skus.*'           => ['string'],
            'include_variants' => ['nullable', 'boolean'],
        ]);

        return Response::json($this->reader->get($validated['skus'], $validated['include_variants'] ?? true));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'skus'             => $schema->array()->items($schema->string())->max(ProductCatalogReader::MAX_SKUS)->required(),
            'include_variants' => $schema->boolean()->default(true)->description('List the variants (SKU + axis values) of configurable products.'),
        ];
    }
}
