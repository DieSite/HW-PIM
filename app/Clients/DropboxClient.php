<?php

namespace App\Clients;

use App\Exceptions\DropboxException;
use App\Services\DropboxTokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Read-only Dropbox API client for fetching product photos.
 *
 * An admin connects the account under Tools → Dropbox (OAuth code flow); the
 * refresh token lives in the database and the access token in Redis, both
 * through DropboxTokenStore. The app has App folder access, so every path
 * resolves from its own folder (/Apps/<app name>/) and Dropbox-API-Path-Root
 * must never be sent.
 */
class DropboxClient
{
    public const API_URL = 'https://api.dropboxapi.com';

    public const CONTENT_URL = 'https://content.dropboxapi.com';

    public const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';

    public const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private DropboxTokenStore $tokens) {}

    public function hasAppCredentials(): bool
    {
        return filled(config('services.dropbox.app_key')) && filled(config('services.dropbox.app_secret'));
    }

    public function isConnected(): bool
    {
        return $this->hasAppCredentials() && $this->tokens->refreshToken() !== null;
    }

    /**
     * Whether the string is a Dropbox share link rather than a path or id.
     */
    public static function isSharedLink(string $reference): bool
    {
        $host = strtolower((string) parse_url($reference, PHP_URL_HOST));

        return in_array(strtolower((string) parse_url($reference, PHP_URL_SCHEME)), ['http', 'https'], true)
            && ($host === 'dropbox.com' || str_ends_with($host, '.dropbox.com'));
    }

    /**
     * @return array{entries: list<array<string, mixed>>, has_more: bool, cursor: ?string}
     */
    public function listFolder(string $path, int $limit): array
    {
        $response = $this->rpc('/2/files/list_folder', [
            'path'      => $this->normalisePath($path),
            'recursive' => false,
            'limit'     => $limit,
        ], $path);

        return $this->page($response['entries'], $response, 'list');
    }

    /**
     * Searches file and folder names, optionally below one folder.
     *
     * @return array{entries: list<array<string, mixed>>, has_more: bool, cursor: ?string}
     */
    public function search(string $query, ?string $path, int $limit): array
    {
        $options = array_filter([
            'path'          => $path !== null ? $this->normalisePath($path) : null,
            'max_results'   => $limit,
            'file_status'   => 'active',
            'filename_only' => true,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $response = $this->rpc('/2/files/search_v2', ['query' => $query, 'options' => $options], $path ?? $query);

        return $this->page(array_column(array_column($response['matches'] ?? [], 'metadata'), 'metadata'), $response, 'search');
    }

    /**
     * Continues a listing or search from the cursor of an earlier page.
     *
     * @return array{entries: list<array<string, mixed>>, has_more: bool, cursor: ?string}
     */
    public function continue(string $cursor): array
    {
        [$kind, $dropboxCursor] = array_pad(explode(':', $cursor, 2), 2, '');

        if ($kind === 'list') {
            $response = $this->rpc('/2/files/list_folder/continue', ['cursor' => $dropboxCursor], 'cursor');

            return $this->page($response['entries'], $response, 'list');
        }

        if ($kind === 'search') {
            $response = $this->rpc('/2/files/search/continue_v2', ['cursor' => $dropboxCursor], 'cursor');

            return $this->page(array_column(array_column($response['matches'] ?? [], 'metadata'), 'metadata'), $response, 'search');
        }

        throw new DropboxException('Unknown cursor; pass the cursor exactly as browse-dropbox returned it.');
    }

    /**
     * Downloads one file by path, file id ("id:...") or share link, after
     * checking its size so an oversized file is never transferred.
     *
     * @return array{contents: string, name: string, path: ?string, size: int}
     */
    public function download(string $reference, int $maxBytes): array
    {
        $isLink = self::isSharedLink($reference);

        $metadata = $isLink
            ? $this->rpc('/2/sharing/get_shared_link_metadata', ['url' => $reference], $reference)
            : $this->rpc('/2/files/get_metadata', ['path' => $this->normalisePath($reference)], $reference);

        if (($metadata['.tag'] ?? null) !== 'file') {
            throw new DropboxException("{$reference} is a folder, not a file. Use browse-dropbox to find the file inside it.");
        }

        if ((int) $metadata['size'] > $maxBytes) {
            throw new DropboxException("{$metadata['name']} is larger than ".($maxBytes / 1024 / 1024).' MB.');
        }

        $response = $this->send(
            fn (PendingRequest $request): Response => $request
                ->withHeaders(['Dropbox-API-Arg' => json_encode($isLink ? ['url' => $reference] : ['path' => $this->normalisePath($reference)])])
                ->withBody('', 'application/octet-stream')
                ->post(self::CONTENT_URL.($isLink ? '/2/sharing/get_shared_link_file' : '/2/files/download')),
            $reference,
        );

        return [
            'contents' => $response->body(),
            'name'     => $metadata['name'],
            'path'     => $metadata['path_display'] ?? null,
            'size'     => (int) $metadata['size'],
        ];
    }

    /**
     * The Dropbox consent page; Dropbox sends the admin back to $redirectUri
     * with a code for connect() and the given state.
     */
    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://www.dropbox.com/oauth2/authorize?'.http_build_query([
            'client_id'         => config('services.dropbox.app_key'),
            'response_type'     => 'code',
            'token_access_type' => 'offline',
            'redirect_uri'      => $redirectUri,
            'state'             => $state,
        ]);
    }

    /**
     * Exchanges the code from the consent page for tokens, reads the account
     * and stores the connection, replacing an earlier one.
     *
     * @return array{account_name: ?string, account_email: ?string, connected_by: ?string}
     */
    public function connect(string $code, string $redirectUri, ?string $connectedBy): array
    {
        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'client_id'     => config('services.dropbox.app_key'),
                'client_secret' => config('services.dropbox.app_secret'),
            ]);
        } catch (ConnectionException $e) {
            throw new DropboxException("Could not reach Dropbox: {$e->getMessage()}");
        }

        if (! $response->successful() || ! $response->json('refresh_token') || ! $response->json('access_token')) {
            throw new DropboxException('Dropbox refused the code: '.($response->json('error_description') ?? $response->body()));
        }

        $account = Http::timeout(30)->withToken($response->json('access_token'))
            ->withBody('null', 'application/json')
            ->post(self::API_URL.'/2/users/get_current_account');

        if (! $account->successful()) {
            throw new DropboxException('Dropbox did not return the account (HTTP '.$account->status().'); check that the app has the account_info.read permission.');
        }

        $details = [
            'account_name'  => $account->json('name.display_name'),
            'account_email' => $account->json('email'),
            'connected_by'  => $connectedBy,
        ];

        $this->tokens->connect($response->json('refresh_token'), $response->json('access_token'), (int) $response->json('expires_in', 14400), $details);

        return $details;
    }

    /**
     * Revokes the tokens at Dropbox (best effort) and forgets the connection.
     */
    public function disconnect(): void
    {
        if ($this->isConnected()) {
            try {
                Http::timeout(15)->withToken($this->accessToken())->withBody('null', 'application/json')->post(self::API_URL.'/2/auth/token/revoke');
            } catch (DropboxException|ConnectionException) {
            }
        }

        $this->tokens->disconnect();
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $response
     * @return array{entries: list<array<string, mixed>>, has_more: bool, cursor: ?string}
     */
    private function page(array $entries, array $response, string $kind): array
    {
        $hasMore = (bool) ($response['has_more'] ?? false);

        return [
            'entries'  => array_map($this->describe(...), $entries),
            'has_more' => $hasMore,
            'cursor'   => $hasMore && isset($response['cursor']) ? "{$kind}:{$response['cursor']}" : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function describe(array $entry): array
    {
        if (($entry['.tag'] ?? null) === 'folder') {
            return ['type' => 'folder', 'name' => $entry['name'], 'path' => $entry['path_display'] ?? null];
        }

        return [
            'type'     => 'file',
            'name'     => $entry['name'],
            'path'     => $entry['path_display'] ?? null,
            'id'       => $entry['id'] ?? null,
            'size'     => $entry['size'] ?? null,
            'modified' => $entry['server_modified'] ?? null,
            'is_photo' => in_array(strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)), self::PHOTO_EXTENSIONS, true),
        ];
    }

    /**
     * Dropbox addresses the root as "" and every other path with a leading
     * slash; ids ("id:...") pass unchanged.
     */
    private function normalisePath(string $path): string
    {
        $path = trim($path);

        if (str_starts_with($path, 'id:')) {
            return $path;
        }

        $path = '/'.trim($path, '/');

        return $path === '/' ? '' : $path;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function rpc(string $endpoint, array $body, string $subject): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->post(self::API_URL.$endpoint, $body), $subject)->json();
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, string $subject): Response
    {
        if (! $this->hasAppCredentials()) {
            throw new DropboxException('The Dropbox app is not configured: set DROPBOX_APP_KEY and DROPBOX_APP_SECRET.');
        }

        if (! $this->isConnected()) {
            throw new DropboxException('Dropbox is not connected: an admin connects it under Tools → Dropbox.');
        }

        $request = Http::timeout(60)->withToken($this->accessToken());

        try {
            $response = $call($request);
        } catch (ConnectionException $e) {
            throw new DropboxException("Could not reach Dropbox: {$e->getMessage()}");
        }

        if ($response->successful()) {
            return $response;
        }

        if ($response->status() === 401) {
            $this->tokens->forgetAccessToken();

            throw new DropboxException('Dropbox rejected the access token; try again, or reconnect under Tools → Dropbox.');
        }

        $summary = (string) ($response->json('error_summary') ?? $response->body());

        throw new DropboxException(match (true) {
            str_contains($summary, 'not_found')         => "{$subject} was not found in Dropbox.",
            str_contains($summary, 'shared_link_access_denied'),
            str_contains($summary, 'shared_link_is_directory'),
            str_contains($summary, 'shared_link_not_found') => "The share link {$subject} cannot be used: {$summary}",
            str_contains($summary, 'missing_scope')         => "The Dropbox app lacks a permission: {$summary}",
            default                                         => "Dropbox error for {$subject} (HTTP {$response->status()}): {$summary}",
        });
    }

    private function accessToken(): string
    {
        if (($token = $this->tokens->accessToken()) !== null) {
            return $token;
        }

        try {
            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->tokens->refreshToken(),
                'client_id'     => config('services.dropbox.app_key'),
                'client_secret' => config('services.dropbox.app_secret'),
            ]);
        } catch (ConnectionException $e) {
            throw new DropboxException("Could not reach Dropbox: {$e->getMessage()}");
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new DropboxException('Dropbox refused the refresh token: '.($response->json('error_description') ?? $response->body()).'. Reconnect under Tools → Dropbox.');
        }

        $this->tokens->putAccessToken($response->json('access_token'), (int) $response->json('expires_in', 14400));

        return $response->json('access_token');
    }
}
