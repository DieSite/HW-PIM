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

    public function patch(string $endpoint, array $data = []): ?array
    {
        return $this->request('PATCH', $endpoint, ['json' => $data]);
    }

    public function delete(string $endpoint): ?array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Pick the Bol.com media type per endpoint family.
     *
     * Offer endpoints moved to Offers API v11 (the v10 versions are marked
     * deprecated). Catalog content + process-status + reports stay on v10.
     *
     * Exposed for the contract tests and smoke-test.
     */
    public static function mediaTypeForEndpoint(string $endpoint): string
    {
        $path = parse_url($endpoint, PHP_URL_PATH) ?: $endpoint;

        if (preg_match('#^/retailer/economic-operators?(/|$)#', $path)) {
            return 'application/vnd.economic-operator.v1+json';
        }

        if (preg_match('#^/retailer(-demo)?/offers(/|$)#', $path)) {
            return 'application/vnd.retailer.v11+json';
        }

        return 'application/vnd.retailer.v10+json';
    }

    protected function request(string $method, string $endpoint, array $options = []): ?array
    {
        $url = $this->baseUrl.$endpoint;
        $credentialId = $this->authHelper?->getCredentialId();
        $mediaType = self::mediaTypeForEndpoint($endpoint);

        \Sentry\configureScope(function (Scope $scope) use ($method, $url, $options, $credentialId, $mediaType) {
            $scope->setContext('bolcom_request', [
                'method'     => $method,
                'url'        => $url,
                'options'    => $options,
                'media_type' => $mediaType,
            ]);
            $scope->setTag('bolcom.endpoint', parse_url($url, PHP_URL_PATH) ?: $endpoint);
            $scope->setTag('bolcom.media_type', $mediaType);
            if ($credentialId !== null) {
                $scope->setTag('bolcom.credential_id', (string) $credentialId);
            }
        });

        try {
            $headers = $this->authHelper->getAuthHeaders($mediaType);

            $http = Http::withHeaders($headers);

            $response = match (strtoupper($method)) {
                'GET'    => $http->get($url, $options['query'] ?? []),
                'POST'   => $http->withBody(json_encode($options['json'] ?? []), $mediaType)->post($url),
                'PUT'    => $http->withBody(json_encode($options['json'] ?? []), $mediaType)->put($url),
                'PATCH'  => $http->withBody(json_encode($options['json'] ?? []), $mediaType)->patch($url),
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
