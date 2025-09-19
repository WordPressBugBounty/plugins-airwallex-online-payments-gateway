<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\PluginService;

use Airwallex\PayappsPlugin\CommonLibrary\UseCase\CurrencySwitcher;
use Exception;
use PHPUnit\Framework\TestCase;

final class QuoteTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testCurrencySwitcherQuote()
    {
        $currencySwitcher = (new CurrencySwitcher())->setPaymentCurrency('CNY')
            ->setTargetCurrency('USD')
            ->setPaymentAmount(100)
            ->get();
        $this->assertEquals('CNY', $currencySwitcher->getPaymentCurrency());
        $this->assertEquals('USD', $currencySwitcher->getTargetCurrency());
        $this->assertEquals(100, $currencySwitcher->getPaymentAmount());
    }
}