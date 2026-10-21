<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProductUpsertException;
use App\Services\Mcp\ProductDeletionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete-products')]
#[Description(<<<'TXT'
    Delete up to 50 products or variants by SKU, also from the site: a deleted variant disappears from its product page, a deleted parent goes to the WooCommerce trash with its variations. This cannot be undone in the PIM. To take a product offline temporarily, set status false with upsert-products instead.

    A parent with variants is only deleted with delete_variants: true. Other products that list a deleted SKU as cross-sell lose that reference. A Bol.com offer is not ended. Always run with dry_run first and check the list (variants, cross_sold_by, warnings).
    TXT)]
#[IsDestructive]
class DeleteProductsTool extends Tool
{
    public function __construct(private ProductDeletionService $deletionService) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'skus'            => ['required', 'array', 'min:1', 'max:'.ProductDeletionService::MAX_ITEMS],
            'skus.*'          => ['string'],
            'delete_variants' => ['nullable', 'boolean'],
            'dry_run'         => ['nullable', 'boolean'],
        ]);

        try {
            return Response::json($this->deletionService->delete(
                $validated['skus'],
                $request->user(),
                (bool) ($validated['delete_variants'] ?? false),
                (bool) ($validated['dry_run'] ?? false),
            ));
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
            'skus'            => $schema->array()->items($schema->string())->max(ProductDeletionService::MAX_ITEMS)->required(),
            'delete_variants' => $schema->boolean()->default(false)->description('Required to delete a parent that has variants; they are deleted with it.'),
            'dry_run'         => $schema->boolean()->default(false)->description('Report what would be deleted without deleting anything.'),
        ];
    }
}
