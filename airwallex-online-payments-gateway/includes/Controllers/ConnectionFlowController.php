<?php
namespace Airwallex\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Airwallex\Services\LogService;
use Airwallex\Services\CacheService;
use Airwallex\Services\Util;
use Airwallex\Main;
use Exception;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\ConnectionFinalize;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\ConnectionFinalizeResponse;

class ConnectionFlowController {
    const CACHE_KEY_PREFIX_CONNECTION_REQUEST_ID = 'awx_connection_request_id_';
    const CACHE_KEY_PREFIX_CONNECTION_FINALIZE = 'awx_connection_finalize_';
    const ROUTE_SLUG_CONNECTION_CALLBACK = 'airwallex_connection_callback';
    protected $cacheService = null;

    public function __construct() {
        $this->cacheService = CacheService::getInstance();
    }

    public function startConnection() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() recursively sanitizes the value, but the sniff doesn't recognize it.
        $env = isset($_POST['env']) ? wc_clean(wp_unslash($_POST['env'])) : 'prod';

        try {
            check_ajax_referer('wc-airwallex-admin-settings-start-connection-flow', 'security');

            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                throw new Exception(__('You do not have permission to perform this action.', 'airwallex-online-payments-gateway'));
            }

            if ( ! is_string( $env ) || ! Util::isValidEnvironment( $env ) ) {
                throw new Exception(__('Invalid request.', 'airwallex-online-payments-gateway'));
            }

            LogService::getInstance()->debug('Start connection for ' . $env . ' environment');

