<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\UseCase\PaymentMethodType;

use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheTrait;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentMethodType\GetList as GetPaymentMethodTypeList;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentMethodType;
use Exception;

class GetList
{
    use CacheTrait;

    /**
     * @var null|bool
     */
    private $active;

    /**
     * @var string
     */
    private $countryCode;

    /**
     * @var string
     */
    private $transactionMode;

    /**
     * @var string
     */
    private $transactionCurrency;

    /**
     * @param bool|null $active
     *
     * @return GetList
     */
    public function setActive(bool $active): GetList
    {
        $this->active = $active;
        return $this;
    }

    /**
     * @return null|bool
     */
    public function getActive()
    {
        if ($this->active === null) {
            return null;
        }
        return $this->active;
    }

    /**
     * @param string $countryCode
     *
     * @return GetList
     */
    public function setCountryCode(string $countryCode): GetList
    {
        $this->countryCode = $countryCode;
        return $this;
    }

    /**
     * @return string
     */
    public function getCountryCode(): string
    {
        return $this->countryCode ?? '';
    }

    /**
     * @param string $transactionMode
     *
     * @return GetList
     */
    public function setTransactionMode(string $transactionMode): GetList
    {
        $this->transactionMode = $transactionMode;
        return $this;
    }

    /**
     * @return string
     */
    public function getTransactionMode(): string
    {
        return $this->transactionMode ?? '';
    }

    /**
     * @param string $transactionCurrency
     *
     * @return GetList
     */
    public function setTransactionCurrency(string $transactionCurrency): GetList
    {
        $this->transactionCurrency = $transactionCurrency;
        return $this;
    }

    /**
     * @return string
     */
    public function getTransactionCurrency(): string
    {
        return $this->transactionCurrency ?? '';
    }

    /**
     * @return array
     * @throws Exception
     */
    public function get(): array
    {
        $cacheName = 'airwallex_payment_method_type_list'
            . $this->getCountryCode()
            . $this->getTransactionMode()
            . $this->getTransactionCurrency()
            . ($this->getActive() === null ? '' : ($this->getActive() ? 'active' : 'inactive'));
        return $this->cacheRemember(
            $cacheName,
            function () {
                return $this->getPaymentMethodTypes();
            }
        );
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getPaymentMethodTypes(): array
    {
        $maxPage = 100;
        $page = 0;
        $paymentMethodTypeList = [];
        do {
            $request = new GetPaymentMethodTypeList();
            if ($this->active !== null) {
                $request->setActive($this->active);
            }
            if ($this->countryCode) {
                $request->setCountryCode($this->countryCode);
            }
            if ($this->transactionMode) {
                $request->setTransactionMode($this->transactionMode);
            }
            if ($this->transactionCurrency) {
                $request->setTransactionCurrency($this->transactionCurrency);
            }
            $getList = $request->setPage($page)->send();

            /** @var PaymentMethodType $paymentMethodType */
            foreach ($getList->getItems() as $paymentMethodType) {
                $paymentMethodTypeList[] = $paymentMethodType;
            }

            $page++;
        } while ($page < $maxPage && $getList->hasMore());
        return $paymentMethodTypeList;
    }
}