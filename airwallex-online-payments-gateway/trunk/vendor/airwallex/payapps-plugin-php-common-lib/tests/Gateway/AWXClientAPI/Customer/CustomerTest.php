<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\AWXClientAPI\Customer;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Create as CreateCustomer;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Retrieve as RetrieveCustomer;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Update as UpdateCustomer;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Customer;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Exception;

final class CustomerTest extends TestCase
{
    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function testCustomerCreate()
    {
        /** @var Customer $customer */
        $customer = (new CreateCustomer())->setCustomerId(1)->send();
        $this->assertNotEmpty($customer->getId());
        $customer2 = (new RetrieveCustomer())->setCustomerId($customer->getId())->send();
        $this->assertEquals($customer->getId(), $customer2->getId());
        $this->assertEquals($customer->getMerchantCustomerId(), $customer2->getMerchantCustomerId());
        $email = 'test' . time() . '@example.com';
        $firstName = 'Test';
        $lastName = 'User';
        $address = [
            'city' => 'New York',
            'country_code' => 'US',
            'postal_code' => '90001',
            'state' => 'NY',
            'street' => '123 Main St',
        ];
        $merchantCustomerId = 'testMerchantCustomerId' . time();
        $phoneNumber = '0123456789' . time();
        $customer4 = (new UpdateCustomer())
            ->setCustomerId($customer2->getId())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setAddress($address)
            ->setMerchantCustomerId($merchantCustomerId)
            ->setPhoneNumber($phoneNumber)
            ->send();
        $customer3 = (new RetrieveCustomer())->setCustomerId($customer2->getId())->send();
        $this->assertEquals($email, $customer3->getEmail());
        $this->assertEquals($firstName, $customer3->getFirstName());
        $this->assertEquals($lastName, $customer3->getLastName());
        $this->assertEquals($merchantCustomerId, $customer3->getMerchantCustomerId());
        $this->assertEquals($phoneNumber, $customer3->getPhoneNumber());
    }
}