<?php

use App\Mcp\Servers\ProductsServer;
use App\Mcp\Tools\BrowseDropboxTool;
use App\Mcp\Tools\UploadProductImageTool;
use App\Services\DropboxTokenStore;
use App\Services\Mcp\ProductImageService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Tests\Feature\Mcp\McpCatalogFixture;
use Webkul\DAM\Models\Asset;

function dropboxPhotoBytes(): string
{
    return (string) app(ImageManager::class)->createImage(640, 480)->fill('336699')->encodeUsingFormat(Format::JPEG);
}

/**
 * A connected Dropbox in Redis under a key prefix of this test only; a team
 * account resolves paths from root namespace 111.
 */
function connectDropbox(?string $rootNamespaceId = '111'): DropboxTokenStore
{
    $store = app(DropboxTokenStore::class);
    $store->connect('refresh', 'short-lived', 14400, [
        'account_name'      => 'HW Inkoop',
        'account_email'     => 'inkoop@example.com',
        'root_namespace_id' => $rootNamespaceId,
        'connected_by'      => 'admin@example.com',
    ]);

    return $store;
}

beforeEach(function () {
    Storage::fake('private');
    McpCatalogFixture::install();

    config()->set('services.dropbox.app_key', 'key');
    config()->set('services.dropbox.app_secret', 'secret');
    config()->set('services.dropbox.redis_key_prefix', 'testing:dropbox:'.Str::random(8).':');

    connectDropbox();

    $this->admin = McpCatalogFixture::admin();
});

afterEach(function () {
    app(DropboxTokenStore::class)->disconnect();
});

it('uploads a photo by Dropbox path from the team root under its Dropbox name', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(['.tag' => 'file', 'name' => 'Trevis 601 sfeer.jpg', 'path_display' => '/Leveranciers/Eurogros/Trevis 601 sfeer.jpg', 'size' => 2048]),
        'content.dropboxapi.com/2/files/download' => Http::response(dropboxPhotoBytes()),
    ]);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(UploadProductImageTool::class, ['dropbox' => 'Leveranciers/Eurogros/Trevis 601 sfeer.jpg'])
        ->assertOk()
        ->assertSee(['"action":"created"', '"file_name":"trevis-601-sfeer-', '"width":640']);

    expect(Asset::query()->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://content.dropboxapi.com/2/files/download'
        && json_decode($request->header('Dropbox-API-Arg')[0], true) === ['path' => '/Leveranciers/Eurogros/Trevis 601 sfeer.jpg']
        && json_decode($request->header('Dropbox-API-Path-Root')[0], true) === ['.tag' => 'root', 'root' => '111']
        && $request->header('Authorization')[0] === 'Bearer short-lived');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2/token'));
});

it('refreshes an expired access token with the stored refresh token and keeps the new one in Redis', function () {
    $store = app(DropboxTokenStore::class);
    $store->forgetAccessToken();

    Http::fake([
        'api.dropboxapi.com/oauth2/token'        => Http::response(['access_token' => 'fresh', 'expires_in' => 14400]),
        'api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => [], 'has_more' => false]),
    ]);

    ProductsServer::actingAs($this->admin, 'api')->tool(BrowseDropboxTool::class, [])->assertOk();
    ProductsServer::actingAs($this->admin, 'api')->tool(BrowseDropboxTool::class, [])->assertOk();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.dropboxapi.com/oauth2/token'
        && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/list_folder') && $request->header('Authorization')[0] === 'Bearer fresh');

    expect($store->accessToken())->toBe('fresh');
});

it('downloads a Dropbox share link through the API, also when it is given as url', function () {
    $link = 'https://www.dropbox.com/scl/fi/abc123/Trevis%20601.jpg?rlkey=xyz&dl=0';

    connectDropbox(rootNamespaceId: null);

    Http::fake([
        'api.dropboxapi.com/2/sharing/get_shared_link_metadata' => Http::response(['.tag' => 'file', 'name' => 'Trevis 601.jpg', 'size' => 2048]),
        'content.dropboxapi.com/2/sharing/get_shared_link_file' => Http::response(dropboxPhotoBytes()),
    ]);

    $upload = app(ProductImageService::class)->upload(['url' => $link], $this->admin, true);

    expect($upload['result'])->action->toBe('would_create')
        ->and($upload['result']['asset']['file_name'])->toStartWith('trevis-601-')
        ->and(Asset::query()->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/sharing/get_shared_link_file')
        && json_decode($request->header('Dropbox-API-Arg')[0], true) === ['url' => $link]
        && ! $request->hasHeader('Dropbox-API-Path-Root'));
});

