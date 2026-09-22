<?php

namespace Airwallex\Services;

use Airwallex\Client\CardClient;
use Airwallex\PayappsPlugin\CommonLibrary\Util\UrlHelper;
use Exception;

class Util {

	const ENV_SANDBOX = 'sandbox';
	const ENV_PROD    = 'prod';

	/**
	 * Legacy sandbox env identifier kept for backward compatibility.
	 */
	const ENV_DEMO = 'demo';

	public static function getLocale() {
		$locale = strtolower( get_bloginfo( 'language' ) );
		$locale = str_replace( '_', '-', $locale );
		if ( substr_count( $locale, '-' ) > 1 ) {
			$parts  = explode( '-', $locale );
			$locale = $parts[0] . '-' . $parts[1];
		}
		if ( strpos( $locale, '-' ) !== false ) {
			$parts = explode( '-', $locale );
			if ( 'zh' === $parts[0] && in_array( $parts[1], array( 'tw', 'hk' ), true ) ) {
				$locale = 'zh-HK';
			} else {
				$locale = $parts[0];
			}
		}
		return $locale;
	}

	/**
	 * Truncate string to given length
	 *
	 * @param string $str    Original string.
	 * @param int    $len    [optional] Maximum number of characters of the returned string excluding the suffix.
	 * @param string $suffix [optional] Suffix to be attached to the end of the string.
	 * @return string
	 */
	public static function truncateString( $str, $len = 128, $suffix = '' ) {
		if ( mb_strlen( $str ) <= $len ) {
			return $str;
		}

		return mb_substr( $str, 0, $len ) . $suffix;
	}

	/**
	 * Rounds a value to a specified precision using a specified rounding mode.
	 *
	 * @param mixed $val The value to be rounded. Can be numeric or a string representation of a number.
	 * @param int $precision [optional] The number of decimal places to round to.
	 * @param int $mode [optional] The rounding mode to be used (defaults to PHP_ROUND_HALF_UP).
	 * @return float The rounded value.
	 */
	public static function round( $val, $precision = 0, $mode = PHP_ROUND_HALF_UP ) {
		if ( ! is_numeric( $val ) ) {
			$val = floatval( $val );
		}
		return round( $val, $precision, $mode );
	}

	/**
	 * Get the current environment setting
	 * 
	 * @return string The current environment
	 */
	public static function getEnvironment() {
		return self::isSandboxEnabled() ? self::ENV_SANDBOX : self::ENV_PROD;
	}

	/**
	 * Whether sandbox mode is enabled in plugin settings.
	 *
	 * @return bool
	 */
	public static function isSandboxEnabled() {
		return in_array( get_option( 'airwallex_enable_sandbox' ), array( true, 'yes' ), true );
	}

	/**
	 * Whether the given environment identifier refers to sandbox (accepts legacy "demo").
	 *
	 * @param string $env
	 * @return bool
	 */
	public static function isSandboxEnvironment( $env = '' ) {
		$targetEnv = $env ? $env : self::getEnvironment();

		return in_array( $targetEnv, array( self::ENV_SANDBOX, self::ENV_DEMO ), true );
	}

	/**
	 * Normalize environment identifiers to sandbox or prod.
	 *
	 * @param string $env
	 * @return string
	 */
	public static function normalizeEnvironment( $env ) {
		return self::isSandboxEnvironment( $env ) ? self::ENV_SANDBOX : self::ENV_PROD;
	}

	/**
	 * Environment identifier for Common Library / REST API authentication.
	 *
	 * @return string
	 */
	public static function getApiEnvironment() {
		return self::getEnvironment();
	}

	/**
	 * Normalize environment identifiers for Common Library / API authentication.
	 *
	 * @param string $env
	 * @return string
	 */
	public static function normalizeApiEnvironment( $env ) {
		return self::normalizeEnvironment( $env );
	}

	/**
	 * Legacy storage key segment for wp_options (e.g. bind_demo_pos_device, _demo suffix).
	 *
	 * @param string $env
	 * @return string
	 */
	public static function getEnvironmentStorageKey( $env = '' ) {
		return self::isSandboxEnvironment( $env ) ? self::ENV_DEMO : self::ENV_PROD;
	}

	/**
	 * @param string $env
	 * @return bool
	 */
	public static function isValidEnvironment( $env ) {
		return in_array( $env, array( self::ENV_SANDBOX, self::ENV_DEMO, self::ENV_PROD ), true );
	}

