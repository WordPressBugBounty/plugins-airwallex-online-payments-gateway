<?php

namespace Airwallex\Gateways;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log as RemoteLog;
use Airwallex\Services\Util;
use Airwallex\Struct\Quote;
use WC_AJAX;
use Exception;
use WC_Order;

defined( 'ABSPATH' ) || exit;

abstract class AirwallexGatewayLocalPaymentMethod extends AbstractAirwallexGateway {

    public function registerHooks() {
        parent::registerHooks();
        // remove_filter( 'wc_airwallex_settings_nav_tabs', [ $this, 'adminNavTab' ] );
        // add_filter( 'wc_airwallex_local_gateways_tab', [ $this, 'adminNavTab' ] );
        add_filter( 'airwallex-lpm-script-data', [ $this, 'getLPMMethodScriptData' ] );
        add_action('wp_footer', [$this, 'renderQuoteExpireHtml']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
    }

    public function enqueueScripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_style('airwallex-css' );
		wp_enqueue_script('airwallex-lpm-js');
		wp_add_inline_script('airwallex-lpm-js', 'var awxEmbeddedLPMData = ' . wp_json_encode($this->getLPMScriptData()), 'before');
	}

    public function enqueueAdminScripts() {
	}

    public function outputSettingsNav() {
		parent::outputSettingsNav();
		// include AIRWALLEX_PLUGIN_PATH . 'includes/Gateways/Settings/views/settings-local-payment-methods-nav.php';
	}

    public function getLPMScriptData() {
        $data = [];
        try {
            $data = [
                'env' => Util::getEnvironment(),
                'ajaxUrl' => WC_AJAX::get_endpoint('%%endpoint%%'),
                'availableCurrencies' => $this->getAvailableCurrencies(),
                'originalCurrency' => get_woocommerce_currency(),
                'nonce' => [
                    'createQuoteCurrencySwitcher' => wp_create_nonce('wc-airwallex-lpm-create-quote-currency-switcher'),
                    'getStoreCurrency' => wp_create_nonce('wc-airwallex-lpm-get-store-currency'),
                ],
                'textTemplate' => [
                    'currencyIneligibleCWOn' => __('$$payment_method_name$$ is not available in $$original_currency$$ for your billing country. We have converted your total to $$converted_currency$$ for you to complete your payment.', 'airwallex-online-payments-gateway'),
                    'currencyIneligibleCWOff' => __('$$payment_method_name$$ is not available in $$original_currency$$ for your billing country. Please use a different payment method to complete your purchase.', 'airwallex-online-payments-gateway'),
                    'conversionRate' => __('1 $$original_currency$$ = $$conversion_rate$$ $$converted_currency$$', 'airwallex-online-payments-gateway'),
                    'convertedAmount' => __('$$converted_amount$$ $$converted_currency$$', 'airwallex-online-payments-gateway'),
                ],
                'alterBoxIcons' => [
                    'criticalIcon' => AIRWALLEX_PLUGIN_URL . '/assets/images/critical_filled.svg',
                    'warningIcon' => AIRWALLEX_PLUGIN_URL . '/assets/images/warning_filled.svg',
                    'infoIcon' => AIRWALLEX_PLUGIN_URL . '/assets/images/info_filled.svg',
                    'selectArrowIcon' => AIRWALLEX_PLUGIN_URL . '/assets/images/select_arrow.svg',
                ],
                'paymentMethods' => [],
            ];
            $data = apply_filters('airwallex-lpm-script-data', $data);
        } catch (Exception $e) {
            $this->logService->error(__METHOD__ . ' Get ' . $this->paymentMethodName . ' script data failed.', $e->getMessage());
        }

		return $data; 
	}

	abstract public function getLPMMethodScriptData( $data );

    public function getAvailableCurrencies() {
        $settings = $this->getCurrencySettings();
        if ( ! empty( $settings['currency_switcher']['currencies'] ) ) {
            return $settings['currency_switcher']['currencies'];
        }

        return []; 
    }

	/**
	 * Render the alter box for ineligible currency with currency switching turned on
	 */
	public function renderCurrencyIneligibleCWOnHtml() {
		$awxAlertAdditionalClass = 'wc-airwallex-lpm-currency-ineligible-switcher-on';
		$awxAlertType            = '';
		$awxAlertText            = '';

		include AIRWALLEX_PLUGIN_PATH . 'templates/airwallex-alert-box.php';
	}

	/**
	 * Render the alter box for ineligible currency with currency switching turned off
	 */
	public function renderCurrencyIneligibleCWOffHtml() {
		$awxAlertAdditionalClass = 'wc-airwallex-lpm-currency-ineligible-switcher-off';
		$awxAlertType            = 'critical';
		$awxAlertText            = '';

		include AIRWALLEX_PLUGIN_PATH . 'templates/airwallex-alert-box.php';
	}

    public function renderQuoteExpireHtml() {
        include_once AIRWALLEX_PLUGIN_PATH . 'templates/airwallex-currency-switching-quote-expire.php';
    }

