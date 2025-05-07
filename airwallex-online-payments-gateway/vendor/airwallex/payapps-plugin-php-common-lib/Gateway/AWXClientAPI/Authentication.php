<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI;

use Airwallex\PayappsPlugin\CommonLibrary\Configuration\Init;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\AccessToken;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;

class Authentication extends AbstractApi
{
    /**
     * @return array
     */
    protected function getHeaders(): array
    {
        return [
            'x-client-id' => Init::getInstance()->get('client_id'),
            'x-api-key' => Init::getInstance()->get('api_key'),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getUri(): string
    {
        return 'authentication/login';
    }

    /**
     * @param Response $response
     * @return AccessToken
     */
    protected function parseResponse(Response $response): AccessToken
    {
        return new AccessToken(json_decode($response->getBody(), true));
    }
}