	/**
	 * Get the api kay
	 * 
	 * @return string API Key
	 */
	public static function getApiKey($env = '') {
		$targetEnv = $env ? $env : Util::getEnvironment();
		
		return self::isSandboxEnvironment( $targetEnv ) ? get_option( 'airwallex_api_key_demo', get_option( 'airwallex_api_key' ) ) : get_option( 'airwallex_api_key' );
	}

	/**
	 * Get the client id
	 * 
	 * @return string Client id
	 */
	public static function getClientId($env = '') {
		$targetEnv = $env ? $env : Util::getEnvironment();

		return self::isSandboxEnvironment( $targetEnv ) ? get_option( 'airwallex_client_id_demo', get_option( 'airwallex_client_id' ) ) : get_option( 'airwallex_client_id' );
	}

	/**
	 * Get the webhook secret
	 * 
	 * @return string Webhook secret
	 */
	public static function getWebhookSecret($env = '') {
		$targetEnv = $env ? $env : Util::getEnvironment();

		return self::isSandboxEnvironment( $targetEnv ) ? get_option( 'airwallex_webhook_secret_demo', get_option( 'airwallex_webhook_secret' ) ) : get_option( 'airwallex_webhook_secret' );
	}

	/**
	 * Get the account id
	 * 
	 * @return string Account id
	 */
	public static function getAccountId($env = '') {
		$targetEnv = $env ? $env : Util::getEnvironment();

		return self::isSandboxEnvironment( $targetEnv ) ? get_option( 'airwallex_account_id_demo', '' ) : get_option( 'airwallex_account_id', '' );
	}

	/**
	 * Get the account name
	 * 
	 * @param string $env
	 * @return string Account name
	 */
	public static function getAccountName($env = '') {
		$targetEnv = $env ? $env : Util::getEnvironment();

		return self::isSandboxEnvironment( $targetEnv ) ? get_option( 'airwallex_account_name_demo', '' ) : get_option( 'airwallex_account_name', '' );
	}

	/**
	 * Get merchant info from JWT token
	 * 
	 * @return array Merchant info
	 */
	public static function getMerchantInfoFromJwtToken() {
		try {
			$token = CardClient::getInstance()->getToken();
			// decode JWT token
			$merchantInfo = [];
			$base64Codes  = explode('.', $token);
			if (!empty($base64Codes[1])) {
				$base64 = str_replace('_', '/', str_replace('-', '+', $base64Codes[1]));
				
				$decoded = json_decode(urldecode(base64_decode($base64)), true);
			}

			if (isset($decoded['account_id'])) {
				$merchantInfo = [
					'accountId' => $decoded['account_id'],
				];
			}

			return $merchantInfo;
		} catch (Exception $ex) {
			LogService::getInstance()->error(__METHOD__, $ex->getMessage());
			return null;
		}
	}

	/**
	 * Get currency format
	 * 
	 * @return array Currency format
	 */
	public static function getCurrencyFormat() {
		$position = get_option( 'woocommerce_currency_pos' );
		$symbol   = html_entity_decode( get_woocommerce_currency_symbol() );
		$prefix   = '';
		$suffix   = '';

		switch ( $position ) {
			case 'left_space':
				$prefix = $symbol . ' ';
				break;
			case 'left':
				$prefix = $symbol;
				break;
			case 'right_space':
				$suffix = ' ' . $symbol;
				break;
			case 'right':
				$suffix = $symbol;
				break;
		}
		
		return [
			'currencyCode'              => get_woocommerce_currency(),
			'currencySymbol'            => $symbol,
			'currencyMinorUnit'         => wc_get_price_decimals(),
			'currencyDecimalSeparator'  => wc_get_price_decimal_separator(),
			'currencyThousandSeparator' => wc_get_price_thousand_separator(),
			'currencyPrefix'            => $prefix,
			'currencySuffix'            => $suffix,
		];
	}

	/**
	 * Generate a Version 4 UUID
	 * 
	 * @return string
	 */
	public static function generateUuidV4() {
		// Generate 16 bytes (128 bits) of random data or use the openssl random pseudo bytes function.
		$data = '';
		if ( function_exists( 'random_bytes' ) ) {
			$data = random_bytes(16);
		} else if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
			$data = openssl_random_pseudo_bytes(16);
		} else {
			$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			$charactersLength = strlen( $characters );
			for ($i = 0; $i < 16; $i++) {
				$data .= $characters[wp_rand( 0, $charactersLength - 1 )];
			}
		}
    
