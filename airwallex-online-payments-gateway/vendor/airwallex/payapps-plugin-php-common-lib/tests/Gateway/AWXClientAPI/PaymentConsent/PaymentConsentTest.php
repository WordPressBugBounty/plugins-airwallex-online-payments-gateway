<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\AWXClientAPI\PaymentConsent;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Create;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentConsent\GetList;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Customer;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;

final class PaymentConsentTest extends TestCase
{
    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function testPaymentConsent()
    {
        /** @var Customer $customer */
        $customer = (new Create())->setCustomerId(1)->send();
        $this->assertNotEmpty($customer->getId());
        $response = (new GetList())->setCustomerId($customer->getId())->send();
        $this->assertCount(0, $response->getItems());
    }
}