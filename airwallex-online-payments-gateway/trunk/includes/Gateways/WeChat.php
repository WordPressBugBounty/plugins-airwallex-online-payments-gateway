<?php

namespace Airwallex\Gateways;

use Airwallex\Services\LogService;
use Airwallex\Struct\Refund;
use Airwallex\Client\WeChatClient;
use Airwallex\Gateways\Settings\AirwallexSettingsTrait;
use Airwallex\Services\OrderService;
use Exception;
use WC_Payment_Gateway;
use WP_Error;
use Airwallex\Services\Util;
use Airwallex\Struct\PaymentIntent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WeChat extends WC_Payment_Gateway {

	use AirwallexGatewayTrait;
	use AirwallexSettingsTrait;

	const ROUTE_SLUG = 'airwallex_wechat';
	const GATEWAY_ID = 'airwallex_wechat';

	public $method_title       = 'Airwallex - WeChat Pay';
	public $method_description = '';
	public $title              = 'Airwallex - WeChat Pay';
	public $description        = '';
	public $icon               = '';
	public $id                 = self::GATEWAY_ID;
	public $plugin_id;
	public $supports = array(
		'products',
		'refunds',
	);
	public $logService;
	public static $instance;

	public function __construct() {
		$this->plugin_id = AIRWALLEX_PLUGIN_NAME;
		$this->init_settings();
		$this->description = $this->get_option( 'description' );
		if ( Util::getClientId() && Util::getApiKey() ) {
			$this->method_description = __( 'Accept only WeChat Pay payments with your Airwallex account.', 'airwallex-online-payments-gateway' );
			$this->form_fields        = $this->get_form_fields();
		}
		$this->title      = $this->get_option( 'title' );
		$this->logService = LogService::getInstance();
		$this->tabTitle   = 'WeChat Pay';
		$this->registerHooks();
	}

	public function registerHooks() {
		add_filter( 'wc_airwallex_settings_nav_tabs', array( $this, 'adminNavTab' ), 13 );
		add_action( 'woocommerce_airwallex_settings_checkout_' . $this->id, array( $this, 'enqueueAdminScripts' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function enqueueScripts() {
		wp_enqueue_script('airwallex-redirect-js');
	}

	public function enqueueAdminScripts() {
	}

	public function get_form_fields() {
		return apply_filters( // phpcs:ignore
			'wc_airwallex_settings', // phpcs:ignore
			array(

				'enabled'     => array(
					'title'       => __( 'Enable/Disable', 'airwallex-online-payments-gateway' ),
					'label'       => __( 'Enable Airwallex WeChat Pay', 'airwallex-online-payments-gateway' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no',
				),
				'title'       => array(
					'title'       => __( 'Title', 'airwallex-online-payments-gateway' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'airwallex-online-payments-gateway' ),
					'default'     => __( 'WeChat Pay', 'airwallex-online-payments-gateway' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Description', 'airwallex-online-payments-gateway' ),
					'type'        => 'text',
					'description' => __( 'This controls the description which the user sees during checkout.', 'airwallex-online-payments-gateway' ),
					'default'     => '',
					'desc_tip'    => true,
				),
			)
		);
	}

	public function getWechatRedirectData() {
		check_ajax_referer('wc-airwallex-get-wechat-redirect-data', 'security');

		$client = WeChatClient::getInstance();
		$order = $this->getOrderFromRequest('Main::getApmRedirectData');
		$orderId = $order->get_id();
		$paymentIntentId = $order->get_meta(OrderService::META_KEY_INTENT_ID);
		$paymentIntent             = $client->getPaymentIntent( $paymentIntentId );
		$paymentIntentClientSecret = $paymentIntent->getClientSecret();

		$airwallexElementConfiguration = [
			'intent' => [
				'id' => $paymentIntentId,
				'client_secret' => $paymentIntentClientSecret
			],
		];
		$airwallexRedirectElScriptData = [
			'elementType' => 'wechat',
			'elementOptions' => $airwallexElementConfiguration,
			'containerId' => 'airwallex-wechat',
			'orderId' => $orderId,
			'paymentIntentId' => $paymentIntentId,
		];

		wp_send_json([
			'success' => true,
			'data' => $airwallexRedirectElScriptData,
		]);
	}

	public function process_payment( $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			if ( empty( $order ) ) {
				$this->logService->debug( __METHOD__ . ' - can not find order', array( 'orderId' => $order_id ) );
				throw new Exception( 'Order not found: ' . $order_id );
			}

			$apiClient           = WeChatClient::getInstance();
			$this->logService->debug( __METHOD__ . ' - before create intent', array( 'orderId' => $order_id ) );
			$paymentIntent             = $apiClient->createPaymentIntent( $order->get_total(), $order->get_id(), $this->is_submit_order_details(), null, 'woo_commerce_wechat' );
			if ( in_array($paymentIntent->getStatus(), PaymentIntent::SUCCESS_STATUSES, true) ) {
				return [
					'result' => 'success',
					'redirect' => $order->get_checkout_order_received_url(),
				];
			}
			$this->logService->debug(
				__METHOD__ . ' - payment intent created ',
				array(
					'paymentIntent' => $paymentIntent,
					'session'  => array(
						'cookie' => WC()->session->get_session_cookie(),
						'data'   => WC()->session->get_session_data(),
					),
				),
				LogService::WECHAT_ELEMENT_TYPE
			);

			WC()->session->set( 'airwallex_order', $order_id );
			WC()->session->set( 'airwallex_payment_intent_id', $paymentIntent->getId() );
			$order->update_meta_data( OrderService::META_KEY_INTENT_ID, $paymentIntent->getId() );
			$order->save();

			$redirectUrl = $this->get_payment_url( 'airwallex_payment_method_wechat' );
			$redirectUrl .= ( strpos( $redirectUrl, '?' ) === false ) ? '?' : '&';
			$redirectUrl .= 'order_id=' . $order_id;
			return [
				'result'   => 'success',
				'redirect' => $redirectUrl,
			];
		} catch ( Exception $e ) {
			$this->logService->error( 'Drop in payment action failed', $e->getMessage(), LogService::CARD_ELEMENT_TYPE );
			$errorJson = json_decode($e->getMessage(), true);
			if (json_last_error() === JSON_ERROR_NONE && !empty($errorJson['data']['message'])) {
				throw new Exception(esc_html__($errorJson['data']['message'], 'airwallex-online-payments-gateway'));
			}
			throw new Exception( esc_html__( 'Airwallex payment error', 'airwallex-online-payments-gateway' ) );
		}
	}

	public function output( $attrs ) {
		if ( is_admin() || empty( WC()->session ) ) {
			$this->logService->debug( 'Update wechat payment shortcode.', array(), LogService::WECHAT_ELEMENT_TYPE );
			return;
		}

		$shortcodeAtts = shortcode_atts(
			array(
				'style' => '',
				'class' => '',
			),
			$attrs,
			'airwallex_payment_method_wechat'
		);

		try {
			$order = $this->getOrderFromRequest('WeChat::output');
			$orderId = $order->get_id();

			$paymentIntentId = $order->get_meta(OrderService::META_KEY_INTENT_ID);
			$apiClient                 = WeChatClient::getInstance();
			$paymentIntent             = $apiClient->getPaymentIntent( $paymentIntentId );
			$paymentIntentClientSecret = $paymentIntent->getClientSecret();
			$confirmationUrl           = $this->get_payment_confirmation_url($orderId, $paymentIntentId);
			$isSandbox                 = $this->is_sandbox();

			$this->logService->debug(
				__METHOD__ . ' - Redirect to the wechat payment page',
				array(
					'orderId'       => $orderId,
					'paymentIntent' => $paymentIntentId,
				),
				LogService::WECHAT_ELEMENT_TYPE
			);

			$this->enqueueScripts();
			
			ob_start();
			include AIRWALLEX_PLUGIN_PATH . '/html/wechat-shortcode.php';
			return ob_get_clean();
		} catch ( Exception $e ) {
			$this->logService->error(__METHOD__ . ' - Wechat payment page redirect failed', $e->getMessage(), LogService::WECHAT_ELEMENT_TYPE );
			wc_add_notice( __( 'Airwallex payment error', 'airwallex-online-payments-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			die;
		}
	}

	public static function getMetaData() {
		$settings = self::getSettings();

		$data = [
			'enabled' => isset($settings['enabled']) ? $settings['enabled'] : 'no',
		];

		return $data;
	}
}
