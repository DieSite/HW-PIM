<?php

namespace App\Helpers;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BolComAuthenticationHelper
{
    protected const TOKEN_URL = 'https://login.bol.com/token';

    protected ?string $clientId = null;

    protected ?string $clientSecret = null;

    protected $credentialsId;

    protected bool $skipCache;

    /**
     * @throws Exception
     */
    public function __construct($credentialsId = null, bool $skipCache = false)
    {
        $this->credentialsId = $credentialsId;
        $this->skipCache = $skipCache;

        if ($credentialsId) {
            $this->loadCredentials($credentialsId);
        }
    }

    /**
     * @throws Exception
     */
    public function getAccessToken(): string
    {
        $cacheKey = 'bolcom_access_token';

        if (! $this->skipCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        return $this->requestNewAccessToken($cacheKey);
    }

    /**
     * @throws Exception
     */
    public function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->getAccessToken(),
            'Accept'        => 'application/vnd.retailer.v10+json',
        ];
    }

    /**
     * @throws Exception
     */
    protected function loadCredentials($credentialsId): void
    {
        $credentials = DB::table('bol_com_credentials')
            ->where('id', $credentialsId)
            ->first();

        if (! $credentials) {
            throw new Exception('Credentials not found for ID: '.$credentialsId);
        }

        $this->clientId = $credentials->client_id;
        $this->clientSecret = $credentials->client_secret;
    }

    /**
     * @throws Exception
     */
    protected function requestNewAccessToken(string $cacheKey): string
    {
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->accept('application/json')
            ->asForm()
            ->post(self::TOKEN_URL, ['grant_type' => 'client_credentials']);

        $response->throw();

        $data = $response->json();

        if (isset($data['access_token'])) {
            Cache::put($cacheKey, $data['access_token'], $data['expires_in']);

            return $data['access_token'];
        }

        throw new Exception('Failed to retrieve access token from Bol.com API');
    }
}