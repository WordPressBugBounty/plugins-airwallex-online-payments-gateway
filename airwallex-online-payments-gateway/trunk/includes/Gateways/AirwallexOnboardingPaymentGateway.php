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
	public $id = 'awx_onboarding_gateway';
	public $enabled = 'yes';

	public function __construct() {
		$this->enabled = $this->get_option('enabled', 'yes');
	}

	public function is_test_mode() {
		return Util::getEnvironment() === 'demo';
	}

	public function is_in_test_mode() {
		return $this->is_test_mode();
	}

	public function is_dev_mode() {
		return false;
	}

	public function is_in_dev_mode() {
		return $this->is_dev_mode();
	}

	public function is_test_mode_onboarding() {
		return Util::getEnvironment() === 'demo';
	}

	public function is_in_test_mode_onboarding() {
		return $this->is_test_mode_onboarding();
	}

	public function get_settings_url() {
		return get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' );
	}

	public function get_connection_url($return_url = '') {
		return get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' );
	}
	
	public function get_onboarding_url() {
		return get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' );
	}

	public function is_onboarding_started() {
		return $this->is_account_connected();
	}

	public function needs_setup() {
		return !Util::getApiKey() || !Util::getClientId();
	}

	public function is_account_connected() {
		return $this->isConnected();
	}

	public function is_onboarding_completed() {
		if ( ! $this->is_onboarding_started() ) {
			return false;
		}

		return $this->is_account_connected();
	}
}
