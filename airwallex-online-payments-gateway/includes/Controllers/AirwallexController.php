<?php

namespace Airwallex\Controllers;

use Airwallex\Client\AbstractClient;
use Airwallex\Client\AdminClient;
use Airwallex\Client\CardClient;
use Airwallex\Client\MainClient;
use Airwallex\Gateways\Card;
use Airwallex\Gateways\Main;
use Airwallex\Gateways\WeChat;
use Airwallex\Services\LogService;
use Airwallex\Services\OrderService;
use Airwallex\Services\WebhookService;
use Airwallex\Struct\PaymentIntent;
use Airwallex\Client\WeChatClient;
use Airwallex\Gateways\AirwallexGatewayTrait;
use Airwallex\Services\Util;
use Exception;
use WC_Order;

class AirwallexController {

	use AirwallexGatewayTrait;

	protected $logService;

	public function __construct() {
		$this->logService = LogService::getInstance();
	}

	private function getPaymentDetailForRedirect(AbstractClient $apiClient, $gateway) {
		$order = $this->getOrderFromRequest('getPaymentDetailForRedirect');

		$paymentIntentId = $order->get_meta('_tmp_airwallex_payment_intent');
		$paymentIntent   = $apiClient->getPaymentIntent( $paymentIntentId );
		$clientSecret = $paymentIntent->getClientSecret();
		$customerId = $paymentIntent->getCustomerId();
		$confirmationUrl = $gateway->get_payment_confirmation_url();
		$isSandbox       = $gateway->is_sandbox();

		return [$order, $paymentIntentId, $clientSecret, $customerId, $confirmationUrl, $isSandbox];
	}

	public function cardPayment() {
		try {
			$gateway = new Card();
			$gateway->enqueueScriptForRedirectCard();
			$apiClient = CardClient::getInstance();
			list( $order, $paymentIntentId, $paymentIntentClientSecret, $airwallexCustomerId, $confirmationUrl, $isSandbox ) = $this->getPaymentDetailForRedirect($apiClient, $gateway);
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
			$gateway = new Main();
			$gateway->enqueueScripts();
			$apiClient = MainClient::getInstance();
			list( $order, $paymentIntentId, $paymentIntentClientSecret, $airwallexCustomerId, $confirmationUrl, $isSandbox ) = $this->getPaymentDetailForRedirect($apiClient, $gateway);
			
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
			$gateway = new WeChat();
			$gateway->enqueueScripts();
			$apiClient = WeChatClient::getInstance();
			list( $order, $paymentIntentId, $paymentIntentClientSecret, $airwallexCustomerId, $confirmationUrl, $isSandbox ) = $this->getPaymentDetailForRedirect($apiClient, $gateway);
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
			wc_add_notice( $e->getMessage(), 'error' );
			wp_send_json([
				'result' => 'fail',
				'error' => $e->getMessage(),
			]);
		}
	}

