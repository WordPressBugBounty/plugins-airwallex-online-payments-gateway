<?php
namespace Airwallex\Client;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Create;

class UpsellCreatePaymentIntent extends Create
{
    private $requestId;

    public function __construct($requestId)
    {
        $this->requestId = $requestId;
    }

    protected function generateRequestId(): string
    {
        return $this->requestId;
    }
}
