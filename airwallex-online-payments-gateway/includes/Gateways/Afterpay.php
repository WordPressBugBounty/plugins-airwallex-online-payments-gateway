<?php

namespace Airwallex\Gateways;

use Airwallex\PayappsPlugin\CommonLibrary\Configuration\PaymentMethodType\Afterpay as AfterpayConfiguration;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Account as MerchantAccount;
use Airwallex\Services\CacheService;
use Airwallex\Services\LogService;
use Error;
use Exception;

defined( 'ABSPATH' ) || exit();

class Afterpay extends AirwallexGatewayLocalPaymentMethod {
    use AirwallexGatewayTrait;

    const GATEWAY_ID = 'afterpay';
    const ROUTE_SLUG = 'airwallex_afterpay';
    const PAYMENT_METHOD_TYPE_NAME = 'afterpay';

    public function __construct() {
        $this->id = 'airwallex_' . self::GATEWAY_ID;
        $this->paymentMethodType = self::GATEWAY_ID;
        $this->paymentMethodName = 'Afterpay';
        $this->method_title = __( 'Airwallex - Afterpay', 'airwallex-online-payments-gateway' );
        $this->method_description = __( 'Accept Afterpay payments with your Airwallex account', 'airwallex-online-payments-gateway' );
        $this->supports    = ['products', 'refunds'];
        $this->tabTitle = __('Afterpay', 'airwallex-online-payments-gateway');
		$this->logService = LogService::getInstance();

        parent::__construct();
    }

    public function get_form_fields() {
		return apply_filters( // phpcs:ignore
			'wc_airwallex_settings', // phpcs:ignore
			[
				'enabled'     => array(
					'title'       => __( 'Enable/Disable', 'airwallex-online-payments-gateway' ),
					'label'       => __( 'Enable Airwallex Afterpay', 'airwallex-online-payments-gateway' ),
					'type'        => 'check_is_enabled',
					'description' => '',
					'default'     => 'no',
				),
				'title'       => array(
					'title'       => __( 'Title', 'airwallex-online-payments-gateway' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'airwallex-online-payments-gateway' ),
					'default'     => __( 'Afterpay', 'airwallex-online-payments-gateway' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Description', 'airwallex-online-payments-gateway' ),
					'type'        => 'text',
					'description' => __( 'This controls the description which the user sees during checkout.', 'airwallex-online-payments-gateway' ),
					'default'     => __( 'Pay with Afterpay', 'airwallex-online-payments-gateway' ),
					'desc_tip'    => true,
				),
            ]
		);
	}

    public function getOwningEntity() {
        $cacheName = 'awxOwningEntity';
        $entity = CacheService::getInstance()->get($cacheName);
        if (is_null($entity)) {
            try {
                $account = (new MerchantAccount())->send();
                $entity = $account->getOwningEntity();
            } catch ( Error $e) {
                $entity = "";
                $this->logService->error( 'Get owning entity failed: ', $e->getMessage() );
            } catch ( Exception $e) {
                $entity = "";
                $this->logService->error( 'Get owning entity failed: ', $e->getMessage() );
            }
            CacheService::getInstance()->set( $cacheName, $entity, empty($entity) ? MINUTE_IN_SECONDS : 24 * HOUR_IN_SECONDS );
        }
        return $entity;
    }

    public function getLPMMethodScriptData($data) {
        $data[$this->id] = [
            'supportedCountryCurrency' => AfterpayConfiguration::SUPPORTED_COUNTRY_TO_CURRENCY,
            'supportedEntityCurrencies' => AfterpayConfiguration::SUPPORTED_ENTITY_TO_CURRENCIES,
        ];
        $data['paymentMethods'][] = $this->id;
        $data['paymentMethodNames']['Afterpay'] = __("Afterpay", 'airwallex-online-payments-gateway');
        $data['owningEntity'] = $this->getOwningEntity();

        return $data;
    }

    public function getPaymentMethod($order, $paymentIntentId): array {
        $billing = $this->getBillingDetail($order);
        $countryCode = $billing['address']['country_code'] ?? '';

        return [
            'type' => 'afterpay',
            'afterpay' => [
                'billing' => $billing,
                'country_code' => $countryCode,
                'flow' => 'webqr',
                'intent_id' => $paymentIntentId,
                'shopper_email' => $billing['email'] ?? '',
                'shopper_name' => $order->get_formatted_billing_full_name(),
                'shopper_phone' => $billing['phone_number'] ?? '',
            ],
        ];
    }

    public function getPaymentMethodOptions(): array {
        return [
            'afterpay' => [
                'auto_capture' => true,
            ]
        ];
    }

    public function payment_fields() {
        echo wp_kses_post( '<p style="display: flex; align-items: center;"><span>' . $this->description . '</span><span class="wc-airwallex-loader"></span></p>' );

        $this->renderAfterpaySupportedCountriesForm();
        $this->renderEntityIneligibleHtml();
        $this->renderCurrencyIneligibleCWOnHtml();
        $this->renderCurrencyIneligibleCWOffHtml();
    }

    public function renderAfterpaySupportedCountriesForm() {
        include AIRWALLEX_PLUGIN_PATH . 'templates/airwallex-afterpay-supported-countries-form.php';
    }

    public function renderEntityIneligibleHtml() {
        $awxAlertAdditionalClass = 'wc-airwallex-lpm-entity-ineligible';
        $awxAlertType            = 'critical';
        $awxAlertText            = __('Invalid merchant entity.', 'airwallex-online-payments-gateway');

        include AIRWALLEX_PLUGIN_PATH . 'templates/airwallex-alert-box.php';
    }


    public function process_payment( $order_id ) {
        $owningEntity = $this->getOwningEntity();
        if ( empty( $owningEntity ) || empty( AfterpayConfiguration::SUPPORTED_ENTITY_TO_CURRENCIES[$owningEntity])) {
            throw new Exception( __('Invalid merchant entity.', 'airwallex-online-payments-gateway') );
        }
        return parent::process_payment( $order_id );
    }
}
