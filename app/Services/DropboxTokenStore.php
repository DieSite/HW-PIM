<?php

namespace App\Services;

use App\Models\DropboxConnection;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Keeps the Dropbox connection: the refresh token and account details in the
 * database ({@see DropboxConnection}), so neither a cache:clear nor a flush of
 * Redis disconnects Dropbox, and the short-lived access token in Redis
 * (expires a few minutes before Dropbox's own expiry). Losing the access token
 * only costs a refresh.
 */
class DropboxTokenStore
{
    /**
     * Seconds taken off Dropbox's expiry, so a token is never used in its
     * last minutes.
     */
    private const EXPIRY_MARGIN = 300;

    /**
     * @return array{refresh_token: string, account_name: ?string, account_email: ?string, connected_by: ?string, connected_at: ?string}|null
     */
    public function connection(): ?array
    {
        $connection = DropboxConnection::query()->latest('id')->first();

        if ($connection === null || blank($connection->refresh_token)) {
            return null;
        }

        return [
            'refresh_token' => $connection->refresh_token,
            'account_name'  => $connection->account_name,
            'account_email' => $connection->account_email,
            'connected_by'  => $connection->connected_by,
            'connected_at'  => $connection->created_at?->toIso8601String(),
        ];
    }

    public function refreshToken(): ?string
    {
        return $this->connection()['refresh_token'] ?? null;
    }

    /**
     * @param  array{account_name: ?string, account_email: ?string, connected_by: ?string}  $details
     */
    public function connect(string $refreshToken, string $accessToken, int $expiresIn, array $details): void
    {
        DB::transaction(function () use ($refreshToken, $details): void {
            DropboxConnection::query()->delete();
            DropboxConnection::query()->create([
                'refresh_token' => $refreshToken,
                'account_name'  => $details['account_name'] ?? null,
                'account_email' => $details['account_email'] ?? null,
                'connected_by'  => $details['connected_by'] ?? null,
            ]);
        });

        $this->putAccessToken($accessToken, $expiresIn);
    }

    public function accessToken(): ?string
    {
        $token = $this->redis()->get($this->key('access_token'));

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function putAccessToken(string $accessToken, int $expiresIn): void
    {
        $this->redis()->setex($this->key('access_token'), max(60, $expiresIn - self::EXPIRY_MARGIN), $accessToken);
    }

    public function forgetAccessToken(): void
    {
        $this->redis()->del([$this->key('access_token')]);
    }

    public function disconnect(): void
    {
        DropboxConnection::query()->delete();
        $this->forgetAccessToken();
    }

    private function key(string $name): string
    {
        return config('services.dropbox.redis_key_prefix').$name;
    }

    private function redis(): Connection
    {
        return Redis::connection(config('services.dropbox.redis_connection'));
    }
}
