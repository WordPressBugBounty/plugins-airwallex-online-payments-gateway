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
