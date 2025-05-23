<?php

namespace Airwallex\Client;

use Airwallex\Services\LogService;
use Airwallex\Services\Util;
use Exception;

class LoggingClient extends AbstractClient {
	public static $instance = null;

	const LOG_SEVERITY_INFO    = 'info';
	const LOG_SEVERITY_WARNING = 'warn';
	const LOG_SEVERITY_ERROR   = 'error';

	protected static $sessionId;
	protected static $accountId;

	private $isActive = false;

	public function __construct( $clientId, $apiKey, $isSandbox ) {
		$this->clientId  = $clientId;
		$this->apiKey    = $apiKey;
		$this->isSandbox = $isSandbox;
		$this->isActive  = self::isActive();
	}

	protected static function getSessionId() {
		if ( ! isset( self::$sessionId ) ) {
			self::$sessionId = Util::generateUuidV4();
		}

		return self::$sessionId;
	}

	protected function getAccountId() {
		try {
			if ( ! isset( self::$accountId ) ) {
				$merchantInfo = Util::getMerchantInfoFromJwtToken( $this->getToken() );
				self::$accountId = isset( $merchantInfo['accountId'] ) ? $merchantInfo['accountId'] : 'unknown';
			}
			return self::$accountId;
		} catch (Exception $e) {
			return 'unknown';
		}
	}

	public function log( $severity, $eventName, $message, $details = array(), $type = 'unknown', $forceRemoteLog = false ) {
		if ( ! $this->isActive && ! $forceRemoteLog) {
			return;
		}

		$data = array(
			'commonData' => array(
				'accountId'  => $this->getAccountId(),
				'appName'    => 'pa_plugin',
				'source'     => 'woo_commerce',
				'deviceId'   => 'unknown',
				'sessionId'  => self::getSessionId(),
				'appVersion' => AIRWALLEX_VERSION,
				'platform'   => $this->getClientPlatform(),
				'env'        => $this->isSandbox ? 'demo' : 'prod',
			),
			'data'       => array(
				array(
					'severity'  => $severity,
					'eventName' => $eventName,
					'message'   => $message,
					'type'      => $type,
					'details'   => wp_json_encode( $details ),
					'trace'     => wp_json_encode( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) ), // phpcs:ignore
				),
			),
		);
		try {
			$client = $this->getHttpClient();
			$client->call(
				'POST',
				$this->getLogUrl( 'papluginlogs/logs' ),
				wp_json_encode( $data ),
				array(
					'Authorization' => 'Bearer ' . $this->getToken(),
					'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
				),
				null,
				true
			);
		} catch ( Exception $e ) {
			//silent
			wc_get_logger()->error( 'An error occurred while attempting to send logs to Airwallex. ' . $e->getMessage() );
		}
	}
	protected function getClientPlatform() {
		$userAgent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( strpos( $userAgent, 'Linux' ) !== false ) {
			return 'linux';
		} elseif ( strpos( $userAgent, 'Android' ) !== false ) {
			return 'android';
		} elseif ( strpos( $userAgent, 'Windows' ) !== false ) {
			return 'windows';
		} elseif ( strpos( $userAgent, 'iPhone' ) !== false || strpos( $userAgent, 'iPad' ) !== false ) {
			return 'ios';
		} elseif ( strpos( $userAgent, 'Macintosh' ) !== false || strpos( $userAgent, 'Mac OS X' ) !== false ) {
			return 'macos';
		} else {
			return 'other';
		}
	}

	public static function isActive() {
		return in_array( get_option( 'airwallex_do_remote_logging' ), array( 'yes', 1, true, '1' ), true );
	}
}
