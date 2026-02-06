<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\PluginService;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\ConnectionFinalize;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\ConnectionFinalizeResponse;
use Exception;
use PHPUnit\Framework\TestCase;

final class ConnectionFinalizeTest extends TestCase
{
    public function testSettersAndParseResponse()
    {
        $connectionFinalize = new ConnectionFinalize();

        $testUrl = 'https://test.airwallex.com';
        try {
            /** @var ConnectionFinalizeResponse $response */
            $connectionFinalize
                ->setPlatform('woo')
                ->setOrigin($testUrl)
                ->setBaseUrl($testUrl)
                ->setWebhookNotificationUrl($testUrl . '/wc-api/airwallex_webhook/')
                ->setConnectionFinalizeToken('fb771064-5b55-48f2-bc9a-0045392addf0')
                ->setAccessToken('fb771064-5b55-48f2-bc9a-0045392addf0')
                ->setRequestId('fb771064-5b55-48f2-bc9a-' . time())
                ->send();
        } catch (Exception $e) {
            $arr = json_decode($e->getMessage(), true);
            $this->assertEquals('unauthorized', $arr['code']);
        }
    }
}
