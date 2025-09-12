<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\AWXClientAPI\Config\ApplePay\Domain;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Config\ApplePay\Domain\AddItems;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Config\ApplePay\Domain\GetList;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Config\ApplePay\Domain\RemoveItems;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\ApplePayDomains;
use Exception;
use PHPUnit\Framework\TestCase;

final class ApplePayDomainsTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testApplePayDomain()
    {
        // First add a domain to ensure we have something to remove
        $domainToRemove = "staging-payapp-mage.airwallex.com";

        (new AddItems())
            ->setItems([$domainToRemove])
            ->send();

        // Now remove it
        /** @var ApplePayDomains $domains */
        $domains = (new RemoveItems())
            ->setItems([$domainToRemove])
            ->setReason('Test cleanup - removing test domain')
            ->send();

        $this->assertInstanceOf(ApplePayDomains::class, $domains);
        // The domain should no longer be in the list
        $this->assertFalse($domains->hasDomain($domainToRemove));

        (new AddItems())
            ->setItems([$domainToRemove])
            ->send();
        /** @var ApplePayDomains $domains */
        $domains = (new GetList())->send();
        $this->assertInstanceOf(ApplePayDomains::class, $domains);
        $this->assertTrue($domains->hasDomain($domainToRemove));
    }
}
