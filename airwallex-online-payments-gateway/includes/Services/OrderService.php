<?php

namespace Airwallex\Services;

use Airwallex\Gateways\Card;
use Airwallex\Gateways\ExpressCheckout;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\AbstractApi;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Exception;
use WC_Order;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Retrieve as RetrievePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent as StructPaymentIntent;
use Automattic\WooCommerce\Enums\OrderStatus;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Capture as CapturePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Create as CreateAirwallexCustomer;
use Airwallex\Services\LogService;

class OrderService {
    protected static $instance = null;

    const PAYMENT_COMPLETE_MESSAGE = 'Airwallex payment complete';
    const PAYMENT_CAPTURED_MESSAGE = 'Airwallex payment captured';
    const PAYMENT_AUTHORIZED_MESSAGE = 'Airwallex payment authorized';
    const META_KEY_PREFIX_PAYMENT_PROCESSED = 'airwallex_payment_processed_';
    const META_KEY_INTENT_ID = '_tmp_airwallex_payment_intent';
    const META_KEY_ORDER_ORIGINAL_CURRENCY = '_tmp_airwallex_order_original_currency';
    const META_KEY_ORDER_ORIGINAL_AMOUNT = '_tmp_airwallex_order_original_amount';
    const META_KEY_AIRWALLEX_CUSTOMER_ID = 'airwallex_customer_id';
    const META_KEY_AIRWALLEX_CONSENT_ID = 'airwallex_consent_id';
    const META_KEY_AIRWALLEX_PAYMENT_METHOD_TYPE = 'airwallex_payment_method_type';
    const META_KEY_AIRWALLEX_PAYMENT_CLIENT_RATE = '_tmp_airwallex_payment_client_rate';
    const META_REFUND_ID = '_airwallex_refund_id_';

    public static function getInstance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

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

