<?php

namespace Airwallex\Gateways;

use Airwallex\Main;
use Airwallex\Client\CardClient;
use Airwallex\PayappsPlugin\CommonLibrary\Exception\RequestException;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentConsent\Retrieve as RetrievePaymentConsent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentConsent as StructPaymentConsent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log as RemoteLog;
use Airwallex\Services\Util;
use Exception;
use Airwallex\Services\CacheService;
use Airwallex\Services\LogService;
use Airwallex\Services\OrderService;
use WC_Subscriptions_Manager;
use Airwallex\PayappsPlugin\CommonLibrary\UseCase\PaymentMethodType\GetList as GetPaymentMethodTypesList;
use WC_HTTPS;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Confirm as ConfirmPaymentIntentRequest;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent as StructPaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Refund\Create as CreateRefund;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Refund as StructRefund;

trait AirwallexGatewayTrait {
	public static function getInstance() {
		if ( empty( static::$instance ) ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	public $iconOrder = array(
		'card_visa'       => 1,
		'card_mastercard' => 2,
		'card_amex'       => 3,
		'card_jcb'        => 4,
	);

	public static function paymentMethodTypeNames() {
		return [
			'wechatpay' => __('WeChat Pay', 'airwallex-online-payments-gateway'),
			'klarna' => __('Klarna', 'airwallex-online-payments-gateway'),
			'afterpay' => __('Afterpay', 'airwallex-online-payments-gateway'),
			'pos' => __('POS', 'airwallex-online-payments-gateway'),
			'card' => __('Card', 'airwallex-online-payments-gateway'),
		];
	}

	public function get_settings_url() {
		return get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' );
	}
	
	public function get_onboarding_url() {
		return get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=airwallex_general' );
	}

	public function getPaymentLogos() {
		$logos = [];
		try {
			$activePaymentMethodTypeItems = $this->getActivePaymentMethodTypeItems();
			foreach ($activePaymentMethodTypeItems as $paymentMethodType) {
				if ( 'card' === $paymentMethodType->getName() ) {
					$prefix     = $paymentMethodType->getName() . '_';
					$subMethods = $paymentMethodType->getCardSchemes();
				} else {
					$prefix     = '';
					$subMethods = $paymentMethodType->getResources();
				}
				if ( isset( $subMethods['logos']['svg'] ) ) {
					$logos[ $prefix . $paymentMethodType->getName() ] = $subMethods['logos']['svg'];
				} else {
					foreach ( $subMethods as $subMethod ) {
						if ( isset( $subMethod['resources']['logos']['svg'] ) ) {
							$logos[ $prefix . $subMethod['name'] ] = $subMethod['resources']['logos']['svg'];
						}
					}
				}
			}
			$logos = $this->sort_icons( $logos );

			return $logos;
		} catch ( Exception $e ) {
			$this->logService->error( 'unable to get payment logos', array( 'exception' => $e->getMessage() ) );
		}
		return $logos;
	}
	
	public function sort_icons( $iconArray ) {
		uksort(
			$iconArray,
			function ( $a, $b ) {
				$orderA = isset( $this->iconOrder[ $a ] ) ? $this->iconOrder[ $a ] : 999;
				$orderB = isset( $this->iconOrder[ $b ] ) ? $this->iconOrder[ $b ] : 999;
				return $orderA - $orderB;
			}
		);
		return $iconArray;
	}

	public function is_submit_order_details() {
		return in_array( get_option( 'airwallex_submit_order_details' ), array( 'yes', 1, true, '1' ), true );
	}

	public function temporary_order_status_after_decline() {
		$temporaryOrderStatus = get_option( 'airwallex_temporary_order_status_after_decline' );
		return $temporaryOrderStatus ? $temporaryOrderStatus : 'pending';
	}

	public function is_sandbox() {
		return in_array( get_option( 'airwallex_enable_sandbox' ), array( true, 'yes' ), true );
	}

	public function isRemoteLoggingEnabled() {
		return in_array( get_option( 'do_remote_logging' ), array( 'yes', 1, true, '1' ), true );
	}

	public function getPaymentFormTemplate() {
		return get_option( 'airwallex_payment_page_template' );
	}

	public function get_payment_url( $type ) {
		$template = get_option( 'airwallex_payment_page_template' );
		if ( 'wordpress_page' === $template ) {
			return $this->getPaymentPageUrl( $type );
		}

		return WC()->api_request_url( static::ROUTE_SLUG );
	}

	public function needs_setup() {
		return true;
	}

	public function get_payment_confirmation_url($orderId = '', $intentId = '') {
		$url = WC()->api_request_url( Main::ROUTE_SLUG_CONFIRMATION );
		if ( empty( $orderId ) ) {
			return $url;
		}
		$url .= strpos($url, '?') !== false ? '&' : '?';
		return $url . "order_id=$orderId&intent_id=$intentId";
	}

	public function init_settings() {
		parent::init_settings();
		$this->enabled = ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] ? 'yes' : 'no';
	}

	public function getPaymentPageUrl( $type, $fallback = '' ) {
		$pageId    = get_option( $type . '_page_id' );
		$permalink = ! empty( $pageId ) ? get_permalink( $pageId ) : '';

		if ( empty( $permalink ) ) {
			$permalink = empty( $fallback ) ? get_home_url() : $fallback;
		}

		return $permalink;
	}

	public static function getSettings() {
		return get_option(AIRWALLEX_PLUGIN_NAME . self::GATEWAY_ID . '_settings', []);
	}

	public function do_subscription_payment( $amount, $order ) {
		try {
			$subscriptionId            = $order->get_meta( '_subscription_renewal' );
			$subscription              = wcs_get_subscription( $subscriptionId );
			$originalOrderId           = $subscription->get_parent();
			$originalOrder             = wc_get_order( $originalOrderId );
			$airwallexCustomerId       = $subscription->get_meta( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID ) ?: $originalOrder->get_meta( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID );
			$airwallexPaymentConsentId = $subscription->get_meta( OrderService::META_KEY_AIRWALLEX_CONSENT_ID ) ?: $originalOrder->get_meta( OrderService::META_KEY_AIRWALLEX_CONSENT_ID );
			$airwallexPaymentMethodType = $subscription->get_meta( OrderService::META_KEY_AIRWALLEX_PAYMENT_METHOD_TYPE ) ?: $originalOrder->get_meta( OrderService::META_KEY_AIRWALLEX_PAYMENT_METHOD_TYPE );
			$paymentIntent             = CardClient::getInstance()->createPaymentIntent( $amount, $order->get_id(), false, $airwallexCustomerId, $airwallexPaymentMethodType );
			$order->update_meta_data( OrderService::META_KEY_INTENT_ID, $paymentIntent->getId() );
			/** @var StructPaymentIntent $paymentIntent */
			if ( $paymentIntent->isAuthorized() || $paymentIntent->isCaptured() ) {
				return;
			}
			/** @var StructPaymentIntent $paymentIntentAfterCapture */
			$paymentIntentAfterCapture = (new ConfirmPaymentIntentRequest())
				->setPaymentIntentId($paymentIntent->getId())
				->setPaymentConsentId($airwallexPaymentConsentId)
				->send();

			if ( $paymentIntentAfterCapture->isCaptured() ) {
				LogService::getInstance()->debug( 'capture successful: ' . $paymentIntent->getId() );
				$order->add_order_note( 'Airwallex payment capture success' );
				$order->payment_complete( $paymentIntent->getId() );
			} else {
				LogService::getInstance()->error( 'capture failed: ' . $paymentIntent->getId() );
				$order->add_order_note( 'Airwallex payment failed capture' );
			}
		} catch ( Exception $e ) {
			$subscriptionId            = $order->get_meta( '_subscription_renewal' );
			$subscription              = wcs_get_subscription( $subscriptionId );
			$originalOrderId           = $subscription->get_parent();
			$originalOrder             = wc_get_order( $originalOrderId );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($originalOrder);
			LogService::getInstance()->error( 'do_subscription_payment failed', $e->getMessage() );
			RemoteLog::error( $e->getMessage(), RemoteLog::ON_PAYMENT_CONFIRMATION_ERROR);
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order           = wc_get_order( $order_id );
		$paymentIntentId = $order->get_transaction_id();
		if (empty($paymentIntentId)) {
			$paymentIntentId = $order->get_meta(OrderService::META_KEY_INTENT_ID);
		}
		try {
			/** @var StructRefund $refund */
			$refund = (new CreateRefund())->setPaymentIntentId($paymentIntentId)->setAmount($amount)->setReason($reason)->send();

			$metaKey = OrderService::META_REFUND_ID . $refund->getId();
			if ( ! $order->meta_exists( $metaKey ) ) {
				$order->add_order_note(
					sprintf(
						__( 'Airwallex refund initiated: %s', 'airwallex-online-payments-gateway' ),
						$refund->getId()
					)
				);
				$order->add_meta_data( $metaKey, array( 'status' => StructRefund::STATUS_RECEIVED ) );
				$order->save();
			} else {
				throw new Exception( "refund {$refund->getId()} already exist.", '1' );
			}
			LogService::getInstance()->debug( __METHOD__ . " - Order: {$order_id}, refund initiated, {$refund->getId()}" );
		} catch ( RequestException $e ) {
			$error = json_decode( $e->getMessage(), true );
			if (is_array( $error ) && isset( $error['message'] ) ) {
				LogService::getInstance()->debug( __METHOD__ . " - Order: {$order_id}, refund failed, {$e->getMessage()}" );
				return new \WP_Error( 'error', 'Refund failed: ' . $error['message'] );
			}
		} catch ( \Exception $e ) {
			LogService::getInstance()->debug( __METHOD__ . " - Order: {$order_id}, refund failed, {$e->getMessage()}" );
			return new \WP_Error( 'error', 'Refund failed: ' . $e->getMessage() );
		}

		return true;
	}

	public function getOrderFromRequest($referrer = '') {
		$orderId = 0;

		if (!empty($_GET['order_id'])) {
			$orderId = (int) $_GET['order_id'];
		}

		if (empty($orderId)) {
			$orderId = (int) WC()->session->get('airwallex_order', 0);
		}

		if (empty($orderId)) {
			$orderId = (int) WC()->session->get('order_awaiting_payment', 0);
		}

		if (empty($orderId)) {
			$errorMessage = 'Unable to retrieve a valid order ID.';
			RemoteLog::error( json_encode(['msg' => $errorMessage, '$_GET' => $_GET]), RemoteLog::ON_PAYMENT_CONFIRMATION_ERROR);
			throw new Exception( __( $errorMessage, 'airwallex-online-payments-gateway' ) );
		}

		$order = wc_get_order( $orderId );
		if ( empty($order) ) {
			$errorMessage = 'Unable to retrieve a valid order.';
			RemoteLog::error( json_encode(['msg' => $errorMessage, '$_GET' => $_GET]), RemoteLog::ON_PAYMENT_CONFIRMATION_ERROR);
			throw new Exception( __( $errorMessage, 'airwallex-online-payments-gateway' ) );
		}
		return $order;
	}

	public function add_subscription_payment_meta( $paymentMeta, $subscription ) {
		$subscription->read_meta_data( true );
		$paymentMeta[ $this->id ] = [
			'post_meta' => [
				OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID => [
					'value' => $subscription->get_meta( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID, true ),
					'label' => 'Airwallex Customer ID',
				],
				OrderService::META_KEY_AIRWALLEX_CONSENT_ID   => [
					'value' => $subscription->get_meta( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, true ),
					'label' => 'Airwallex Payment Consent ID',
				],
			],
		];

		return $paymentMeta;
	}

	public function validate_subscription_payment_meta( $paymentMethodId, $paymentMethodData ) {
		if ( $paymentMethodId === $this->id ) {
			if ( empty( $paymentMethodData['post_meta'][OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID]['value'] ) ) {
				throw new Exception( __('"Airwallex Customer ID" is required.', 'airwallex-online-payments-gateway') );
			}
			if ( empty( $paymentMethodData['post_meta'][OrderService::META_KEY_AIRWALLEX_CONSENT_ID]['value'] ) ) {
				throw new Exception( __('"Airwallex Payment Consent ID" is required.', 'airwallex-online-payments-gateway') );
			}
			/** @var StructPaymentConsent $paymentConsent */
			$paymentConsent = (new RetrievePaymentConsent())->setPaymentConsentId($paymentMethodData['post_meta'][OrderService::META_KEY_AIRWALLEX_CONSENT_ID]['value'])->send();
			if ( empty($paymentConsent->getStatus()) || $paymentConsent->getStatus() !== StructPaymentConsent::STATUS_VERIFIED ) {
				throw new Exception( __("Invalid Airwallex Payment Consent.", 'airwallex-online-payments-gateway') );
			}
			if ( $paymentConsent->getCustomerId() !== $paymentMethodData['post_meta'][OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID]['value'] ) {
				throw new Exception( __('The provided "Airwallex Customer ID" does not match the associated "Airwallex Payment Consent ID".', 'airwallex-online-payments-gateway') );
			}
		}
	}

	/**
	 * @param \WC_Subscription $subscription
	 * @param \WC_Order        $order
	 */
	public function update_failing_payment_method( $subscription, $order ) {
		$subscription->update_meta_data( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, $order->get_meta( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, true ) );
		$subscription->update_meta_data( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID, $order->get_meta( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID, true ) );
		$subscription->save();
	}

	public function getActivePaymentMethodTypeItems() {
		$cacheName = 'awxActivePaymentMethodTypes';
		$cacheService = CacheService::getInstance();
		$paymentMethodTypes = $cacheService->get( $cacheName );

		if ( is_null( $paymentMethodTypes ) ) {
			$paymentMethodTypes = [];
			try {
				$paymentMethodTypes = (new GetPaymentMethodTypesList())->setCacheTime(300)->setActive(true)->setIncludeResources(true)->get();
			} catch ( Exception $e ) {
				LogService::getInstance()->error(__METHOD__ . ' Failed to get payment method types: ' . $e->getMessage());
			}
			$cacheService->set( $cacheName, $paymentMethodTypes, MINUTE_IN_SECONDS );
		}

		return $paymentMethodTypes;
	}

	public function getActivePaymentMethodTypeNames() {
		$activePaymentMethodTypeNames = [];
		try {
			$activePaymentMethodTypeItems = $this->getActivePaymentMethodTypeItems();
			foreach ($activePaymentMethodTypeItems as $activeItem) {
				$activePaymentMethodTypeNames[] = $activeItem->getName();
			}
		} catch( Exception $e) {
			LogService::getInstance()->error('getActivePaymentMethodTypeNames failed: ', $e->getMessage());
		}
		return $activePaymentMethodTypeNames;
	}

	public function getActivePaymentMethodTypeResources() {
		$activePaymentMethodTypeResources = [];
		try {
			$activePaymentMethodTypeItems = $this->getActivePaymentMethodTypeItems();
			foreach ($activePaymentMethodTypeItems as $activeItem) {
				$activePaymentMethodTypeResources[$activeItem->getName()][$activeItem->getTransactionMode()] = $activeItem->getResources();
			}
		} catch( Exception $e) {
			LogService::getInstance()->error('getActivePaymentMethodTypeResources failed: ', $e->getMessage());
		}
		return $activePaymentMethodTypeResources;
	}

	public function get_icon() {
		$icon = $this->getIcon();

		if ( $icon['url'] ) {
			$icon = '<img src="' . WC_HTTPS::force_https_url( $icon['url'] ) . '" class="airwallex-card-icon" alt="' . esc_attr( $this->get_title() ) . '" />';

			return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id ); // phpcs:ignore
		} else {
			return parent::get_icon();
		}
	}

	public function getIcon() {
		$paymentMethodTypes = $this->getActivePaymentMethodTypeResources();
		$iconUrl = '';
		if ( ! empty( $paymentMethodTypes[$this->paymentMethodType]['oneoff']['logos']['svg'] ) ) {
			$iconUrl = $paymentMethodTypes[$this->paymentMethodType]['oneoff']['logos']['svg'];
		} else if ( ! empty( $paymentMethodTypes[$this->paymentMethodType]['recurring']['logos']['svg'] ) ) {
			$iconUrl = $paymentMethodTypes[$this->paymentMethodType]['recurring']['logos']['svg'];
		}
		return [
			'url' => $iconUrl,
			'alt' => $this->title,
		];
	}

	public function paymentMethodNotEnabledMessage($paymentMethodName) {
		$activatePaymentMethodUrl = Util::getDomainUrl() . '/app/acquiring/payment-methods/other-pms';
		return sprintf(
			/* translators: Placeholder 1: Payment method type. Placeholder 2: Opening a tag. Placeholder 3: Close a tag. Placeholder 4: Payment method type */
			__('You have not activated %1$s as a payment method. Please go to %2$s Airwallex %3$s to activate %4$s before trying again.', 'airwallex-online-payments-gateway'),
			$paymentMethodName,
			'<a target="_blank" href="'. $activatePaymentMethodUrl .'">',
			'</a>',
			$paymentMethodName
		);
	}

	public function generate_check_is_enabled_html($key, $data) {
		$fieldKey = $this->get_field_key( $key );
		$value = $this->get_option( $key );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr($fieldKey); ?>">
                    <?php echo wp_kses_post($data['title']); ?>
				</label>
			</th>
			<td class="forminp">
				<fieldset>
                    <div class="is-awx-payment-method-enabled">
                        <div>
                            <input
                                    type="checkbox"
                                    id="<?php echo esc_attr($fieldKey); ?>"
                                    name="<?php echo esc_attr($fieldKey); ?>"
                                    value="yes"
                                    <?php checked(in_array($value, ['yes', true, 1, 'true', 1], true)); ?>
                            />
                            <input type="hidden" name="awx_payment_method_type" value="<?php echo esc_attr(self::PAYMENT_METHOD_TYPE_NAME); ?>" />
                            <label for="<?php echo esc_attr($fieldKey); ?>">
                                <?php echo wp_kses_post($data['label']); ?>
                            </label>
                            <span class="wc-awx-checkbox-spinner" style="position: absolute;"></span>
                        </div>
                        <div class="wc-awx-checkbox-error-message awx-payment-method-not-enabled">
                            <img src='<?php echo esc_attr(AIRWALLEX_PLUGIN_URL); ?>/assets/images/warning.svg'>
                            <?php echo wp_kses_post($this->paymentMethodNotEnabledMessage(self::paymentMethodTypeNames()[self::PAYMENT_METHOD_TYPE_NAME] ?? '')); ?>
                        </div>
                        <div class="wc-awx-checkbox-error-message awx-request-failed">
                            <?php echo __('Failed to check payment method status. Please try again.', 'airwallex-online-payments-gateway'); ?>
                        </div>
                    </div>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}
}
