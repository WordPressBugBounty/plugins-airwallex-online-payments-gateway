<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\AWXClientAPI\PaymentIntent;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Create as CreatePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Retrieve as RetrievePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent;
use Exception;
use PHPUnit\Framework\TestCase;

final class PaymentIntentTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testPaymentPayment()
    {
        $orderId = (string)time();
        $email = time() . '@gmail.com';
        $amount = 100.0;
        /** @var PaymentIntent $paymentIntent */
        $paymentIntent = (new CreatePaymentIntent())
            ->setCustomer(['email' => $email])
            ->setAmount($amount)
            ->setMerchantOrderId($orderId)
            ->setCurrency('USD')
            ->send();
        $this->assertEquals($orderId, $paymentIntent->getMerchantOrderId());
        $this->assertEquals($amount, $paymentIntent->getAmount());
        /** @var PaymentIntent $paymentIntent */
        $paymentIntent = (new RetrievePaymentIntent())
            ->setPaymentIntentId($paymentIntent->getId())
            ->send();
        $this->assertEquals($orderId, $paymentIntent->getMerchantOrderId());
        $this->assertEquals($amount, $paymentIntent->getAmount());
        $this->assertEquals($email, $paymentIntent->getCustomer()['email']);
    }
}
