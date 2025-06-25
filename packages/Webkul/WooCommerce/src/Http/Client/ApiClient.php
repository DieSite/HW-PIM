<?php

namespace Webkul\WooCommerce\Http\Client;

use Illuminate\Http\Client\Response;
use Webkul\WooCommerce\Traits\RestApiEndpointsTrait;

/**
 * REST APIClient class.
 */
class ApiClient
{
    use RestApiEndpointsTrait;

    public const WORD_PRESS_END_POINT = ['getACFField', 'addMedia', 'getMedia'];

    protected $ch;

    /**
     * Request.
     *
     * @var Request
     */
    private $request;

    /**
     * Response.
     *
     * @var Response
     */
    private $response;

    /**
     * Initialize.
     *
     * @param  string  $accessToken  access token
     */
    public function __construct(
        protected $url,
        protected $consumerKey,
        protected $consumerSecret,
        protected $options = []
    ) {
        $this->url = $this->buildApiUrl($url);
    }

    /**
     * Build API URL.
     *
     * @param  string  $url  Store URL.
     * @return string
     */
    protected function buildApiUrl($url, $replaceUrl = null)
    {
        $api = '/wp-json/wc/';
        $url = str_replace(['/wp-admin', 'shop/'], ['', ''], $url);
        $updatedUrl = \rtrim($url, '/').$api.($this->options['version'] ?? 'v2').'/';

        if (! empty($this->options['type'])) {
            $updatedUrl = str_replace('/wp-json/wc/', '/wp-json/'.$this->options['type'].'/', $updatedUrl);
        }
        $this->url = $updatedUrl;

        $response = $this->request('settings', [], []);

        if (! empty($response['data']['status']) && $response['data']['status'] == 404) {
            $updatedUrl = str_replace('v2', 'v1', $updatedUrl);
        }

        return $updatedUrl;
    }

    /**
     * Make requests.
     *
     * @param  string  $endpoint  Request endpoint.
     * @param  array  $parameters  Request parameters.
     * @param  array  $data  Request data.
     * @return array
     */
    public function request($endpoint, $parameters = [], $payload = [], $headers = [])
    {
        if ($endpoint === 'getAllGroup') {
            $this->url = str_replace('wp-json/wc/v2/', '', $this->url);
        }

        // Initialize cURL.
        $this->ch = \curl_init();

        // Set request args.
        $request = $this->createRequest($endpoint, $parameters, $payload, $headers);
        // Default cURL settings.
        $this->setDefaultCurlSettings();

        // Get response.
        $response = $this->createResponse();

        // Check for cURL errors.
        if (\curl_errno($this->ch)) {
            $response['error'] = \curl_error($this->ch);
            $response['code'] = 0;
        }

        \curl_close($this->ch);

        return $response;
    }

    /**
     * Create request.
     *
     * @param  string  $endpoint  Request endpoint.
     * @param  string  $method  Request method.
     * @param  array  $data  Request data.
     * @param  array  $parameters  Request parameters.
     * @return Request
     */
    protected function createRequest($endpoint, $parameters = [], $data = [], $headers = [])
    {
        $holdEndPoint = $endpoint;
        if (array_key_exists($endpoint, $this->apiEndpoints)) {

            if (in_array($endpoint, self::WORD_PRESS_END_POINT)) {
                $this->url = str_replace('wc', 'wp', $this->url);
            }
            $method = $this->apiEndpoints[$endpoint]['method'];
            $endpoint = $this->apiEndpoints[$endpoint]['url'];
            foreach ($parameters as $key => $val) {
                $needle = '{_'.$key.'}';

                if (strpos($endpoint, $needle) !== false) {
                    $endpoint = str_replace($needle, $val, $endpoint);
                    unset($parameters[$key]);
                }
            }
        } else {
            return;
        }
        $body = '';
        $url = $this->url.$endpoint;

        $hasData = ! empty($data);

        if (strpos($endpoint, 'media/') !== false) {
            $this->url = str_replace('v1', 'v2', $this->url);
            $url = $this->url.$endpoint;
        }

        // Setup authentication.
        $this->authenticate($url, $method, $parameters, $headers, $holdEndPoint);
        // Setup method.
        $this->setupMethod($method);
        // Include post fields.
        if ($hasData) {
            $body = json_encode($data);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $body);
        }

    }

    /**
     * Set default cURL settings.
     */
    protected function setDefaultCurlSettings()
    {
        $verifySsl = $this->options['verifySsl'] ?? false;
        $timeout = $this->options['timeout'] ?? 300;
        $followRedirects = $this->options['followRedirects'] ?? true;

        \curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        if (! $verifySsl) {
            \curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, $verifySsl);
        }
        if ($followRedirects) {
            \curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        }

        \curl_setopt($this->ch, CURLOPT_MAXREDIRS, 10);
        \curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        \curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        \curl_setopt($this->ch, CURLOPT_TIMEOUT, $timeout);
        \curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    }

    protected function createResponse()
    {
        // Get response data.

        $rawBody = \curl_exec($this->ch);

        $code = \curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

        \Log::info("Body: $rawBody");
        try {
            $body = json_decode($rawBody, true);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            $body = [];
        }

        if (! empty($body) && gettype($body) != 'integer' && gettype($body) != 'boolean') {
            $response = array_merge(['code' => $code], $body);
        } else {
            $response = ['code' => $code];
        }

        return $response;
    }

    /**
     * Authenticate.
     *
     * @param  string  $url  Request URL.
     * @param  string  $method  Request method.
     * @param  array  $parameters  Request parameters.
     * @return array
     */
    protected function authenticate($url, $method, $parameters, $headers, $holdEndPoint = null)
    {
        if ($this->isSsl() && ! in_array($holdEndPoint, ['getACFField', 'getMedia', 'getAllGroup'])) {
            $basicAuth = new BasicAuth($this->consumerKey, $this->consumerSecret);
            $basicAuth->addAuthentication($this->ch, $url, $parameters, $headers);
        } else {
            $oAuth = new Oauth($this->consumerKey, $this->consumerSecret);
            $oAuth->addAuthentication($this->ch, $url, $method, $parameters, $headers);
        }
    }

    /**
     * Setup method.
     *
     * @param  string  $method  Request method.
     */
    protected function setupMethod($method)
    {
        if ($method == 'POST') {
            \curl_setopt($this->ch, CURLOPT_POST, true);
        } elseif (\in_array($method, ['PUT', 'DELETE', 'OPTIONS'])) {
            \curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);
        }
    }

    /**
     * Check if is under SSL.
     *
     * @return bool
     */
    protected function isSsl()
    {
        return \substr($this->url, 0, 8) === 'https://';
    }

    /**
     * Get request data.
     *
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get response data.
     *
     * @return Response
     */
    public function getResponse()
    {
        return $this->response;
    }
}
