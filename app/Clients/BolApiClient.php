<?php

namespace App\Clients;

use App\Helpers\BolComAuthenticationHelper;
use App\Models\BolComCredential;
use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Sentry\State\Scope;

class BolApiClient
{
    protected string $baseUrl;

    protected ?BolComAuthenticationHelper $authHelper;

    public function __construct($credentialIdOrObject = null, bool $skipCache = false)
    {
        $this->baseUrl = config('bolcom.api_url');

        if ($credentialIdOrObject instanceof BolComCredential) {
            $this->setCredential($credentialIdOrObject);
        } elseif ($credentialIdOrObject) {
            $this->authHelper = new BolComAuthenticationHelper($credentialIdOrObject, $skipCache);
        } else {
            $this->authHelper = null;
        }
    }

    public function setCredential(BolComCredential $credential): static
    {
        $this->authHelper = new BolComAuthenticationHelper($credential->id, false);

        return $this;
    }

    public function get(string $endpoint, array $params = []): ?array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    public function post(string $endpoint, array $data = []): ?array
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    public function put(string $endpoint, array $data = []): ?array
    {
        return $this->request('PUT', $endpoint, ['json' => $data]);
    }

    public function delete(string $endpoint): ?array
    {
        return $this->request('DELETE', $endpoint);
    }

    protected function request(string $method, string $endpoint, array $options = []): ?array
    {
        $url = $this->baseUrl.$endpoint;

        \Sentry\configureScope(function (Scope $scope) use ($method, $url, $options) {
            $scope->setContext('bolcom_request', [
                'method'  => $method,
                'url'     => $url,
                'options' => $options,
            ]);
        });

        try {
            $headers = $this->authHelper->getAuthHeaders();

            $http = Http::withHeaders($headers);

            $contentType = 'application/vnd.retailer.v10+json';

            $response = match (strtoupper($method)) {
                'GET'    => $http->get($url, $options['query'] ?? []),
                'POST'   => $http->withBody(json_encode($options['json'] ?? []), $contentType)->post($url),
                'PUT'    => $http->withBody(json_encode($options['json'] ?? []), $contentType)->put($url),
                'DELETE' => $http->delete($url),
                default  => throw new Exception("Unsupported HTTP method: {$method}"),
            };

            $response->throw();

            return $response->json();
        } catch (\Exception $e) {
            if ($e instanceof RequestException) {
                \Sentry\configureScope(function (Scope $scope) use ($e) {
                    $scope->setContext('bolcom_response', [
                        'body'    => $e->response->body(),
                        'status'  => $e->response->status(),
                        'headers' => $e->response->headers(),
                    ]);
                });
            }

            throw new Exception('Bol.com API error', previous: $e);
        }
    }
}