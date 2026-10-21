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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('upload-product-image')]
#[Description('Upload one photo (JPEG, PNG or WebP, max 25 MB) into the DAM from Dropbox, a public URL or base64 content and get its asset id, file name, URL and a preview. A photo whose exact bytes are already in the DAM is not stored twice: the existing asset is returned ("action": "reused"). Uploading does not change any product; link the asset with set-product-images. dry_run checks the file and shows the preview without storing it.')]
#[IsIdempotent]
#[IsDestructive(false)]
class UploadProductImageTool extends Tool
{
    use RespondsWithImages;

    public function __construct(private ProductImageService $imageService) {}

    /**
     * @return Response|list<Response>
     */
    public function handle(Request $request): Response|array
    {
        $validated = $request->validate([
            'url'            => ['nullable', 'string', 'url'],
            'content_base64' => ['nullable', 'string'],
            'dropbox'        => ['nullable', 'string', 'max:2000'],
            'file_name'      => ['nullable', 'string', 'max:200'],
            'dry_run'        => ['nullable', 'boolean'],
        ]);

        try {
            $upload = $this->imageService->upload($validated, $request->user(), (bool) ($validated['dry_run'] ?? false));
        } catch (ProductUpsertException $e) {
            return Response::error($e->getMessage());
        }

        $previews = $upload['preview'] !== null ? [['label' => $upload['result']['asset']['file_name'] ?? 'upload', 'image' => $upload['preview']]] : [];

        return $this->withImages($upload['result'], $previews);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url'            => $schema->string()->description('Public http(s) URL of the photo.'),
            'content_base64' => $schema->string()->description('The photo file as base64 (a data: URI prefix is allowed). Use instead of url.'),
            'dropbox'        => $schema->string()->description('A file in the connected Dropbox: its path (e.g. "/Leveranciers/Desso/Trevis 01.jpg") or id ("id:...") as browse-dropbox lists it, or a Dropbox share link. Use instead of url.'),
            'file_name'      => $schema->string()->description('Descriptive file name, e.g. "HW Trevis 01 beige 900.jpg"; it is made URL-safe and gets a random suffix. Defaults to the source\'s file name.'),
            'dry_run'        => $schema->boolean()->default(false)->description('Check the photo and return the preview without storing it.'),
        ];
    }
}
