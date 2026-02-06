<?php

namespace Airwallex\Gateways;

use Airwallex\Controllers\ControllerFactory;
use Airwallex\Gateways\Settings\AirwallexSettingsTrait;
use Airwallex\Services\ServiceFactory;
use Airwallex\Services\Util;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

abstract class AbstractAirwallexGateway extends WC_Payment_Gateway {
	use AirwallexSettingsTrait;
	use AirwallexGatewayTrait;

	const PAYMENT_METHOD_TYPE_CACHE_KEY = 'paymentMethodTypes';
	const CURRENCY_SETTINGS_CACHE_KEY = 'currencySettings';

	protected $logService;
	protected $cacheService;
	protected $orderService;
	protected $quoteController;
	protected $orderController;
	public $paymentMethodType;
	public $paymentMethodName;

	public function __construct() {
		$this->logService = ServiceFactory::createLogService();
		$this->cacheService = ServiceFactory::createCacheService(Util::getClientId());
		$this->orderService = ServiceFactory::createOrderService();
		$this->quoteController = ControllerFactory::createQuoteController();
		$this->orderController = ControllerFactory::createOrderController();

		$this->plugin_id   = AIRWALLEX_PLUGIN_NAME;
		$this->init_settings();
		$this->enabled = 'yes' === $this->enabled ? 'yes' : 'no';
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->registerHooks();
	}

	public function registerHooks() {
		add_filter( 'wc_airwallex_settings_nav_tabs', array( $this, 'adminNavTab' ), 14 );
		add_action( 'woocommerce_airwallex_settings_checkout_' . $this->id, array( $this, 'enqueueAdminScripts' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function needs_setup() {
		return true;
	}

	public function isAvailable() {
		$activePaymentMethodTypeNames = $this->getActivePaymentMethodTypeNames();
		return in_array($this->paymentMethodType, $activePaymentMethodTypeNames, true) ? 'yes' : 'no';
	}
}
