<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\AWXClientAPI;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Authentication;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\AccessToken;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;

final class AuthenticateTest extends TestCase
{
    /**
     * @throws GuzzleException
     */
    public function testAuthentication()
    {
        /** @var AccessToken $accessToken */
        $accessToken = (new Authentication())->send();
        $this->assertNotEmpty('token', $accessToken->getToken());
    }
}