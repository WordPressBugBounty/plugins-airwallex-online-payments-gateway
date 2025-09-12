<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\UseCase\PaymentConsent;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Create;
use Airwallex\PayappsPlugin\CommonLibrary\UseCase\PaymentConsent\All;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentConsent;

final class AllTest extends TestCase
{
    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function testCustomerCreate()
    {
        $customer = (new Create())->setCustomerId(1)->send();
        $all = (new All())
            ->setNextTriggeredBy(PaymentConsent::TRIGGERED_BY_CUSTOMER)
            ->setCustomerId($customer->getId())
            ->get();
        $this->assertCount(0, $all);
    }
}