<?php

namespace Airwallex\Controllers;

use Airwallex\Client\AdminClient;
use Airwallex\Gateways\Card;
use Airwallex\Gateways\Main;
use Airwallex\Gateways\WeChat;
use Airwallex\Services\LogService;
use Airwallex\Services\OrderService;
use Airwallex\Services\WebhookService;
use Airwallex\Gateways\AirwallexGatewayTrait;
use Airwallex\PayappsPlugin\CommonLibrary\Exception\UnauthorizedException;
use Airwallex\Services\Util;
use Exception;
use Error;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log as RemoteLog;
use Airwallex\PayappsPlugin\CommonLibrary\UseCase\PaymentMethodType\GetList as GetPaymentMethodTypeList;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentMethodType as StructPaymentMethodType;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Terminal\GetList as GetTerminalList;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Terminal as StructTerminal;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Retrieve as RetrievePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent as StructPaymentIntent;

class AirwallexController {

	use AirwallexGatewayTrait;

	protected $logService;

	public function __construct() {
		$this->logService = LogService::getInstance();
	}

	private function getPaymentDetailForRedirect($gateway) {
		$order = $this->getOrderFromRequest('getPaymentDetailForRedirect');

		$paymentIntentId = $order->get_meta(OrderService::META_KEY_INTENT_ID);
		/** @var StructPaymentIntent $paymentIntent */
		$paymentIntent = (new RetrievePaymentIntent())->setPaymentIntentId($paymentIntentId)->send();
		$clientSecret = $paymentIntent->getClientSecret();
		$customerId = $paymentIntent->getCustomerId();
		$confirmationUrl = $gateway->get_payment_confirmation_url($order->get_id(), $paymentIntentId);
		$isSandbox       = $gateway->is_sandbox();

		return [$order, $paymentIntentId, $clientSecret, $customerId, $confirmationUrl, $isSandbox];
	}

