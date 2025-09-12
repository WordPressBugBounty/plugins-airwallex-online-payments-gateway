<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\AWXClientAPI\PaymentIntent;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Cancel;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Create as CreatePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent;
use Exception;
use PHPUnit\Framework\TestCase;

final class CancelTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testCancel()
    {
        /** @var PaymentIntent $paymentIntent */
        $paymentIntent = (new CreatePaymentIntent())
            ->setAmount(100.0)
            ->setCurrency('USD')
            ->setMerchantOrderId('test-cancel-order-' . time())
            ->setCustomer(['email' => 'test-cancel-' . time() . '@example.com'])
            ->send();

        /** @var PaymentIntent $result */
        $result = (new Cancel())
            ->setPaymentIntentId($paymentIntent->getId())
            ->send();

        $this->assertInstanceOf(PaymentIntent::class, $result);
        $this->assertEquals($paymentIntent->getId(), $result->getId());
        $this->assertEquals(PaymentIntent::STATUS_CANCELLED, $result->getStatus());
    }
}
