<?php

namespace Airwallex\Controllers;

if (!defined('ABSPATH')) {
	exit;
}

use Airwallex\Gateways\AirwallexGatewayTrait;
use Airwallex\Services\OrderService;
use Airwallex\Gateways\Card;
use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheManager;

class PaymentConsentController {

	use AirwallexGatewayTrait;

	protected $cacheService;
	protected $orderService;

	public function __construct() {
		$this->cacheService = CacheManager::getInstance();
		$this->orderService = OrderService::getInstance();
	}

	public function syncAllConsents() {
		if ( ! is_user_logged_in() || ! current_user_can('administrator') || !function_exists('wcs_get_subscriptions') ) {
			wp_send_json_error(['message' => 'Access denied.']);
			return;
		}

		set_time_limit(0);
		$paged = 1;
		$maxPages = 1000;
		$done = [];
		while ($paged <= $maxPages) {
			$subscriptions = wcs_get_subscriptions(['subscriptions_per_page' => 100, 'paged' => $paged]);
			if (count($subscriptions) === 0) {
				break;
			}
			$paged++;

			foreach ($subscriptions as $subscription) {
				$wpUserId = $subscription->get_user_id();
				$airwallexCustomerId = $subscription->get_meta( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID, true );
				if (empty($airwallexCustomerId)) continue;
				$doneKey = "$wpUserId-$airwallexCustomerId";
				if (isset($done[$doneKey])) continue;
				Card::getInstance()->syncSaveCards($airwallexCustomerId, $wpUserId);
				$done[$doneKey] = true;
			}
		}
		wp_send_json([
			'success' => true,
		]);
	}
}