    public function process_payment( $order_id ) {
        if ( !empty( $_POST['is_payment_aborted']) && $_POST['is_payment_aborted'] === 'true' ) {
            throw new Exception( __( 'Payment aborted.', 'airwallex-online-payments-gateway' ));
        }
        $result = [];
        try {
            $deviceData = isset($_POST['airwallex_device_data']) ? json_decode(wc_clean(wp_unslash($_POST['airwallex_device_data']))) : [];
            $targetCurrency = isset($_POST['airwallex_target_currency']) ? wc_clean(wp_unslash($_POST['airwallex_target_currency'])) : get_woocommerce_currency();
            $availableCurrency = $this->getAvailableCurrencies();

            $order = wc_get_order( $order_id );
            if ( empty( $order ) ) {
				$this->logService->debug(__METHOD__ . ' can not find order', [ 'orderId' => $order_id ] );
				throw new Exception( 'Order not found: ' . $order_id );
			}

            $airwallexCustomerId = null;
			if ( $order->get_customer_id( '' ) ) {
				$airwallexCustomerId = $this->orderService->getAirwallexCustomerId( get_current_user_id(), $this->gatewayClient );
			}

            $this->logService->debug(__METHOD__ . ' create payment intent', [ 'orderId' => $order_id ] );
            $paymentMethodType = empty(static::GATEWAY_ID) ? 'woo_commerce' : 'woo_commerce_' . static::GATEWAY_ID;

            $paymentIntent   = $this->gatewayClient->createPaymentIntent( $order->get_total(), $order->get_id(), true, $airwallexCustomerId, $paymentMethodType );
            $this->logService->debug(__METHOD__ . ' payment intent created', [ 'payment intent' => $paymentIntent->toArray() ] );

            $this->logService->debug(__METHOD__ . ' confirm payment intent', [ 'payment intent id' => $paymentIntent->getId() ] );
            $confirmPayload = [
                'device_data' => $deviceData,
                'payment_method' => $this->getPaymentMethod($order, $paymentIntent->getId()),
                'payment_method_options' => $this->getPaymentMethodOptions(),
            ];

            if ($targetCurrency !== $paymentIntent->getBaseCurrency() && false !== array_search($targetCurrency, $availableCurrency)) {
                $this->logService->debug(__METHOD__ . ' - Create quote for ' . $targetCurrency );
                $quote = $this->gatewayClient->createQuoteForCurrencySwitching($paymentIntent->getBaseCurrency(), $targetCurrency, $paymentIntent->getBaseAmount());
                $confirmPayload['currency_switcher'] = [
                    'target_currency' => $targetCurrency,
                    'quote_id' => $quote->getId(),
                ];
                $order->update_meta_data( '_tmp_airwallex_payment_client_rate', $quote->getClientRate() );
            }

            $confirmedIntent = $this->gatewayClient->confirmPaymentIntent($paymentIntent->getId(), $confirmPayload);
            $this->logService->debug(__METHOD__ . ' payment intent confirmed', [ 'payment intent' => $confirmedIntent ] );

            $nextAction = $confirmedIntent->getNextAction();
            if (isset($nextAction['type']) && 'redirect' === $nextAction['type']) {
                $result = [
                    'result' => 'success',
                    'redirect' => $nextAction['url'],
                ];
            } else {
                throw new Exception('Not redirect payment method.');
            }

            WC()->session->set( 'airwallex_order', $order_id );
            WC()->session->set( 'airwallex_payment_intent_id', $paymentIntent->getId() );
			$order->update_meta_data( '_tmp_airwallex_payment_intent', $paymentIntent->getId() );
			$order->save();
        } catch (Exception $e) {
            $this->logService->error(__METHOD__ . ' Some went wrong during checkout.', $e->getMessage());
            RemoteLog::error( $e->getMessage(), RemoteLog::ON_PAYMENT_CONFIRMATION_ERROR);
			$errorJson = json_decode($e->getMessage(), true);
			if (json_last_error() === JSON_ERROR_NONE && !empty($errorJson['data']['message'])) {
				throw new Exception(esc_html__($errorJson['data']['message'], 'airwallex-online-payments-gateway'));
			}            
            $result = [
                'result' => 'failed',
                'message' => $e->getMessage(),
            ];
            wc_add_notice($e->getMessage(), 'error');
        }

        return $result;
	}

    public function getBillingDetail($order) {
        $billing = [];
        if ( $order->has_billing_address() ) {
            $address     = [
                'city'         => $order->get_billing_city(),
                'country_code' => $order->get_billing_country(),
                'postcode'     => $order->get_billing_postcode(),
                'state'        => $order->get_billing_state() ? $order->get_billing_state() : $order->get_shipping_state(),
                'street'       => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
            ];
            $billing = [
                'first_name'   => $order->get_billing_first_name(),
                'last_name'    => $order->get_billing_last_name(),
                'email'        => $order->get_billing_email(),
                'phone_number' => $order->get_billing_phone(),
            ];
            if ( ! empty( $address['city'] ) && ! empty( $address['country_code'] ) && ! empty( $address['street'] ) ) {
                $billing['address'] = $address;
            }
        }

        return $billing;
    }

    abstract public function getPaymentMethod($order, $paymentIntentId);

    abstract public function getPaymentMethodOptions();
}
