<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProductUpsertException;
use App\Services\Mcp\CatalogStructureService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('create-categories')]
#[Description('Create up to 50 categories, e.g. a brand category "Brinker". Each is created in WooCommerce right away (an existing shop category with the same slug is reused), so products can be put in it with upsert-products. An existing code is reported as "exists" and left alone. Use dry_run first.')]
#[IsIdempotent]
#[IsDestructive(false)]
class CreateCategoriesTool extends Tool
{
    public function __construct(private CatalogStructureService $structure) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'categories'   => ['required', 'array', 'min:1', 'max:'.CatalogStructureService::MAX_ITEMS],
            'categories.*' => ['array'],
            'dry_run'      => ['nullable', 'boolean'],
        ]);

        try {
            return Response::json($this->structure->createCategories(array_values($validated['categories']), $request->user(), (bool) ($validated['dry_run'] ?? false)));
        } catch (ProductUpsertException $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'categories' => $schema->array()->max(CatalogStructureService::MAX_ITEMS)->required()->items($schema->object([
                'code'   => $schema->string()->required()->description('The category code, as used in values.categories, e.g. "Brinker". Existing codes are written like the shown name.'),
                'name'   => $schema->string()->description('Shop name; defaults to the code.'),
                'parent' => $schema->string()->description('Parent category code. The current categories are all top-level.'),
            ])),
            'dry_run' => $schema->boolean()->default(false)->description('Check without creating anything.'),
        ];
    }
}
