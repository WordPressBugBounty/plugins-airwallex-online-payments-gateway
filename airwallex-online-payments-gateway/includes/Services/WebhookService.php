<?php

namespace Airwallex\Services;

use Exception;
use WC_Order;
use WC_Order_Refund;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Refund as StructRefund;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent as StructPaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Configuration\Webhook;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Retrieve as RetrievePaymentIntent;

class WebhookService {

	/**
	 * Process webhook event
	 *
	 * @param $headers
	 * @param $msg
	 * @throws Exception
	 */
	public function process( $headers, $msg ) {
		$logService = LogService::getInstance();
		$orderService = $this->getOrderService();
		try {
			$this->verifySignature( $headers, $msg );
		} catch ( Exception $e ) {
			$logService->debug( 'unable to verify webhook signature: ' . $e->getMessage() );
			wp_send_json( array( 'success' => 0 ), 401 );
			die;
		}

		$messageData = json_decode( $msg, true );
		$eventType       = $messageData['name'];
		$eventObjectType = explode( '.', $eventType )[0];
		if ( 'payment_intent' === $eventObjectType ) {
			$logService->debug( '🖧 received payment_intent webhook' );
			$paymentIntent = new StructPaymentIntent( $messageData['data']['object'] );
			$logService->debug( '🖧 received payment_intent webhook, name: ' . $eventType . ' payment intent id: ' . $paymentIntent->getId());
			$orderId       = $this->getOrderIdForPaymentIntent( $paymentIntent );

			/**
			 * WC_Order object
			 *
			 * @var \WC_Order $order
			 */
			$order = wc_get_order( $orderId );

			if ( $order ) {
				$upsellPaymentIntentIds = [];
				if (class_exists('\WFOCU_Gateway')) {
					$upsellPaymentIntentIds = \Airwallex\Gateways\FunnelKitUpsell::getInstance()->getUpsellPaymentIntentIds($order);
				}
				if ( in_array( $paymentIntent->getId(), $upsellPaymentIntentIds, true ) ) {
					return;
				}
				$this->verifyIntentFromOrder($order, $paymentIntent, $eventType);
				switch ( $eventType ) {
					case Webhook::STATUS_PAYMENT_INTENT_CANCELLED:
						$logService->debug( 'skip cancelled webhook to avoid long pending orders being marked failed', $eventType );
						break;
					case Webhook::STATUS_PAYMENT_INTENT_PAYMENT_FAILED:
						$logService->debug( '🖧 payment failed webhook, marking order declined', $eventType );
						$orderService->setTemporaryOrderStateAfterDecline( $order );
						break;
					case Webhook::STATUS_PAYMENT_INTENT_SUCCEEDED:
					case Webhook::STATUS_PAYMENT_INTENT_CAPTURE_REQUIRED:
					case Webhook::STATUS_PAYMENT_INTENT_REQUIRES_CAPTURE:
						if ($order instanceof WC_Order) {
							// Never complete an order from the webhook body alone. Re-fetch the
							// payment intent from the Airwallex API and complete the order based on
							// the trusted intent's status, amount and currency instead.
							$trustedPaymentIntent = $this->getTrustedPaymentIntent( $paymentIntent );
							$orderService->setPaymentSuccess( $order, $trustedPaymentIntent, __METHOD__ );
						}
						break;
					default:
						if ( $paymentIntent->getStatus() === StructPaymentIntent::STATUS_REQUIRES_CUSTOMER_ACTION ) {
							$logService->debug( '🖧 detected pending status from webhook', $eventType );
							$orderService->setPendingStatus( $order );
						}
				}

				if (method_exists($order, 'add_order_note')) {
					$order->add_order_note( 'Airwallex Webhook notification: ' . $eventType . "\n\n" . 'Amount: ' . $paymentIntent->getAmount() . $paymentIntent->getCurrency() . "\n\nCaptured amount: " . $paymentIntent->getCapturedAmount() );
				}
			}
			return;
		}
		if ( 'refund' === $eventObjectType ) {
			$logService->debug( '🖧 received refund webhook' );
			$refund = new StructRefund( $messageData['data']['object'] );
			if (!in_array($refund->getStatus(), [StructRefund::STATUS_SETTLED, StructRefund::STATUS_SUCCEEDED, StructRefund::STATUS_PROCESSING, StructRefund::STATUS_ACCEPTED], true)) {
				return;
			}
			$refundMetaKey = OrderService::getRefundOrderMetaKey($refund->getId());

			$logService->debug( '🖧 received refund webhook, refund id: ' . $refund->getId() );
			$order = $orderService->getOrderByAirwallexRefundId( $refund->getId() );
			if ( $order ) {
				$refundInfo = $order->get_meta( $refundMetaKey, true ) ?: [];
				if ( empty($refundInfo['status']) || StructRefund::STATUS_SUCCEEDED !== $refundInfo['status'] ) {
					$order->add_order_note(
						sprintf(
							__( "Airwallex Webhook notification: %1\$s \n\n Amount:  (%2\$s).", 'airwallex-online-payments-gateway' ),
							$eventType,
							$refund->getAmount()
						)
					);
					$refundInfo['status'] = StructRefund::STATUS_SUCCEEDED;
					$order->update_meta_data( $refundMetaKey, $refundInfo );
					$order->save();
				}
				$logService->debug( __METHOD__ . " - Order {$order->get_id()}, refund id {$refund->getId()}, event type {$messageData['name']}, event id {$messageData['id']}" );
			} else {
				$paymentIntentId = $refund->getPaymentIntentId();
				$order           = $orderService->getOrderByPaymentIntentId( $paymentIntentId );
				if ( empty( $order ) ) {
					$logService->warning( __METHOD__ . ' - no order found for refund', array( 'paymentIntent' => $paymentIntentId ) );
					throw new Exception( esc_html( 'no order found for refund on payment_intent ' . $paymentIntentId ) );
				}
				$order->add_order_note(
					sprintf(
						__( "Airwallex Webhook notification: %1\$s \n\n Amount:  (%2\$s).", 'airwallex-online-payments-gateway' ),
						$eventType,
						$refund->getAmount()
					)
				);

				/*
					Retain some of the old logic temporarily to account for any unprocessed refunds created prior to the release.
					The old logic should be removed at a later stage.
				*/
				if ( ! $orderService->getRefundIdByAirwallexRefundId( $refund->getId() ) ) {
					if ( $orderService->getRefundByAmountAndTime( $order->get_id(), $refund->getAmount() ) ) {
						$order->add_meta_data( $refundMetaKey, array( 'status' => StructRefund::STATUS_SUCCEEDED ) );
						$order->save();
					} else {
						$refundData = array(
							'amount'         => $refund->getAmount(),
							'reason'         => $refund->getReason(),
							'order_id'       => $order->get_id(),
							'refund_payment' => false,
							'restock_items'  => false,
						);
						$wcRefund = wc_create_refund($refundData);
						if ( $wcRefund instanceof WC_Order_Refund ) {
							$order->add_meta_data( $refundMetaKey, array( 'status' => StructRefund::STATUS_SUCCEEDED ) );
							$order->save();
						} else {
							$order->add_order_note(
								sprintf(
									/* translators: 1.Refund ID. */
									__( 'Failed to create WC refund from webhook notification for refund (%s).', 'airwallex-online-payments-gateway' ),
									$refund->getId()
								)
							);
							$logService->error( __METHOD__ . ' failed to create WC refund from webhook notification', $refundData );
						}
					}
				}
			}
		}
	}

