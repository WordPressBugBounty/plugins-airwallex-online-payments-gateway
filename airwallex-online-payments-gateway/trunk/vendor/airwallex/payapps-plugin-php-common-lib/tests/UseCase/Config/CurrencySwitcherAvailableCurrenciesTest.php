<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\UseCase\Config;

use Airwallex\PayappsPlugin\CommonLibrary\UseCase\Config\CurrencySwitcherAvailableCurrencies;
use Exception;
use PHPUnit\Framework\TestCase;

final class CurrencySwitcherAvailableCurrenciesTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testAvailableCurrencies()
    {
        $currencies = (new CurrencySwitcherAvailableCurrencies())->get();
        $this->assertContains('USD', $currencies);
    }
}