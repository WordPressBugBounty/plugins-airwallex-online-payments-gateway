<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log;

use Airwallex\PayappsPlugin\CommonLibrary\Configuration\Init;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\AbstractApi;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;

class Log extends AbstractApi
{
    /**
     * @var string
     */
    const DEMO_BASE_URL = 'https://api-demo.airwallex.com/';

    /**
     * @var string
     */
    const PRODUCTION_BASE_URL = 'https://api.airwallex.com/';

    /**
     * @var string
     */
    private static $sessionId = null;

    /**
     * @var Log
     */
    private static $instance = null;

    /**
     * @return array
     */
    protected function getHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getUri(): string
    {
        return 'papluginlogs/logs';
    }

    /**
     * @param string $token
     *
     * @return string
     */
    protected function decodeJWT(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return 'decode failed';
        }

        $decoded = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return $decoded['account_id'] ?? 'decode failed';
    }

    /**
     * @return string
     *
     * @throws GuzzleException
     */
    protected function getAccountId(): string
    {
        return $this->decodeJWT($this->getToken());
    }

    /**
     * @return string
     */
    protected static function getSessionId(): string
    {
        if (!isset(self::$sessionId)) {
            self::$sessionId = uniqid('', true);
        }

        return self::$sessionId;
    }

    /**
     * @return string
     */
    protected function getClientPlatform(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $platforms = [
            'linux' => 'Linux',
            'android' => 'Android',
            'windows' => 'Windows',
            'ios' => ['iPhone', 'iPad'],
            'macos' => ['Macintosh', 'Mac OS X'],
            'other' => '',
        ];

        foreach ($platforms as $key => $values) {
            foreach ((array)$values as $value) {
                if (!empty($userAgent) && strpos($userAgent, $value) !== false) {
                    return $key;
                }
            }
        }

        return 'other';
    }

    /**
     * @return void
     *
     * @throws GuzzleException
     */
    protected function initializePostParams()
    {
        $this->setParams([
            'commonData' => [
                'accountId' => $this->getAccountId(),
                'appName' => 'pa_plugin',
                'source' => Init::getInstance()->get('plugin_type') ?: 'php_common_library',
                'deviceId' => 'unknown',
                'sessionId' => self::getSessionId(),
                'appVersion' => Init::getInstance()->get('plugin_version'),
                'platform' => $this->getClientPlatform(),
                'env' => Init::getInstance()->get('env') === 'demo' ? 'demo' : 'prod',
            ]
        ]);
    }

    /**
     * @return Log
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Log();
        }
        return self::$instance;
    }

    /**
     * @param string $eventName
     * @param string $message
     *
     * @return Response
     * @throws GuzzleException
     */
    public static function info(string $message, string $eventName = ""): Response
    {
        return self::log('info', $eventName, $message);
    }

    /**
     * @param string $eventName
     * @param string $message
     *
     * @return Response
     * @throws GuzzleException
     */
    public static function error(string $message, string $eventName = ""): Response
    {
        return self::log('error', $eventName, $message);
    }

    /**
     * @param string $severity
     * @param string $eventName
     * @param string $message
     *
     * @return Response
     * @throws GuzzleException
     */
    public static function log(string $severity, string $eventName, string $message): Response
    {
        $instance = self::getInstance();
        $instance->setParams([
            'data' => [
                [
                    'severity' => $severity,
                    'eventName' => $eventName,
                    'message' => $message,
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
                    'metadata' => $instance->getMetadata(),
                ]
            ]
        ]);

        return $instance->send();
    }

    /**
     * @param Response $response
     *
     * @return Response
     */
    protected function parseResponse(Response $response): Response
    {
        return $response;
    }
}