	/**
	 * Get the OrderService instance.
	 *
	 * Wrapped in its own method so it can be overridden in tests.
	 *
	 * @return OrderService
	 */
	protected function getOrderService() {
		return new OrderService();
	}

	/**
	 * Retrieve the payment intent from the Airwallex API so an order is only ever completed
	 * from a trusted source, not from the (unauthenticated-reachable) webhook body.
	 *
	 * Wrapped in its own method so it can be overridden in tests.
	 *
	 * @param StructPaymentIntent $paymentIntentFromWebhook
	 * @return StructPaymentIntent
	 * @throws Exception when the intent cannot be retrieved.
	 */
	protected function getTrustedPaymentIntent( StructPaymentIntent $paymentIntentFromWebhook ) {
		return ( new RetrievePaymentIntent() )->setPaymentIntentId( $paymentIntentFromWebhook->getId() )->send();
	}

	public function verifyIntentFromOrder($order, StructPaymentIntent $paymentIntent, $eventType) {
		if ( empty( $order ) ) {
			throw new Exception( esc_html( 'No order found for the order id in webhook. Payment intent id: ' . $paymentIntent->getId() ) );
		}
		// A genuine payment_intent webhook always carries an intent id. Reject an empty id so it
		// cannot match an order that has no intent id yet (empty === empty), which would otherwise
		// let an attacker target any pending order.
		if ( '' === (string) $paymentIntent->getId() ) {
			throw new Exception( 'Missing payment intent id in webhook event ' . esc_html( $eventType ) );
		}
		$paymentIntentIdFromOrder = $order->get_meta( OrderService::META_KEY_INTENT_ID );
		if ( $paymentIntent->getId() !== $paymentIntentIdFromOrder ) {
			if (in_array($paymentIntent->getStatus(), [StructPaymentIntent::STATUS_CREATED, StructPaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD], true)) {
				return;
			}
			throw new Exception('Mismatch in payment intent ID from webhook and order. Debug info: ' . wp_json_encode([
				'payment_intent_id_from_order' => $paymentIntentIdFromOrder,
				'payment_intent_id_from_webhook' => $paymentIntent->getId(),
				'event_name' => $eventType,
			]));
		}
	}

