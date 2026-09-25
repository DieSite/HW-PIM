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

#[Name('get-family-attributes')]
#[Description('Without arguments: list the attribute families. With "family": its attributes (code, type, which values section they go in, required, writable, select options), the channels/locales and the attributes usable as variant axes. Add "attribute" to get every option of one select attribute.')]
#[IsReadOnly]
class GetFamilyAttributesTool extends Tool
{
    public function __construct(private ProductCatalogReader $reader) {}

    public function handle(Request $request): Response
    {
        if (! AdminPermission::allows($request->user(), 'catalog.products')) {
            return Response::error('Your admin role lacks the catalog.products permission.');
        }

        $validated = $request->validate([
            'family'    => ['nullable', 'string'],
            'attribute' => ['nullable', 'string'],
        ]);

        if (empty($validated['family'])) {
            return Response::json(['families' => $this->reader->families()]);
        }

        $attributes = $this->reader->familyAttributes($validated['family'], $validated['attribute'] ?? null);

        if ($attributes === null) {
            return Response::error("Unknown family \"{$validated['family']}\". Call this tool without arguments to list the families.");
        }

        return Response::json($attributes);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'family'    => $schema->string()->description('Family code, e.g. "hw". Omit to list the families.'),
            'attribute' => $schema->string()->description('One attribute code, to get all of its options instead of the first 100.'),
        ];
    }
}