	private function getOrderAndPaymentIntentForConfirmation() {
		$order = $this->getOrderFromRequest('getOrderAndPaymentIntentForConfirmation');
		$paymentIntentId = $order->get_meta('_tmp_airwallex_payment_intent');

		if (empty($paymentIntentId)) {
			$errorMessage = 'Order confirmation error: Unable to retrieve a valid intent ID.';
			$this->logService->remoteError( LogService::ON_PAYMENT_CONFIRMATION_ERROR, 'payment confirmation exception', array( 'msg' => $errorMessage, '$_GET' => json_encode($_GET) ) );
			throw new Exception( __( $errorMessage, 'airwallex-online-payments-gateway' ) );
		}

		return array(
			'order_id'          => $order->get_id(),
			'payment_intent_id' => $paymentIntentId,
		);
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
					'session'       => array(
						'cookie' => WC()->session->get_session_cookie(),
						'data'   => WC()->session->get_session_data(),
					),
				)
			);

			$apiClient     = CardClient::getInstance();
			$paymentIntent = $apiClient->getPaymentIntent( $paymentIntentId );
			$this->logService->debug(
				'paymentConfirmation() payment intent',
				array(
					'paymentIntent' => $paymentIntent->toArray(),
				)
			);

			if ( ! empty( $_GET['awx_return_result'] ) ) {
				$this->handleRedirectWithReturnResult( $paymentIntent );
			}

			$order = wc_get_order( $orderId );


			if ( ! empty($_GET['is_airwallex_save_checked']) && in_array($_GET['is_airwallex_save_checked'], ['true', '1'], true) ) {
				try {
					(new Card())->syncSaveCards();
				} catch ( Exception $e ) {
					$this->logService->error('Error syncing save cards: ', $e->getMessage());
				}
			}

			if ( empty( $order ) ) {
				throw new Exception( 'Order not found: ' . $orderId );
			}

			if ( number_format( $paymentIntent->getAmount(), 2 ) !== number_format( $order->get_total(), 2 ) ) {
				//amount mismatch
				$this->logService->error( 'paymentConfirmation() payment amounts did not match', array( number_format( $paymentIntent->getAmount(), 2 ), number_format( $order->get_total(), 2 ), $paymentIntent->toArray() ) );
				$this->setTemporaryOrderStateAfterDecline( $order );
				wc_add_notice( __( 'Airwallex payment error', 'airwallex-online-payments-gateway' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				die;
			}

			if ( $paymentIntent->getStatus() === PaymentIntent::STATUS_SUCCEEDED ) {
				$orderService->paymentCompleteByCapture($order, $this->logService, 'checkout', $paymentIntent);
			} elseif ( $paymentIntent->getStatus() === PaymentIntent::STATUS_REQUIRES_CAPTURE ) {
				$orderService->paymentCompleteByAuthorize($order, $this->logService, 'checkout', $paymentIntent);
			} elseif ( in_array( $paymentIntent->getStatus(), PaymentIntent::PENDING_STATUSES, true ) ) {
				$this->logService->debug( 'paymentConfirmation() pending status', array( $paymentIntent->toArray() ) );
				$orderService->setPendingStatus( $order );
			} else {
				$this->logService->warning( 'paymentConfirmation() invalid status', array( $paymentIntent->toArray() ) );
				$this->setTemporaryOrderStateAfterDecline( $order );
				wc_add_notice( __( 'Airwallex payment error', 'airwallex-online-payments-gateway' ), 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				die;
			}

			if (in_array( $paymentIntent->getStatus(), PaymentIntent::SUCCESS_STATUSES, true )
				&& PaymentIntent::PAYMENT_METHOD_TYPE_CARD === strtoupper($paymentIntent->getPaymentMethodType())) {
				$order->add_order_note($paymentIntent->getCardAVSResult());
				$order->add_order_note($paymentIntent->getThreeDSAuthenticationData());
			}

			WC()->cart->empty_cart();
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			die;
		} catch ( Exception $e ) {
			$this->logService->error( 'paymentConfirmation() payment confirmation controller action failed', $e->getMessage() );
			if ( ! empty( $order ) ) {
				$this->setTemporaryOrderStateAfterDecline( $order );
			}
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
	 * @param PaymentIntent $paymentIntent
	 */
	private function handleRedirectWithReturnResult( $paymentIntent ) {
		$awxReturnResult = isset($_GET['awx_return_result']) ? wc_clean( $_GET['awx_return_result'] ) : '';
		switch ($awxReturnResult) {
			case 'success':
				break;
			case 'failure':
			case 'cancel':
			case 'back':
				if ( in_array( $paymentIntent->getStatus(), PaymentIntent::SUCCESS_STATUSES, true ) ) {
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

	/**
	 * Set temporary order status after payment is declined
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	private function setTemporaryOrderStateAfterDecline( $order ) {
		( new OrderService() )->setTemporaryOrderStateAfterDecline( $order );
	}

	public function webhook() {
		$body = file_get_contents( 'php://input' );
		$this->logService->debug( '🖧 webhook body', array( 'body' => $body ) );
		$webhookService = new WebhookService();
		try {
			$webhookService->process( $this->getRequestHeaders(), $body );
			wp_send_json( array( 'success' => 1 ), 200 );
			die;
		} catch ( Exception $exception ) {
			$this->logService->warning( 'webhook exception', array( 'msg' => $exception->getMessage() ) );
			$this->logService->remoteError( LogService::ON_PROCESS_WEBHOOK_ERROR, 'webhook exception', array( 'msg' => $exception->getMessage() ) );
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

	/**
	 * Log js errors on the server side
	 */
	public function jsLog() {
		$body = json_decode( file_get_contents( 'php://input' ), true );
		if ( empty( $body['lg'] ) ) {
			return;
		}

		foreach ( $body['lg'] as $log ) {
			if ( $log['l'] <= 3000 ) {
				$this->logService->debug( $log['m'] );
			} elseif ( $log['lg'] <= 4000 ) {
				$this->logService->warning( $log['m'] );
			} else {
				$this->logService->error( $log['m'] );
			}
		}
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
}