	/**
	 * Verify webhook content and signature
	 *
	 * @param array $headers
	 * @param string $msg
	 * @throws Exception
	 */
	private function verifySignature( $headers, $msg ) {

		$timestamp = isset( $headers['x-timestamp'] ) ? $headers['x-timestamp'] : '';
		$secret    = Util::getWebhookSecret();
		$signature = isset( $headers['x-signature'] ) ? $headers['x-signature'] : '';

		// A webhook must never be accepted unless it is authenticated with a secret that is
		// actually configured. Refuse to verify when the secret, timestamp or signature is
		// empty rather than computing an HMAC with an empty key, which an attacker can
		// trivially reproduce on a store that never set a webhook secret.
		if ( '' === (string) $secret ) {
			throw new Exception( 'Webhook secret is not configured; refusing to process the webhook.' );
		}
		if ( '' === (string) $timestamp || '' === (string) $signature ) {
			throw new Exception( 'Missing webhook timestamp or signature.' );
		}

		$calculatedSignature = hash_hmac( 'sha256', $timestamp . $msg, $secret );
		if ( ! hash_equals( $calculatedSignature, (string) $signature ) ) {
			throw new Exception(
				sprintf(
					'Invalid signature: %1$s vs. %2$s',
					esc_html( $signature ),
					esc_html( $calculatedSignature )
				)
			);
		}
	}

	/**
	 * Get order id from payment intent
	 *
	 * @param StructPaymentIntent $paymentIntent
	 * @return int
	 */
	private function getOrderIdForPaymentIntent( StructPaymentIntent $paymentIntent ) {
		$metaData = $paymentIntent->getMetadata();
		if ( ! empty( $metaData['wp_order_id'] ) ) {
			return (int) $metaData['wp_order_id'];
		} else {
			return (int) $paymentIntent->getMerchantOrderId();
		}
	}
}
