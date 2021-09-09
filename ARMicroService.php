<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;

class ARMicroService extends GuzzleRequestService
{
    private $client;
    public $service;

    public function __construct(string $service)
    {
        $this->service = $service;
        $this->continue_on_request_error = true;
        $this->only_return_contents = false;
        $service_config = config('ar-services.' . $service);
        if (!$service_config) {
            throw new Exception('Service: ' . $service . ' not found!');
        }
        if (Cache::has($service_config['cache_key'])) {
            $token_data = Cache::get($service_config['cache_key']);
        } else {
            $token_client = $this->_createClient($service_config['url']);
            $token_data = $this->_doRequest($token_client, 'oauth/token', 'POST', $service_config['auth']);
            $token_data = (object) json_decode($token_data->getBody()->getContents());
            Cache::put($service_config['cache_key'], $token_data, $token_data->expires_in);
        }
        $custom_headers = auth('api')->check() ? ['X-EXTERNAL-USER' => auth('api')->user()->id] : [];
        $this->client = $this->_createClient(
            $service_config['url'] . '/api/v' . $service_config['version'] . '/',
            array_merge($custom_headers, [
                'Authorization' => 'Bearer ' . $token_data->access_token,
                'X-USER-AGENT' => request()->server('HTTP_USER_AGENT')
            ])
        );
    }
  
    public function query(string $uri, string $method = 'GET', string $query_string = null, array $post_data = [])
    {
        $service_config = config('ar-services.' . $this->service);
        if ($method === 'POST' && $uri === 'curator') {
            $user = auth('api')->user();
            $add = [
                'external_user_id' => $user->id,
                'first_name' => $user->fname,
                'last_name' => $user->lname,
                'email' => $user->email,
                'spotify_id' => $user->spotify->spotify_id,
            ];
            $post_data = array_merge($post_data, $add);
        }
        return $this->_doRequest(
            $this->client,
            $uri . ($query_string ? '?' . $query_string : ''),
            $method,
            [],
            $post_data
        );
    }

}
