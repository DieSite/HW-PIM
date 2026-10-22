<?php

use App\Clients\DropboxClient;
use App\Services\DropboxTokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Webkul\User\Models\Admin;

beforeEach(function () {
    config()->set('services.dropbox.app_key', 'key');
    config()->set('services.dropbox.app_secret', 'secret');
    config()->set('services.dropbox.redis_key_prefix', 'testing:dropbox:'.Str::random(8).':');

    $this->store = app(DropboxTokenStore::class);
    $this->admin = Admin::first();
});

afterEach(function () {
    app(DropboxTokenStore::class)->disconnect();
});

it('asks for the app credentials and shows the redirect URI to register', function () {
    config()->set('services.dropbox.app_key', null);

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.tools.dropbox.index'))
        ->assertOk()
        ->assertSee('DROPBOX_APP_KEY')
        ->assertSee(route('admin.tools.dropbox.callback'))
        ->assertDontSee('Koppel Dropbox');
});

it('sends the admin to the Dropbox consent page with a state kept in the session', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.tools.dropbox.index'))
        ->assertOk()
        ->assertSee('Koppel Dropbox');

    $response = $this->actingAs($this->admin, 'admin')->post(route('admin.tools.dropbox.connect'));

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($response->headers->get('Location'))->toStartWith('https://www.dropbox.com/oauth2/authorize?')
        ->and($query)->toMatchArray([
            'client_id'         => 'key',
            'response_type'     => 'code',
            'token_access_type' => 'offline',
            'redirect_uri'      => route('admin.tools.dropbox.callback'),
            'state'             => session('dropbox_oauth_state'),
        ]);
});

it('stores the tokens and account in Redis when Dropbox sends the admin back', function () {
    Http::fake([
        'api.dropboxapi.com/oauth2/token'                => Http::response(['access_token' => 'short-lived', 'refresh_token' => 'long-lived', 'expires_in' => 14400]),
        'api.dropboxapi.com/2/users/get_current_account' => Http::response([
            'name'      => ['display_name' => 'HW Inkoop'],
            'email'     => 'inkoop@example.com',
            'root_info' => ['.tag' => 'team', 'root_namespace_id' => '111', 'home_namespace_id' => '222'],
        ]),
    ]);

    $this->actingAs($this->admin, 'admin')
        ->withSession(['dropbox_oauth_state' => 'abc'])
        ->get(route('admin.tools.dropbox.callback', ['code' => 'the-code', 'state' => 'abc']))
        ->assertRedirect(route('admin.tools.dropbox.index'))
        ->assertSessionHas('success', 'Dropbox is gekoppeld met inkoop@example.com.');

    expect($this->store->connection())->toMatchArray([
        'refresh_token' => 'long-lived',
        'account_name'  => 'HW Inkoop',
        'account_email' => 'inkoop@example.com',
        'connected_by'  => $this->admin->email,
    ])->and($this->store->accessToken())->toBe('short-lived');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.dropboxapi.com/oauth2/token'
        && $request['grant_type'] === 'authorization_code' && $request['code'] === 'the-code'
        && $request['redirect_uri'] === route('admin.tools.dropbox.callback'));

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.tools.dropbox.index'))
        ->assertOk()
        ->assertSee('HW Inkoop')
        ->assertSee('Ontkoppelen');
});

it('lists the app folder without a path root header, also for a team account', function () {
    $this->store->connect('long-lived', 'short-lived', 14400, ['account_name' => 'HW Inkoop', 'account_email' => null, 'connected_by' => null]);

    Http::fake(['api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => [['.tag' => 'folder', 'name' => 'Brinker', 'path_display' => '/Brinker']], 'has_more' => false])]);

    expect(app(DropboxClient::class)->listFolder('', 100)['entries'])
        ->toBe([['type' => 'folder', 'name' => 'Brinker', 'path' => '/Brinker']]);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/files/list_folder')
        && $request['path'] === ''
        && ! $request->hasHeader('Dropbox-API-Path-Root'));
});

it('refuses a callback with a wrong state or a denied consent and stores nothing', function () {
    Http::fake();

    $this->actingAs($this->admin, 'admin')
        ->withSession(['dropbox_oauth_state' => 'abc'])
        ->get(route('admin.tools.dropbox.callback', ['code' => 'the-code', 'state' => 'forged']))
        ->assertSessionHas('error', 'De koppeling is verlopen of niet vanuit deze pagina gestart. Probeer het opnieuw.');

    $this->actingAs($this->admin, 'admin')
        ->withSession(['dropbox_oauth_state' => 'abc'])
        ->get(route('admin.tools.dropbox.callback', ['error' => 'access_denied', 'error_description' => 'The user chose not to give your app access', 'state' => 'abc']))
        ->assertSessionHas('error', 'Dropbox heeft geen toegang gegeven: The user chose not to give your app access');

    Http::assertNothingSent();

    expect($this->store->connection())->toBeNull();
});

it('shows the Dropbox error when the code is refused', function () {
    Http::fake(['api.dropboxapi.com/oauth2/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'code doesn\'t exist or has expired'], 400)]);

    $this->actingAs($this->admin, 'admin')
        ->withSession(['dropbox_oauth_state' => 'abc'])
        ->get(route('admin.tools.dropbox.callback', ['code' => 'old', 'state' => 'abc']))
        ->assertSessionHas('error', 'Dropbox refused the code: code doesn\'t exist or has expired');

    expect($this->store->connection())->toBeNull();
});

it('revokes the token at Dropbox and forgets the connection on disconnect', function () {
    $this->store->connect('long-lived', 'short-lived', 14400, ['account_name' => 'HW Inkoop', 'account_email' => null, 'connected_by' => null]);

    Http::fake(['api.dropboxapi.com/2/auth/token/revoke' => Http::response('null')]);

    $this->actingAs($this->admin, 'admin')
        ->post(route('admin.tools.dropbox.disconnect'))
        ->assertRedirect(route('admin.tools.dropbox.index'))
        ->assertSessionHas('success', 'Dropbox is ontkoppeld.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2/auth/token/revoke') && $request->header('Authorization')[0] === 'Bearer short-lived');

    expect($this->store->connection())->toBeNull()
        ->and($this->store->accessToken())->toBeNull();
});
