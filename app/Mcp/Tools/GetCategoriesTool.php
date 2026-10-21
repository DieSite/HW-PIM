<?php

namespace App\Mcp\Tools;

use App\Mcp\AdminPermission;
use App\Services\Mcp\CatalogStructureService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-categories')]
#[Description('List every category: code (what values.categories takes), name, parent, the number of parent products in it and whether it exists in WooCommerce. Brands, colours and shapes are categories too.')]
#[IsReadOnly]
class GetCategoriesTool extends Tool
{
    public function __construct(private CatalogStructureService $structure) {}

    public function handle(Request $request): Response
    {
        if (! AdminPermission::allows($request->user(), 'catalog.categories')) {
            return Response::error('Your admin role lacks the catalog.categories permission.');
        }

        return Response::json(['categories' => $this->structure->categories()]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
