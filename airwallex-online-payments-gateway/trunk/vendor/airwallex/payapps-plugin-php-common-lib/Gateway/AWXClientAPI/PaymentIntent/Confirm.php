<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\AbstractApi;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent;

class Confirm extends AbstractApi
{
    /**
     * @var string
     */
    private $id;

    /**
     * @inheritDoc
     */
    protected function getUri(): string
    {
        return 'pa/payment_intents/' . $this->id . '/confirm';
    }

    /**
     * @param string $customerId
     *
     * @return Confirm
     */
    public function setCustomerId(string $customerId): Confirm
    {
        return $this->setParam('customer_id', $customerId);
    }

    /**
     * @param array $deviceData
     *
     * @return Confirm
     */
    public function setDeviceData(array $deviceData): Confirm
    {
        return $this->setParam('device_data', $deviceData);
    }

    /**
     * @param string $paymentIntentId
     *
     * @return Confirm
     */
    public function setPaymentIntentId(string $paymentIntentId): Confirm
    {
        $this->id = $paymentIntentId;
        return $this;
    }

    /**
     * @param array $externalRecurringData
     *
     * @return Confirm
     */
    public function setExternalRecurringData(array $externalRecurringData): Confirm
    {
        return $this->setParam('external_recurring_data', $externalRecurringData);
    }

    /**
     * @param array $paymentConsent
     *
     * @return Confirm
     */
    public function setPaymentConsent(array $paymentConsent): Confirm
    {
        return $this->setParam('payment_consent', $paymentConsent);
    }

    /**
     * @param string $paymentConsentId
     *
     * @return Confirm
     */
    public function setPaymentConsentId(string $paymentConsentId): Confirm
    {
        return $this->setParam('payment_consent_id', $paymentConsentId);
    }

    /**
     * @param array $paymentMethod
     *
     * @return Confirm
     */
    public function setPaymentMethod(array $paymentMethod): Confirm
    {
        return $this->setParam('payment_method', $paymentMethod);
    }

    /**
     * @param array $paymentMethodOptions
     *
     * @return Confirm
     */
    public function setPaymentMethodOptions(array $paymentMethodOptions): Confirm
    {
        return $this->setParam('payment_method_options', $paymentMethodOptions);
    }

    /**
     * @param string $returnUrl
     *
     * @return Confirm
     */
    public function setReturnUrl(string $returnUrl): Confirm
    {
        return $this->setParam('return_url', $returnUrl);
    }

    /**
     * @param $response
     *
     * @return PaymentIntent
     */
    protected function parseResponse($response): PaymentIntent
    {
        return new PaymentIntent(json_decode($response->getBody(), true));
    }
}
