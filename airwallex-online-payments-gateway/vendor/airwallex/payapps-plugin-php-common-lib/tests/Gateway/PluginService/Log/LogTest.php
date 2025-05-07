<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\PluginService\Log;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Create;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log\Log;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase
{
    /**
     * @throws GuzzleException
     */
    public function testCustomerCreate()
    {
        $resp1 = Log::error('onWebhook', 'something wrong');
        $resp2 = Log::info('onAirwallexController', 'log for test');
        $this->assertEquals('ok', (string)$resp1->getBody());
        $this->assertEquals('ok', (string)$resp2->getBody());
    }
}