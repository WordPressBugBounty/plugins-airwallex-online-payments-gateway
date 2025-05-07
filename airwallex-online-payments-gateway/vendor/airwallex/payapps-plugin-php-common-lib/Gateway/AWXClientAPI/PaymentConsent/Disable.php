<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentConsent;

use Airwallex\PayappsPlugin\CommonLibrary\Configuration\Init;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\AbstractApi;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentConsent;
use GuzzleHttp\Psr7\Response;

class Disable extends AbstractApi
{
    /**
     * @var string
     */
    protected $paymentConsentId;

    /**
     * @inheritDoc
     */
    protected function getUri(): string
    {
        return 'pa/payment_consents/' . $this->paymentConsentId . '/disable';
    }

    /**
     * @param string $paymentConsentId
     *
     * @return $this
     */
    public function setPaymentConsentId(string $paymentConsentId): Disable
    {
        $this->paymentConsentId = $paymentConsentId;

        return $this;
    }

    /**
     * @param Response $response
     *
     * @return bool
     */
    protected function parseResponse(Response $response): bool
    {
        $paymentConsent = new PaymentConsent(json_decode($response->getBody(), true));
        return $paymentConsent->getStatus() === 'DISABLED';
    }
}