<?php

namespace Airwallex;

use Airwallex\Gateways\Blocks\AirwallexAfterpayWCBlockSupport;
use Airwallex\Gateways\Card;
use Airwallex\Gateways\GatewayFactory;
use Airwallex\Gateways\Main as MainGateway;
use Airwallex\Gateways\WeChat;
use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheManager;
use Airwallex\Services\CacheService;
use Airwallex\Services\LogService;
use Airwallex\Services\OrderService;
use Airwallex\Gateways\Blocks\AirwallexCardWCBlockSupport;
use Airwallex\Gateways\Blocks\AirwallexMainWCBlockSupport;
use Airwallex\Gateways\Blocks\AirwallexWeChatWCBlockSupport;
use Airwallex\Controllers\AirwallexController;
use Airwallex\Controllers\ConnectionFlowController;
use Airwallex\Gateways\AirwallexOnboardingPaymentGateway;
use Airwallex\Gateways\Blocks\AirwallexExpressCheckoutWCBlockSupport;
use Airwallex\Gateways\ExpressCheckout;
use Airwallex\Gateways\Settings\AdminSettings;
use Airwallex\Gateways\Settings\APISettings;
use Airwallex\Services\Util;
use Airwallex\Gateways\Blocks\AirwallexKlarnaWCBlockSupport;
use Airwallex\Gateways\Klarna;
use Airwallex\Gateways\Afterpay;
use Exception;
use WC_Order;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Airwallex\Controllers\ControllerFactory;
use Airwallex\Gateways\Blocks\AirwallexPOSWCBlockSupport;
use Airwallex\Gateways\POS;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Retrieve as RetrievePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent as StructPaymentIntent;

class Main {

	const ROUTE_SLUG_CONFIRMATION = 'airwallex_payment_confirmation';
	const ROUTE_SLUG_WEBHOOK      = 'airwallex_webhook';

	const OPTION_KEY_MERCHANT_COUNTRY = 'airwallex_merchant_country';

	const AWX_PAGE_ID_CACHE_KEY = 'airwallex_page_ids';

	public static $instance;

	public static function getInstance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function getInstanceKey() {
		return md5( AUTH_KEY );
	}

	public function init() {
		$this->registerEvents();
		$this->registerOrderStatus();
		$this->registerCron();
		$this->registerExpressCheckoutButtons();
		$this->noticeApiKeyMissing();
		$this->registerAjax();
	}

	public function registerAjax() {
		add_action('wc_ajax_airwallex_currency_switcher_create_quote', [ControllerFactory::createQuoteController(), 'createQuoteForCurrencySwitching']);
		add_action('wc_ajax_airwallex_get_store_currency', [ControllerFactory::createOrderController(), 'getStoreCurrency']);
		add_action('wc_ajax_airwallex_get_tokens', function(){ Card::getInstance()->getTokens(); });
		add_action('wc_ajax_airwallex_get_pos_terminals', function(){ POS::getInstance()->getPOSTerminals(); });
		add_action('wc_ajax_airwallex_get_apm_redirect_data', function(){ MainGateway::getInstance()->getApmRedirectData(); });
		add_action('wc_ajax_airwallex_get_apm_data', function(){ MainGateway::getInstance()->getApmData(); });
		add_action('wc_ajax_airwallex_get_card_redirect_data', function(){ Card::getInstance()->getCardRedirectData(); });
		add_action('wc_ajax_airwallex_get_card_data', function(){ Card::getInstance()->getCardData(); });
		add_action('wc_ajax_airwallex_get_wechat_redirect_data', function(){ WeChat::getInstance()->getWechatRedirectData(); });
		add_action('wc_ajax_airwallex_get_express_checkout_data', function(){ ExpressCheckout::getInstance()->getExpressCheckoutData(); });
		add_action('wc_ajax_airwallex_sync_all_consents', [ControllerFactory::createPaymentConsentController(), 'syncAllConsents']);
		add_action('wc_ajax_airwallex_get_customer_client_secret', function(){ Card::getInstance()->getCustomerClientSecret(); });
		add_action('wc_ajax_airwallex_connection_test', [ControllerFactory::createAirwallexController(), 'connectionTest']);
		add_action('wc_ajax_airwallex_connection_click', [ControllerFactory::createAirwallexController(), 'connectionClick']);
		add_action('wc_ajax_airwallex_start_connection_flow', [ControllerFactory::createConnectionFlowController(), 'startConnection']);
		add_action('wc_ajax_airwallex_is_payment_method_enabled', [ControllerFactory::createAirwallexController(), 'isPaymentMethodEnabled']);
		add_action('wc_ajax_airwallex_get_cart_details', [ControllerFactory::createOrderController(), 'getCartDetails']);
		add_action('wc_ajax_airwallex_get_shipping_options', [ControllerFactory::createOrderController(), 'getShippingOptions']);
		add_action('wc_ajax_airwallex_update_shipping_method', [ControllerFactory::createOrderController(), 'updateShippingMethod']);
		add_action('wc_ajax_airwallex_create_order', [ControllerFactory::createOrderController(), 'createOrderFromCart']);
		add_action('wc_ajax_airwallex_add_to_cart', [ControllerFactory::createOrderController(), 'addToCart']);
		add_action('wc_ajax_airwallex_start_payment_session', [ControllerFactory::createPaymentSessionController(), 'startPaymentSession']);
		add_action('wc_ajax_airwallex_activate_payment_method', [ControllerFactory::createGatewaySettingsController(), 'activatePaymentMethod']);
		add_action('wc_ajax_airwallex_get_estimated_cart_details', [ControllerFactory::createOrderController(), 'getEstimatedCartDetail']);
		add_action('wc_ajax_airwallex_update_order_status_after_payment_decline', [ControllerFactory::createOrderController(), 'updateOrderStatusAfterPaymentDecline']);
	}

