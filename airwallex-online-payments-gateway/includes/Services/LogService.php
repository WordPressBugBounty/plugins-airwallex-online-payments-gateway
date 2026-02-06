<?php

namespace Airwallex\Services;

use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log as RemoteLog;

class LogService {

	const CARD_ELEMENT_TYPE            = 'cardElement';
	const DROP_IN_ELEMENT_TYPE         = 'dropInElement';
	const WECHAT_ELEMENT_TYPE          = 'wechatElement';
	const GOOGLE_EXPRESS_CHECKOUT_TYPE = 'googleExpressCheckout';
	const APPLE_EXPRESS_CHECKOUT_TYPE  = 'appleExpressCheckout';
	const ON_PROCESS_WEBHOOK_ERROR     = 'onProcessWebhookError';
	const ON_PAYMENT_CONFIRMATION_ERROR = 'onPaymentConfirmationError';
	const ON_PAYMENT_INTENT_CREATE_ERROR = 'onPaymentIntentCreateError';
	const SEVERITY_INFO = 'info';
	const SEVERITY_WARN = 'warn';
	const SEVERITY_ERROR = 'error';

	private $logDir;
	private static $instance;

	public static function getInstance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		if ( defined( 'WC_LOG_DIR' ) ) {
			$this->logDir = WC_LOG_DIR;
		} else {
			$uploadDir = wp_upload_dir();
			$this->logDir = $uploadDir['basedir'] . '/airwallex-logs/';
			if ( ! is_dir( $this->logDir ) ) {
				mkdir( $this->logDir, 0755, true );
			}
		}
	}

	private function getLogFile( $level ) {
		return $this->logDir . 'airwallex-' . $level . '-' . gmdate( 'Y-m-d' ) . '_' . md5( Util::getApiKey() ) . '.log';
	}

	public function log( $message, $level = 'debug', $data = null ) {
		file_put_contents( $this->getLogFile( $level ), '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . ' | ' . wp_json_encode( $data ) . "\n", 8 ); // @codingStandardsIgnoreLine.
	}

	public function debug( $message, $data = null, $type = 'unknown' ) {
		$this->log( $message, 'debug', $data );
		$this->remoteLog(self::SEVERITY_INFO, 'wp_info', $message, $data, $type);
	}

	public function warning( $message, $data = null, $type = 'unknown' ) {
		$this->log( '⚠ ' . $message, 'debug', $data );
		$this->log( $message, 'warning', $data );
		$this->remoteLog(self::SEVERITY_WARN, 'wp_warning', $message, $data, $type);
	}

	public function error( $message, $data = null, $type = 'unknown' ) {
		$this->log( '💣 ' . $message, 'debug', $data );
		$this->log( $message, 'error', $data );
		$this->remoteLog(self::SEVERITY_ERROR, 'wp_error', $message, $data, $type);
	}

	public static function isRemoteLogActive() {
		return in_array( get_option( 'airwallex_do_remote_logging' ), array( 'yes', 1, true, '1' ), true );
	}

	public function remoteLog($severity, $eventName, $message, $data, $type) {
		$dataStr = "";
		if (is_string($data)) {
			$dataStr = $data;
		} else if (is_array($data)) {
			$dataStr = json_encode($data, JSON_UNESCAPED_UNICODE);
		}
		$fullMessage = $type . "\n" . $message . "\n" . $dataStr;

		if (self::isRemoteLogActive()) {
			if ($severity === self::SEVERITY_ERROR) {
				RemoteLog::error($fullMessage, $eventName);
			} else {
				RemoteLog::info($fullMessage, $eventName);
			}
		}
	}
}