	public static function getRefundOrderMetaKey($refundId) {
		return self::META_REFUND_ID . $refundId;
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
					self::getRefundOrderMetaKey($refundId)
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
					self::getRefundOrderMetaKey($refundId)
				)
			);
		}


		return empty( $orderId ) ? false : wc_get_order( $orderId );
	}

	/**
	 * Get airwallex customer ID
	 *
	 * @param int $wordpressCustomerId
	 * @return int|mixed
	 * @throws Exception
	 */
	public function getAirwallexCustomerId( $wordpressCustomerId ) {
		$metaKey = 'airwallex_customer_id';
		$merchantInfo = Util::getMerchantInfoFromJwtToken();
		if ($merchantInfo && !empty($merchantInfo['accountId'])) {
			$metaKey = 'airwallex_customer_id_' . $merchantInfo['accountId'];
		}
		$airwallexCustomerId = get_user_meta( $wordpressCustomerId, $metaKey, true );
		if ( $airwallexCustomerId ) {
			return $airwallexCustomerId;
		}
		$customer = (new CreateAirwallexCustomer())->setCustomerId((string)$wordpressCustomerId)->send();
		$airwallexCustomerId = $customer->getId();
		update_user_meta( $wordpressCustomerId, $metaKey, $airwallexCustomerId );
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

		$logService = LogService::getInstance();
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
						/** @var StructPaymentIntent $paymentIntent */
						$paymentIntent = (new RetrievePaymentIntent())->setPaymentIntentId($paymentIntentId)->send();
					} catch ( Exception $e ) {
						$error = json_decode($e->getMessage(), true);
						if (is_array($error) && isset($error['code'])) {
							if ($error['code'] === AbstractApi::ERROR_RESOURCE_NOT_FOUND) {
								$order->update_meta_data( '_airwallex_payment_intent_not_found', true );
								$order->save();
							}
						}
						$logService->error( 'checkPendingTransactions failed for order #' . $order->get_id() . ' with paymentIntent ' . $paymentIntentId );
						$logService->error( $e->getMessage(), __METHOD__ );
						return;
					}
					try {
						( new OrderService() )->setPaymentSuccess( $order, $paymentIntent, __METHOD__ );
					} catch ( Exception $e ) {
						$logService->error( 'checkPendingTransactions setPaymentSuccess failed for order #' . $order->get_id() . ' with paymentIntent ' . $paymentIntentId );
						$logService->error( $e->getMessage(), __METHOD__ );
					}
				}
			}
		}
	}

	public function update_consent($paymentIntent, $order)
	{
		/** @var StructPaymentIntent $paymentIntent */
		$orderId = $order->get_id();
		if ( $paymentIntent->getPaymentConsentId() ) {
			$order->update_meta_data( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, $paymentIntent->getPaymentConsentId() );
			$order->update_meta_data( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID, $paymentIntent->getCustomerId() );
			$order->save_meta_data();

			if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				$subscriptions = wcs_get_subscriptions_for_order( $orderId );
				if ( !empty( $subscriptions ) ) {
					foreach ( $subscriptions as $subscription ) {
						$subscription->update_meta_data( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, $paymentIntent->getPaymentConsentId() );
						$subscription->update_meta_data( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID, $paymentIntent->getCustomerId() );
						$subscription->save_meta_data();
					}
				}
			}
		}
	}

	public function updateOrderDetails(WC_Order $order, StructPaymentIntent $paymentIntent) {
		/** @var StructPaymentIntent $paymentIntent */
		if (empty($paymentIntent->getBaseCurrency()) || empty($paymentIntent->getCurrency())) {
			return;
		}
		if ($paymentIntent->getCurrency() === $paymentIntent->getBaseCurrency()) {
			return;
		}
		$rate = $order->get_meta(self::META_KEY_AIRWALLEX_PAYMENT_CLIENT_RATE, true);
		if (empty($rate)) {
			if ($paymentIntent->getBaseAmount() == 0) {
				return;
			}
			$rate = $paymentIntent->getAmount() / $paymentIntent->getBaseAmount();
		}
		$orderItemTypes = array( 'line_item', 'shipping', 'fee', 'tax', 'coupon' );
		foreach ( $orderItemTypes as $type ) {
			foreach ( $order->get_items( $type ) as $item ) {
				switch ($type) {
					case 'line_item':
						if (is_callable([$item, 'set_subtotal']) && is_callable([$item, 'get_subtotal'])) {
							$item->set_subtotal($item->get_subtotal(false) * $rate);
						}
						if (is_callable([$item, 'set_total']) && is_callable([$item, 'get_total'])) {
							$item->set_total($item->get_total(false) * $rate);
						}
						break;
					case 'shipping':
						if (is_callable([$item, 'set_total']) && is_callable([$item, 'get_total'])) {
							$item->set_total($item->get_total(false) * $rate);
						}
						break;
					case 'fee':
						if (is_callable([$item, 'set_total']) && is_callable([$item, 'get_total'])) {
							$item->set_total($item->get_total(false) * $rate);
						}
						if (is_callable([$item, 'set_amount']) && is_callable([$item, 'get_amount'])) {
							$item->set_amount($item->get_amount(false) * $rate);
						}
						break;
					case 'tax':
						if (is_callable([$item, 'set_tax_total']) && is_callable([$item, 'get_tax_total'])) {
							$item->set_tax_total($item->get_tax_total(false) * $rate);
						}
						break;
					case 'coupon':
						if (is_callable([$item, 'set_discount']) && is_callable([$item, 'get_discount'])) {
							$item->set_discount($item->get_discount(false) * $rate);
						}
						break;
					default:
						break;
				}
			}
		}

		$order->calculate_totals();
		$order->set_total( $paymentIntent->getAmount() );
		$order->set_currency($paymentIntent->getCurrency());
		$order->add_meta_data('airwallex_payment_currency', $paymentIntent->getCurrency());
		$order->save();
	}

	public function paymentCompleteByCapture($order, $referrer, $paymentIntent) {
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
			$isProcessed = $order->meta_exists( $metaKey );
			if ( !$isProcessed ) {
				$this->update_consent( $paymentIntent, $order );
				$order->payment_complete( $paymentIntent->getId() );
				$order->add_meta_data( $metaKey, 'processed' );
				$order->save_meta_data();
				$order->add_order_note( __( self::PAYMENT_COMPLETE_MESSAGE, 'airwallex-online-payments-gateway' ) );
				LogService::getInstance()->debug( $referrer . ': paymentCompleteByCapture', array('payment_intent_id' => $paymentIntent->getId()) );
			}
			$wpdb->query( "COMMIT" );
			if ( !$isProcessed ) {
				$this->updateOrderDetails( $order, $paymentIntent );
			}
		} catch ( Exception $e ) {
			$wpdb->query( "ROLLBACK" );
			LogService::getInstance()->error( "$referrer " . __METHOD__ . $e->getMessage() );
			throw $e;
		}
	}

	public function paymentCompleteByAuthorize($order, $referrer, $paymentIntent) {
		global $wpdb;

		$logService = LogService::getInstance();
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
			$isProcessed = $order->meta_exists( $metaKey );
			if ( !$isProcessed ) {
				$this->update_consent( $paymentIntent, $order );
				$this->setAuthorizedStatus( $order );
				$paymentGateway = wc_get_payment_gateway_by_order( $order );
				/** @var StructPaymentIntent $paymentIntent */
				if ( ($paymentGateway instanceof Card || $paymentGateway instanceof ExpressCheckout 
					|| in_array($paymentIntent->getPaymentMethodType(), ['card', 'googlepay', 'applepay'], true)) && Card::getInstance()->is_capture_immediately() ) {
					$logService->debug( $referrer . ' start capture: ' . $paymentIntent->getId());
					/** @var StructPaymentIntent $paymentIntentAfterCapture */
					$paymentIntentAfterCapture = (new CapturePaymentIntent())->setPaymentIntentId($paymentIntent->getId())->setAmount($paymentIntent->getAmount())->send();
					if ( $paymentIntentAfterCapture->isCaptured() ) {
						$order->payment_complete( $paymentIntent->getId() );
						$order->add_meta_data(  $metaKey, 'processed' );
						$order->save_meta_data();
						$order->add_order_note( __( self::PAYMENT_CAPTURED_MESSAGE, 'airwallex-online-payments-gateway' ) );
						$logService->debug( $referrer . ' payment success: ' . $paymentIntent->getId());
					} else {
						$logService->error( $referrer . ' payment capture failed: ' . $paymentIntent->getId() );
						if ($referrer === 'checkout') {
							wc_add_notice( __( 'Airwallex payment error: capture failed. ', 'airwallex-online-payments-gateway' ), 'error' );
							wp_safe_redirect( wc_get_checkout_url() );
							$wpdb->query( "COMMIT" );
							die;
						}
					}
				} else {
					$logService->debug( $referrer . ': paymentCompleteByAuthorize', array('payment_intent_id' => $paymentIntent->getId()) );
					$order->payment_complete( $paymentIntent->getId() );
					$order->add_meta_data(  $metaKey, 'processed' );
					$order->save_meta_data();
					$order->add_order_note( __( self::PAYMENT_AUTHORIZED_MESSAGE, 'airwallex-online-payments-gateway' ) );
				}
			}
			$wpdb->query( "COMMIT" );
			if ( !$isProcessed ) {
				$this->updateOrderDetails( $order, $paymentIntent );
			}
		} catch ( Exception $e ) {
			$wpdb->query( "ROLLBACK" );
			$logService->error( "$referrer " . __METHOD__ . $e->getMessage() );
			throw $e;
		}
    }

	public function setPaymentSuccess( $order, $paymentIntent, $referrer = 'webhook' ) {
		if ( empty( $order ) ) {
			throw new Exception( __("Order not found.") );
		}

		/** @var StructPaymentIntent $paymentIntent */
		$metadata = $paymentIntent->getMetadata();
		if (!empty($metadata) && !empty($metadata['wp_order_id']) && intval($metadata['wp_order_id']) !== $order->get_id()) {
			throw new Exception(
				sprintf(
					__('Order ID mismatched: expected %d, got %s', 'airwallex-online-payments-gateway'),
					$order->get_id(),
					$metadata['wp_order_id'] ?? 'null'
				)
			);
		}

		if ( $paymentIntent->isCaptured() ) {
			$this->paymentCompleteByCapture($order, $referrer, $paymentIntent);
		} elseif ( $paymentIntent->isAuthorized() ) {
			$this->paymentCompleteByAuthorize($order, $referrer, $paymentIntent);
		}
	}

	/**
	 * Set temporary order status after the payment is declined
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	public function setTemporaryOrderStateAfterDecline( $order ) {
		$paymentIntentId = $order->get_transaction_id() ?: $order->get_meta(OrderService::META_KEY_INTENT_ID);
		if ($paymentIntentId) {
			try {
				/** @var StructPaymentIntent $paymentIntent */
				$paymentIntent = (new RetrievePaymentIntent())->setPaymentIntentId($paymentIntentId)->send();
				if ($paymentIntent->isAuthorized() || $paymentIntent->isCaptured()) {
					return;		
				}
			} catch (Exception $e) {
				LogService::getInstance()->error($e->getMessage(), __METHOD__);
				return;
			}

		}

		$completedStatuses = ['processing', 'completed'];
		if (class_exists(OrderStatus::class)) {
			$completedStatuses = [OrderStatus::PROCESSING, OrderStatus::COMPLETED];
		}
		if (in_array($order->get_status(), $completedStatuses, true)) {
			return;		
		}

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