		// Set the version to 0100 (4 in binary) to indicate it's a version 4 UUID.
		$data[6] = chr( ord($data[6] ) & 0x0f | 0x40);
		
		// Set the bits for variant to 10.
		$data[8] = chr( ord($data[8] ) & 0x3f | 0x80);
		
		// Output the 36 character UUID.
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex($data), 4 ) );
	}

	/**
	 * Get the host for checkout UI according to the environment
	 * 
	 * @param string $env
	 * @return string
	 */
	public static function getCheckoutUIEnvHost($env) {
		$envHosts = [
			'staging' => 'https://checkout-staging.airwallex.com',
			self::ENV_SANDBOX => 'https://checkout.sandbox.airwallex.com',
			self::ENV_DEMO => 'https://checkout.sandbox.airwallex.com',
			self::ENV_PROD => 'https://checkout.airwallex.com',
		];

		$normalizedEnv = self::normalizeEnvironment( $env );
		if ( self::ENV_SANDBOX === $normalizedEnv ) {
			return $envHosts[ self::ENV_SANDBOX ];
		}

		return isset( $envHosts[ $env ] ) ? $envHosts[ $env ] : $envHosts[ self::ENV_PROD ];
	}

	/**
	 * Check whether the client is new
	 * 
	 * @return bool
	 */
	public static function isNewClient($env = '') {
		return empty(Util::getApiKey($env)) || empty(Util::getClientId($env));
	}

	/**
	 * Get the domain url
	 */
	public static function getDomainUrl($env = '') {
		$targetEnv = $env ? $env : Util::getEnvironment();

		$domainUrls = [
			'staging' => 'https://staging.airwallex.com',
			self::ENV_SANDBOX => 'https://www.sandbox.airwallex.com',
			self::ENV_DEMO => 'https://www.sandbox.airwallex.com',
			self::ENV_PROD => 'https://www.airwallex.com',
		];

		$normalizedEnv = self::normalizeEnvironment( $targetEnv );

		if ( self::ENV_SANDBOX === $normalizedEnv ) {
			return $domainUrls[ self::ENV_SANDBOX ];
		}

		return $domainUrls[ $targetEnv ] ?? 'https://staging.airwallex.com';
	}

	/**
	 * Check whether the current user has a specific role
	 * 
	 * @return boolean
	 */
	public static function currentUserHasRole($role) {
		$user = wp_get_current_user();
		if (empty($user)) {
			return false;
		}

		return in_array($role, $user->roles, true);
	}

	/**
	 * Get the origin from the URL
	 * 
	 * @param string $url
	 * @return string
	 */
	public static function getOriginFromUrl($url) {
		$urlComponents = wp_parse_url($url);

		if (!isset($urlComponents['scheme'], $urlComponents['host'])) {
			return ''; // Handle the error for an invalid URL
		}

		$origin = $urlComponents['scheme'] . '://' . $urlComponents['host'];

		if (isset($urlComponents['port']) && !in_array($urlComponents['port'], [80, 443])) {
			$origin .= ':' . $urlComponents['port'];
		}

		return $origin;
	}

	/**
	 * Resolve the merchant_website_url reported for Mastercard AN 6022.
	 *
	 * Returns the store origin (scheme + host, with a trailing slash) where the
	 * customer completes the transaction, derived from the WooCommerce store
	 * home URL. Returns an empty string when a valid http(s) origin cannot be
	 * determined so the optional field is simply omitted rather than breaking
	 * checkout.
	 *
	 * @return string
	 */
	public static function getMerchantWebsiteUrl() {
		return UrlHelper::toOrigin(home_url('/'));
	}

	/**
	 * Get request headers
	 * 
	 * @return array
	 */
	public static function getRequestHeaders() {
		$headers = array();
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $k => $v ) {
				$headers[ strtolower( $k ) ] = $v;
			}
			return $headers;
		}

		foreach ( $_SERVER as $name => $value ) {
			if ( substr( $name, 0, 5 ) === 'HTTP_' ) {
				$headers[ str_replace( ' ', '-', strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ] = $value;
			}
		}
		return $headers;
	}

	/**
	 * Check whether the store is connected via connection flow
	 * 
	 * @return boolean
	 */
	public static function isConnectedViaConnectionFlow() {
		return 'connection_flow' === get_option('airwallex_connection_type', '');
	}

	/**
	 * Check whether the store is connected by API key
	 * 
	 * @return boolean
	 */
	public static function isConnectedViaAPIKey() {
		return 'api_key' === get_option('airwallex_connection_type', '');
	}
}
