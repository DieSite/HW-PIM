<?php

use Illuminate\Support\Str;
use Tests\Feature\Mcp\McpCatalogFixture;
use Webkul\AdminApi\Repositories\ClientRepository;

const MCP_REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

/**
 * @return array{client_id: string}
 */
function registerMcpClient(): array
{
    return test()->postJson('/oauth/register', [
        'client_name'   => 'Claude',
        'redirect_uris' => [MCP_REDIRECT],
    ])->assertCreated()->json();
}

/**
 * Runs the authorization-code + PKCE flow a Claude connector performs and
 * returns the access token.
 */
function mcpAccessToken(\Webkul\User\Models\Admin $admin): string
{
    $client = registerMcpClient();
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $consent = test()->actingAs($admin, 'admin')->get('/oauth/authorize?'.http_build_query([
        'client_id'             => $client['client_id'],
        'redirect_uri'          => MCP_REDIRECT,
        'response_type'         => 'code',
        'scope'                 => 'mcp:use',
        'state'                 => 'xyz',
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk()->assertSee('Toestaan');

    $redirect = test()->post('/oauth/authorize', [
        'state'     => 'xyz',
        'client_id' => $client['client_id'],
        'auth_token' => $consent->viewData('authToken'),
    ])->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    expect($redirect)->toStartWith(MCP_REDIRECT)->and($query['state'])->toBe('xyz');

    $token = test()->postJson('/oauth/token', [
        'grant_type'    => 'authorization_code',
        'client_id'     => $client['client_id'],
        'redirect_uri'  => MCP_REDIRECT,
        'code_verifier' => $verifier,
        'code'          => $query['code'],
    ])->assertOk();

    expect($token->json('expires_in'))->toBeGreaterThanOrEqual(86400 - 60)
        ->and($token->json('refresh_token'))->toBeString();

    return $token->json('access_token');
}

function mcpCall(string $token, string $method, array $params = []): \Illuminate\Testing\TestResponse
{
    return test()->withToken($token)->postJson('/mcp/products', [
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => $method,
        'params'  => (object) $params,
    ]);
}

beforeEach(function () {
    McpCatalogFixture::install();
});

it('publishes the OAuth discovery metadata', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp/products')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['mcp:use']);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('registration_endpoint', url('/oauth/register'))
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);
});

it('registers public clients for allowed redirect domains only', function () {
    $client = registerMcpClient();

    expect($client['token_endpoint_auth_method'])->toBe('none')
        ->and($client['redirect_uris'])->toBe([MCP_REDIRECT]);

    $this->postJson('/oauth/register', ['redirect_uris' => ['https://evil.example/callback']])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

it('challenges unauthenticated MCP requests, whatever they accept', function () {
    $this->postJson('/mcp/products', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');

    $this->post('/mcp/products', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'text/event-stream'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

it('sends a guest from the authorize screen through the MCP login and back', function () {
    $client = registerMcpClient();
    $authorizeUrl = '/oauth/authorize?'.http_build_query([
        'client_id'             => $client['client_id'],
        'redirect_uri'          => MCP_REDIRECT,
        'response_type'         => 'code',
        'scope'                 => 'mcp:use',
        'code_challenge'        => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]);

    $this->get($authorizeUrl)->assertRedirect(route('mcp.oauth.login'));

    $this->get(route('mcp.oauth.login'))->assertOk();

    $admin = McpCatalogFixture::admin();

    $location = $this->post(route('admin.session.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $returnedQuery);
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $expectedQuery);

    expect(parse_url($location, PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and($returnedQuery)->toEqualCanonicalizing($expectedQuery);
});

/**
 * HUIS-EN-WONEN-PIM-4T: with APP_DEBUG off, UnoPim's exception handler sent
 * every non-admin guest to the non-existent shop login route.
 */
it('sends guests to the MCP login and answers MCP with a 401 when debug is off', function () {
    config(['app.debug' => false]);
    $this->app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, fn ($app) => new \Webkul\Core\Exceptions\Handler($app));

    $client = registerMcpClient();

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id'             => $client['client_id'],
        'redirect_uri'          => MCP_REDIRECT,
        'response_type'         => 'code',
        'scope'                 => 'mcp:use',
        'code_challenge'        => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]))->assertRedirect(route('mcp.oauth.login'));

    $this->post('/mcp/products', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'text/event-stream'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');

    $this->get(route('admin.dashboard.index'))->assertRedirect(route('admin.session.create'));

    $token = mcpAccessToken(McpCatalogFixture::admin());

    mcpCall($token, 'tools/list')->assertOk()->assertSee('upsert-products');
});

it('issues a token through the authorization code flow and serves the tools with it', function () {
    McpCatalogFixture::rug('MCP-E2E', ['merk' => 'Eurogros']);

    $token = mcpAccessToken(McpCatalogFixture::admin());

    mcpCall($token, 'tools/list')
        ->assertOk()
        ->assertSee(['get-family-attributes', 'search-products', 'get-products', 'upsert-products']);

    mcpCall($token, 'tools/call', ['name' => 'get-products', 'arguments' => ['skus' => ['MCP-E2E']]])
        ->assertOk()
        ->assertSee('Eurogros');
});

it('rejects tokens that were not granted the mcp:use scope', function () {
    $admin = McpCatalogFixture::admin();
    $admin->forceFill(['password' => bcrypt('secret-pass')])->save();

    $client = app(ClientRepository::class)->createPasswordGrantClientForAdmin($admin->id, 'AdminApi', 'admins');

    $token = $this->postJson('/oauth/token', [
        'grant_type'    => 'password',
        'client_id'     => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'username'      => $admin->email,
        'password'      => 'secret-pass',
        'scope'         => '',
    ])->assertOk()->json('access_token');

    mcpCall($token, 'tools/list')->assertForbidden();
});
