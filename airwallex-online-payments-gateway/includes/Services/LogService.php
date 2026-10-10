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

	const LOG_FILE_HEADER = "<?php http_response_code(403); exit; ?>\n";
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
		}
		$this->logDir = rtrim( $this->logDir, '/\\' ) . '/';
		$this->prepareLogDirectory();
	}

	private function prepareLogDirectory() {
		if ( ! is_dir( $this->logDir ) && ! wp_mkdir_p( $this->logDir ) ) {
			return;
		}
		$protectionFiles = array(
			'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php' => "<?php http_response_code(403); exit;\n",
			'index.html' => '',
		);
		foreach ( $protectionFiles as $name => $content ) {
			$handle = @fopen( $this->logDir . $name, 'xb' );
			if ( false !== $handle ) {
				fwrite( $handle, $content );
				fclose( $handle );
			}
		}
	}

	private function getLogFile( $level ) {
		$suffix = hash_hmac( 'sha256', 'airwallex_log', wp_salt() );
		return $this->logDir . 'airwallex-' . $level . '-' . gmdate( 'Y-m-d' ) . '_' . $suffix . '.log.php';
	}

	public function log( $message, $level = 'debug', $data = null ) {
		$path = $this->getLogFile( $level );
		$handle = @fopen( $path, 'a+b' );
		if ( false === $handle ) {
			return;
		}
		if ( flock( $handle, LOCK_EX ) ) {
			rewind( $handle );
			$stat = fstat( $handle );
			$ready = 0 === $stat['size']
				? strlen( self::LOG_FILE_HEADER ) === fwrite( $handle, self::LOG_FILE_HEADER )
				: self::LOG_FILE_HEADER === fread( $handle, strlen( self::LOG_FILE_HEADER ) );
			if ( $ready ) {
				fwrite( $handle, '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . ' | ' . wp_json_encode( $data ) . "\n" );
			}
			flock( $handle, LOCK_UN );
		}
		fclose( $handle );
		@chmod( $path, 0600 );
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
