<?php
namespace Airwallex\Client;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Confirm;

class UpsellConfirmPaymentIntent extends Confirm
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
