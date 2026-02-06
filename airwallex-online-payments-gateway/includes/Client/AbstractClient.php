<?php

namespace Airwallex\Client;

use Airwallex\Gateways\Card;
use Airwallex\Gateways\ExpressCheckout;
use Airwallex\PayappsPlugin\CommonLibrary\Configuration\Init as CommonLibraryInit;
use Airwallex\Services\CacheService;
use Airwallex\Services\LogService;
use Airwallex\Services\OrderService;
use Exception;
use Airwallex\Services\Util;
use Airwallex\Main;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log as RemoteLog;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Retrieve as RetrieveCustomer;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Cancel as CancelPaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Authentication;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Customer\Update as UpdateCustomer;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Retrieve as RetrievePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent as StructPaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Create as CreatePaymentIntentRequest;

abstract class AbstractClient {
	const CACHE_ACCESS_TOKEN_NAME    = 'awxAuth';

	protected $clientId;
	protected $apiKey;
	protected $isSandbox;
	protected $gateway;
	protected $token;
	protected $tokenExpiry;
	protected $cacheService;

	/**
	 * Get instance of the AbstractClient class.
	 *
	 * @return AbstractClient
	 */
	final public static function getInstance() {
		if ( empty( static::$instance ) ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	public function __construct() {
		$this->clientId = Util::getClientId();
		$this->apiKey = Util::getApiKey();
		$this->isSandbox = in_array( get_option( 'airwallex_enable_sandbox' ), array( true, 'yes' ), true );
	}

	public function getPaymentDescriptor() {
		return get_option( 'airwallex_payment_descriptor', Card::getDescriptorSetting());
	}

	final protected function getCacheService() {
		if ( ! isset( $this->cacheService ) ) {
			$this->cacheService = CacheService::getInstance();
		}
		return $this->cacheService;
	}

	public function setClientId( $clientId ) {
		$this->clientId = $clientId;
	}

	public function setApiKey( $apiKey ) {
		$this->apiKey = $apiKey;
	}

	public function setIsSandbox( $isSandbox ) {
		$this->isSandbox = $isSandbox;
	}

	/**
	 * Get access token from airwallex and cache it for later use
	 *
	 * @throws Exception
	 */
	final public function getToken() {
		if (!Util::getClientId() || !Util::getApiKey()) {
			throw new Exception('Client id and api key are required.');
		}

		$cache = $this->getCacheService();
		$token = $cache->get(self::CACHE_ACCESS_TOKEN_NAME);
		if ($token) {
			return $token;
		}

		$accessToken = (new Authentication())->send();
		if ($accessToken && $accessToken->getToken()) {
			$token = $accessToken->getToken();
			$cache->set(self::CACHE_ACCESS_TOKEN_NAME, $token, 25 * MINUTE_IN_SECONDS);
		}

		return $token;
	}

	final public function testAuth() {
		if (empty(Util::getClientId()) || empty(Util::getApiKey())) {
			return false;
		}
		CommonLibraryInit::getInstance()->updateConfig([
			'env' => Util::getEnvironment(),
			'client_id' => Util::getClientId(),
			'api_key' => Util::getApiKey(),
		]);
		$cacheName = 'awxTestAuth_' . md5(Util::getClientId() . '-' . Util::getApiKey());
		$token = $this->getCacheService()->get($cacheName);
		if ($token) return true;
		$token = '';
		try {
			$accessToken = (new Authentication())->send();
			if ($accessToken && $accessToken->getToken()) {
				$token = $accessToken->getToken();
			}
		} catch (Exception $e)  {
			LogService::getInstance()->error('Authentication failed: ' . $e->getMessage(), __METHOD__);
		}
		$this->getCacheService()->set($cacheName, $token, $token ? 25 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS);
		return !empty($token);
	}

	/**
	 * Create new payment intent in airwallex
	 *
	 * @param $amount
	 * @param $orderId
	 * @param bool $withDetails
	 * @param null $customerId
	 * @param string $paymentMethodType
	 *
	 * @return StructPaymentIntent
	 * @throws Exception
	 */
	final public function createPaymentIntent( $amount, $orderId, $withDetails = false, $customerId = null, $paymentMethodType = '' ) {
		$order       = wc_get_order( (int) $orderId );
		$orderNumber = $order->get_meta( '_order_number' );
		$orderNumber = $orderNumber ? $orderNumber : $orderId;

		$url = WC()->api_request_url( Main::ROUTE_SLUG_CONFIRMATION );
		$url .= strpos($url, '?') !== false ? '&' : '?';
		$url .= "order_id=$orderId";		
		$data        = array(
			'amount'            => $amount,
			'currency'          => $order->get_currency(),
			'descriptor'        => str_replace( '%order%', $orderId, $this->getPaymentDescriptor() ),
			'metadata'          => array(
				'wp_order_id'     => $orderId,
				'wp_instance_key' => Main::getInstanceKey(),
			),
			'merchant_order_id' => $orderNumber,
			'return_url'        => $url,
			'order'             => array(
				'type' => 'physical_goods',
			),
			'request_id'        => uniqid(),
		)
			+ ( null !== $customerId ? array( 'customer_id' => $customerId ) : array() );

		if ( mb_strlen( $data['descriptor'] ) > 32 ) {
			$data['descriptor'] = mb_substr( $data['descriptor'], 0, 32 );
		}

		// Set customer detail
		$customerAddress = array(
			'city'         => $order->get_billing_city(),
			'country_code' => $order->get_billing_country(),
			'postcode'     => $order->get_billing_postcode(),
			'state'        => $order->get_billing_state(),
			'street'       => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
		);

		$customer = array(
			'email'                => $order->get_billing_email(),
			'first_name'           => $order->get_billing_first_name(),
			'last_name'            => $order->get_billing_last_name(),
			'merchant_customer_id' => $order->get_customer_id(),
			'phone_number'         => $order->get_billing_phone(),
		);

		$data['customer'] = null === $customerId ? $customer : null;

		if ( $data['customer'] && $order->get_billing_city() && $order->get_billing_country() && $order->get_billing_address_1() ) {
			$data['customer']['address'] = $customerAddress;
		}

		if (!empty($customerId)) {
			try {
				$customerObject = (new RetrieveCustomer())->setCustomerId($customerId)->send();
				if (!$customerObject->getEmail()) {
					(new UpdateCustomer())
						->setCustomerId($customerId)
						->setEmail($order->get_billing_email() ?? '')
						->setFirstName($order->get_billing_first_name() ?? '')
						->setLastName($order->get_billing_last_name() ?? '')
						->setPhoneNumber($order->get_billing_phone() ?? '')
						->setAddress($customerAddress)
						->send();
				}
			} catch (\Exception $e) {
				RemoteLog::error( 'Error update customer failed: ' . $e->getMessage() );
			} catch (\Error $e) {
				RemoteLog::error( 'Error update customer failed: ' . $e->getMessage() );
			}
		}

		// Set order details
		$orderData = array(
			'type'     => 'physical_goods',
			'products' => array(),
		);

		$orderItemTotal = 0;

		$orderItemTypes = array( 'line_item', 'shipping', 'fee', 'tax' );
		foreach ( $orderItemTypes as $type ) {
			foreach ( $order->get_items( $type ) as $item ) {
				if ($item->get_quantity() <= 0) continue;
				$itemDetail = array(
					'name'       => ( mb_strlen( $item->get_name() ) <= 120 ? $item->get_name() : mb_substr( $item->get_name(), 0, 117 ) . '...' ),
					'desc'       => $item->get_name(),
					'quantity'   => $item->get_quantity(),
					'sku'        => '',
					'type'       => $item->get_type(),
					'unit_price' => is_callable( array( $item, 'get_total' ) ) ? $item->get_total() : 0,
				);

				if ( $item->is_type( 'line_item' ) ) {
					$product = $item->get_product();
					if ( ! empty( $product ) ) {
						$itemDetail['sku'] = Util::truncateString( $product->get_sku(), 117, '...' );
						$itemDetail['type'] = $product->is_virtual() ? 'virtual' : 'physical';
					}
					if ( $itemDetail['quantity'] > 0 ) {
						$itemDetail['unit_price'] /= $itemDetail['quantity'];
					}
				} elseif ( $item->is_type( 'shipping' ) ) {
					$itemDetail['sku'] = $item->get_method_id();
				} elseif ( $item->is_type( 'tax' ) ) {
					$itemDetail['unit_price'] = $item->get_tax_total() + $item->get_shipping_tax_total();
				}

				if ( $itemDetail['unit_price'] >= 0 ) {
					$itemDetail['unit_price'] = Util::round( $itemDetail['unit_price'], wc_get_price_decimals() );
					$orderData['products'][]  = $itemDetail;
					$orderItemTotal += $itemDetail['unit_price'] * $itemDetail['quantity'];
				}
			}
		}

		if ($amount - $orderItemTotal >= 0.0001) {
			$orderData['products'][] = array(
				'name'       => 'Other Fees',
				'desc'       => '',
				'quantity'   => 1,
				'sku'        => '',
				'unit_price' => ceil(($amount - $orderItemTotal) * 10000) / 10000,
			);
		}

		if ( $order->has_shipping_address() ) {
			$shippingAddress       = array(
				'city'         => $order->get_shipping_city(),
				'country_code' => $order->get_shipping_country(),
				'postcode'     => $order->get_shipping_postcode(),
				'state'        => $order->get_shipping_state(),
				'street'       => trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()),
			);
			$orderData['shipping'] = array(
				'first_name'      => $order->get_shipping_first_name(),
				'last_name'       => $order->get_shipping_last_name(),
				'shipping_method' => Util::truncateString($order->get_shipping_method(), 117, '...'),
			);
			if ( $order->get_shipping_city() && $order->get_shipping_country() && $order->get_shipping_address_1() ) {
				$orderData['shipping']['address'] = $shippingAddress;
			}
		} elseif ( $order->has_billing_address() ) {

			$billingAddress        = array(
				'city'         => $order->get_billing_city(),
				'country_code' => $order->get_billing_country(),
				'postcode'     => $order->get_billing_postcode(),
				'state'        => $order->get_billing_state(),
				'street'       => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
			);
			$orderData['shipping'] = array(
				'first_name'      => $order->get_billing_first_name(),
				'last_name'       => $order->get_billing_last_name(),
				'shipping_method' => Util::truncateString($order->get_shipping_method(), 117, '...'),
			);
			if ( $order->get_billing_city() && $order->get_billing_country() && $order->get_billing_address_1() ) {
				$orderData['shipping']['address'] = $billingAddress;
			}
		}

		$data['order'] = $orderData;
		$data['metadata'] +=  $this->getMetaData();

		$data += $this->getReferrer($paymentMethodType);
		$intent = $this->getCachedPaymentIntent( $data );
		if ( $intent && $intent instanceof StructPaymentIntent ) {
			/** @var StructPaymentIntent $liveIntent */
			try {
				$liveIntent = (new RetrievePaymentIntent())->setPaymentIntentId($intent->getId())->send();
				if ( $liveIntent
					&& number_format( $liveIntent->getAmount(), 2 ) === number_format( (float) $data['amount'], 2 )
					&& $liveIntent->getStatus() !== StructPaymentIntent::STATUS_CANCELLED ) {
					return $liveIntent;
				}
			} catch ( Exception $e ) {
				LogService::getInstance()->error('retrieve cached intent failed: ' . $e->getMessage(), __METHOD__);
			}
		}

		$paymentIntentId = $order->get_meta(OrderService::META_KEY_INTENT_ID);
		if ($paymentIntentId) {
			try {
				/** @var StructPaymentIntent $paymentIntent */
				$paymentIntent = (new RetrievePaymentIntent())->setPaymentIntentId($paymentIntentId)->send();

				$metadata = $paymentIntent->getMetadata();
				if (!empty($metadata) && !empty($metadata['wp_order_id']) && intval($metadata['wp_order_id']) === $order->get_id()) {
					if ( $paymentIntent->isAuthorized() || $paymentIntent->isCaptured() ) {
						OrderService::getInstance()->setPaymentSuccess( $order, $paymentIntent, __METHOD__ );
						return $paymentIntent;
					}
					if ($paymentIntent->getStatus() !== StructPaymentIntent::STATUS_CANCELLED) {
						(new CancelPaymentIntent())->setPaymentIntentId($paymentIntentId)->send();
					}
				}
			} catch (Exception $e) {
				LogService::getInstance()->error('handle old intent failed: ' . $e->getMessage(), __METHOD__);
			}
		}

		try {
			$newPaymentIntentRequest = (new CreatePaymentIntentRequest())
				->setAmount($data['amount'])
				->setCurrency($data['currency'])
				->setDescriptor($data['descriptor'])
				->setReferrerDataType($data['referrer_data']['type'])
				->setMetadata($data['metadata'])
				->setMerchantOrderId($data['merchant_order_id'])
				->setReturnUrl($data['return_url'])
				->setOrder($data['order']);
			if ($customerId) {
				$newPaymentIntentRequest = $newPaymentIntentRequest->setCustomerId($customerId);
			} else {
				$newPaymentIntentRequest = $newPaymentIntentRequest->setCustomer($data['customer']);
			}
			$newPaymentIntent = $newPaymentIntentRequest->send();
			$order->update_meta_data( OrderService::META_KEY_ORDER_ORIGINAL_CURRENCY, $order->get_currency() );
			$order->update_meta_data( OrderService::META_KEY_ORDER_ORIGINAL_AMOUNT, $order->get_total() );
			$order->update_meta_data( OrderService::META_KEY_AIRWALLEX_PAYMENT_METHOD_TYPE, $paymentMethodType );
			$order->save_meta_data();

			$this->savePaymentIntentToCache( $data, $newPaymentIntent );
			return $newPaymentIntent;
		} catch ( Exception $e ) {
			RemoteLog::error( 'Create payment intent failed: ' . $e->getMessage(), RemoteLog::ON_PAYMENT_CREATION_ERROR);
			throw new Exception( 'Create payment intent failed: ' . $e->getMessage() );
		}
	}

	protected function savePaymentIntentToCache( $data, $paymentIntent ) {
		if ( isset( $data['request_id'] ) ) {
			unset( $data['request_id'] );
		}
		$key = 'payment-intent-' . md5( serialize( $data ) ); // phpcs:ignore
		return $this->getCacheService()->set( $key, $paymentIntent );
	}

	protected function getCachedPaymentIntent( $data ) {
		if ( isset( $data['request_id'] ) ) {
			unset( $data['request_id'] );
		}
		$key = 'payment-intent-' . md5( serialize( $data ) ); // phpcs:ignore
		return $this->getCacheService()->get( $key );
	}

	protected function getReferrer( $paymentMethodType ) {
		$nameMaps = [
			'card'       => 'credit_card',
			'wechatpay'  => 'wechat',
		];
		if (isset($nameMaps[$paymentMethodType])) {
			$paymentMethodType = $nameMaps[$paymentMethodType];
		}
		$paymentMethodType = $paymentMethodType ? 'woo_commerce_' . $paymentMethodType : 'woo_commerce';
		return array(
			'referrer_data' => array(
				'type'    => $paymentMethodType,
				'version' => AIRWALLEX_VERSION,
			),
		);
	}

	protected function getMetaData() {
		return [
			'plugin_info' => wp_json_encode([
				'php_version' => phpversion(),
				'wordpress_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : '',
				'woo_commerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'payment_form_template' => get_option( 'airwallex_payment_page_template', 'default' ),
				'express_checkout' => ExpressCheckout::getMetaData(),
				'plugin_version' => AIRWALLEX_VERSION,
			]),
		];
	}
}
