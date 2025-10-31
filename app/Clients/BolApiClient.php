<?php

namespace App\Clients;

use App\Helpers\BolComAuthenticationHelper;
use App\Models\BolComCredential;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sentry\State\Scope;

class BolApiClient
{
    /**
     * Base API URL
     */
    protected $baseUrl;

    /**
     * The HTTP client instance
     */
    protected $client;

    /**
     * Authentication helper
     */
    protected $authHelper;

    /**
     * Constructor
     *
     * @throws Exception
     */
    public function __construct($credentialIdOrObject = null, $skipCache = false)
    {
        $this->client = new Client();
        $this->baseUrl = config('bolcom.api_url');

        if ($credentialIdOrObject instanceof BolComCredential) {
            $this->setCredential($credentialIdOrObject);
        } elseif ($credentialIdOrObject) {
            $this->authHelper = new BolComAuthenticationHelper($credentialIdOrObject, $skipCache);
        } else {
            $this->authHelper = null;
        }
    }

    /**
     * @throws Exception
     */
    public function setCredential(BolComCredential $credential)
    {
        $this->authHelper = new BolComAuthenticationHelper($credential->id, false);

        return $this;
    }

    /**
     * Make a GET request to the Bol.com API
     *
     * @throws Exception|GuzzleException
     */
    public function get($endpoint, $params = [])
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    /**
     * Make a POST request to the Bol.com API
     *
     * @throws Exception|GuzzleException
     */
    public function post($endpoint, $data = [])
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    /**
     * Make a PUT request to the Bol.com API
     *
     * @throws Exception|GuzzleException
     */
    public function put($endpoint, $data = [])
    {
        return $this->request('PUT', $endpoint, ['json' => $data]);
    }

    /**
     * Make a DELETE request to the Bol.com API
     *
     * @throws GuzzleException
     */
    public function delete($endpoint)
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Make an HTTP request to the Bol.com API
     *
     * @throws Exception|GuzzleException
     */
    protected function request($method, $endpoint, $options = [])
    {
        \Sentry\configureScope(function (Scope $scope) use ($method, $endpoint, $options) {
            $scope->setContext('bolcom_request', [
                'method'  => $method,
                'url'     => $this->baseUrl.$endpoint,
                'options' => $options,
            ]);
        });

        try {
            $headers = $this->authHelper->getAuthHeaders();
            $headers['Content-Type'] = 'application/vnd.retailer.v10+json';

            $response = $this->client->request($method, $this->baseUrl.$endpoint, array_merge([
                'headers' => $headers,
            ], $options));

            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            throw new Exception('Bol.com API error', previous: $e);
        }
    }
}