	public function cardPayment() {
		try {
			$gateway = Card::getInstance();
			list( $order, $paymentIntentId, $paymentIntentClientSecret, $airwallexCustomerId, $confirmationUrl, $isSandbox ) = $this->getPaymentDetailForRedirect($gateway);
			$orderService = new OrderService();
			$isSubscription = $orderService->containsSubscription( $order->get_id() );
			$autoCapture = $gateway->is_capture_immediately();
			$this->logService->debug(
				__METHOD__ . ' - Card payment redirect',
				array(
					'orderId'       => $order->get_id(),
					'paymentIntent' => $paymentIntentId,
				),
				LogService::CARD_ELEMENT_TYPE
			);

			include AIRWALLEX_PLUGIN_PATH . '/html/card-payment.php';
			die;
		} catch ( Exception $e ) {
			LogService::getInstance()->error(__METHOD__ . ' - Card payment controller action failed', $e->getMessage(), LogService::CARD_ELEMENT_TYPE );
			wc_add_notice( __( 'Airwallex payment error', 'airwallex-online-payments-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			die;
		}
	}

	public function dropInPayment() {
		try {
			$gateway = Main::getInstance();
			list( $order, $paymentIntentId, $paymentIntentClientSecret, $airwallexCustomerId, $confirmationUrl, $isSandbox ) = $this->getPaymentDetailForRedirect($gateway);
			$isShowOrderDetails = $gateway->isShowOrderDetails();
			$orderService = new OrderService();
			$isSubscription = $orderService->containsSubscription( $order->get_id() );
			$this->logService->debug(
				__METHOD__ . ' - Drop in payment redirect',
				array(
					'orderId'       => $order->get_id(),
					'paymentIntent' => $paymentIntentId,
				),
				LogService::DROP_IN_ELEMENT_TYPE
			);

			include AIRWALLEX_PLUGIN_PATH . '/html/drop-in-payment.php';
			die;
		} catch ( Exception $e ) {
			LogService::getInstance()->error( __METHOD__ . ' - Drop in payment controller action failed', $e->getMessage(), LogService::CARD_ELEMENT_TYPE );
			wc_add_notice( __( 'Airwallex payment error', 'airwallex-online-payments-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			die;
		}
	}

	public function weChatPayment() {
		try {
			$gateway = WeChat::getInstance();
			list( $order, $paymentIntentId, $paymentIntentClientSecret, $airwallexCustomerId, $confirmationUrl, $isSandbox ) = $this->getPaymentDetailForRedirect($gateway);
			$orderId = $order->get_id();
			$this->logService->debug(
				__METHOD__ . 'WeChat payment redirect',
				array(
					'orderId'       => $order->get_id(),
					'paymentIntent' => $paymentIntentId,
				),
				LogService::WECHAT_ELEMENT_TYPE
			);

			include AIRWALLEX_PLUGIN_PATH . '/html/wechat.php';
			die;
		} catch ( Exception $e ) {
			$this->logService->error( __METHOD__ . ' - WeChat payment controller action failed', $e->getMessage(), LogService::WECHAT_ELEMENT_TYPE );
			wc_add_notice( __( 'Airwallex payment error', 'airwallex-online-payments-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			die;
		}
	}

	public function processOrderPay() {
		try {
			if ( isset( $_POST['woocommerce_pay'], $_GET['key'] ) ) {
				$nonce_value = wc_get_var( $_REQUEST['woocommerce-pay-nonce'], wc_get_var( $_REQUEST['_wpnonce'], '' ) ); // @codingStandardsIgnoreLine.
	
				if ( ! wp_verify_nonce( $nonce_value, 'woocommerce-pay' ) ) {
					throw new Exception( __( 'Invalid request.', 'airwallex-online-payments-gateway' ) );
				}
			} else {
				throw new Exception( __( 'Invalid request.', 'airwallex-online-payments-gateway' ) );
			}

			$order_key = isset($_GET['key']) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
			$order_id  = isset($_GET['order_id']) ? absint( $_GET['order_id'] ) : 0;
			$order     = wc_get_order( $order_id );
			if ( ! $order || ! hash_equals( $order_key, $order->get_order_key() ) || ! $order->needs_payment() ) {
				throw new Exception( __( 'You are not authorized to update this order.', 'airwallex-online-payments-gateway' ) );
			}

			$payment_method_id = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : false;
			if ( ! $payment_method_id ) {
				throw new Exception( __( 'Invalid payment method.', 'airwallex-online-payments-gateway' ) );
			}
			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
			$payment_method     = isset( $available_gateways[ $payment_method_id ] ) ? $available_gateways[ $payment_method_id ] : false;
			if ( ! $payment_method ) {
				throw new Exception( __( 'Invalid payment method.', 'airwallex-online-payments-gateway' ) );
			}
			$order->set_payment_method( $payment_method );
			$order->save();

			$result = $payment_method->process_payment($order->get_id());

			wp_send_json($result);
		} catch ( Exception $e ) {
			$this->logService->error( __METHOD__, $e->getMessage() );
			wc_add_notice( $e->getMessage(), 'error' );
			wp_send_json([
				'result' => 'fail',
				'error' => $e->getMessage(),
			]);
		}
	}

	private function getOrderAndPaymentIntentForConfirmation() {
		$order = $this->getOrderFromRequest('getOrderAndPaymentIntentForConfirmation');
		$paymentIntentId = $order->get_meta(OrderService::META_KEY_INTENT_ID);

		if (empty($paymentIntentId)) {
			$errorMessage = 'Order confirmation error: Unable to retrieve a valid intent ID.';
			RemoteLog::error( json_encode(['msg' => $errorMessage, '$_GET' => $_GET]), RemoteLog::ON_PAYMENT_CONFIRMATION_ERROR);
			throw new Exception( __( $errorMessage, 'airwallex-online-payments-gateway' ) );
		}

		return array(
			'order_id'          => $order->get_id(),
			'payment_intent_id' => $paymentIntentId,
		);
	}

	public function getCardAVSResult($paymentIntent) {
		$additionalInfo = [
			'avs_check' => $paymentIntent->getLatestPaymentAttempt()['authentication_data']['avs_result'] ?? '',
			'brand' => $paymentIntent->getLatestPaymentAttempt()['payment_method']['card']['brand'] ?? '',
			'last4' => $paymentIntent->getLatestPaymentAttempt()['payment_method']['card']['last4'] ?? '',
			'cvc_check' => $paymentIntent->getLatestPaymentAttempt()['authentication_data']['cvc_result'] ?? '',
		];

		$orderNote = sprintf(
			'<p>%1$s</p>
			<ul>
				<li>%2$s</li>
				<li>%3$s</li>
				<li>%4$s</li>
				<li>%5$s</li>
			</ul>',
			__('Address Verification Result', 'airwallex-online-payments-gateway'),
			__('AVS:', 'airwallex-online-payments-gateway') . ' ' . esc_html($additionalInfo['avs_check']),
			__('Card Brand:', 'airwallex-online-payments-gateway') . ' ' . esc_html(strtoupper($additionalInfo['brand'])),
			__('Card Last Digits:', 'airwallex-online-payments-gateway') . ' ' . esc_html($additionalInfo['last4']),
			__('CVC:', 'airwallex-online-payments-gateway') . ' ' . esc_html($additionalInfo['cvc_check'])
		);

		return $orderNote;
	}

	/**
	 * Retrieves the 3DS authentication data of the payment.
	 *
	 * @return string The formatted HTML string containing the 3DS authentication data.
	 */
	public function getThreeDSAuthenticationData($paymentIntent) {
		$authData = [
			'threeDS_triggered' => !empty($paymentIntent->getLatestPaymentAttempt()['authentication_data']['ds_data']['version']),
			"frictionless" => $paymentIntent->getLatestPaymentAttempt()['authentication_data']['ds_data']['frictionless'] ?? '',
			'authenticated' => $paymentIntent->getLatestPaymentAttempt()['authentication_data']['ds_data']['pa_res_status'] ?? '',
		];

		$orderNote = $authData['threeDS_triggered'] ? sprintf(
			'<p>%1$s</p>
			<ul>
				<li>%2$s</li>
				<li>%3$s</li>
				<li>%4$s</li>
			</ul>',
			__('3D Secure Data', 'airwallex-online-payments-gateway'),
			__('Triggered:', 'airwallex-online-payments-gateway') . ' ' . __('Y - Triggered', 'airwallex-online-payments-gateway'),
			__('Frictionless:', 'airwallex-online-payments-gateway') . ' ' . (StructPaymentIntent::THREE_DS_FRICTIONLESS_MAP[$authData['frictionless']] ?? $authData['frictionless']),
			__('Authenticated:', 'airwallex-online-payments-gateway') . ' ' . (StructPaymentIntent::THREE_DS_AUTHENTICATED_MAP[$authData['authenticated']] ?? $authData['authenticated'])
		) : sprintf(
			'<p>%1$s</p>
			<ul>
				<li>%2$s</li>
			</ul>',
			__('3D Secure Data', 'airwallex-online-payments-gateway'),
			__('Triggered:', 'airwallex-online-payments-gateway') . ' ' . __('N - Not Triggered', 'airwallex-online-payments-gateway')
		);

		return $orderNote;
	}

	public function paymentConfirmation() {
		try {
			$orderInformation = $this->getOrderAndPaymentIntentForConfirmation();
			$orderId          = $orderInformation['order_id'];
			$paymentIntentId  = $orderInformation['payment_intent_id'];
			$orderService     = new OrderService();

			$this->logService->debug(
				'paymentConfirmation() init',
				array(
					'paymentIntent' => $paymentIntentId,
					'orderId'       => $orderId,
				)
			);

			/** @var StructPaymentIntent $paymentIntent */
			$paymentIntent = (new RetrievePaymentIntent())->setPaymentIntentId($paymentIntentId)->send();
			$this->logService->debug(
				'paymentConfirmation() payment intent: ' . $paymentIntentId
			);

			if ( ! empty( $_GET['awx_return_result'] ) ) {
				$this->handleRedirectWithReturnResult( $paymentIntent );
			}

			$order = wc_get_order( $orderId );

			try {
				$orderService->setPaymentSuccess($order, $paymentIntent, __METHOD__);
			} catch (Exception $e) {
				$this->logService->error( __METHOD__, $e->getMessage() );
				wc_add_notice( __( $e->getMessage(), 'airwallex-online-payments-gateway' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				die;
			}

			if ( $paymentIntent->getStatus() === StructPaymentIntent::STATUS_REQUIRES_CUSTOMER_ACTION ) {
				$this->logService->debug( 'paymentConfirmation() pending status: ' . $paymentIntentId );
				$orderService->setPendingStatus( $order );
			} else if (!$paymentIntent->isAuthorized() && !$paymentIntent->isCaptured()) {
				$this->logService->warning( 'paymentConfirmation() invalid status: ' . $paymentIntentId );
				OrderService::getInstance()->setTemporaryOrderStateAfterDecline( $order );
				$error = empty($_GET['confirmationError']) ? 'Airwallex payment error' : sanitize_text_field( wp_unslash( $_GET['confirmationError'] ) );
				wc_add_notice( __( $error, 'airwallex-online-payments-gateway' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				die;
			}

			/** @var StructPaymentIntent $paymentIntent */
			if ( ($paymentIntent->isAuthorized() || $paymentIntent->isCaptured())
				&& StructPaymentIntent::PAYMENT_METHOD_TYPE_CARD === strtolower($paymentIntent->getPaymentMethodType())) {
				$order->add_order_note($this->getCardAVSResult($paymentIntent));
				$order->add_order_note($this->getThreeDSAuthenticationData($paymentIntent));
			}

			WC()->cart->empty_cart();

			if ( (! empty($_GET['is_airwallex_save_checked']) && in_array($_GET['is_airwallex_save_checked'], ['true', '1'], true))
				|| $orderService->containsSubscription( $order->get_id() )) {
				try {
					if ($paymentIntent->getPaymentMethodType() === 'card') {
						$airwallexCustomerId = (new OrderService())->getAirwallexCustomerId( get_current_user_id() );
						Card::getInstance()->syncSaveCards($airwallexCustomerId, get_current_user_id());
					}
				} catch ( Exception | Error $e ) {
					$this->logService->error('Error syncing save cards: ', $e->getMessage());
				}
			}

			if ( class_exists('\WFOCU_Public') && method_exists('\WFOCU_Public', 'get_instance') ) {
				$wfocu_public = \WFOCU_Public::get_instance();

				if ( method_exists($wfocu_public, 'maybe_setup_upsell') ) {
					$wfocu_public->maybe_setup_upsell($order->get_id());
				}
			}

			wp_safe_redirect( $order->get_checkout_order_received_url() );
			die;
		} catch ( Exception $e ) {
			$this->logService->error( 'paymentConfirmation() payment confirmation controller action failed', $e->getMessage() );
			wc_add_notice( __( 'Airwallex payment error', 'airwallex-online-payments-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			die;
		}
	}

	/**
	 * Handle the redirect if awx_return_result is available in the redirect url.
	 * 
	 * Some payment method supports different return url for success/back/cancel payment.
	 * If the payment is not successful, we should redirect the shopper back to the checkout page.
	 * 
	 * @param StructPaymentIntent $paymentIntent
	 */
	private function handleRedirectWithReturnResult( $paymentIntent ) {
		$awxReturnResult = isset($_GET['awx_return_result']) ? wc_clean( $_GET['awx_return_result'] ) : '';
		switch ($awxReturnResult) {
			case 'success':
				break;
			case 'failure':
			case 'cancel':
			case 'back':
				if ( $paymentIntent->isCaptured() || $paymentIntent->isAuthorized() ) {
					$this->logService->warning( __METHOD__ . ' Return result does not match with intent status. ', [
						'intentStatus' => $paymentIntent->getStatus(),
						'returnResult' => $awxReturnResult,
					] );
				} else {
					if ('failure' === $awxReturnResult) {
						wc_add_notice( __( 'Payment failed to be authenticated, the payment provider declined this transaction.', 'airwallex-online-payments-gateway' ), 'error' );
					}
					$this->logService->debug( __METHOD__ . ' Payment Incomplete: The transaction was not finalized by the user. Return result ' . $awxReturnResult );
					wp_safe_redirect( wc_get_checkout_url() );
					die;
				}
				break;
			default:
				break;
		}
	}

	public function webhook() {
		$body = file_get_contents( 'php://input' );
		$webhookService = new WebhookService();
		try {
			$webhookService->process( $this->getRequestHeaders(), $body );
			wp_send_json( array( 'success' => 1 ), 200 );
			die;
		} catch ( Exception $exception ) {
			$this->logService->warning( 'webhook exception', array( 'msg' => $exception->getMessage() ) );
			RemoteLog::error( json_encode(['msg' => $exception->getMessage(), 'body' => $body]), RemoteLog::ON_PROCESS_WEBHOOK_ERROR);
			wp_send_json( array( 'success' => 0 ), 401 );
			die;
		}
	}

	private function getRequestHeaders() {
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

	public function connectionTest() {
		check_ajax_referer('wc-airwallex-admin-settings-connection-test', 'security');

		$env  = isset($_POST['env']) ? wc_clean(wp_unslash($_POST['env'])) : '';

		try {
			$apiClient = AdminClient::getInstance();
			if ($env) {
				$apiClient->setClientId(Util::getClientId($env));
				$apiClient->setApiKey(Util::getApiKey($env));
				$apiClient->setIsSandbox('demo' === $env);
				update_option('airwallex_enable_sandbox', 'demo' === $env ? 'yes' : 'no');
			}

			if ( $apiClient->testAuth() ) {
				wp_send_json([
					'success' => true,
					'message' => __('Connection test to Airwallex was successful.', 'airwallex-online-payments-gateway'),
				]);
			} else {
				wp_send_json([
					'success' => false,
					'message' => __('Invalid client id or api key, please check your entries.', 'airwallex-online-payments-gateway'),
				]);
			}
		} catch (Exception $e) {
			wp_send_json([
				'success' => false,
				'message' => __('Something went wrong.', 'airwallex-online-payments-gateway'),
			]);
		}
	}

	public function connectionClick() {
		check_ajax_referer('wc-airwallex-admin-settings-connection-click', 'security');

		$env  = isset($_POST['env']) ? wc_clean(wp_unslash($_POST['env'])) : '';

		if (!in_array($env, ['demo', 'prod'], true)) {
			wp_send_json([
				'success' => false,
				'message' => __('Invalid request.', 'airwallex-online-payments-gateway'),
			]);
			die;
		}

		update_option('airwallex_connection_clicked_' . $env, 'yes');

		wp_send_json([
			'success' => true,
		]);
	}

	public function isPOSEnabled() {
		try {
			(new GetTerminalList())->setPageSize(5)->setStatus(StructTerminal::STATUS_ACTIVE)->send();
		} catch (UnauthorizedException $e) {
			$this->sendPaymentMethodTypeEnabledResponse(false);
			return;
		}
		$this->sendPaymentMethodTypeEnabledResponse(true);
	}

	public function sendPaymentMethodTypeEnabledResponse($isEnabled) {
		wp_send_json([
			'success' => true,
			'is_enabled' => $isEnabled,
		]);
	}

	public function isPaymentMethodEnabled() {
		check_ajax_referer('wc-airwallex-admin-settings-is-payment-method-enabled', 'security');
		$paymentMethodTypeFromRequest  = isset($_GET['payment_method_type']) ? wc_clean(wp_unslash($_GET['payment_method_type'])) : '';

		try {
			if ($paymentMethodTypeFromRequest === 'pos') {
				$this->isPOSEnabled();
				return;
			}
			$paymentMethodTypes = (new GetPaymentMethodTypeList())
				->setActive(true)
				->setTransactionMode(StructPaymentMethodType::PAYMENT_METHOD_TYPE_ONE_OFF)
				->getPaymentMethodTypes();
			/** @var StructPaymentMethodType $paymentMethodType */
			foreach ($paymentMethodTypes as $paymentMethodType) {
				if ($paymentMethodType->getName() === $paymentMethodTypeFromRequest) {
					$this->sendPaymentMethodTypeEnabledResponse(true);
					return;
				}
			}
			$this->sendPaymentMethodTypeEnabledResponse(false);
		} catch (Exception $e) {
			LogService::getInstance()->error($e->getMessage(), __METHOD__);
			wp_send_json([
				'success' => false,
			]);
		}
	}
}