it('refuses folders, oversized files, missing files and an unconnected Dropbox', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/get_metadata' => Http::sequence()
            ->push(['.tag' => 'folder', 'name' => 'Eurogros'])
            ->push(['.tag' => 'file', 'name' => 'huge.jpg', 'size' => ProductImageService::MAX_UPLOAD_BYTES + 1])
            ->push(['error_summary' => 'path/not_found/..'], 409),
    ]);

    $upload = fn (string $reference) => ProductsServer::actingAs($this->admin, 'api')
        ->tool(UploadProductImageTool::class, ['dropbox' => $reference]);

    $upload('/Leveranciers/Eurogros')->assertHasErrors(['is a folder, not a file']);
    $upload('/huge.jpg')->assertHasErrors(['huge.jpg is larger than 25 MB']);
    $upload('/missing.jpg')->assertHasErrors(['/missing.jpg was not found in Dropbox']);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'content.dropboxapi.com'));

    app(DropboxTokenStore::class)->disconnect();

    $upload('/x.jpg')->assertHasErrors(['Dropbox is not connected: an admin connects it under Tools → Dropbox']);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(UploadProductImageTool::class, ['url' => 'https://93.184.215.14/rug.jpg', 'dropbox' => '/x.jpg'])
        ->assertHasErrors(['Give exactly one of "url", "content_base64" or "dropbox"']);
});

it('lists a folder, searches names and continues from a cursor', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/list_folder/continue' => Http::response(['entries' => [
            ['.tag' => 'file', 'name' => 'Trevis 602.png', 'path_display' => '/Leveranciers/Trevis 602.png', 'id' => 'id:b', 'size' => 10, 'server_modified' => '2026-10-01T10:00:00Z'],
        ], 'has_more' => false]),
        'api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => [
            ['.tag' => 'folder', 'name' => 'Eurogros', 'path_display' => '/Leveranciers/Eurogros'],
            ['.tag' => 'file', 'name' => 'prijslijst.pdf', 'path_display' => '/Leveranciers/prijslijst.pdf', 'id' => 'id:a', 'size' => 10, 'server_modified' => '2026-10-01T10:00:00Z'],
        ], 'has_more' => true, 'cursor' => 'C1']),
        'api.dropboxapi.com/2/files/search_v2' => Http::response(['matches' => [
            ['metadata' => ['.tag' => 'metadata', 'metadata' => ['.tag' => 'file', 'name' => 'Trevis 601.JPG', 'path_display' => '/Leveranciers/Eurogros/Trevis 601.JPG', 'id' => 'id:c', 'size' => 10]]],
        ], 'has_more' => false]),
    ]);

    $browse = fn (array $arguments) => ProductsServer::actingAs($this->admin, 'api')->tool(BrowseDropboxTool::class, $arguments);

    $browse(['path' => '/Leveranciers/'])
        ->assertOk()
        ->assertSee(['"type":"folder"', '"path":"/Leveranciers/Eurogros"', '"is_photo":false', '"has_more":true', '"cursor":"list:C1"']);

    $browse(['cursor' => 'list:C1'])->assertOk()->assertSee(['"name":"Trevis 602.png"', '"is_photo":true', '"cursor":null']);

    $browse(['query' => 'Trevis 601', 'path' => '/Leveranciers'])->assertOk()->assertSee(['"id":"id:c"', '"is_photo":true']);

    $browse(['cursor' => 'bogus'])->assertHasErrors(['Unknown cursor']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/files/list_folder') && $request['path'] === '/Leveranciers');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/files/list_folder/continue') && $request['cursor'] === 'C1');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/files/search_v2')
        && $request['query'] === 'Trevis 601' && $request['options']['path'] === '/Leveranciers' && $request['options']['filename_only'] === true);
});

it('asks to reconnect when Dropbox refuses the refresh token', function () {
    app(DropboxTokenStore::class)->forgetAccessToken();

    Http::fake(['api.dropboxapi.com/oauth2/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'refresh token is malformed'], 400)]);

    ProductsServer::actingAs($this->admin, 'api')
        ->tool(BrowseDropboxTool::class, [])
        ->assertHasErrors(['Dropbox refused the refresh token: refresh token is malformed']);
});
