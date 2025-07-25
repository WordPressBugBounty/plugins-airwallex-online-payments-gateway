<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\PluginService;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase
{
    public function testCustomerCreate()
    {
        $resp1 = Log::error(Log::ON_PROCESS_WEBHOOK_ERROR, 'something wrong');
        $resp2 = Log::info(Log::ON_PAYMENT_CREATION_ERROR, 'log for test');
        $this->assertEquals('ok', (string)$resp1->getBody());
        $this->assertEquals('ok', (string)$resp2->getBody());
    }
}