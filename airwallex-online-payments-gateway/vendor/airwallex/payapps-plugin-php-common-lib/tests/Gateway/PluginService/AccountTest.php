<?php declare(strict_types=1);

namespace Airwallex\PayappsPlugin\CommonLibrary\tests\Gateway\PluginService;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Account;
use Exception;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testGetAccount()
    {
        $account = (new Account())->send();
        $this->assertStringStartsWith('AIRWALLEX_', $account->getOwningEntity());
    }
}