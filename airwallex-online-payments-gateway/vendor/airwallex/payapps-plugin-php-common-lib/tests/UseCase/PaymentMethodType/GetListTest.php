<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\UseCase\PaymentMethodType;

use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentMethodType;
use Airwallex\PayappsPlugin\CommonLibrary\UseCase\PaymentMethodType\GetList as GetPaymentMethodTypeList;
use Exception;
use PHPUnit\Framework\TestCase;

final class GetListTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testGetPaymentMethodTypeList()
    {
        $activeList = (new GetPaymentMethodTypeList())
            ->setActive(true)
            ->get();
        $recurringList = (new GetPaymentMethodTypeList())
            ->setActive(true)
            ->setTransactionMode(PaymentMethodType::PAYMENT_METHOD_TYPE_RECURRING)
            ->get();
        $oneOffList = (new GetPaymentMethodTypeList())
            ->setActive(true)
            ->setTransactionMode(PaymentMethodType::PAYMENT_METHOD_TYPE_ONE_OFF)
            ->get();
        $this->assertCount(count($activeList), array_merge($recurringList, $oneOffList));
    }
}