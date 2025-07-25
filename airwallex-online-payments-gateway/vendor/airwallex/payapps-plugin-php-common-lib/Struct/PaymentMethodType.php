<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\Struct;

class PaymentMethodType extends AbstractBase
{
    const PAYMENT_METHOD_TYPE_RECURRING = 'recurring';
    const PAYMENT_METHOD_TYPE_ONE_OFF = 'oneoff';

    /**
     * @var bool
     */
    private $active;

    /**
     * @var string
     */
    private $flow;

    /**
     * @var string
     */
    private $name;

    /**
     * @var array
     */
    private $transactionCurrencies;

    /**
     * @var string
     */
    private $transactionMode;

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active ?? false;
    }

    /**
     * @param bool $active
     *
     * @return PaymentMethodType
     */
    public function setActive(bool $active): PaymentMethodType
    {
        $this->active = $active;
        return $this;
    }

    /**
     * @return string
     */
    public function getFlow(): string
    {
        return $this->flow ?? '';
    }

    /**
     * @param string $flow
     *
     * @return PaymentMethodType
     */
    public function setFlow(string $flow): PaymentMethodType
    {
        $this->flow = $flow;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name ?? '';
    }

    /**
     * @param string $name
     *
     * @return PaymentMethodType
     */
    public function setName(string $name): PaymentMethodType
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return array
     */
    public function getTransactionCurrencies(): array
    {
        return $this->transactionCurrencies ?? [];
    }

    /**
     * @param array $transaction_currencies
     *
     * @return PaymentMethodType
     */
    public function setTransactionCurrencies(array $transaction_currencies): PaymentMethodType
    {
        $this->transactionCurrencies = $transaction_currencies;
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
     * @param string $transaction_mode
     *
     * @return PaymentMethodType
     */
    public function setTransactionMode(string $transaction_mode): PaymentMethodType
    {
        $this->transactionMode = $transaction_mode;
        return $this;
    }
}
