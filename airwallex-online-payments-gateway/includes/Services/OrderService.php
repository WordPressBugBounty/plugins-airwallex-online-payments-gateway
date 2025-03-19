<?php

namespace Airwallex\Services;

use Airwallex\Client\AbstractClient;
use Airwallex\Client\CardClient;
use Airwallex\Client\HttpClient;
use Airwallex\Gateways\Card;
use Airwallex\Gateways\ExpressCheckout;
use Airwallex\Struct\PaymentIntent;
use Airwallex\Struct\Refund;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Exception;
use WC_Order;

class OrderService {

    const PAYMENT_COMPLETE_MESSAGE = 'Airwallex payment complete';
    const PAYMENT_CAPTURED_MESSAGE = 'Airwallex payment captured';
    const PAYMENT_AUTHORIZED_MESSAGE = 'Airwallex payment authorized';
    const META_KEY_PREFIX_PAYMENT_PROCESSED = 'airwallex_payment_processed_';
    const META_KEY_INTENT_ID = '_tmp_airwallex_payment_intent';

    public function getOrderMetaTableName() {
        if ( class_exists(OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
            return OrdersTableDataStore::get_meta_table_name();
        }
        global $wpdb;
        return $wpdb->postmeta;
    }

    public function getOrderIdColumnNameFromMetaTable() {
        if ( class_exists(OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
            return 'order_id';
        }
        return 'post_id';
    }

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
		$airwallexCustomerId = get_user_meta( $wordpressCustomerId, 'airwallex_customer_id', true );
		if ( $airwallexCustomerId ) {
			return $airwallexCustomerId;
		}
		$randomId = uniqid( (string)$wordpressCustomerId . '-', true );
		$customer = $client->createCustomer( $randomId );
		$airwallexCustomerId = $customer->getId();
		update_user_meta( $wordpressCustomerId, 'airwallex_customer_id', $airwallexCustomerId );
		return $airwallexCustomerId;
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

	public function paymentCompleteByCapture($order, $logService, $referrer, $paymentIntent) {
		global $wpdb;

		$tableName = $this->getOrderMetaTableName();
		$orderIdColumnName = $this->getOrderIdColumnNameFromMetaTable();
		$orderId = $order->get_id();
		$metaKey = self::META_KEY_PREFIX_PAYMENT_PROCESSED . $orderId;

		$wpdb->query( "START TRANSACTION" );
		try {
			$wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM $tableName WHERE $orderIdColumnName = %d  AND meta_key = %s FOR UPDATE",
					$orderId,
					self::META_KEY_INTENT_ID
				) 
			);
			$order->read_meta_data(true);
			if ( !$order->meta_exists( $metaKey ) ) {
				$order->payment_complete( $paymentIntent->getId() );
				$order->add_meta_data( $metaKey, 'processed' );
				$order->save();
				$order->add_order_note( __( self::PAYMENT_COMPLETE_MESSAGE, 'airwallex-online-payments-gateway' ) );
			}
			$wpdb->query( "COMMIT" );
		} catch ( Exception $e ) {
			$wpdb->query( "ROLLBACK" );
			$logService->error( "$referrer " . __METHOD__ . $e->getMessage() );
			throw $e;
		}
	}

	public function paymentCompleteByAuthorize($order, $logService, $referrer, $paymentIntent) {
		global $wpdb;

		$tableName = $this->getOrderMetaTableName();
		$orderIdColumnName = $this->getOrderIdColumnNameFromMetaTable();
		$orderId = $order->get_id();
		$metaKey = self::META_KEY_PREFIX_PAYMENT_PROCESSED . $orderId;

		$wpdb->query( "START TRANSACTION" );
		try {
			$wpdb->get_row(
				$wpdb->prepare("SELECT * FROM $tableName WHERE $orderIdColumnName = %d  AND meta_key = %s FOR UPDATE",
					$orderId,
					self::META_KEY_INTENT_ID
				) 
			);
			$order->read_meta_data(true);
			if ( !$order->meta_exists( $metaKey ) ) {
				$this->setAuthorizedStatus( $order );
				$paymentGateway = wc_get_payment_gateway_by_order( $order );
				if ( $paymentGateway instanceof Card || $paymentGateway instanceof ExpressCheckout ) {
					if ( $paymentGateway->is_capture_immediately() ) {
						$logService->debug( $referrer . ' start capture', array( $paymentIntent->toArray() ) );
						$apiClient   = CardClient::getInstance();
						$paymentIntentAfterCapture = $apiClient->capture( $paymentIntent->getId(), $paymentIntent->getAmount() );
						if ( $paymentIntentAfterCapture->getStatus() === PaymentIntent::STATUS_SUCCEEDED ) {
							$order->payment_complete( $paymentIntent->getId() );
							$order->add_meta_data(  $metaKey, 'processed' );
							$order->save();
							$order->add_order_note( __( self::PAYMENT_CAPTURED_MESSAGE, 'airwallex-online-payments-gateway' ) );
							$logService->debug( $referrer . ' payment success', $paymentIntent->toArray() );
						} else {
							$logService->error( $referrer . ' payment capture failed', $paymentIntentAfterCapture->toArray() );
							$this->setTemporaryOrderStateAfterDecline( $order );
							if ($referrer === 'checkout') {
								wc_add_notice( __( 'Airwallex payment error', 'airwallex-online-payments-gateway' ), 'error' );
								wp_safe_redirect( wc_get_checkout_url() );
								$wpdb->query( "COMMIT" );
								die;
							}
						}
					} else {
						$logService->debug( $referrer . ': paymentCompleteByAuthorize', array() );
						$order->payment_complete( $paymentIntent->getId() );
						$order->add_meta_data(  $metaKey, 'processed' );
						$order->save();
						$order->add_order_note( __( self::PAYMENT_AUTHORIZED_MESSAGE, 'airwallex-online-payments-gateway' ) );
					}
				}
			}
			$wpdb->query( "COMMIT" );
		} catch ( Exception $e ) {
			$wpdb->query( "ROLLBACK" );
			$logService->error( "$referrer " . __METHOD__ . $e->getMessage() );
			throw $e;
		}
    }

	public function setPaymentSuccess( $order, $paymentIntent, $referrer = 'webhook' ) {
		$logService = new LogService();
		if ( PaymentIntent::STATUS_SUCCEEDED === $paymentIntent->getStatus() ) {
			$this->paymentCompleteByCapture($order, $logService, $referrer, $paymentIntent);
		} elseif ( PaymentIntent::STATUS_REQUIRES_CAPTURE === $paymentIntent->getStatus() ) {
			$this->paymentCompleteByAuthorize($order, $logService, $referrer, $paymentIntent);
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
