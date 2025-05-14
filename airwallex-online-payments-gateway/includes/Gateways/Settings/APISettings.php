<?php

namespace Airwallex\Gateways\Settings;

use Airwallex\Controllers\AirwallexController;
use Airwallex\Gateways\Settings\AbstractAirwallexSettings;
use Airwallex\Main;
use Airwallex\Services\Util;
use WC_AJAX;
use Airwallex\Gateways\Card;
use Airwallex\Controllers\ConnectionFlowController;

if (!defined('ABSPATH')) {
	exit;
}

class APISettings extends AbstractAirwallexSettings {
	const ID = 'airwallex_general';
	const CONNECTION_FAILED_HELP_LINK = 'https://www.airwallex.com/docs/payments__plugins__woocommerce__install-the-woocommerce-plugin#configure-api-settings-and-webhooks';

	public function __construct() {
		$this->id          = self::ID;
		$this->tabTitle    = __('API Settings', 'airwallex-online-payments-gateway');
		$this->customTitle = __('Airwallex - API Settings', 'airwallex-online-payments-gateway');

		parent::__construct();
	}

	public function hooks() {
		parent::hooks();
		add_action('woocommerce_update_options_checkout_' . $this->id, array($this, 'process_admin_options'));
		add_filter('wc_airwallex_settings_nav_tabs', array($this, 'adminNavTab'), 10);
		add_action('woocommerce_airwallex_settings_checkout_' . $this->id, array($this, 'admin_options'));
		add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
		add_action('wc_ajax_airwallex_connection_test', [new AirwallexController(), 'connectionTest']);
		add_action('wc_ajax_airwallex_connection_click', [new AirwallexController(), 'connectionClick']);
		add_action('wc_ajax_airwallex_start_connection_flow', [new ConnectionFlowController(), 'startConnection']);
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'account_not_connected_alter' => [
				'type' => 'alert',
				'display' => false,
				'alert_type' => 'warning',
				'title' => __('Activate your Airwallex plug-in', 'airwallex-online-payments-gateway'),
				'text' => __('Before you can receive payments with Airwallex, you need to connect your Airwallex account.', 'airwallex-online-payments-gateway'),
				'class' => 'wc-airwallex-connection-alert wc-airwallex-account-not-connected',
				'showDismiss' => false,
			],
			'demo_account_not_connected_alter' => [
				'type' => 'alert',
				'display' => false,
				'alert_type' => 'warning',
				'title' => __('Connect your Airwallex demo account', 'airwallex-online-payments-gateway'),
				'text' => __('Before you can receive test payments with Airwallex, you need to connect your Airwallex account.', 'airwallex-online-payments-gateway'),
				'class' => 'wc-airwallex-connection-alert wc-airwallex-demo-account-not-connected',
				'showDismiss' => false,
			],
			'account_connected_alter' => [
				'type' => 'alert',
				'display' => false,
				'alert_type' => 'success',
				'title' => __('Your Airwallex plug-in is activated', 'airwallex-online-payments-gateway'),
				'text' => __('You can also manage which account is connected to your WooCommerce store.', 'airwallex-online-payments-gateway'),
				'class' => 'wc-airwallex-connection-alert wc-airwallex-account-connected',
				'showDismiss' => true,
			],
			'demo_account_connected_alter' => [
				'type' => 'alert',
				'display' => true,
				'alert_type' => 'info',
				'title' => __('You are connected to a demo account', 'airwallex-online-payments-gateway'),
				'text' => __('Before you can receive test payments with Airwallex, you need to connect your Airwallex demo account.', 'airwallex-online-payments-gateway'),
				'class' => 'wc-airwallex-connection-alert wc-airwallex-demo-account-connected',
				'showDismiss' => true,
			],
			'enable_sandbox'                       => array(
				'title'   => __( 'Enable sandbox', 'airwallex-online-payments-gateway' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'id'      => 'airwallex_enable_sandbox',
				'value'   => get_option( 'airwallex_enable_sandbox' ),
				'class' => 'wc-airwallex-sandbox',
			),
			'connection_failed_alter' => [
				'type' => 'alert',
				'display' => true,
				'alert_type' => 'critical',
				'title' => __('Connect using your client ID and API key', 'airwallex-online-payments-gateway'),
				'text' => sprintf(
					/* translators: Placeholder 1: Open link tag. Placeholder 2: Close link tag */
					__('We were unable to connect the Airwallex account as the business information of the account does not match this WooCommerce store. You can still connect the account using its unique client ID and API key, or connect a different account. %1$sLearn more%2$s', 'airwallex-online-payments-gateway'),
					'<a href="' . self::CONNECTION_FAILED_HELP_LINK . '" target="_blank">',
					'</a>'
                ),
				'class' => 'wc-airwallex-connection-alert wc-airwallex-connection-failed',
				'showDismiss' => true,
			],
			'connect_airwallex'                     => array(
				'title'   => __( 'Connected Airwallex account', 'airwallex-online-payments-gateway' ),
				'type'    => 'connect_airwallex',
				'default' => '',
				'id'      => 'connect_airwallex_button',
				'class' => 'wc-airwallex-connect-button button-secondary',
			),
			'client_id' => array(
				'title' => __('Unique Client ID', 'airwallex-online-payments-gateway'),
				'type' => 'text',
				'description' => '',
				'default' => Util::getClientId(),
				'id' => 'airwallex_client_id',
				'value' => get_option('airwallex_client_id'),
			),
			'api_key' => array(
				'title' => __('API Key', 'airwallex-online-payments-gateway'),
				'type' => 'password',
				'description' => '',
				'default' => Util::getApiKey(),
				'id' => 'airwallex_api_key',
				'value' => get_option('airwallex_api_key'),
			),
			'webhook_secret' => array(
				'title' => __('Webhook Secret', 'airwallex-online-payments-gateway'),
				'type' => 'password',
				'description' => __('Webhook URL:', 'airwallex-online-payments-gateway') . WC()->api_request_url( Main::ROUTE_SLUG_WEBHOOK ),
				'default' => Util::getWebhookSecret(),
				'id' => 'airwallex_webhook_secret',
				'value' => get_option('airwallex_webhook_secret'),
			),
			'connect_via_api_key' => array(
				'title' => '',
				'type' => 'api_key_connect_buttons',
				'description' => '',
				'default' => '',
				'id' => 'api_key_connect_buttons',
				'value' => '',
				'class' => 'button-secondary'
			),
			'temporary_order_status_after_decline' => array(
				'title'   => __( 'Temporary order status after decline during checkout', 'airwallex-online-payments-gateway' ),
				'id'      => 'airwallex_temporary_order_status_after_decline',
				'type'    => 'select',
				'description'    => __( 'This order status is set, when the payment has been declined and the customer redirected to the checkout page to try again.', 'airwallex-online-payments-gateway' ),
				'options' => array(
					'pending' => _x( 'Pending payment', 'Order status', 'airwallex-online-payments-gateway' ),
					'failed'  => _x( 'Failed', 'Order status', 'airwallex-online-payments-gateway' ),
				),
				'value'   => get_option( 'airwallex_temporary_order_status_after_decline' ),
			),
			'order_status_pending'                 => array(
				'title'   => __( 'Order state for pending payments', 'airwallex-online-payments-gateway' ),
				'id'      => 'airwallex_order_status_pending',
				'type'    => 'select',
				'description'    => __( 'Certain local payment methods have asynchronous payment confirmations that can take up to a few days. Card payments are always instant.', 'airwallex-online-payments-gateway' ),
				'options' => array_merge( array( '' => __( '[Do not change status]', 'airwallex-online-payments-gateway' ) ), wc_get_order_statuses() ),
				'value'   => get_option( 'airwallex_order_status_pending' ),
			),
			'order_status_authorized'              => array(
				'title'   => __( 'Order state for authorized payments', 'airwallex-online-payments-gateway' ),
				'id'      => 'airwallex_order_status_authorized',
				'type'    => 'select',
				'description'    => __( 'Status for orders that are authorized but not captured', 'airwallex-online-payments-gateway' ),
				'options' => array_merge( array( '' => __( '[Do not change status]', 'airwallex-online-payments-gateway' ) ), wc_get_order_statuses() ),
				'value'   => get_option( 'airwallex_order_status_authorized' ),
			),
			'cronjob_interval'                     => array(
				'title'   => __( 'Cronjob interval', 'airwallex-online-payments-gateway' ),
				'id'      => 'airwallex_cronjob_interval',
				'type'    => 'select',
				'description'    => '',
				'options' => array(
					'3600'  => __( 'Every hour (recommended)', 'airwallex-online-payments-gateway' ),
					'14400' => __( 'Every 4 hours', 'airwallex-online-payments-gateway' ),
					'28800' => __( 'Every 8 hours', 'airwallex-online-payments-gateway' ),
					'43200' => __( 'Every 12 hours', 'airwallex-online-payments-gateway' ),
				),
				'value'   => get_option( 'airwallex_cronjob_interval' ),
			),
			'do_js_logging'                        => array(
				'title'   => __( 'Activate JS logging', 'airwallex-online-payments-gateway' ),
				'description'    => __( 'Yes (only for special cases after contacting Airwallex)', 'airwallex-online-payments-gateway' ),
				'type'    => 'checkbox',
				'default' => '',
				'id'      => 'airwallex_do_js_logging',
				'value'   => get_option( 'airwallex_do_js_logging' ),
			),
			'do_remote_logging'                    => array(
				'title'   => __( 'Activate remote logging', 'airwallex-online-payments-gateway' ),
				'description'    => __( 'Send diagnostic data to Airwallex', 'airwallex-online-payments-gateway' ) . '<br/><small>' . __( 'Help Airwallex easily resolve your issues and improve your experience by automatically sending diagnostic data. Diagnostic data may include order details.', 'airwallex-online-payments-gateway' ) . '</small>',
				'type'    => 'checkbox',
				'default' => '',
				'id'      => 'airwallex_do_remote_logging',
				'value'   => get_option( 'airwallex_do_remote_logging' ),
			),
			'payment_page_template'                => array(
				'title'   => __( 'Payment form template', 'airwallex-online-payments-gateway' ),
				'id'      => 'airwallex_payment_page_template',
				'type'    => 'select',
				'description'    => '',
				'options' => array(
					'default'        => __( 'Default', 'airwallex-online-payments-gateway' ),
					'wordpress_page' => __( 'WordPress page shortcodes', 'airwallex-online-payments-gateway' ),
				),
				'value'   => get_option( 'airwallex_payment_page_template' ),
				'default' => Util::isNewClient() ? 'wordpress_page' : 'default',
			),
			'payment_descriptor'  => array(
				'title' => __( 'Statement descriptor', 'airwallex-online-payments-gateway' ),
				'type' => 'text',
				'custom_attributes' => array(
					'maxlength' => 28,
				),
				/* translators: Placeholder 1: Order number. */
				'description' => __( 'Descriptor that will be displayed to the customer. For example, in customer\'s credit card statement. Use %order% as a placeholder for the order\'s ID.', 'airwallex-online-payments-gateway' ),
				'value' => get_option( 'airwallex_payment_descriptor'),
				'default' => Card::getDescriptorSetting(),
			),
		);
	}

