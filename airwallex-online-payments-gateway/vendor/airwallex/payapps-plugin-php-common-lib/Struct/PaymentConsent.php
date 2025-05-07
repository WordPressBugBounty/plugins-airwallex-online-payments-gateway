<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\Struct;

class PaymentConsent extends AbstractBase
{
    /**
     * @var string
     */
    const STATUS_VERIFIED = 'VERIFIED';

    /**
     * @var string
     */
    const TRIGGERED_BY_CUSTOMER = 'customer';

    /**
     * @var string
     */
    const TRIGGERED_BY_MERCHANT = 'merchant';

    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $clientSecret;

    /**
     * @var string
     */
    protected $connectedAccountId;

    /**
     * @var string
     */
    protected $createdAt;

    /**
     * @var string
     */
    protected $customerId;

    /**
     * @var string
     */
    protected $disableReason = '';

    /**
     * @var array
     */
    protected $mandate = [];

    /**
     * @var array
     */
    protected $metadata = [];

    /**
     * @var string
     */
    protected $status;

    /**
     * @var string
     */
    protected $initialPaymentIntentId;

    /**
     * @var string
     */
    protected $updatedAt;

    /**
     * @var array
     */
    protected $nextAction;

    /**
     * @var array
     */
    protected $failureReason = [];

    /**
     * @var string
     */
    protected $merchantTriggerReason;

    /**
     * @var string
     */
    protected $nextTriggeredBy;

    /**
     * @var array
     */
    protected $paymentMethod = [];

    /**
     * @var string
     */
    protected $requestId;

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param string $id
     *
     * @return PaymentConsent
     */
    public function setId(string $id): PaymentConsent
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return string
     */
    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    /**
     * @param string $clientSecret
     *
     * @return PaymentConsent
     */
    public function setClientSecret(string $clientSecret): PaymentConsent
    {
        $this->clientSecret = $clientSecret;
        return $this;
    }

    /**
     * @return string
     */
    public function getConnectedAccountId(): string
    {
        return $this->connectedAccountId;
    }

    /**
     * @param string $connectedAccountId
     *
     * @return PaymentConsent
     */
    public function setConnectedAccountId(string $connectedAccountId): PaymentConsent
    {
        $this->connectedAccountId = $connectedAccountId;
        return $this;
    }

    /**
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /**
     * @param string $createdAt
     *
     * @return PaymentConsent
     */
    public function setCreatedAt(string $createdAt): PaymentConsent
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * @return string
     */
    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    /**
     * @param string $customerId
     *
     * @return PaymentConsent
     */
    public function setCustomerId(string $customerId): PaymentConsent
    {
        $this->customerId = $customerId;
        return $this;
    }

    /**
     * @return string
     */
    public function getDisableReason(): string
    {
        return $this->disableReason;
    }

    /**
     * @param string $disableReason
     *
     * @return PaymentConsent
     */
    public function setDisableReason(string $disableReason): PaymentConsent
    {
        $this->disableReason = $disableReason;
        return $this;
    }

    /**
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array $metadata
     *
     * @return PaymentConsent
     */
    public function setMetadata(array $metadata): PaymentConsent
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * @return array
     */
    public function getMandate(): array
    {
        return $this->mandate;
    }

    /**
     * @param array $mandate
     *
     * @return PaymentConsent
     */
    public function setMandate(array $mandate): PaymentConsent
    {
        $this->mandate = $mandate;
        return $this;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     *
     * @return PaymentConsent
     */
    public function setStatus(string $status): PaymentConsent
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return string
     */
    public function getInitialPaymentIntentId(): string
    {
        return $this->initialPaymentIntentId;
    }

    /**
     * @param string $initialPaymentIntentId
     *
     * @return PaymentConsent
     */
    public function setInitialPaymentIntentId(string $initialPaymentIntentId): PaymentConsent
    {
        $this->initialPaymentIntentId = $initialPaymentIntentId;
        return $this;
    }

    /**
     * @return string
     */
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    /**
     * @param string $updatedAt
     *
     * @return PaymentConsent
     */
    public function setUpdatedAt(string $updatedAt): PaymentConsent
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return array
     */
    public function getNextAction(): array
    {
        return $this->nextAction;
    }

    /**
     * @param array $nextAction
     *
     * @return PaymentConsent
     */
    public function setNextAction(array $nextAction): PaymentConsent
    {
        $this->nextAction = $nextAction;
        return $this;
    }

    /**
     * @return array
     */
    public function getFailureReason(): array
    {
        return $this->failureReason;
    }

    /**
     * @param array $failureReason
     *
     * @return PaymentConsent
     */
    public function setFailureReason(array $failureReason): PaymentConsent
    {
        $this->failureReason = $failureReason;
        return $this;
    }

    /**
     * @return string
     */
    public function getMerchantTriggerReason(): string
    {
        return $this->merchantTriggerReason;
    }

    /**
     * @param string $merchantTriggerReason
     *
     * @return PaymentConsent
     */
    public function setMerchantTriggerReason(string $merchantTriggerReason): PaymentConsent
    {
        $this->merchantTriggerReason = $merchantTriggerReason;
        return $this;
    }

    /**
     * @return string
     */
    public function getNextTriggeredBy(): string
    {
        return $this->nextTriggeredBy;
    }

    /**
     * @param string $nextTriggeredBy
     *
     * @return PaymentConsent
     */
    public function setNextTriggeredBy(string $nextTriggeredBy): PaymentConsent
    {
        $this->nextTriggeredBy = $nextTriggeredBy;
        return $this;
    }

    /**
     * @return array
     */
    public function getPaymentMethod(): array
    {
        return $this->paymentMethod;
    }

    /**
     * @param array $paymentMethod
     *
     * @return PaymentConsent
     */
    public function setPaymentMethod(array $paymentMethod): PaymentConsent
    {
        $this->paymentMethod = $paymentMethod;
        return $this;
    }

    /**
     * @return string
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * @param string $requestId
     *
     * @return PaymentConsent
     */
    public function setRequestId(string $requestId): PaymentConsent
    {
        $this->requestId = $requestId;
        return $this;
    }

    /**
     * @return string
     */
    public function getCardBrand(): string
    {
        return $this->paymentMethod['card']['brand'] ?? '';
    }

    /**
     * @return string
     */
    public function getCardExpiryMonth(): string
    {
        return $this->paymentMethod['card']['expiry_month'] ?? '';
    }

    /**
     * @return string
     */
    public function getCardExpiryYear(): string
    {
        return $this->paymentMethod['card']['expiry_year'] ?? '';
    }

    /**
     * @return string
     */
    public function getCardLast4(): string
    {
        return $this->paymentMethod['card']['last4'] ?? '';
    }
}
