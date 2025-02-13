<?php
namespace Airwallex\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Airwallex\Services\LogService;
use Airwallex\Client\AdminClient;
use Airwallex\Services\CacheService;
use Airwallex\Services\Util;
use Airwallex\Main;
use Exception;

class ConnectionFlowController {
    const CACHE_KEY_PREFIX_CONNECTION_REQUEST_ID = 'awx_connection_request_id_';
    const CACHE_KEY_PREFIX_CONNECTION_FINALIZE = 'awx_connection_finalize_';
    const ROUTE_SLUG_CONNECTION_CALLBACK = 'airwallex_connection_callback';
    protected $cacheService = null;

    public function __construct() {
        $this->cacheService = new CacheService();
    }

    public function startConnection() {
        $env = isset($_POST['env']) ? wc_clean(wp_unslash($_POST['env'])) : 'prod';
        LogService::getInstance()->debug('Start connection for ' . $env . ' environment');

        try {
            check_ajax_referer('wc-airwallex-admin-settings-start-connection-flow', 'security');

            if (!Util::currentUserHasRole('administrator')) {
                throw new Exception(__('You do not have permission to perform this action.', 'airwallex-online-payments-gateway'));
            }

            $requestId = Util::generateUuidV4();
            $this->cacheService->set(self::CACHE_KEY_PREFIX_CONNECTION_REQUEST_ID . $requestId, ['requestId' => $requestId, 'env'=>$env], MINUTE_IN_SECONDS * 30);
            $domainUrl = Util::getDomainUrl($env);
            $startConnectionParams = [
                'platform' => 'woo',
                'origin' => isset($_SERVER['HTTP_ORIGIN']) ? wc_clean(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '',
                'returnUrl' => WC()->api_request_url( self::ROUTE_SLUG_CONNECTION_CALLBACK ),
                'requestId' => $requestId,
            ];
            $startConnectionUrl = $domainUrl . '/payment_app/plugin/api/v1/connection/start/?' . http_build_query($startConnectionParams);

            LogService::getInstance()->debug('Connection url generated ', $startConnectionUrl);

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
        try {
            LogService::getInstance()->debug('Connection flow callback', $_GET);

            if (!Util::currentUserHasRole('administrator')) {
                throw new Exception(__('You do not have permission to perform this action.', 'airwallex-online-payments-gateway'));
            }
            
            $code = isset($_GET['code']) ? wc_clean(wp_unslash($_GET['code'])) : '';
            $requestId = isset($_GET['requestId']) ? wc_clean(wp_unslash($_GET['requestId'])) : '';

            // validate the request id against the stored one
            $cachedRequestData = $this->cacheService->get(self::CACHE_KEY_PREFIX_CONNECTION_REQUEST_ID . $requestId);
            if (empty($cachedRequestData['requestId']) || $requestId !== $cachedRequestData['requestId']) {
                throw new Exception(__('Invalid request.', 'airwallex-online-payments-gateway'));
            }

            $accessToken = gzdecode(base64_decode($code)); // decode the based64 encoded and gzipped code
            $baseUrl = home_url();
            $validationToken = Util::generateUuidV4();
            $this->cacheService->set(self::CACHE_KEY_PREFIX_CONNECTION_FINALIZE . $requestId, $validationToken, MINUTE_IN_SECONDS * 5);
            $payload = [
				'platform' => 'woo',
				'origin' => Util::getOriginFromUrl($baseUrl),
				'baseUrl' => $baseUrl,
				'webhookNotificationUrl' => WC()->api_request_url( Main::ROUTE_SLUG_WEBHOOK ),
				'token' => $validationToken,
				'requestId' => $requestId,
			];

            $adminClient = AdminClient::getInstance();
            $res = $adminClient->finalizeConnection($cachedRequestData['env'], $accessToken, $payload);

            LogService::getInstance()->debug('connectionCallback', print_r($res, true));
            if ($res->status !== 200) {
                throw new Exception(isset($res->data['error']) ? $res->data['error'] : 'Failed to finalize connection');
            }
            wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=airwallex_general'));
        } catch (Exception $e) {
            update_option('airwallex_connection_type', 'api_key');
            wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=airwallex_general&error=connection_failed'));
            LogService::getInstance()->error('Failed to finalize connection', $e->getMessage());
        }
	}

	public function saveAccountSetting() {
        try {
            LogService::getInstance()->debug('Save Account Setting');

            $headers = Util::getRequestHeaders();
            $content = file_get_contents( 'php://input' );
            $postData = json_decode($content, true);
            $requestId = isset($postData['request_id']) ? wc_clean(wp_unslash($postData['request_id'])) : '';

            $cachedRequestData = $this->cacheService->get(self::CACHE_KEY_PREFIX_CONNECTION_REQUEST_ID . $requestId);
            if (empty($cachedRequestData['env'])) {
                throw new Exception(__('Invalid request.', 'airwallex-online-payments-gateway'));
            }
            $cachedToken = $this->cacheService->get(self::CACHE_KEY_PREFIX_CONNECTION_FINALIZE . $requestId);
            $this->verifySignature($headers, $content, $cachedToken);

            foreach (['client_id', 'api_key', 'webhook_secret', 'account_id', 'account_name'] as $key) {
                $optionKey = 'airwallex_' . $key . ($cachedRequestData['env'] === 'demo' ? '_demo' : '');
                if (empty($postData[$key])) {
                    throw new Exception(__('Invalid request. Missing required fields.', 'airwallex-online-payments-gateway'));
                }
                update_option($optionKey, $postData[$key]);
            }
            update_option('airwallex_enable_sandbox', 'demo' === $cachedRequestData['env'] ? 'yes' : 'no');
            if ('prod' === $cachedRequestData['env']) {
                update_option('airwallex_connection_type', 'connection_flow');
            }

            wp_send_json([
                'success' => true,
                'message' => __('Settings saved.', 'airwallex-online-payments-gateway'),
            ]);
        } catch (Exception $e) {
            LogService::getInstance()->error('Failed to save settings', $e->getMessage());
            wp_send_json([
                'success' => false,
                'message' => __('Failed to save settings.', 'airwallex-online-payments-gateway'),
            ]);
        }
	}

    private function verifySignature( $headers, $msg, $secret ) {
        $timestamp           = $headers['x-timestamp'];
        $signature           = $headers['x-signature'];
        $calculatedSignature = hash_hmac( 'sha256', $timestamp . $msg, $secret );
        
        if ( $calculatedSignature !== $signature ) {
            throw new Exception(
                sprintf(
                    'Invalid signature: %1$s vs. %2$s',
                    esc_html( $signature ),
                    esc_html( $calculatedSignature )
                )
            );
        }
    }
}