	public function generate_alert_html($key, $data) {
		$data = wp_parse_args(
			$data,
			array(
				'display' => 'none',
				'alert_type' => 'info',
				'title' => '',
				'id' => '',
				'class' => '',
			)
		);
		ob_start();

		$awxAlertAdditionalClass = $data['class'];
		$awxAlterShowDismiss = $data['showDismiss'];
		$awxAlterDisplay = $data['display'];
		$awxAlertType = $data['alert_type'];
		$awxAlertText = $data['text'];
		$awxAlertTitle = $data['title'];

		include AIRWALLEX_PLUGIN_PATH . 'templates/airwallex-alert-box.php';

		return ob_get_clean();
	}

	public function generate_connect_airwallex_html($key, $data) {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'text'        => '',
				'class'       => '',
				'style'       => '',
				'desc'        => '',
				'desc_tip'    => false,
				'id'          => 'wc-airwallex-button_' . $key,
				'disabled'    => false,
				'css'         => '',
				'showDismiss' => false,
				'label'       => __('Connect Account', 'airwallex-online-payments-gateway'),
			)
		);
		ob_start();

		include AIRWALLEX_PLUGIN_PATH . 'includes/Gateways/Settings/views/connected_account.php';

		return ob_get_clean();
	}

	public function generate_api_key_connect_buttons_html($key, $data) {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'text'        => '',
				'class'       => '',
				'style'       => '',
				'desc'        => '',
				'desc_tip'    => false,
				'id'          => 'wc-airwallex-button_' . $key,
				'disabled'    => false,
				'css'         => '',
				'showDismiss' => false,
				'label_via_api_key' => __('Connect with API key', 'airwallex-online-payments-gateway'),
				'label_via_connection_flow' => __('Connect via Airwallex log-in', 'airwallex-online-payments-gateway'),
				'label_cancel' => __('Cancel', 'airwallex-online-payments-gateway'),
			)
		);
		ob_start();

		include AIRWALLEX_PLUGIN_PATH . 'includes/Gateways/Settings/views/api_key_connect_buttons.php';

		return ob_get_clean();
	}

	public function init_settings() {
		parent::init_settings();

		// make it compatible with the old approach
		foreach ($this->settings as $key => $value) {
			$this->settings[$key] = get_option('airwallex_' . $key, $value);
		}
	}

	public function process_admin_options() {
		parent::process_admin_options();

		// make it compatible with the old approach
		foreach ($this->settings as $key => $value) {
			update_option('airwallex_' . $key, $value, 'yes');
		}
	}

	public function enqueueAdminScripts() {
		$this->enqueueAdminSettingsScripts();
		wp_add_inline_script(
			'airwallex-admin-settings',
			'var awxAdminSettings = ' . wp_json_encode($this->getExpressCheckoutSettingsScriptData()),
			'before'
		);
		wp_add_inline_script(
			'airwallex-admin-settings',
			'var awxAdminECSettings = "";',
			'before'
		);
	}

	public function getExpressCheckoutSettingsScriptData() {
		return [
			'apiSettings' => [
				'env' => Util::getEnvironment(),
				'connected' => $this->isConnected(),
				'nonce' => [
					'connectionTest' => wp_create_nonce('wc-airwallex-admin-settings-connection-test'),
					'connectionClick' => wp_create_nonce('wc-airwallex-admin-settings-connection-click'),
					'startConnectionFlow' => wp_create_nonce('wc-airwallex-admin-settings-start-connection-flow'),
				],
				'ajaxUrl' => [
					'connectionTest' => WC_AJAX::get_endpoint('airwallex_connection_test'),
					'connectionClick' => WC_AJAX::get_endpoint('airwallex_connection_click'),
					'startConnectionFlow' => WC_AJAX::get_endpoint('airwallex_start_connection_flow'),
				],
				'accountName' => [
					'demo' => Util::getAccountName('demo'),
					'prod' => Util::getAccountName('prod'),
				],
				'connectButtonText' => [
					'connect' => __('Connect Account', 'airwallex-online-payments-gateway'),
					'manage' => __('Manage', 'airwallex-online-payments-gateway'),
				],
				'connectionFailed' => $this->isConnectionFailed(),
				'connectionClicked' => [
					'demo' => get_option('airwallex_connection_clicked_demo') ?: 'no',
					'prod' => get_option('airwallex_connection_clicked_prod') ?: 'no',
				],
				'connectedViaConnectionFlow' => Util::isConnectedViaConnectionFlow(),
				'connectedViaApiKey' => Util::isConnectedViaApiKey(),
			],
		];
	}

	private function isConnectionFailed() {
		return isset($_GET['error']) && 'connection_failed' === wc_clean($_GET['error']);
	}
}
