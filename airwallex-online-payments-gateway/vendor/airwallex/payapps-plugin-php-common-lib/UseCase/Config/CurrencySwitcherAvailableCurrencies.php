<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\UseCase\Config;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Config\GetAvailableCurrencies;
use Exception;

class CurrencySwitcherAvailableCurrencies
{
    /**
     * @return array
     * @throws Exception
     */
    public function get(): array
    {
        $page = 0;
        $all = [];
        $maxPage = 100;
        $getCurrenciesRequest = new GetAvailableCurrencies();
        do {
            $getList = $getCurrenciesRequest->setPage($page)->send();
            foreach ($getList->getItems() as $items) {
                $all[] = $items;
            }
            $page++;
        } while ($page < $maxPage && $getList->hasMore());
        foreach ($all as $item) {
            if (isset($item['type']) && $item['type'] === 'currency_switcher') {
                return $item['currencies'] ?? [];
            }
        }
        return [];
    }
}
