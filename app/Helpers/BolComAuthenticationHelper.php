<?php

namespace App\Helpers;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BolComAuthenticationHelper
{
    protected const TOKEN_URL = 'https://login.bol.com/token';

    protected $client;

    protected $clientId;

    protected $clientSecret;

    protected $credentialsId;

    protected $skipCache = false;

    /**
     * @throws Exception
     */
    public function __construct($credentialsId = null, $skipCache = false)
    {
        $this->client = new Client;
        $this->credentialsId = $credentialsId;
        $this->skipCache = $skipCache;

        if ($credentialsId) {
            $this->loadCredentials($credentialsId);
        }
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
     * @throws GuzzleException
     */
    public function getAccessToken()
    {
        $cacheKey = 'bolcom_access_token';

        if (! $this->skipCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        return $this->requestNewAccessToken($cacheKey);
    }

    /**
     * @throws Exception|GuzzleException
     */
    protected function requestNewAccessToken($cacheKey)
    {
        $response = $this->client->post(self::TOKEN_URL, [
            'auth'    => [$this->clientId, $this->clientSecret],
            'headers' => [
                'Accept' => 'application/json',
            ],
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        if (isset($data['access_token'])) {
            Cache::put($cacheKey, $data['access_token'], $data['expires_in']);

            return $data['access_token'];
        }

        throw new Exception('Failed to retrieve access token from Bol.com API');
    }

    /**
     * @throws GuzzleException
     */
    public function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->getAccessToken(),
            'Accept'        => 'application/vnd.retailer.v10+json',
            'Content-Type'  => 'application/vnd.retailer.v10+json',
        ];
    }
}
