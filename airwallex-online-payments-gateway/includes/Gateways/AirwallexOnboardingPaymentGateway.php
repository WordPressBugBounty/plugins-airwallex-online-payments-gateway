<?php

namespace Airwallex\Gateways;

use Airwallex\Gateways\Settings\AirwallexSettingsTrait;
use WC_Payment_Gateway;
use Airwallex\Services\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AirwallexOnboardingPaymentGateway extends WC_Payment_Gateway {
	use AirwallexSettingsTrait;

	public $method_title = 'Airwallex';
	public $method_description = 'Accept 160+ payment methods including cards, Apple Pay, Alipay, and Klarna to reach more customers globally.';
	public $icon = AIRWALLEX_PLUGIN_URL . '/assets/images/airwallex.svg';
	public $isConnected = null;

	public function __construct() {
		if ($this->isConnected === null) {
			$this->isConnected = $this->isConnected();
		}
		$this->enabled = $this->isConnected;
	}

	public function get_settings_url() {
		return get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' );
	}
	
	public function get_onboarding_url() {
		return get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' );
	}
	
	public function is_enabled() {
		return $this->isConnected;
	}

	public function needs_setup() {
		return !Util::getApiKey();
	}

	public function is_account_connected() {
		return $this->isConnected;
	}
}