            $requestId = Util::generateUuidV4();
            $this->cacheService->set(self::CACHE_KEY_PREFIX_CONNECTION_REQUEST_ID . $requestId, ['requestId' => $requestId, 'env'=>$env], MINUTE_IN_SECONDS * 30);
            $domainUrl = Util::getDomainUrl($env);
            $startConnectionParams = [
                'platform' => 'woo',
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() recursively sanitizes the value, but the sniff doesn't recognize it.
                'origin' => isset($_SERVER['HTTP_ORIGIN']) ? wc_clean(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '',
                'returnUrl' => WC()->api_request_url( self::ROUTE_SLUG_CONNECTION_CALLBACK ),
                'requestId' => $requestId,
            ];
            $startConnectionUrl = $domainUrl . '/payment_app/plugin/api/v1/connection/start/?' . http_build_query($startConnectionParams);

            LogService::getInstance()->debug('Connection url generated');

            wp_send_json([
                'success' => true,
                'redirect_url' => $startConnectionUrl,
            ]);
        } catch (Exception $e) {
            LogService::getInstance()->error('Failed to start connection', $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('Something went wrong while trying to connect your account. Please try again.', 'airwallex-online-payments-gateway'),
            ]);
        }
    }

    public function connectionCallback() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_safe_redirect( home_url() );
            return;
        }

        try {
            LogService::getInstance()->debug('Connection flow callback', [
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth-style callback reached via redirect from Airwallex; admin capability is verified above.
                'requestId' => isset( $_GET['requestId'] ) ? sanitize_text_field( wp_unslash( $_GET['requestId'] ) ) : null,
            ]);

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth-style callback reached via redirect from Airwallex; admin capability is verified above; wc_clean() sanitizes the value, but the sniff doesn't recognize it.
            $code = isset($_GET['code']) ? wc_clean(wp_unslash($_GET['code'])) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth-style callback reached via redirect from Airwallex; admin capability is verified above; wc_clean() sanitizes the value, but the sniff doesn't recognize it.
            $requestId = isset($_GET['requestId']) ? wc_clean(wp_unslash($_GET['requestId'])) : '';

            // validate the request id against the stored one
            $cachedRequestData = $this->cacheService->get(self::CACHE_KEY_PREFIX_CONNECTION_REQUEST_ID . $requestId);
            if (empty($cachedRequestData['requestId']) || $requestId !== $cachedRequestData['requestId']) {
                throw new Exception(__('Invalid request.', 'airwallex-online-payments-gateway'));
            }

            $accessToken = gzdecode(base64_decode($code)); // decode the based64 encoded and gzipped code
            $baseUrl = home_url();
            $validationToken = Util::generateUuidV4();
            $connectionTtl = MINUTE_IN_SECONDS * 5;
            $this->cacheService->set(self::CACHE_KEY_PREFIX_CONNECTION_FINALIZE . $requestId, $validationToken, $connectionTtl);
            $this->cacheService->set(self::CACHE_KEY_PREFIX_CONNECTION_REQUEST_ID . $requestId, $cachedRequestData, $connectionTtl);

            
            /** @var ConnectionFinalizeResponse $connectionFinalizeResponse */
            $connectionFinalizeResponse = (new ConnectionFinalize())
                ->setPlatform('woo')
                ->setOrigin(Util::getOriginFromUrl($baseUrl))
                ->setBaseUrl($baseUrl)
                ->setWebhookNotificationUrl(WC()->api_request_url( Main::ROUTE_SLUG_WEBHOOK ))
                ->setConnectionFinalizeToken($validationToken)
                ->setAccessToken($accessToken)
                ->setRequestId($requestId)
                ->send();

            if (!empty($connectionFinalizeResponse->getError())) {
                LogService::getInstance()->error('connectionCallback', $connectionFinalizeResponse->getError());
                throw new Exception($connectionFinalizeResponse->getError());
            }
            wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=airwallex_general'));
        } catch (Exception $e) {
            // Connection flow failed. Switch the active mode to API key so the settings
            // screen shows the API key inputs, which are the fallback after this flow fails.
            if (Util::isSandboxEnvironment()) {
                update_option('airwallex_connection_type_demo', 'api_key');
            } else {
                update_option('airwallex_connection_type', 'api_key');
            }

            wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=airwallex_general&error=connection_failed'));
            LogService::getInstance()->error('Failed to finalize connection', $e->getMessage());
        }
	}

	public function saveAccountSetting() {
        $requestId = '';
        try {
            LogService::getInstance()->debug('Save Account Setting');

            $headers = Util::getRequestHeaders();
            $content = file_get_contents( 'php://input' );
            $postData = json_decode($content, true);
            if ( ! is_array( $postData ) ) {
                $postData = array();
            }
            $requestId = isset($postData['request_id']) ? wc_clean(wp_unslash($postData['request_id'])) : '';

            $cachedRequestData = $this->cacheService->get(self::CACHE_KEY_PREFIX_CONNECTION_REQUEST_ID . $requestId);
            if ( ! is_array( $cachedRequestData ) || empty( $cachedRequestData['env'] ) || ! is_string( $cachedRequestData['env'] ) ) {
                throw new Exception(__('Invalid request.', 'airwallex-online-payments-gateway'));
            }
            $cachedToken = $this->cacheService->get(self::CACHE_KEY_PREFIX_CONNECTION_FINALIZE . $requestId);
            $this->verifySignature($headers, $content, $cachedToken);

            $optionSuffix = Util::isSandboxEnvironment( $cachedRequestData['env'] ) ? '_demo' : '';
            foreach (['client_id', 'api_key', 'webhook_secret', 'account_id', 'account_name'] as $key) {
                update_option( 'airwallex_' . $key . $optionSuffix, $this->accountSettingValue( $postData, $key ) );
            }
            update_option('airwallex_enable_sandbox', Util::isSandboxEnvironment( $cachedRequestData['env'] ) ? 'yes' : 'no');
            if ( Util::ENV_PROD === Util::normalizeEnvironment( $cachedRequestData['env'] ) ) {
                update_option('airwallex_connection_type', 'connection_flow');
            } else {
                update_option('airwallex_connection_type_demo', 'connection_flow');
            }
            LogService::getInstance()->debug('Save Account Setting successfully');
            wp_send_json([
                'success' => true,
                'message' => __('Settings saved.', 'airwallex-online-payments-gateway'),
            ]);
        } catch (Exception $e) {
            LogService::getInstance()->error(
                'Failed to save settings',
                array(
                    'reason'         => $e->getMessage(),
                    'correlation_id' => Util::correlationId( $requestId ),
                )
            );
            wp_send_json([
                'success' => false,
                'message' => __('Failed to save settings.', 'airwallex-online-payments-gateway'),
            ]);
        }
	}

    private function verifySignature( $headers, $msg, $secret ) {
        if ( ! is_string( $secret ) || '' === $secret ) {
            throw new Exception( 'Connection signature secret is not configured; refusing to process the request.' );
        }

        Util::verifySignature( $headers, $msg, $secret );
    }

    /**
     * @param array  $postData
     * @param string $key
     * @return string
     * @throws Exception
     */
    private function accountSettingValue( $postData, $key ) {
        if ( ! isset( $postData[ $key ] ) || ! is_string( $postData[ $key ] ) ) {
            throw new Exception( __( 'Invalid request. Missing required fields.', 'airwallex-online-payments-gateway' ) );
        }

        $value = trim( $postData[ $key ] );
        if ( '' === $value ) {
            throw new Exception( __( 'Invalid request. Missing required fields.', 'airwallex-online-payments-gateway' ) );
        }

        if ( strlen( $value ) > 2048 ) {
            throw new Exception( __( 'Invalid request.', 'airwallex-online-payments-gateway' ) );
        }

        return $value;
    }
}
