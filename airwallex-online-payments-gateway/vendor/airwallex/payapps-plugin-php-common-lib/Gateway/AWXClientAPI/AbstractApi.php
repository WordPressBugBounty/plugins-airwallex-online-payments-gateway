<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI;

use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheTrait;
use Airwallex\PayappsPlugin\CommonLibrary\Configuration\Init;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Response;
use Error;
use Exception;
use Composer\InstalledVersions;

abstract class AbstractApi
{
    use CacheTrait;

    /**
     * @var int
     */
    const TIMEOUT = 30;

    /**
     * @var string
     */
    const DEMO_BASE_URL = 'https://api-demo.airwallex.com/api/v1/';

    /**
     * @var string
     */
    const PRODUCTION_BASE_URL = 'https://api.airwallex.com/api/v1/';

    /**
     * @var string
     */
    const X_API_VERSION = '2024-06-30';

    /**
     * @var array
     */
    protected $params = [];

    /**
     * @return mixed
     * @throws Exception
     */
    public function send()
    {
        try {
            if (Init::getInstance()->get('plugin_type') === 'woo_commerce') {
                return $this->wpHttpSend();
            }
            return $this->guzzleHttpSend();
        } catch (Error $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public function wpHttpSend()
    {
        $url = $this->getBaseUrl() . $this->getUri();
        $headers = array_merge($this->getHeaders(), [
            'Content-Type' => 'application/json',
            'x-api-version' => self::X_API_VERSION
        ]);

        if ($this->getMethod() === 'GET') {
            $data = wp_remote_get($url . '?' . http_build_query($this->params), [
                'timeout' => self::TIMEOUT,
                'headers' => $headers,
            ]);
        } else {
            $this->initializePostParams();
            $data = wp_remote_post($url, [
                'method' => $this->getMethod(),
                'timeout' => self::TIMEOUT,
                'redirection' => 5,
                'headers' => $headers,
                'body' => json_encode($this->params),
                'cookies' => array(),
            ]);
        }
        $response = new Response();
        $body = is_wp_error($data) ? '' : ($data['body'] ?? '');
        $response->setBody($body);
        return $this->parseResponse($response);
    }

    /**
     * @throws Exception
     */
    public function guzzleHttpSend()
    {
        $client = new \GuzzleHttp\Client([
            'base_uri' => $this->getBaseUrl(),
            'timeout' => self::TIMEOUT,
        ]);

        $options = [
            'headers' => array_merge($this->getHeaders(), [
                'Content-Type' => 'application/json',
                'x-api-version' => self::X_API_VERSION
            ]),
            'http_errors' => false
        ];

        $method = $this->getMethod();
        if ($method === 'POST') {
            $this->initializePostParams();
            $options['json'] = $this->params;
        } elseif ($method === 'GET') {
            $options['query'] = $this->params;
        }

        $response = $client->request($method, $this->getUri(), $options);
        return $this->parseResponse($response);
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function initializePostParams()
    {
        $this->params['request_id'] = $this->generateRequestId();
        $this->params['referrer_data'] = $this->getReferrerData();
        $this->params['metadata'] = $this->getMetadata();
    }

    /**
     * @return string
     * @throws Exception
     */
    protected function generateRequestId(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }

    /**
     * @param array $params
     * @return self
     */
    protected function setParams(array $params): self
    {
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return self
     */
    protected function setParam(string $name, $value): self
    {
        $this->params[$name] = $value;
        return $this;
    }

    /**
     * @param string $name
     * @return self
     */
    protected function unsetParam(string $name): self
    {
        unset($this->params[$name]);
        return $this;
    }

    /**
     * @return array
     */
    protected function getMetadata(): array
    {
        return [
            'php_version' => phpversion(),
            'platform_version' => Init::getInstance()->get('platform_version'),
            'common_library_version' => InstalledVersions::getPrettyVersion(InstalledVersions::getRootPackage()['name']),
            'host' => $_SERVER['HTTP_HOST'] ?? '',
        ];
    }

    /**
     * @return string
     */
    protected function getBaseUrl(): string
    {
        return Init::getInstance()->get('env') === 'demo' ? static::DEMO_BASE_URL : static::PRODUCTION_BASE_URL;
    }

    /**
     * @return string
     */
    protected function getMethod(): string
    {
        return 'POST';
    }

    /**
     * @return array
     */
    private function getReferrerData(): array
    {
        return [
            'type' => Init::getInstance()->get('plugin_type'),
            'version' => Init::getInstance()->get('plugin_version'),
        ];
    }

    /**
     * @return string
     */
    abstract protected function getUri(): string;

    /**
     * @return mixed
     */
    abstract protected function parseResponse($response);

    /**
     * @return array
     * @throws Exception
     */
    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getToken(),
        ];
    }

    /**
     * @return string
     * @throws Exception
     */
    protected function getToken(): string
    {
        $cacheName = 'airwallex_token' . Init::getInstance()->get('api_key');
        return $this->cacheRemember($cacheName, function () {
            $accessToken = (new Authentication())->send();
            return $accessToken->getToken();
        }, 60 * 30);
    }
}
