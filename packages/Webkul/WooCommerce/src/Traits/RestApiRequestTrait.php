<?php

namespace Webkul\WooCommerce\Traits;

use Illuminate\Http\JsonResponse;
use Webkul\WooCommerce\Http\Client\ApiClient;
use Webkul\WooCommerce\Models\Credential;

/**
 * trait used to RestApiRequestTrait
 */
trait RestApiRequestTrait
{
    use RestApiEndpointsTrait;

    protected $jsonEncode = false;

    public function checkCredentials($params)
    {
        $oauthClient = new ApiClient($params['shopUrl'], $params['consumerKey'], $params['consumerSecret']);
        $response = $oauthClient->request('settings', [], []);

        return ! empty($response['code']) && $response['code'] == JsonResponse::HTTP_OK && strpos($params['shopUrl'], '/wp-login.php') === false;
    }

    /**
     *  Check the credentials and get store view.
     */
    private function getApiClient(): ApiClient
    {
        $credential = $this->formatCredential($this->credential);
        $ApiClient = new ApiClient($credential['shopUrl'], $credential['consumerKey'], $credential['consumerSecret']);

        return $ApiClient;
    }

    private function formatCredential(array|Credential $credential): array
    {
        if (! is_array($credential)) {
            $credential = [
                'shopUrl'        => $credential->shopUrl,
                'consumerKey'    => $credential->consumerKey,
                'consumerSecret' => $credential->consumerSecret,
            ];
        }

        return $credential;
    }

    public function convertToJson($data)
    {
        if (is_array($data)) {
            $data = json_encode($data);
            $this->jsonEncode = true;
        }

        return $data;
    }

    public function formatEndpoint($endpointName, $store = '')
    {
        $endpoint = null;
        $store = $store ? '/'.$store.'/' : '/';

        if (array_key_exists($endpointName, $this->apiEndpoints)) {
            $endpoint = $this->apiEndpoints[$endpointName];
            $endpoint = str_replace('/{_store}/', $store, $endpoint);
        }

        return $endpoint;
    }
}
