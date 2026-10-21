<?php

namespace App\Mcp\Tools;

use App\Clients\DropboxClient;
use App\Exceptions\DropboxException;
use App\Mcp\AdminPermission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('browse-dropbox')]
#[Description('Find photos in the connected Dropbox. With "path": list that folder (omit for the top level). With "query": search file and folder names, optionally below "path". Lists folders and files with path, id, size and whether the file is a usable photo (JPEG, PNG, WebP). When has_more is true, call again with only "cursor" for the next page. Pass a file\'s path or id as "dropbox" to upload-product-image to bring it into the PIM; a dry run there shows a preview.')]
#[IsReadOnly]
class BrowseDropboxTool extends Tool
{
    public const MAX_LIMIT = 500;

    public function __construct(private DropboxClient $dropbox) {}

    public function handle(Request $request): Response
    {
        if (! AdminPermission::allows($request->user(), 'catalog.products.edit')) {
            return Response::error('Your admin role lacks the catalog.products.edit permission.');
        }

        $validated = $request->validate([
            'path'   => ['nullable', 'string', 'max:2000'],
            'query'  => ['nullable', 'string', 'max:200'],
            'cursor' => ['nullable', 'string'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);

        try {
            $page = match (true) {
                filled($validated['cursor'] ?? null) => $this->dropbox->continue($validated['cursor']),
                filled($validated['query'] ?? null)  => $this->dropbox->search($validated['query'], $validated['path'] ?? null, $limit),
                default                              => $this->dropbox->listFolder((string) ($validated['path'] ?? ''), $limit),
            };
        } catch (DropboxException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json($page);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path'   => $schema->string()->description('Folder path, e.g. "/Leveranciers/Desso". Omit for the top level.'),
            'query'  => $schema->string()->description('Part of a file or folder name to search for, e.g. "Trevis 601".'),
            'cursor' => $schema->string()->description('The cursor of the previous page, to continue a listing or search.'),
            'limit'  => $schema->integer()->max(self::MAX_LIMIT)->default(100)->description('Entries per page.'),
        ];
    }
}
