<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\PluginService;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase
{
    public function testLogRequest()
    {
        $resp1 = Log::error('something wrong', Log::ON_PROCESS_WEBHOOK_ERROR);
        $resp2 = Log::info('log for test', Log::ON_PAYMENT_CREATION_ERROR);
        $this->assertEquals('ok', (string)$resp1->getBody());
        $this->assertEquals('ok', (string)$resp2->getBody());
    }
}