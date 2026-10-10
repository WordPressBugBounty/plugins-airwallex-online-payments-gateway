<?php

namespace Airwallex\Controllers;

use Airwallex\Services\LogService;
use Airwallex\Services\Util;
use Exception;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Config\ApplePay\StartPaymentSession;

if (!defined('ABSPATH')) {
	exit;
}

class PaymentSessionController {
	const CONFIGURATION_ERROR = 'configuration_error';

	protected $cardClient;

	public function __construct() {
	}

	public function startPaymentSession() {
		check_ajax_referer('wc-airwallex-express-checkout-start-payment-session', 'security');

		if ( ! $this->hasActiveCart() ) {
			$this->sendFailure( 'No active cart.' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() recursively sanitizes the value, but the sniff doesn't recognize it.
		$validationURL = isset($_POST['validationURL']) ? wc_clean(wp_unslash($_POST['validationURL'])) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() recursively sanitizes the value, but the sniff doesn't recognize it.
		$origin        = isset($_POST['origin']) ? wc_clean(wp_unslash($_POST['origin'])) : '';

		if ( ! Util::isStoreHost( $origin ) ) {
			$this->sendFailure( 'Invalid payment session origin.' );
		}

		if ( ! Util::isApplePayValidationUrl( $validationURL ) ) {
			$this->sendFailure( 'Invalid payment session validation URL.' );
		}

		LogService::getInstance()->debug(__METHOD__ . " - Start payment session for {$origin} with {$validationURL}.");
		try {
			$paymentSession = (new StartPaymentSession())->setInitiativeParams([
				'validation_url' => $validationURL,
				'initiative_context' => $origin,
			])->send();
			
			LogService::getInstance()->debug(__METHOD__ . ' - Payment session started.');

			wp_send_json([
				'success' => true,
				'paymentSession' => json_decode($paymentSession, true),
			]);
		} catch (Exception $e) {
			$this->sendFailure( $e->getMessage() );
		}
	}

	/**
	 * @param string $reason
	 */
	private function sendFailure( $reason ) {
		LogService::getInstance()->error( __METHOD__ . ' - Start payment session failed.', $reason );
		wp_send_json([
			'success' => false,
			'error' => [
				'message' => __( 'Failed to complete payment. Please try again.', 'airwallex-online-payments-gateway' ),
			],
		]);
	}

	/**
	 * @return bool
	 */
	private function hasActiveCart() {
		return function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty();
	}
}
