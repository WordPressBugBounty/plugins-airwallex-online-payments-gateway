<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\UseCase\PaymentConsent;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentConsent\GetList as GetPaymentConsentList;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentConsent;
use Exception;

class All
{
    /**
     * @var string
     */
    protected $triggeredBy;

    /**
     * @var string
     */
    protected $customerId;

    /**
     * @return array
     * @throws Exception
     */
    public function get(): array
    {
        $index = 0;
        $all = [];
        while (true) {
            $getList = (new GetPaymentConsentList())
                ->setCustomerId($this->customerId)
                ->setNextTriggeredBy($this->triggeredBy)
                ->setPage($index)
                ->setStatus(PaymentConsent::STATUS_VERIFIED)
                ->send();
            /** @var PaymentConsent $paymentConsent */
            foreach ($getList->getItems() as $paymentConsent) {
                $all[] = $paymentConsent;
            }

            if (!$getList->hasMore()) {
                break;
            }

            $index++;
        }
        return $all;
    }

    /**
     * @param string $triggeredBy
     *
     * @return All
     */
    public function setNextTriggeredBy(string $triggeredBy): All
    {
        $this->triggeredBy = $triggeredBy;
        return $this;
    }

    /**
     * @param string $airwallexCustomerId
     *
     * @return All
     */
    public function setCustomerId(string $airwallexCustomerId): All
    {
        $this->customerId = $airwallexCustomerId;
        return $this;
    }
}