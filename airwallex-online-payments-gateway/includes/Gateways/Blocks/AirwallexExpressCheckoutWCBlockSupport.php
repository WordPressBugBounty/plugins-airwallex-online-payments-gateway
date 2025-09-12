<?php

namespace Airwallex\Gateways\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Airwallex\Client\ApplePayClient;
use Airwallex\Services\OrderService;
use Airwallex\Client\CardClient;
use Airwallex\Client\GatewayClient;
use Airwallex\Services\Util;
use Airwallex\Gateways\ExpressCheckout;
use Airwallex\Services\LogService;
use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheManager;

class AirwallexExpressCheckoutWCBlockSupport extends AirwallexWCBlockSupport {

	protected $name                  = 'airwallex_express_checkout';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings              = get_option( 'airwallex-online-payments-gatewayairwallex_card_settings', array() );
		$this->enabled               = ! empty( $this->settings['enabled'] ) && in_array( $this->settings['enabled'], array( 'yes', 1, true, '1' ), true ) ? 'yes' : 'no';
		$cardClient                  = CardClient::getInstance();
		$applePayClient              = ApplePayClient::getInstance();
		$gatewayClient               = GatewayClient::getInstance();
		$cacheService                = CacheManager::getInstance();
		$orderService                = OrderService::getInstance();
		$this->gateway               = new ExpressCheckout();
	}

	/**
	 * Enqueues the style needed for the payment block.
	 *
	 * @return void
	 */
	public function enqueue_style() {
		if (!is_checkout() && !is_cart()) {
            return;
        }

		wp_enqueue_style('airwallex-css');
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$this->enqueue_style();

		$dependencies = ( function_exists('is_login') && is_login() ) || is_admin() ? ['jquery'] : ['jquery', 'jquery-blockui'];
		wp_register_script(
			'airwallex-wc-ec-blocks-integration',
			AIRWALLEX_PLUGIN_URL . '/build/airwallex-wc-ec-blocks.min.js',
			$dependencies,
			AIRWALLEX_VERSION,
			true
		);

		return array( 'airwallex-wc-ec-blocks-integration' );
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method only in the admin section.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles_for_admin() {
		$this->enqueue_style();

		wp_register_script(
			'airwallex-wc-ec-blocks-integration',
			AIRWALLEX_PLUGIN_URL . '/build/airwallex-wc-ec-blocks.min.js',
			['jquery'],
			AIRWALLEX_VERSION,
			true
		);

		return array( 'airwallex-wc-ec-blocks-integration' );
	}

	/**
	 * Returns an associative array of data to be exposed for the payment method's client side.
	 */
	public function get_payment_method_data() {
		$shouldDisplay = $this->shouldDisplay();
		$data = $this->gateway->getExpressCheckoutScriptData(true);
		if (isset($data['googlePayEnabled'])) {
			$data['googlePayEnabled'] = $data['googlePayEnabled'] && $shouldDisplay;
		}
		if (isset($data['applePayEnabled'])) {
			$data['applePayEnabled'] = $data['applePayEnabled'] && $shouldDisplay;
		}
		return $data;
	}

	public function shouldDisplay() {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();

		if (!isset($gateways['airwallex_card']) || !isset($gateways['airwallex_express_checkout'])) {
			return false;
		}

		if (empty($this->gateway->get_option('payment_methods'))) {
			return false;
		}

		if (!Util::getClientId() || !Util::getApiKey()) {
			LogService::getInstance()->debug('API Key and client secret are not set correctly.');
			return false;
		}

		if (!is_ssl()) {
			LogService::getInstance()->debug('Airwallex Express Checkout requires SSL.');
			return false;
		}

		// Don't show on the cart or checkout page if items in the cart are not supported.
		if (
			$this->gateway->isCartOrCheckout()
			&& !$this->gateway->isCartItemsAllowed()
		) {
			return false;
		}

		// Don't show on checkout if disabled.
		if (is_checkout()) {
			return $this->gateway->shouldShowButtonOnPage('checkout');
		}

		// Don't show on cart if disabled.
		if (is_cart()) {
			return $this->gateway->shouldShowButtonOnPage('cart');
		}

		return true;
	}
}
