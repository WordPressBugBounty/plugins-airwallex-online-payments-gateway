<?php

namespace Airwallex\Services;

use Airwallex\Main;
use Airwallex\Struct\PaymentIntent;
use Airwallex\Struct\Refund;
use Exception;
use WC_Order;
use WC_Order_Refund;

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
		$orderService = new OrderService();
		try {
			$this->verifySignature( $headers, $msg );
		} catch ( Exception $e ) {
			$logService->warning( 'unable to verify webhook signature', array( $headers, $msg ) );
			throw $e;
		}

		$messageData = json_decode( $msg, true );
		$logService->debug( '🖧 received webhook notification', $messageData );
		$eventType       = $messageData['name'];
		$eventObjectType = explode( '.', $eventType )[0];
		if ( 'payment_intent' === $eventObjectType ) {
			$logService->debug( '🖧 received payment_intent webhook' );
			$paymentIntent = new PaymentIntent( $messageData['data']['object'] );
			$orderId       = $this->getOrderIdForPaymentIntent( $paymentIntent );

			/**
			 * WC_Order object
			 *
			 * @var \WC_Order $order
			 */
			$order = wc_get_order( $orderId );

			if ( $order ) {
				$this->verifyIntentFromOrder($order, $paymentIntent->getId());
				switch ( $eventType ) {
					case 'payment_intent.cancelled':
						$order->update_status( 'failed', 'Airwallex Webhook' );
						break;
					case 'payment_intent.succeeded':
					case 'payment_intent.capture_required':
					case 'payment_intent.requires_capture':
						if ($order instanceof WC_Order) {
							$orderService->setPaymentSuccess( $order, $paymentIntent );
						}
						break;
					default:
						$attempt = $paymentIntent->getLatestPaymentAttempt();
						if ( isset($attempt['']) && in_array( $attempt[''], array_map( 'strtolower', PaymentIntent::PENDING_STATUSES ), true ) ) {
							$logService->debug( '🖧 detected pending status from webhook', $eventType );
							$orderService->setPendingStatus( $order );
						}
				}

				if (method_exists($order, 'add_order_note')) {
					$order->add_order_note( 'Airwallex Webhook notification: ' . $eventType . "\n\n" . 'Amount: ' . $paymentIntent->getAmount() . $paymentIntent->getCurrency() . "\n\nCaptured amount: " . $paymentIntent->getCapturedAmount() );
				}
			}
		} elseif ( 'refund.processing' === $eventType || 'refund.accepted' === $eventType || 'refund.succeeded' === $eventType ) {
			$logService->debug( '🖧 received refund webhook' );
			$refund = new Refund( $messageData['data']['object'] );

			$order = $orderService->getOrderByAirwallexRefundId( $refund->getId() );
			if ( $order ) {
				$refundInfo = $order->get_meta( $refund->getMetaKey(), true );
				if ( Refund::STATUS_SUCCEEDED !== $refundInfo['status'] ) {
					$order->add_order_note(
						sprintf(
							__( "Airwallex Webhook notification: %1\$s \n\n Amount:  (%2\$s).", 'airwallex-online-payments-gateway' ),
							$eventType,
							$refund->getAmount()
						)
					);
					$refundInfo['status'] = Refund::STATUS_SUCCEEDED;
					$order->update_meta_data( $refund->getMetaKey(), $refundInfo );
					$order->save();
				}
				$logService->debug( __METHOD__ . " - Order {$order->get_id()}, refund id {$refund->getId()}, event type {$messageData['name']}, event id {$messageData['id']}" );
			} else {
				$paymentIntentId = $refund->getPaymentIntentId();
				$order           = $orderService->getOrderByPaymentIntentId( $paymentIntentId );
				if ( empty( $order ) ) {
					$logService->warning( __METHOD__ . ' - no order found for refund', array( 'paymentIntent' => $paymentIntentId ) );
					throw new Exception( 'no order found for refund on payment_intent ' . $paymentIntentId );
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
						$order->add_meta_data( $refund->getMetaKey(), array( 'status' => Refund::STATUS_SUCCEEDED ) );
						$order->save();
					} else {
						$wcRefund = wc_create_refund(
							array(
								'amount'         => $refund->getAmount(),
								'reason'         => $refund->getReason(),
								'order_id'       => $order->get_id(),
								'refund_payment' => false,
								'restock_items'  => false,
							)
						);
						if ( $wcRefund instanceof WC_Order_Refund ) {
							$order->add_meta_data( $refund->getMetaKey(), array( 'status' => Refund::STATUS_SUCCEEDED ) );
							$order->save();
						} else {
							$order->add_order_note(
								sprintf(
									/* translators: 1.Refund ID. */
									__( 'Failed to create WC refund from webhook notification for refund (%s).', 'airwallex-online-payments-gateway' ),
									$refund->getId()
								)
							);
							$logService->error( __METHOD__ . ' failed to create WC refund from webhook notification', $wcRefund );
						}
					}
				}
			}
		}
	}

	public function verifyIntentFromOrder($order, $paymentIntentId) {
		if ( empty( $order ) ) {
			throw new Exception('No order found for the order id in webhook. Payment intent id: ' . $paymentIntentId);
		}

		$paymentIntentIdFromOrder = $order->get_meta( '_tmp_airwallex_payment_intent' );
		if ( $paymentIntentId !== $paymentIntentIdFromOrder ) {
			throw new Exception('Mismatch in payment intent ID from webhook and order. Debug info: ' . wp_json_encode([
				'payment_intent_id_from_order' => $paymentIntentIdFromOrder,
				'payment_intent_id_from_webhook' => $paymentIntentId,
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

		$timestamp           = $headers['x-timestamp'];
		$secret              = Util::getWebhookSecret();
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

	/**
	 * Get order id from payment intent
	 *
	 * @param PaymentIntent $paymentIntent
	 * @return int
	 */
	private function getOrderIdForPaymentIntent( PaymentIntent $paymentIntent ) {
		$metaData = $paymentIntent->getMetadata();
		if ( ! empty( $metaData['wp_order_id'] ) ) {
			return (int) $metaData['wp_order_id'];
		} else {
			return (int) $paymentIntent->getMerchantOrderId();
		}
	}
}
