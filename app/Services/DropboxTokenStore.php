<?php

namespace App\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Keeps the Dropbox connection in Redis: the refresh token and account
 * details (no expiry) and the short-lived access token (expires a few minutes
 * before Dropbox's own expiry). Redis rather than the cache store, so a
 * cache:clear on deploy never disconnects Dropbox.
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
        $fields = $this->redis()->hgetall($this->key('connection'));

        if (empty($fields['refresh_token'])) {
            return null;
        }

        return [
            'refresh_token' => $fields['refresh_token'],
            'account_name'  => $fields['account_name'] ?? null,
            'account_email' => $fields['account_email'] ?? null,
            'connected_by'  => $fields['connected_by'] ?? null,
            'connected_at'  => $fields['connected_at'] ?? null,
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
        $this->redis()->del([$this->key('connection')]);
        $this->redis()->hmset($this->key('connection'), array_map(fn (?string $value): string => (string) $value, [
            'refresh_token' => $refreshToken,
            ...$details,
            'connected_at'  => now()->toIso8601String(),
        ]));

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
        $this->redis()->del([$this->key('connection'), $this->key('access_token')]);
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
