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

#[Name('add-attribute-options')]
#[Description('Add options to a select attribute, e.g. a new maatgroep "80 cm x 250 cm" a supplier has. Follow the spelling of the existing options (get-family-attributes with "attribute"). An existing code is reported as "exists". WooCommerce needs no separate step: the shop creates the term when the first product using it is synced. Use dry_run first.')]
#[IsIdempotent]
#[IsDestructive(false)]
class AddAttributeOptionsTool extends Tool
{
    public function __construct(private CatalogStructureService $structure) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'attribute' => ['required', 'string'],
            'options'   => ['required', 'array', 'min:1', 'max:'.CatalogStructureService::MAX_ITEMS],
            'options.*' => ['array'],
            'dry_run'   => ['nullable', 'boolean'],
        ]);

        try {
            return Response::json($this->structure->addOptions($validated['attribute'], array_values($validated['options']), $request->user(), (bool) ($validated['dry_run'] ?? false)));
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
            'attribute' => $schema->string()->required()->description('Attribute code, e.g. "maatgroep".'),
            'options'   => $schema->array()->max(CatalogStructureService::MAX_ITEMS)->required()->items($schema->object([
                'code'  => $schema->string()->required()->description('The value products store, e.g. "80 cm x 250 cm".'),
                'label' => $schema->string()->description('Admin label; defaults to the code.'),
                'after' => $schema->string()->description('Place it right after this existing option; default is at the end.'),
            ])),
            'dry_run' => $schema->boolean()->default(false)->description('Check without saving.'),
        ];
    }
}
