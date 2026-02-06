<?php
namespace Airwallex\Gateways\Blocks;

use Airwallex\Gateways\POS;
use Airwallex\Gateways\GatewayFactory;
use Airwallex\Services\LogService;

defined( 'ABSPATH' ) || exit();

class AirwallexPOSWCBlockSupport extends AirwallexWCBlockSupport {
    protected $name = 'airwallex_pos';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->gateway = POS::getInstance();
		$this->settings = get_option( 'airwallex-online-payments-gatewayairwallex_pos_settings', array() );
		$this->enabled  = ! empty( $this->settings['enabled'] ) && in_array( $this->settings['enabled'], array( 'yes', 1, true, '1' ), true ) ? 'yes' : 'no';
	}

	/**
	 * Returns an associative array of data to be exposed for the payment method's client side.
	 */
	public function get_payment_method_data() {
		return [
			'enabled'     => $this->is_active(),
			'name'        => $this->name,
			'title'       => $this->settings['title'] ?? '',
			'description' => $this->settings['description'],
			'supports'    => $this->get_supported_features(),
			'icon'        => $this->gateway->getIcon(),
			'paymentMethodName' => $this->gateway->paymentMethodName
		];
	}
}
