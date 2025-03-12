<?php

namespace Webkul\WooCommerce\Http\Client;

/**
 * Basic auth
 */
class BasicAuth
{
    /**
     * Initialize Basic Auth
     *
     * @param  string  $consumerKey  Consumer key.
     * @param  string  $consumerSecret  Consumer Secret.
     */
    public function __construct(
        protected $consumerKey,
        protected $consumerSecret,
    ) {}

    public function addAuthentication(&$ch, $url, $parameters, $headers)
    {
        $extraParam = parse_url($url);
        $extraParam = isset($extraParam['query']) ? $extraParam['query'] : null;
        if ($extraParam) {
            parse_str($extraParam, $extraparameters);
            $parameters = array_merge($parameters, $extraparameters);
        }

        if ($parameters) {
            $url = $url.'?'.http_build_query($parameters);
        }

        if (gettype($ch) == 'object') {
            $basicKey = base64_encode($this->consumerKey.':'.$this->consumerSecret);
            $authHeaders = [
                'Authorization: Basic '.$basicKey,
                'Content-Type: application/json',
            ];

            $headers = array_merge($headers, $authHeaders);

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
    }
}
