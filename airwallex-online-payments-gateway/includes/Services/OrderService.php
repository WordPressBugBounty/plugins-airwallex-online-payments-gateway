<?php

namespace Airwallex\Services;

use Airwallex\Client\AbstractClient;
use Airwallex\Client\CardClient;
use Airwallex\Client\HttpClient;
use Airwallex\Gateways\Card;
use Airwallex\Struct\PaymentIntent;
use Airwallex\Struct\Refund;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Exception;
use WC_Order;

class OrderService {

	/**
	 * Get order by payment intent ID
	 *
	 * @param $paymentIntentId
	 * @return WC_Order|null
	 */
	public function getOrderByPaymentIntentId( $paymentIntentId ) {
		global $wpdb;
		$orderId = null;
		if ( class_exists(OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS enabled
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'
					SELECT id FROM ' . OrdersTableDataStore::get_orders_table_name() . "
					WHERE
						type = 'shop_order'
							AND
						transaction_id = %s",
					array(
						$paymentIntentId,
					)
				)
			);
			if ( $row ) {
				$orderId = (int) $row->id;
			}
		} else {
			// HPOS disabled
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'
					SELECT p.ID FROM ' . $wpdb->posts . ' p
						JOIN ' . $wpdb->postmeta . " pm ON (p.ID = pm.post_id AND pm.meta_key = '_transaction_id')
					WHERE
						p.post_type = 'shop_order'
							AND
						pm.meta_value = %s",
					array(
						$paymentIntentId,
					)
				)
			);
			if ( $row ) {
				$orderId = (int) $row->ID;
			}
		}

		if ( $orderId ) {
			$order = wc_get_order( $orderId );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}
		return null;
	}

	public function getRefundByAmountAndTime( $orderId, $amount ) {
		global $wpdb;

		$startTime = ( new \DateTime( 'now - 600 seconds', new \DateTimeZone( '+0000' ) ) )->format( 'Y-m-d H:i:s' );
		$endTime   = ( new \DateTime( 'now + 600 seconds', new \DateTimeZone( '+0000' ) ) )->format( 'Y-m-d H:i:s' );

		if ( class_exists(OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS enabled

			$row = $wpdb->get_row(
				$wpdb->prepare(
					'
							SELECT o.id FROM 
											 ' . OrdersTableDataStore::get_orders_table_name() . ' o
											 JOIN ' . OrdersTableDataStore::get_orders_table_name() . ' o_parent ON (o.parent_order_id = o_parent.id)
											 JOIN ' . OrdersTableDataStore::get_meta_table_name() . " pm ON (o.id = pm.order_id AND pm.meta_key = '_refund_amount')
											 LEFT JOIN " . OrdersTableDataStore::get_meta_table_name() . " pm_refund_id ON (o.id = pm.order_id AND pm.meta_key = '_airwallex_refund_id')
							WHERE
								o_parent.payment_method LIKE %s
									AND
								o.type = 'shop_order_refund'
									AND
								o.parent_order_id = %s
									AND
								o.date_created_gmt > %s
									AND
								o.date_created_gmt < %s
									AND
								pm.meta_value = %s
									AND
								pm_refund_id.meta_value IS NULL",
					array(
						'airwallex_%',
						$orderId,
						$startTime,
						$endTime,
						number_format( $amount, 2 ),
					)
				)
			);
			if ( $row ) {
				return $row->id;
			}
		} else {
			// HPOS disabled

			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT p.ID FROM 
							 ' . $wpdb->posts . ' p
							 JOIN ' . $wpdb->postmeta . " pm ON (p.ID = pm.post_id AND pm.meta_key = '_refund_amount')
							 JOIN " . $wpdb->postmeta . " pm_payment ON (p.post_parent = pm_payment.post_id AND pm_payment.meta_key = '_payment_method' AND  pm_payment.meta_value LIKE %s)
							 LEFT JOIN " . $wpdb->postmeta . " pm_refund_id ON (p.ID = pm.post_id AND pm.meta_key = '_airwallex_refund_id')
							WHERE
								p.post_type = 'shop_order_refund'
									AND
								p.post_parent = %s
									AND
								p.post_date_gmt > %s
									AND
								p.post_date_gmt < %s
									AND
								pm.meta_value = %s
									AND
								pm_refund_id.meta_value IS NULL",
					array(
						'airwallex_%',
						$orderId,
						$startTime,
						$endTime,
						number_format( $amount, 2 ),
					)
				)
			);
			if ( $row ) {
				return $row->ID;
			}
		}


		return null;
	}

	/**
	 * Get WC refund id by airwallex refund ID
	 *
	 * @param string $refundId
	 * @return null|string
	 */
	public function getRefundIdByAirwallexRefundId( $refundId ) {
		global $wpdb;
		if ( class_exists(OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS enabled
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'
						SELECT o.id FROM ' . OrdersTableDataStore::get_orders_table_name() . ' o
							JOIN ' . OrdersTableDataStore::get_meta_table_name() . " pm ON (o.id = pm.order_id AND pm.meta_key = '_airwallex_refund_id')
						WHERE
							o.type = 'shop_order_refund'
								AND
							pm.meta_value = %s",
					array(
						$refundId,
					)
				)
			);
			if ( $row ) {
				return $row->id;
			}
		} else {
			// HPOS disabled
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'
						SELECT p.ID FROM ' . $wpdb->posts . ' p
							JOIN ' . $wpdb->postmeta . " pm ON (p.ID = pm.post_id AND pm.meta_key = '_airwallex_refund_id')
						WHERE
							p.post_type = 'shop_order_refund'
								AND
							pm.meta_value = %s",
					array(
						$refundId,
					)
				)
			);
			if ( $row ) {
				return $row->ID;
			}
		}
		return null;
	}

	/**
	 * Get WC order by airwallex refund ID
	 *
	 * @param string $refundId
	 * @return bool|WC_Order
	 */
	public function getOrderByAirwallexRefundId( $refundId ) {
		global $wpdb;
		if ( class_exists(OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS enabled
			$orderId = $wpdb->get_var(
				$wpdb->prepare(
					'
						SELECT wc_order.id
						FROM ' . OrdersTableDataStore::get_orders_table_name() . ' wc_order
						INNER JOIN ' . OrdersTableDataStore::get_meta_table_name() . " order_meta ON wc_order.id = order_meta.order_id
						WHERE wc_order.type = 'shop_order' AND order_meta.meta_key = %s",
					Refund::META_REFUND_ID . $refundId
				)
			);
		} else {
			// HPOS disabled
			$orderId = $wpdb->get_var(
				$wpdb->prepare(
					"
						SELECT wc_order.ID
						FROM {$wpdb->posts} wc_order
						INNER JOIN {$wpdb->postmeta} order_meta ON wc_order.ID = order_meta.post_id
						WHERE wc_order.post_type = 'shop_order' AND order_meta.meta_key = %s",
					Refund::META_REFUND_ID . $refundId
				)
			);
		}


		return empty( $orderId ) ? false : wc_get_order( $orderId );
	}

	/**
	 * Get airwallex customer ID
	 *
	 * @param int $wordpressCustomerId
	 * @param AbstractClient $client
	 * @return int|mixed
	 * @throws Exception
	 */
	public function getAirwallexCustomerId( $wordpressCustomerId, AbstractClient $client ) {

		if ( empty( $wordpressCustomerId ) ) {
			$wordpressCustomerId = uniqid();
		}

		$customer = $client->getCustomer( $wordpressCustomerId );
		if ( $customer ) {
			return $customer->getId();
		}
		$customer = $client->createCustomer( $wordpressCustomerId );
		return $customer->getId();
	}


	public function containsSubscription( $orderId ) {
		return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $orderId ) || wcs_is_subscription( $orderId ) || wcs_order_contains_renewal( $orderId ) ) );
	}

	protected function getPendingPaymentOrdersIds() {
		global $wpdb;

		if ( class_exists(OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS enabled
			return $wpdb->get_col(
				'
					SELECT wc_order.id FROM ' . OrdersTableDataStore::get_orders_table_name() . " wc_order
				 	LEFT JOIN " . OrdersTableDataStore::get_meta_table_name() . " order_meta_intent_not_found ON (wc_order.id = order_meta_intent_not_found.order_id AND order_meta_intent_not_found.meta_key = '_airwallex_payment_intent_not_found')
					WHERE
						payment_method = 'airwallex_card'
							AND
						type = 'shop_order'
							AND
						status = 'wc-pending'
							AND
						order_meta_intent_not_found.meta_value IS NULL"
			);
		} else {
			// HPOS disabled
			return $wpdb->get_col(
				'
					SELECT p.ID FROM ' . $wpdb->posts . ' p
						JOIN ' . $wpdb->postmeta . " pm ON (p.ID = pm.post_id AND pm.meta_key = '_payment_method' AND pm.meta_value = 'airwallex_card')
						LEFT JOIN " . $wpdb->postmeta . " pm_intent_not_found ON (p.ID = pm_intent_not_found.post_id AND pm_intent_not_found.meta_key = '_airwallex_payment_intent_not_found')
					WHERE
						p.post_type = 'shop_order'
							AND
						p.post_status = 'wc-pending'
							AND
						pm_intent_not_found.meta_value IS NULL"
			);
		}
	}

	public function checkPendingTransactions() {
		static $isStarted;
		if ( empty( $isStarted ) ) {
			$isStarted = true;
		} else {
			return;
		}

		$logService = new LogService();
		$logService->debug( '⏱ start checkPendingTransactions()' );
		$ordersIds = $this->getPendingPaymentOrdersIds();
		foreach ( $ordersIds as $orderId ) {
			$order           = new WC_Order( (int) $orderId );
			$paymentIntentId = $order->get_transaction_id();
			if ( $paymentIntentId ) {
				if ( class_exists(OrderUtil::class ) &&  OrderUtil::custom_orders_table_usage_is_enabled() ) {
					// HPOS enabled
					$paymentMethod = $order->get_payment_method();
				} else {
					// HPOS disabled
					$paymentMethod = $order->get_meta('_payment_method' );
				}
				if ( Card::GATEWAY_ID === $paymentMethod ) {
					try {
						$paymentIntent = CardClient::getInstance()->getPaymentIntent( $paymentIntentId );
						( new OrderService() )->setPaymentSuccess( $order, $paymentIntent, 'cron' );
					} catch ( Exception $e ) {
						if (HttpClient::HTTP_STATUS_NOT_FOUND === $e->getCode()) {
							$order->update_meta_data( '_airwallex_payment_intent_not_found', true );
							$order->save();
						}
						$logService->warning( 'checkPendingTransactions failed for order #' . $order->get_id() . ' with paymentIntent ' . $paymentIntentId );
					}
				}
			}
		}
	}

	public function setPaymentSuccess( \WC_Order $order, PaymentIntent $paymentIntent, $referrer = 'webhook' ) {
		$logService = new LogService();
		$logIcon    = ( 'webhook' === $referrer ? '🖧' : '⏱' );
		if ( PaymentIntent::STATUS_SUCCEEDED === $paymentIntent->getStatus() ) {
			$logService->debug( $logIcon . ' payment success', $paymentIntent->toArray() );
			$order->payment_complete( $paymentIntent->getId() );
		} elseif ( PaymentIntent::STATUS_REQUIRES_CAPTURE === $paymentIntent->getStatus() ) {
			$apiClient   = CardClient::getInstance();
			$cardGateway = new Card();
			if ( $cardGateway->is_capture_immediately() ) {
				$paymentIntentAfterCapture = $apiClient->capture( $paymentIntent->getId(), $paymentIntent->getAmount() );
				if ( $paymentIntentAfterCapture->getStatus() === PaymentIntent::STATUS_SUCCEEDED ) {
					$order->payment_complete( $paymentIntent->getId() );
					$logService->debug( $logIcon . ' payment success', $paymentIntentAfterCapture->toArray() );
				} else {
					$logService->debug( $logIcon . ' capture failed', $paymentIntentAfterCapture->toArray() );
					$order->add_order_note( 'Airwallex payment failed capture' );
				}
			} else {
				$order->payment_complete( $paymentIntent->getId() );
				$order->add_order_note( 'Airwallex payment authorized' );
				$logService->debug(
					$logIcon . ' payment authorized',
					array(
						'order'          => $order->get_id(),
						'payment_intent' => $paymentIntent->getId(),
					)
				);
			}
		}
	}

	/**
	 * Set temporary order status after the payment is declined
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	public function setTemporaryOrderStateAfterDecline( $order ) {
		$orderStatus = get_option( 'airwallex_temporary_order_status_after_decline' );
		if ( $orderStatus ) {
			$order->update_status( $orderStatus, 'Airwallex status update (decline)' );
		}
	}

	/**
	 * Set pending status to order
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	public function setPendingStatus( $order ) {
		$orderStatus = get_option( 'airwallex_order_status_pending' );
		if ( $orderStatus ) {
			$order->update_status( $orderStatus, 'Airwallex status update (pending)' );
		}
	}

	/**
	 * Set authorized status to order
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	public function setAuthorizedStatus( $order ) {
		$orderStatus = get_option( 'airwallex_order_status_authorized' );
		if ( $orderStatus ) {
			$order->update_status( $orderStatus, 'Airwallex status update (authorized)' );
		}
	}
}
