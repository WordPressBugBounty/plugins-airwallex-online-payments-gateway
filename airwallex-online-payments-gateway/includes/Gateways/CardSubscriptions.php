<?php

namespace Airwallex\Gateways;

use Airwallex\Client\CardClient;
use Airwallex\Services\LogService;
use Airwallex\Struct\PaymentIntent;
use Exception;
use WC_Order;
use WC_Subscription;
use WC_Subscriptions_Cart;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CardSubscriptions extends Card {
	public function __construct() {
		parent::__construct();
	
		$this->supports = array_merge(
			$this->supports,
			array(
				'subscriptions',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes',
				'multiple_subscriptions',
				'subscription_payment_method_change_admin',
				'subscription_payment_method_change',
				'subscription_payment_method_change_customer',
			)
		);

		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'do_subscription_payment' ), 10, 2 );
			add_filter( 'woocommerce_my_subscriptions_payment_method', array( $this, 'subscription_payment_information' ), 10, 2 );
			add_filter( 'airwallexMustSaveCard', array( $this, 'mustSaveCard' ) );
			add_filter( 'woocommerce_subscription_payment_meta', [ $this, 'add_subscription_payment_meta' ], 10, 2 );
			add_action( 'woocommerce_subscription_validate_payment_meta', [ $this, 'validate_subscription_payment_meta' ], 10, 2 );
			add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array( $this, 'update_failing_payment_method' ), 10, 2 );
		}
	}

	/**
	 * @param WC_Subscription $subscription
	 * @param WC_Order        $order
	 */
	public function update_failing_payment_method( $subscription, $order ) {
		$subscription->update_meta_data( 'airwallex_consent_id', $order->get_meta( 'airwallex_consent_id', true ) );
		$subscription->update_meta_data( 'airwallex_customer_id', $order->get_meta( 'airwallex_customer_id', true ) );
		$subscription->save();
	}

	public function add_subscription_payment_meta( $paymentMeta, $subscription ) {
		$subscription->read_meta_data( true );
		$paymentMeta[ $this->id ] = [
			'post_meta' => [
				'airwallex_customer_id' => [
					'value' => $subscription->get_meta( 'airwallex_customer_id', true ),
					'label' => 'Airwallex Customer ID',
				],
				'airwallex_consent_id'   => [
					'value' => $subscription->get_meta( 'airwallex_consent_id', true ),
					'label' => 'Airwallex Payment Consent ID',
				],
			],
		];

		return $paymentMeta;
	}

	public function validate_subscription_payment_meta( $paymentMethodId, $paymentMethodData ) {
		if ( $paymentMethodId === $this->id ) {
            if ( empty( $paymentMethodData['post_meta']['airwallex_customer_id']['value'] ) ) {
                throw new Exception( __('"Airwallex Customer ID" is required.', 'airwallex-online-payments-gateway') );
			}
            if ( empty( $paymentMethodData['post_meta']['airwallex_consent_id']['value'] ) ) {
                throw new Exception( __('"Airwallex Payment Consent ID" is required.', 'airwallex-online-payments-gateway') );
			}
			$paymentConsent  = (new CardClient())->getPaymentConsent(
				$paymentMethodData['post_meta']['airwallex_consent_id']['value']
			);
			if ( empty($paymentConsent->getStatus()) || $paymentConsent->getStatus() !== 'VERIFIED' ) {
				throw new Exception( __("Invalid Airwallex Payment Consent.", 'airwallex-online-payments-gateway') );
			}
			if ( $paymentConsent->getCustomerId() !== $paymentMethodData['post_meta']['airwallex_customer_id']['value'] ) {
				throw new Exception( __('The provided "Airwallex Customer ID" does not match the associated "Airwallex Payment Consent ID".', 'airwallex-online-payments-gateway') );
			}
		}
	}

	public function mustSaveCard( $mustSaveCard ) {
		if ( WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return true;
		}

		return $mustSaveCard;
	}

	public function subscription_payment_information( $paymentMethodName, $subscription ) {
		$customerId = $subscription->get_customer_id();
		if ( $subscription->get_payment_method() !== $this->id || ! $customerId ) {
			return $paymentMethodName;
		}
		//add additional payment details
		return $paymentMethodName;
	}
}