	public function registerEvents() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'addPaymentGateways' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handleStatusChange' ), 10, 4 );
		add_action( 'woocommerce_api_' . Card::ROUTE_SLUG, array( new AirwallexController(), 'cardPayment' ) );
		add_action( 'woocommerce_api_' . MainGateway::ROUTE_SLUG, array( new AirwallexController(), 'dropInPayment' ) );
		add_action( 'woocommerce_api_' . WeChat::ROUTE_SLUG, array( new AirwallexController(), 'weChatPayment' ) );
		add_action( 'woocommerce_api_' . self::ROUTE_SLUG_CONFIRMATION, array( new AirwallexController(), 'paymentConfirmation' ) );
		add_action( 'woocommerce_api_' . self::ROUTE_SLUG_WEBHOOK, array( new AirwallexController(), 'webhook' ) );
		add_action( 'woocommerce_api_airwallex_process_order_pay', [new AirwallexController(), 'processOrderPay'] );
		add_action( 'woocommerce_api_airwallex_connection_callback', [new ConnectionFlowController(), 'connectionCallback'] );
		add_action( 'woocommerce_api_airwallex_account_settings', [new ConnectionFlowController(), 'saveAccountSetting'] );
		add_filter( 'plugin_action_links_' . plugin_basename( AIRWALLEX_PLUGIN_PATH . AIRWALLEX_PLUGIN_NAME . '.php' ), array( $this, 'addPluginSettingsLink' ) );
		add_action( 'airwallex_check_pending_transactions', array( $this, 'checkPendingTransactions' ) );
		add_action( 'requests-requests.before_request', array( $this, 'modifyRequestsForLogging' ), 10, 5 );
		add_action( 'wp_loaded', array( $this, 'createPages' ) );
		add_action(
			'wp_loaded',
			function () {
				add_shortcode( 'airwallex_payment_method_card', array( Card::getInstance(), 'output' ) );
				add_shortcode( 'airwallex_payment_method_wechat', array( WeChat::getInstance(), 'output' ) );
				add_shortcode( 'airwallex_payment_method_all', array( MainGateway::getInstance(), 'output' ) );
			}
		);
		add_filter( 'display_post_states', array( $this, 'addDisplayPostStates' ), 10, 2 );
		if ( ! is_admin() ) {
			add_filter( 'wp_get_nav_menu_items', array( $this, 'excludePagesFromMenu' ), 10, 3 );
			add_filter( 'wp_list_pages_excludes', array( $this, 'excludePagesFromList' ), 10, 1 );
		}
		add_action( 'woocommerce_blocks_loaded', array( $this, 'woocommerceBlockSupport' ) );
		add_action( 'woocommerce_init', [AdminSettings::class, 'init'] );
		add_action( 'woocommerce_init', [$this, 'initAPISettings'] );
		add_filter( 'woocommerce_available_payment_gateways', [$this, 'disableGatewayOrderPay'] );
		add_action( 'wp_enqueue_scripts', [$this, 'registerScripts'], 1 );
		add_action( 'wp_enqueue_scripts', [$this, 'enqueueScripts'] );
		add_action( 'admin_enqueue_scripts', [$this, 'enqueueAdminScripts'] );
		add_action( 'woocommerce_review_order_after_order_total', [$this, 'renderCurrencySwitchingHtml'] );
	}

	/**
	 * Render the currency switching box to display the original amount and the converted amount
	 */
	public function renderCurrencySwitchingHtml() {
		include_once AIRWALLEX_PLUGIN_PATH . 'templates/airwallex-currency-switching.php';
	}

	public function noticeApiKeyMissing() {
		$clientId = Util::getClientId();
		$apiKey   = Util::getApiKey();

		if ( $clientId && $apiKey ) {
			return;
		}

		add_action(
			'admin_notices',
			function () {
				printf(
					/* translators: Placeholder 1: Opening div and strong tag. Placeholder 2: Close strong tag and insert new line. Placeholder 3: Open link tag. Placeholder 4: Close link and div tag. */
					esc_html__(
						'%1$sTo start using Airwallex payment methods, please connect your account first.%2$s %3$sAPI Settings%4$s',
						'airwallex-online-payments-gateway'
					),
					'<div class="notice notice-error is-dismissible" style="padding:12px 12px"><strong>',
					'</strong><br />',
					'<a class="button-primary" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' ) ) . '">',
					'</a></div>'
				);
			}
		);
	}

	public function modifyRequestsForLogging( $url, $headers, $data, $type, &$options ) {
		if ( ! $options['blocking'] && strpos( $url, 'airwallex' ) ) {
			if ( class_exists('\WpOrg\Requests\Transport\Fsockopen') ) {
				$options['transport'] = '\WpOrg\Requests\Transport\Fsockopen';
			} else {
				$options['transport'] = 'Requests_Transport_fsockopen';
			}
		}
	}

	protected function registerOrderStatus() {

		add_filter(
			'init',
			function () {
				register_post_status(
					'airwallex-issue',
					array(
						'label'                     => __( 'Airwallex Issue', 'airwallex-online-payments-gateway' ),
						'public'                    => true,
						'exclude_from_search'       => false,
						'show_in_admin_all_list'    => true,
						'show_in_admin_status_list' => true,
					)
				);
				register_post_status(
					'wc-airwallex-pending',
					array(
						'label'                     => __( 'Airwallex Pending', 'airwallex-online-payments-gateway' ),
						'public'                    => true,
						'exclude_from_search'       => false,
						'show_in_admin_all_list'    => true,
						'show_in_admin_status_list' => true,
					)
				);
			}
		);

		add_filter(
			'wc_order_statuses',
			function ( $statusList ) {
				$statusList['wc-airwallex-pending'] = __( 'Airwallex Pending', 'airwallex-online-payments-gateway' );
				$statusList['airwallex-issue']      = __( 'Airwallex Issue', 'airwallex-online-payments-gateway' );
				return $statusList;
			}
		);
	}

	protected function registerCron() {
		$interval = (int) get_option( 'airwallex_cronjob_interval' );
		$interval = ( $interval < 3600 ) ? 3600 : $interval;
		add_action(
			'init',
			function () use ( $interval ) {
				if ( function_exists( 'as_schedule_cron_action' ) ) {
					if ( ! as_next_scheduled_action( 'airwallex_check_pending_transactions' ) ) {
						as_schedule_recurring_action(
							strtotime( 'midnight tonight' ),
							$interval,
							'airwallex_check_pending_transactions'
						);
					}
				}
			}
		);
	}

	/**
	 * Exclude airwallex payment pages from menu
	 *
	 * @param  array  $items An array of menu item post objects.
	 * @return array  Menu item list exclude airwallex payment pages
	 */
	public function excludePagesFromMenu( $items ) {
		$cacheService   = CacheManager::getInstance();
		$excludePageIds = explode( ',', (string) $cacheService->get( self::AWX_PAGE_ID_CACHE_KEY ) );
		foreach ( $items as $key => $item ) {
			if ( in_array( strval( $item->object_id ), $excludePageIds, true ) ) {
				unset( $items[ $key ] );
			}
		}

		return $items;
	}

	/**
	 * Exclude airwallex payment pages from default menu
	 *
	 * @param  array $exclude_array An array of page IDs to exclude.
	 * @return array Page list exclude airwallex payment pages
	 */
	public function excludePagesFromList( $excludeArray ) {
		$cacheService   = CacheService::getInstance();
		$excludePageIds = explode( ',', (string) $cacheService->get( self::AWX_PAGE_ID_CACHE_KEY ) );
		if ( is_array( $excludePageIds ) ) {
			$excludeArray += $excludePageIds;
		}

		return $excludeArray;
	}

	/**
	 * Create pages that the plugin relies on, storing page IDs in variables.
	 */
	public function createPages() {
		// Set the locale to the store locale to ensure pages are created in the correct language.
		wc_switch_to_site_locale();

		include_once WC()->plugin_path() . '/includes/admin/wc-admin-functions.php';

		$cardShortcode   = 'airwallex_payment_method_card';
		$wechatShortcode = 'airwallex_payment_method_wechat';
		$allShortcode    = 'airwallex_payment_method_all';

		$pages = array(
			'payment_method_card'   => array(
				'name'    => _x( 'airwallex_payment_method_card', 'Page slug', 'airwallex-online-payments-gateway' ),
				'title'   => _x( 'Payment', 'Page title', 'airwallex-online-payments-gateway' ),
				'content' => '<!-- wp:shortcode -->[' . $cardShortcode . ']<!-- /wp:shortcode -->',
			),
			'payment_method_wechat' => array(
				'name'    => _x( 'airwallex_payment_method_wechat', 'Page slug', 'airwallex-online-payments-gateway' ),
				'title'   => _x( 'Payment', 'Page title', 'airwallex-online-payments-gateway' ),
				'content' => '<!-- wp:shortcode -->[' . $wechatShortcode . ']<!-- /wp:shortcode -->',
			),
			'payment_method_all'    => array(
				'name'    => _x( 'airwallex_payment_method_all', 'Page slug', 'airwallex-online-payments-gateway' ),
				'title'   => _x( 'Payment', 'Page title', 'airwallex-online-payments-gateway' ),
				'content' => '<!-- wp:shortcode -->[' . $allShortcode . ']<!-- /wp:shortcode -->',
			),
		);

		$pageIds = array();
		foreach ( $pages as $key => $page ) {
			$pageIds[] = wc_create_page(
				esc_sql( $page['name'] ),
				'airwallex_' . $key . '_page_id',
				$page['title'],
				$page['content']
			);
		}

		$pageIdStr    = implode( ',', $pageIds );
		$cacheService = CacheService::getInstance();
		if ( $cacheService->get( self::AWX_PAGE_ID_CACHE_KEY ) !== $pageIdStr ) {
			$cacheService->set( self::AWX_PAGE_ID_CACHE_KEY, $pageIdStr, 0 );
		}

		// Restore the locale to the default locale.
		wc_restore_locale();
	}

	/**
	 * Add a post display state for special Airwallex pages in the page list table.
	 *
	 * @param array   $post_states An array of post display states.
	 * @param WP_Post $post        The current post object.
	 */
	public function addDisplayPostStates( $post_states, $post ) {
		if ( get_option( 'airwallex_payment_method_card_page_id' ) === strval( $post->ID ) ) {
			$post_states['awx_page_for_card_method'] = __( 'Airwallex - Cards', 'airwallex-online-payments-gateway' );
		} elseif ( get_option( 'airwallex_payment_method_wechat_page_id' ) === strval( $post->ID ) ) {
			$post_states['awx_page_for_wechat_method'] = __( 'Airwallex - WeChat Pay', 'airwallex-online-payments-gateway' );
		} elseif ( get_option( 'airwallex_payment_method_all_page_id' ) === strval( $post->ID ) ) {
			$post_states['awx_page_for_all_method'] = __( 'Airwallex - All Payment Methods', 'airwallex-online-payments-gateway' );
		}

		return $post_states;
	}

	public function addPluginSettingsLink( $links ) {
		$settingsLink = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' ) . '">' . __( 'Airwallex API settings', 'airwallex-online-payments-gateway' ) . '</a>';
		array_unshift( $links, $settingsLink );
		return $links;
	}

	public function addPaymentGateways( $gateways ) {
		if (!empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/wc-admin/settings/payments/providers') !== false) {
			$gateways[] = AirwallexOnboardingPaymentGateway::class;
			return $gateways;
		}
		$gateways[] = MainGateway::class;
		$gateways[] = Card::class;
		$gateways[] = WeChat::class;
		$gateways[] = ExpressCheckout::class;
		$gateways[] = POS::class;
		if (!is_wc_endpoint_url('order-pay')) {
			$gateways[] = Klarna::class;
			$gateways[] = Afterpay::class;
		}
		$gateways[] = AirwallexOnboardingPaymentGateway::class;

		return $gateways;
	}

	/**
	 * Handle order status change
	 *
	 * @param $orderId
	 * @param $statusFrom
	 * @param $statusTo
	 * @param WC_Order $order
	 */
	public function handleStatusChange( $orderId, $statusFrom, $statusTo, $order ) {
		$this->handleStatusChangeForCard( $statusTo, $order );
	}

	public function checkPendingTransactions() {
		( new OrderService() )->checkPendingTransactions();
	}

	/**
	 * Handle order status change for card payment
	 *
	 * @param $statusTo
	 * @param WC_Order $order
	 */
	private function handleStatusChangeForCard( $statusTo, $order ) {
		$cardGateway = Card::getInstance();
		$paymentIntentId = $order->get_meta(OrderService::META_KEY_INTENT_ID);
		if ( empty( $paymentIntentId ) ) {
			return;
		}
		try {
			/** @var StructPaymentIntent $paymentIntent */
			$paymentIntent = (new RetrievePaymentIntent())->setPaymentIntentId($paymentIntentId)->send();
		} catch (Exception $e) {
			LogService::getInstance()->error(__METHOD__ . ' fetch intent failed: ' . $e->getMessage());
			return;
		}

		if ( $order->get_payment_method() !== $cardGateway->id
			&& $order->get_payment_method() !== ExpressCheckout::GATEWAY_ID 
			&& !in_array($paymentIntent->getPaymentMethodType(), ['card', 'googlepay', 'applepay'], true)) {
			return;
		}

		if ( $cardGateway->is_capture_immediately() ) {
			return;
		}

		if ( $statusTo === $cardGateway->get_option( 'capture_trigger_order_status' ) || 'wc-' . $statusTo === $cardGateway->get_option( 'capture_trigger_order_status' ) ) {
			try {
				if ( ! $cardGateway->is_captured( $order ) ) {
					$cardGateway->capture( $order );
				} else {
					LogService::getInstance()->debug( 'skip capture by status change because order is already captured', [
						'order_id' => $order->get_id(),
						'transaction_id' => $order->get_transaction_id(),
					] );
				}
			} catch ( Exception $e ) {
				LogService::getInstance()->error( 'capture by status error', $e->getMessage() );
				$order->add_order_note( 'ERROR: ' . $e->getMessage() );
			}
		}
	}

	public function registerScripts() {
		// register all the scripts and styles
		$awxHost = Util::getCheckoutUIEnvHost( Util::getEnvironment() );
		wp_register_script(
			'airwallex-lib-js',
			$awxHost . '/assets/elements.bundle.min.js',
			[],
			gmdate('Ymd'),
			true
		);
		wp_register_script(
			'airwallex-common-js',
			AIRWALLEX_PLUGIN_URL . '/assets/js/airwallex-local.js',
			['jquery'],
			AIRWALLEX_VERSION,
			true
		);
		wp_register_script(
			'airwallex-lpm-js', 
			AIRWALLEX_PLUGIN_URL . '/build/airwallex-lpm.min.js', 
			['airwallex-common-js', 'airwallex-lib-js'],
			AIRWALLEX_VERSION,
			true
		);
		wp_register_script(
			'airwallex-card-js',
			AIRWALLEX_PLUGIN_URL . '/build/airwallex-card.min.js',
			['airwallex-common-js', 'airwallex-lib-js'],
			AIRWALLEX_VERSION,
			true
		);
		wp_register_script(
			'airwallex-apm-js',
			AIRWALLEX_PLUGIN_URL . '/build/airwallex-apm.min.js',
			['airwallex-common-js', 'airwallex-lib-js'],
			AIRWALLEX_VERSION,
			true
		);
		wp_register_script(
			'airwallex-redirect-js',
			AIRWALLEX_PLUGIN_URL . '/build/airwallex-redirect.min.js',
			['airwallex-common-js', 'airwallex-lib-js'],
			AIRWALLEX_VERSION,
			true
		);
		wp_register_script(
			'airwallex-express-checkout',
			AIRWALLEX_PLUGIN_URL . '/build/airwallex-express-checkout.min.js',
			['airwallex-lib-js', 'jquery'],
			AIRWALLEX_VERSION,
			true
		);
		wp_register_script(
			'airwallex-js-logging-js',
			AIRWALLEX_PLUGIN_URL . '/assets/js/jsnlog.js',
			[],
			AIRWALLEX_VERSION,
			false
		);

		wp_register_style(
			'airwallex-css',
			AIRWALLEX_PLUGIN_URL . '/assets/css/airwallex-checkout.css',
			[],
			AIRWALLEX_VERSION
		);
		wp_register_style(
			'airwallex-redirect-element-css',
			AIRWALLEX_PLUGIN_URL . '/assets/css/airwallex.css',
			[],
			AIRWALLEX_VERSION
		);
		wp_register_style(
			'airwallex-block-css',
			AIRWALLEX_PLUGIN_URL . '/assets/css/airwallex-checkout-blocks.css',
			[],
			AIRWALLEX_VERSION
		);
	}

	public function enqueueScripts() {
		$confirmationUrl  = WC()->api_request_url( self::ROUTE_SLUG_CONFIRMATION );
		$commonScriptData = [
			'env' => Util::getEnvironment(),
			'locale' => Util::getLocale(),
			'isOrderPayPage'  => is_wc_endpoint_url( 'order-pay' ),
		];
		if ( isset( $_GET['pay_for_order'] ) && 'true' === $_GET['pay_for_order'] ) {
			global $wp;
			$order_id = (int) $wp->query_vars['order-pay'];
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( is_a( $order, 'WC_Order' ) ) {
					$confirmationUrl .= ( strpos( $confirmationUrl, '?' ) === false ) ? '?' : '&';
					$confirmationUrl .= 'order_id=' . $order_id;
					$orderKey = isset($_GET['key']) ? wc_clean(wp_unslash( $_GET['key'] )) : '';
					$orderPayUrl = WC()->api_request_url('airwallex_process_order_pay');
					$orderPayUrl .= ( strpos( $orderPayUrl, '?' ) === false ) ? '?' : '&';
					$orderPayUrl .= 'order_id=' . $order_id . '&key=' . $orderKey;
					$commonScriptData['processOrderPayUrl'] = $orderPayUrl;
					$commonScriptData['billingFirstName'] = $order->get_billing_first_name();
					$commonScriptData['billingLastName'] = $order->get_billing_last_name();
					$commonScriptData['billingAddress1'] = $order->get_billing_address_1();
					$commonScriptData['billingAddress2'] = $order->get_billing_address_2();
					$commonScriptData['billingState'] = $order->get_billing_state();
					$commonScriptData['billingCity'] = $order->get_billing_city();
					$commonScriptData['billingPostcode'] = $order->get_billing_postcode();
					$commonScriptData['billingCountry'] = $order->get_billing_country();
					$commonScriptData['billingEmail'] = $order->get_billing_email();
				}
			}
		}
		$commonScriptData['confirmationUrl'] = $confirmationUrl;
		$commonScriptData['getApmRedirectData']['url'] = \WC_AJAX::get_endpoint('airwallex_get_apm_redirect_data');
		$commonScriptData['getApmRedirectData']['nonce'] = wp_create_nonce('wc-airwallex-get-apm-redirect-data');
		$commonScriptData['getApmData']['url'] = \WC_AJAX::get_endpoint('airwallex_get_apm_data');
		$commonScriptData['getApmData']['nonce'] = wp_create_nonce('wc-airwallex-get-apm-data');
		$commonScriptData['updateOrderStatusAfterPaymentDecline']['url'] = \WC_AJAX::get_endpoint('airwallex_update_order_status_after_payment_decline');
		$commonScriptData['updateOrderStatusAfterPaymentDecline']['nonce'] = wp_create_nonce('wc-airwallex-update-order-status-after-payment-decline');
		$commonScriptData['getCardRedirectData']['url'] = \WC_AJAX::get_endpoint('airwallex_get_card_redirect_data');
		$commonScriptData['getCardRedirectData']['nonce'] = wp_create_nonce('wc-airwallex-get-card-redirect-data');
		$commonScriptData['getCardData']['url'] = \WC_AJAX::get_endpoint('airwallex_get_card_data');
		$commonScriptData['getCardData']['nonce'] = wp_create_nonce('wc-airwallex-get-card-data');
		$commonScriptData['getWechatRedirectData']['url'] = \WC_AJAX::get_endpoint('airwallex_get_wechat_redirect_data');
		$commonScriptData['getWechatRedirectData']['nonce'] = wp_create_nonce('wc-airwallex-get-wechat-redirect-data');
		$commonScriptData['getExpressCheckoutData']['url'] = \WC_AJAX::get_endpoint('airwallex_get_express_checkout_data');
		$commonScriptData['getExpressCheckoutData']['nonce'] = wp_create_nonce('wc-airwallex-get-express-checkout-data');
		$commonScriptData['getExpressCheckoutData']['isProductPage'] = is_product() || wc_post_content_has_shortcode('product_page');
		$commonScriptData['getExpressCheckoutData']['checkout'] = ExpressCheckout::getInstance()->getCheckoutDetail();
		$commonScriptData['getExpressCheckoutData']['hasSubscriptionProduct'] = ExpressCheckout::getInstance()->hasSubscriptionProduct();
		wp_add_inline_script( 'airwallex-common-js', 'var awxCommonData=' . wp_json_encode($commonScriptData), 'before' );
	}

	public function enqueueAdminScripts() {
		wp_register_script(
			'airwallex-admin-settings',
			AIRWALLEX_PLUGIN_URL . '/assets/js/admin/airwallex-admin-settings.js',
			['jquery'],
			AIRWALLEX_VERSION,
			true
		);
		
		wp_register_style(
			'airwallex-admin-css',
			AIRWALLEX_PLUGIN_URL . '/assets/css/airwallex-checkout-admin.css',
			[],
			AIRWALLEX_VERSION
		);
	}

	public function woocommerceBlockSupport() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new AirwallexMainWCBlockSupport() );
					$payment_method_registry->register( new AirwallexCardWCBlockSupport() );
					$payment_method_registry->register( new AirwallexWeChatWCBlockSupport() );
					$payment_method_registry->register( new AirwallexExpressCheckoutWCBlockSupport() );
					$payment_method_registry->register( new AirwallexKlarnaWCBlockSupport() );
					$payment_method_registry->register( new AirwallexAfterpayWCBlockSupport() );
					$payment_method_registry->register( new AirwallexPOSWCBlockSupport() );
				}
			);
		}
	}

	public function registerExpressCheckoutButtons() {
		$displayExpressCheckoutButtonSeparatorHtml = function() {
			GatewayFactory::create( ExpressCheckout::class )->displayExpressCheckoutButtonSeparatorHtml();
		};
		add_action( 'woocommerce_after_add_to_cart_quantity', $displayExpressCheckoutButtonSeparatorHtml, 4 );
		add_action( 'woocommerce_proceed_to_checkout', $displayExpressCheckoutButtonSeparatorHtml, 4 );
		add_action( 'woocommerce_checkout_before_customer_details', $displayExpressCheckoutButtonSeparatorHtml, 4 );

		$displayExpressCheckoutButtonHtml = function() {
			GatewayFactory::create( ExpressCheckout::class )->displayExpressCheckoutButtonHtml();
		};
		add_action( 'woocommerce_after_add_to_cart_quantity', $displayExpressCheckoutButtonHtml, 3 );
		add_action( 'woocommerce_proceed_to_checkout', $displayExpressCheckoutButtonHtml, 3 );
		add_action( 'woocommerce_checkout_before_customer_details', $displayExpressCheckoutButtonHtml, 3 );
	}

	public function disableGatewayOrderPay($available_gateways) {
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			unset( $available_gateways['airwallex_express_checkout'] );
		}

		return $available_gateways;
	}

	public function initAPISettings() {
		new APISettings();
	}
}
