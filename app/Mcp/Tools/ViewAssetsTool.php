<?php

namespace App\Mcp\Tools;

use App\Mcp\AdminPermission;
use App\Mcp\Tools\Concerns\RespondsWithImages;
use App\Services\Mcp\AssetDescriber;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Webkul\DAM\Models\Asset;

#[Name('view-assets')]
#[Description('Look at up to 10 DAM photos by asset id: file name, URL, size, pixel dimensions and a preview image of each. Use it to check a product\'s photos or to compare suspected duplicates.')]
#[IsReadOnly]
class ViewAssetsTool extends Tool
{
    use RespondsWithImages;

    public const MAX_ASSETS = 10;

    public function __construct(private AssetDescriber $describer) {}

    /**
     * @return Response|list<Response>
     */
    public function handle(Request $request): Response|array
    {
        if (! AdminPermission::allows($request->user(), 'catalog.products')) {
            return Response::error('Your admin role lacks the catalog.products permission.');
        }

        $validated = $request->validate([
            'asset_ids'   => ['required', 'array', 'min:1', 'max:'.self::MAX_ASSETS],
            'asset_ids.*' => ['integer'],
        ]);

        $ids = array_map('intval', $validated['asset_ids']);
        $described = $this->describer->describe($ids);
        $assets = Asset::query()->whereIn('id', $ids)->get()->keyBy('id');
        $previews = [];

        foreach ($described as $id => &$asset) {
            $contents = $assets->has($id) ? $this->describer->contents($assets->get($id)) : null;

            if ($contents === null) {
                $asset['missing'] = true;

                continue;
            }

            if ($size = @getimagesizefromstring($contents)) {
                $asset['width'] = $size[0];
                $asset['height'] = $size[1];
            }

            if ($preview = $this->describer->preview($contents)) {
                $previews[] = ['label' => "asset {$id} ({$asset['file_name']})", 'image' => $preview];
            }
        }

        unset($asset);

        return $this->withImages(['assets' => array_values($described)], $previews);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_ids' => $schema->array()->items($schema->integer())->max(self::MAX_ASSETS)->required(),
        ];
    }
}
