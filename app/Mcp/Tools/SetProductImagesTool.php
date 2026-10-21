<?php

namespace App\Mcp\Tools;

use App\Exceptions\ProductUpsertException;
use App\Mcp\Tools\Concerns\RespondsWithImages;
use App\Services\Mcp\ProductImageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('set-product-images')]
#[Description(<<<'TXT'
    Set the photos of up to 10 existing products, exactly like saving them in the admin. "images" is the complete ordered list of asset ids for afbeelding: the first is the main photo (hoofdafbeelding); leave an id out to unlink that photo, [] removes all photos. Omit "images" to keep the list and only re-edit the main photo.

    The main photo goes through the primary image editor, which makes two new assets: afbeelding (917x1094, white padding, cut to the rug shape, HW icon) and afbeelding_zonder_logo (the same without icon). This happens automatically when the main photo changes; pass primary_edit to adjust it (shape, scale, offset, rotation, toggles) or primary_edit: false to use the photo as it is. When the main photo is already the edited composite, primary_edit starts from the original photo and the saved settings. The other photos get the HW icon stamped on them on save.

    Always run with dry_run first: it saves nothing, reports the before/after lists (id, file name, URL) and returns previews of both composites. Look at the previews, adjust primary_edit if the rug is badly placed, then run again without dry_run. Saved products are synced to WooCommerce and, when enabled, Bol.com.
    TXT)]
#[IsDestructive(false)]
class SetProductImagesTool extends Tool
{
    use RespondsWithImages;

    public function __construct(private ProductImageService $imageService) {}

    /**
     * @return Response|list<Response>
     */
    public function handle(Request $request): Response|array
    {
        $validated = $request->validate([
            'items'    => ['required', 'array', 'min:1', 'max:'.ProductImageService::MAX_ITEMS],
            'items.*'  => ['array'],
            'dry_run'  => ['nullable', 'boolean'],
            'previews' => ['nullable', 'boolean'],
        ]);

        try {
            $outcome = $this->imageService->setImages(
                array_values($validated['items']),
                $request->user(),
                (bool) ($validated['dry_run'] ?? false),
                (bool) ($validated['previews'] ?? true),
            );
        } catch (ProductUpsertException $e) {
            return Response::error($e->getMessage());
        }

        return $this->withImages($outcome['result'], $outcome['previews']);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $shapes = array_keys(config('product_image_editor.shapes', []));

        return [
            'items' => $schema->array()->max(ProductImageService::MAX_ITEMS)->required()->items(
                $schema->object([
                    'sku'          => $schema->string()->required(),
                    'images'       => $schema->array()->items($schema->integer())->description('Ordered asset ids for afbeelding; the first is the main photo. Omit to keep the current list.'),
                    'primary_edit' => $schema->object([
                        'source_asset_id' => $schema->integer()->description('The original photo to composite. Defaults to the main photo (or, for the current composite, its recorded original).'),
                        'shape'           => $schema->string()->enum($shapes)->description('Rug shape; defaults to the saved shape or the product\'s "vorm".'),
                        'scale'           => $schema->number()->min(0.1)->max(10)->description('Zoom; 1 = the rug fills the frame.'),
                        'offset_x'        => $schema->integer()->description('Moves the rug right (negative: left) in frame pixels at scale 1, before rotation. Default 0 = centred.'),
                        'offset_y'        => $schema->integer()->description('Moves the rug down (negative: up) in frame pixels at scale 1, before rotation. Default 0 = centred.'),
                        'rotation'        => $schema->integer()->enum(ProductImageService::ROTATIONS)->description('Clockwise degrees.'),
                        'resize'          => $schema->boolean()->description('Resize to 917x1094 (default true).'),
                        'padding'         => $schema->boolean()->description('White padding and shape cut-out (default true).'),
                        'icon'            => $schema->boolean()->description('HW icon on afbeelding (default true).'),
                        'outline'         => $schema->boolean()->description('Dark outline around shaped rugs (default false).'),
                    ])->description('Primary image editor settings, or false to skip the editor.'),
                ])
            ),
            'dry_run'  => $schema->boolean()->default(false)->description('Report the changes and return previews without saving or syncing anything.'),
            'previews' => $schema->boolean()->default(true)->description('Return preview images of the main photo and afbeelding_zonder_logo.'),
        ];
    }
}
