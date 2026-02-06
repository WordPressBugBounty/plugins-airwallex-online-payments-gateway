<?php

namespace Airwallex\Controllers;

if (!defined('ABSPATH')) {
	exit;
}

use Airwallex\Services\LogService;
use Airwallex\Gateways\AirwallexGatewayTrait;
use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheManager;
use Exception;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Config\ApplePay\Domain\GetList as GetApplePayRegisteredDomains;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Config\ApplePay\Domain\AddItems as RegisterNewDomainForApplePay;

class GatewaySettingsController {
	use AirwallexGatewayTrait;

	const CONFIGURATION_ERROR = 'configuration_error';
	const PAYMENT_METHOD_NOT_ACTIVATED = 'payment_method_not_activated';
	const DOMAIN_FILE_UPLOAD_ERROR = 'domain_file_upload_error';
	const DOMAIN_REGISTRATION_ERROR = 'domain_registration_error';

	protected $cacheService;

	public function __construct() {
		$this->cacheService = CacheManager::getInstance();
	}

	public function activatePaymentMethod() {
		check_ajax_referer('wc-airwallex-admin-settings-activate-payment-method', 'security');

		$paymentMethodType = isset($_POST['payment_method_type']) ? wc_clean(wp_unslash($_POST['payment_method_type'])) : '';
		$domain = isset($_POST['domain_name']) ? wc_clean(wp_unslash($_POST['domain_name'])) : '';
		$domain = ! empty( $domain ) ? $domain : ( isset($_SERVER['SERVER_NAME']) ? wc_clean(wp_unslash($_SERVER['SERVER_NAME'])) : '' );
		
		LogService::getInstance()->debug(__METHOD__ . " - Activate payment method {$paymentMethodType} for {$domain}.");
		$result = ['success' => true];
		try {
			$activePaymentMethodTypeNames = $this->getActivePaymentMethodTypeNames();
			if (!in_array($paymentMethodType, $activePaymentMethodTypeNames, true)) {
				throw new Exception("Payment method type {$paymentMethodType} is not activated.");
			}
		} catch (Exception $e) {
			LogService::getInstance()->error(__METHOD__ . " Failed to activate payment method {$paymentMethodType}.", $e->getMessage());
			$result['success'] = false;
			$result['error'] = [
				'code' => self::PAYMENT_METHOD_NOT_ACTIVATED,
				'message' => __('Payment Method is not activated.', 'airwallex-online-payments-gateway'),
			];
		}

		if ($result['success'] && 'applepay' === $paymentMethodType) {
			$result = $this->registerDomain($domain);
		}

		wp_send_json($result);
	}

	private function registerDomain($domain) {
		try {
			LogService::getInstance()->debug(__METHOD__ . " - Register domain for {$domain}.");

			$registeredDomains = (new GetApplePayRegisteredDomains())->send();
			if (in_array($domain, $registeredDomains->getItems())) {
				LogService::getInstance()->debug(__METHOD__ . " - Domain {$domain} already registered.");
				return ['success' => true];
			}

			$result = $this->addDomainFileToServerRoot($domain);
			if ($result['success']) {
				LogService::getInstance()->debug(__METHOD__ . " - Register domain with Airwallex for {$domain}.");
				$domains = (new RegisterNewDomainForApplePay())->setItems([$domain])->send();
				if (empty($domains) || !in_array($domain, $domains->getItems())) {
					throw new Exception(__('Failed to register the domain with Airwallex.', 'airwallex-online-payments-gateway'));
				}
			}

			return $result;
		} catch (Exception $e) {
			LogService::getInstance()->error(__METHOD__ . ' - Failed to register domain .', $e->getMessage());
			return [
				'success' => false,
				'error' => [
					'code' => self::DOMAIN_REGISTRATION_ERROR,
					'message' => __('Failed to register the domain with Airwallex.', 'airwallex-online-payments-gateway'),
				],
			];
		}
	}

	private function addDomainFileToServerRoot($serverName) {
		$success = false;
		try {
			LogService::getInstance()->debug(__METHOD__ . " - Add domain registration file for {$serverName}.");
			// try to add domain association file.
			if ( isset( $_SERVER['DOCUMENT_ROOT'] ) ) {
				$path = wc_clean(wp_unslash($_SERVER['DOCUMENT_ROOT'])) . DIRECTORY_SEPARATOR . '.well-known';
				$file = $path . DIRECTORY_SEPARATOR . 'apple-developer-merchantid-domain-association';
				if (sha1_file(AIRWALLEX_PLUGIN_PATH . 'apple-developer-merchantid-domain-association') === sha1_file($file)) {
					$success = true;
				} else {
					require_once( ABSPATH . '/wp-admin/includes/file.php' );
					if ( function_exists( 'WP_Filesystem' ) && ( WP_Filesystem() ) ) {
						/**
						 * WP_Filesystem_Base
						 *
						 * @var WP_Filesystem_Base $wp_filesystem
						 */
						global $wp_filesystem;
						if ( ! $wp_filesystem->is_dir( $path ) ) {
							$wp_filesystem->mkdir( $path );
						}
						$contents = $wp_filesystem->get_contents( AIRWALLEX_PLUGIN_PATH . 'apple-developer-merchantid-domain-association' );
						$wp_filesystem->put_contents( $file, $contents, 0755 );
						if (sha1_file(AIRWALLEX_PLUGIN_PATH . 'apple-developer-merchantid-domain-association') !== sha1_file($file)) {
							throw new Exception(__('Failed to move the file.', 'airwallex-online-payments-gateway'));
						} else {
							$success = true;
							LogService::getInstance()->debug(__METHOD__ . " - Domain registration file added to server root.");
						}
					}
				}
			}
		} catch (Exception $e) {
			LogService::getInstance()->error(__METHOD__ . ' - Failed to add domain registration file to server root.', $e->getMessage());
			$success = false;
		}

		return $success ? ['success' => true] : [
			'success' => false,
			'error' => [
				'code' => self::DOMAIN_FILE_UPLOAD_ERROR,
				'message' => __('Domain file is not added into server root.', 'airwallex-online-payments-gateway'),
			],
		];
	}
}
