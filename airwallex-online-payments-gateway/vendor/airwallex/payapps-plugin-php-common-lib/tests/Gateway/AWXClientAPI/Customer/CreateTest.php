<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\AWXClientAPI\Customer;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Create;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Customer;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Exception;

final class CreateTest extends TestCase
{
    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function testCustomerCreate()
    {
        /** @var Customer $customer */
        $customer = (new Create())->setCustomerId()->send();
        $this->assertNotEmpty($customer->getId());
    }
}