<?php

namespace Webkul\WooCommerce\Http\Client;

/**
 * wocoomerce api client
 */
class Oauth
{
    protected const API_VERSION = 'v2';

    public function __construct(
        protected $consumerKey,
        protected $consumerSecret,
    ) {}

    public function getApiUrl($url, $method, $params = [])
    {
        $extraParam = parse_url($url);
        $extraParam = isset($extraParam['query']) ? $extraParam['query'] : null;
        if ($extraParam) {
            parse_str($extraParam, $extraparameters);
            $params = array_merge($params, $extraparameters);
        }

        $url = strtok($url, '?');

        $method = strtoupper($method);
        $oauthTimestamp = time();

        $parameters = array_merge($params, [
            'oauth_consumer_key'     => $this->consumerKey,
            'oauth_timestamp'        => $oauthTimestamp,
            'oauth_nonce'            => rand(10000, 1000000),
            'oauth_signature_method' => 'HMAC-SHA256',
        ]);

        // START Generate OAuth Signature
        $query = [];
        $normalizedParameters = [];

        foreach ($parameters as $key => $value) {
            $key = str_replace('%', '%25', rawurlencode(rawurldecode($key)));
            $value = str_replace('%', '%25', rawurlencode(rawurldecode($value)));
            $normalizedParameters[$key] = $value;
        }

        uksort($normalizedParameters, 'strcmp');

        foreach ($normalizedParameters as $key => $value) {
            $query[] = $key.'%3D'.$value;
        }

        $queryString = implode('%26', $query);
        $stringToSign = $method.'&'.rawurlencode($url).'&'.$queryString;
        $secret = $this->consumerSecret;

        if (strpos($url, 'wp-json') !== false) {
            $secret .= '&';
        }

        $parameters['oauth_signature'] = base64_encode(hash_hmac('SHA256', $stringToSign, $secret, true));

        uksort($parameters, 'strcmp');

        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                uksort($parameters[$key], 'strcmp');
            }
        }

        return $url.'?'.http_build_query($parameters);
    }

    public function addAuthentication(&$ch, $url, $method, $parameters, $headers)
    {
        if (gettype($ch) == 'resource' || gettype($ch) == 'object') {
            $url = $this->getApiUrl($url, $method, $parameters);

            curl_setopt($ch, CURLOPT_URL, $url);

            $authHeaders = [
                'Content-Type: application/json',
            ];

            $headers = array_merge($headers, $authHeaders);

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    }
}
