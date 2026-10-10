<?php

namespace Airwallex\Gateways;

use Airwallex\Gateways\Settings\AirwallexSettingsTrait;
use Airwallex\Services\LogService;
use Airwallex\Services\OrderService;
use Airwallex\Services\Util;
use WC_Order;
use WFOCU_Gateway;
use WFOCU_AJAX_Controller;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Retrieve as RetrievePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentConsent\Retrieve as RetrievePaymentConsent;
use Airwallex\Client\UpsellConfirmPaymentIntent as ConfirmPaymentIntentRequest;
use Airwallex\Client\UpsellCreatePaymentIntent as CreatePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Cancel as CancelPaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent as StructPaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentConsent as StructPaymentConsent;
use Exception;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log as RemoteLog;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\Refund\Create as CreateRefund;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\Refund as StructRefund;
use Airwallex\PayappsPlugin\CommonLibrary\Util\AmountHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'WFOCU_Gateway' ) ) {
    class FunnelKitUpsell extends WFOCU_Gateway {
        use AirwallexGatewayTrait;
        use AirwallexSettingsTrait;
        use FunnelKitSnapshotTrait;
        use FunnelKitPricingTrait;
        use FunnelKitIntentTrait;

        const THREEDS_RESULT_PAGE_ROUTE_SLUG = 'airwallex_3ds_return_page';
        const AIRWALLEX_UPSELL_PAYMENT_INTENTS_META_KEY = '_tmp_airwallex_upsell_payment_intents';
        const AIRWALLEX_UPSELL_PAYMENT_INTENT_SNAPSHOTS_META_KEY = '_airwallex_upsell_payment_intent_snapshots';
        const AIRWALLEX_UPSELL_FUNNEL_SESSION_META_KEY = '_airwallex_upsell_funnel_session';
        const AIRWALLEX_UPSELL_CONSUMED_PAYMENT_INTENTS_META_KEY = '_airwallex_upsell_consumed_payment_intents';
        const AIRWALLEX_UPSELL_PAY_BY_TOKEN_META_KEY = '_tmp_airwallex_pay_by_token';
        const AIRWALLEX_UPSELL_REQUIRES_CVC_META_KEY = '_tmp_airwallex_requires_cvc';
        const FKWCS_SOURCE_ID_META_KEY = '_fkwcs_source_id';
        const GATEWAY_ID = 'airwallex_card';

        const AIRWALLEX_UPSELL_ATTEMPTS_META_KEY = '_airwallex_upsell_attempts';
        private $lockedOrderId = null;
        private $fulfillingOrderId = null;
        protected static $instance = null;

        public $key = 'airwallex_card';
        public $method_description = '';
        public $description = '';
        public $id = self::GATEWAY_ID;
        public $supports = [ 'no-gateway-upsells' ];
        public $refund_supported = true;

        public function __construct() {
            parent::__construct();
            add_action( 'wfocu_footer_before_print_scripts', array( $this, 'maybe_render_in_offer_transaction_scripts' ), 999 );
            add_filter( 'wfocu_allow_ajax_actions_for_charge_setup', array( $this, 'allow_check_action' ) );
            add_action( 'wc_ajax_wfocu_front_handle_fkwcs_airwallex_payments', [ $this, 'process_client_payment' ] );
            add_action( 'wfocu_subscription_created_for_upsell', array( $this, 'save_airwallex_consent_to_subscription' ), 10, 3 );
            add_action( 'wfocu_offer_accepted_and_processed', array( $this, 'rememberConsumedUpsellIntent' ), 0, 5 );
            add_action( 'woocommerce_api_' . self::THREEDS_RESULT_PAGE_ROUTE_SLUG, array( $this, 'threeDSReturnPage' ) );
        }

        public function save_airwallex_consent_to_subscription( $subscription, $key, $order ) {
            try {
                $paymentIntentId = $order->get_meta( self::FKWCS_SOURCE_ID_META_KEY );
                if (empty($paymentIntentId)) return;
                $paymentIntent = ( new RetrievePaymentIntent() )->setPaymentIntentId( $paymentIntentId )->send();
                if ($paymentIntent->getPaymentConsentId()) {
                    $subscription->update_meta_data( OrderService::META_KEY_AIRWALLEX_CUSTOMER_ID, $paymentIntent->getCustomerId() );
                    $subscription->update_meta_data( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, $paymentIntent->getPaymentConsentId() );
                    $subscription->save_meta_data();
                }
            } catch (Exception $e) {
                LogService::getInstance()->error( __METHOD__, $e->getMessage() );
                RemoteLog::error('FunnelKit Upsell create intent failed: ' . $e->getMessage());
                $this->handle_api_error(
                    __( "We couldn't link your saved payment method to this subscription. Please contact us if you don't see your subscription updated.", 'airwallex-online-payments-gateway' ),
                    $e->getMessage(),
                    $order
                );
            }
        }

        private function fulfillChargedUpsell( $order, $paymentIntent, $offerId ) {
            $paymentIntentId = (string) $paymentIntent->getId();
            if ( ! $this->upsellIntentMatches( $paymentIntent, $order, $offerId ) ) {
                return false;
            }
            if ( ! $this->acquireUpsellAcceptLock( $order->get_id() ) ) {
                return false;
            }
            try {
                $this->reloadOrderMeta( $order );
                if ( $this->upsellPaymentIntentIsConsumed( $order, $paymentIntentId ) ) {
                    return array( 'redirect_url' => '' );
                }
                $package = $this->bookablePackageFromSnapshot( $this->getUpsellPaymentIntentSnapshot( $order, $paymentIntentId ) );
                if ( null === $package || ! class_exists( 'WFOCU_Core' ) || ! is_object( WFOCU_Core()->data ) || ! is_object( WFOCU_Core()->process_offer ) ) {
                    return false;
                }
                WFOCU_Core()->data->set( '_upsell_package', $package );
                WFOCU_Core()->data->set( '_transaction_id', $paymentIntentId );
                $order->update_meta_data( self::FKWCS_SOURCE_ID_META_KEY, $paymentIntentId );
                $order->set_payment_method( self::GATEWAY_ID );
                $order->save();
                $this->fulfillingOrderId = (string) $order->get_id();
                try {
                    $result = WFOCU_Core()->process_offer->_handle_upsell_charge( true );
                } finally {
                    $this->fulfillingOrderId = null;
                }
                $this->reloadOrderMeta( $order );
                if ( ! $this->upsellPaymentIntentIsConsumed( $order, $paymentIntentId ) ) {
                    return false;
                }
                return is_array( $result ) ? $result : array();
            } catch ( Exception $e ) {
                $this->reloadOrderMeta( $order );
                if ( $this->upsellPaymentIntentIsConsumed( $order, $paymentIntentId ) ) {
                    return array( 'redirect_url' => '' );
                }
                LogService::getInstance()->error( 'Upsell fulfillment failed for intent ' . $paymentIntentId . ': ' . $e->getMessage() );
                return false;
            } finally {
                $this->releaseUpsellAcceptLock( $order->get_id() );
            }
        }

        private function acquireUpsellAcceptLock( $orderId ) {
            if ( $this->lockedOrderId === (string) $orderId ) {
                return true;
            }
            global $wpdb;
            if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
                return false;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory lock; must not be cached.
            $acquired = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT GET_LOCK(%s, %d)',
                    'awx_upsell_order_' . (int) $orderId,
                    5
                )
            );
            return '1' === (string) $acquired;
        }

        private function releaseUpsellAcceptLock( $orderId ) {
            if ( $this->lockedOrderId === (string) $orderId ) {
                return;
            }
            global $wpdb;
            if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
                return;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the connection-scoped advisory lock.
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT RELEASE_LOCK(%s)',
                    'awx_upsell_order_' . (int) $orderId
                )
            );
        }

        public function process_refund_offer( $order ) {
            $refundData = wc_clean( $_POST );  // phpcs:ignore WordPress.Security.NonceVerification.Missing

            $txnId        = $refundData['txn_id'] ?? '';
            $amt          = $refundData['amt'] ?? '';
            $refundReason = $refundData['refund_reason'] ?? '';
            try {
                /** @var StructRefund $refund */
                $refund = (new CreateRefund())->setPaymentIntentId($txnId)->setAmount($amt)->setReason($refundReason)->send();
                return $refund && $refund->getId();
            } catch ( Exception $e ) {
                LogService::getInstance()->error( 'FunnelKit Upsell refund failed: ' . $e->getMessage() );
                return false;
            }
        }

        public function process_client_payment() {
            check_ajax_referer( 'wfocu_front_charge', 'nonce' );
            $order = WFOCU_Core()->data->get_parent_order();
            if ( ! $order || ! $this->acquireUpsellAcceptLock( $order->get_id() ) ) {
                wp_send_json( array( 'result' => 'error' ) );
                return;
            }
            $this->lockedOrderId = (string) $order->get_id();
            try {
                $this->reloadOrderMeta( $order );
                $response = $this->processLockedClientPayment();
            } catch ( Exception $exception ) {
                $response = $this->upsellErrorResponse( $order, __( 'Unable to verify upsell payment. Please contact support before retrying.', 'airwallex-online-payments-gateway' ), $exception->getMessage(), false );
            } finally {
                $this->lockedOrderId = null;
                $this->releaseUpsellAcceptLock( $order->get_id() );
            }
            wp_send_json( $response );
        }

        private function processLockedClientPayment() {

            $currentOffer     = WFOCU_Core()->data->get( 'current_offer' );
            $currentOfferMeta = WFOCU_Core()->offers->get_offer_meta( $currentOffer );
            WFOCU_Core()->data->set( '_offer_result', true );
            $postedData = WFOCU_Core()->process_offer->parse_posted_data( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

            if ( false === WFOCU_AJAX_Controller::validate_charge_request( $postedData ) ) {
                return array( 'result' => 'error' );
            }

            WFOCU_Core()->process_offer->execute( $currentOfferMeta );

            $parentOrder = WFOCU_Core()->data->get_parent_order();

            $airwallexCustomerId = OrderService::getInstance()->getAirwallexCustomerId( get_current_user_id() );

            $upsellPackage = WFOCU_Core()->data->get( '_upsell_package' );

            $paymentIntentId = $_POST['payment_intent_id'] ?? null;
            if ( array_key_exists( 'payment_intent_id', $_POST ) && ( ! is_string( $paymentIntentId ) || '' === $paymentIntentId ) ) {
                return array( 'result' => 'error' );
            }
            if ( is_string( $paymentIntentId ) && '' !== $paymentIntentId ) {
                try {
                    $paymentIntent = (new RetrievePaymentIntent())->setPaymentIntentId($paymentIntentId)->send();
                } catch (Exception $e) {
                    LogService::getInstance()->error( 'Failed to fetch intent: ' . $e->getMessage() );
                    RemoteLog::error('Failed to fetch intent: ' . $e->getMessage());
                    return $this->upsellErrorResponse(
                        $parentOrder,
                        __( "We couldn't verify your payment details. Please try again.", 'airwallex-online-payments-gateway' ),
                        $e->getMessage(),
                        false
                    );
                }

                if (( $_POST['is_3ds_cancelled'] ?? '' ) === 'true') {
                    if ( ! $this->upsellIntentMatches( $paymentIntent, $parentOrder, $currentOffer ) ) {
                        $logMessage = 'Rejected 3DS cancellation for an intent that is not this upsell.';
                        LogService::getInstance()->error( $logMessage );
                        RemoteLog::error( $logMessage );
                        return $this->upsellErrorResponse(
                            $parentOrder,
                            __( "We couldn't verify this upsell payment. Your original order is unaffected.", 'airwallex-online-payments-gateway' ),
                            $logMessage,
                            false
                        );
                    }
                    $paymentIntent = $this->cancelUpsellPaymentIntent( $paymentIntent );
                    if ( ! $paymentIntent ) {
                        return $this->upsellErrorResponse(
                            $parentOrder,
                            __( "We couldn't cancel this verification. Your original order is unaffected.", 'airwallex-online-payments-gateway' ),
                            'Upsell 3DS intent could not be retrieved after cancellation.',
                            false
                        );
                    }
                    if ( StructPaymentIntent::STATUS_SUCCEEDED === $paymentIntent->getStatus() ) {
                        return $this->paymentIntentResponse( $paymentIntent, $parentOrder, $currentOffer );
                    }
                    if ( StructPaymentIntent::STATUS_CANCELLED !== $paymentIntent->getStatus() ) {
                        return $this->upsellErrorResponse(
                            $parentOrder,
                            __( "We couldn't cancel this verification. Your original order is unaffected.", 'airwallex-online-payments-gateway' ),
                            'Upsell 3DS intent was not cancelled.',
                            false
                        );
                    }
                    WFOCU_Core()->public->handle_failed_upsell();
                    $get_offer = WFOCU_Core()->offers->get_the_next_offer();
                    $data['redirect_url'] = WFOCU_Core()->public->get_the_upsell_url( $get_offer );
                    WFOCU_Core()->data->set( 'current_offer', $get_offer );
                    WFOCU_Core()->data->save();
                    $error = '3D Secure authentication was canceled by the user.';
                    $this->handle_api_error(__( '3D Secure authentication was canceled by the user.', 'airwallex-online-payments-gateway' ), $error, $parentOrder);
                    return array(
                            'result'                => 'success',
                            'payment_intent_status' => $paymentIntent->getStatus(),
                            'next_action'           => $paymentIntent->getNextAction(),
                            'data'                  => $data
                    );
                    return;
                }

                return $this->paymentIntentResponse( $paymentIntent, $parentOrder, $currentOffer );
            }

            $upsellPackage = $this->packagePricedFromOffer( $upsellPackage, $currentOfferMeta, $parentOrder );
            if ( null === $upsellPackage ) {
                $logMessage = 'Upsell price could not be calculated from the offer.';
                LogService::getInstance()->error( $logMessage );
                RemoteLog::error( $logMessage );
                return $this->upsellErrorResponse(
                    $parentOrder,
                    __( "We couldn't process this upsell offer. Your original order is unaffected.", 'airwallex-online-payments-gateway' ),
                    $logMessage,
                    true
                );
            }
            WFOCU_Core()->data->set( '_upsell_package', $upsellPackage );
            $attempts = $this->getUpsellIntentMeta( $parentOrder, self::AIRWALLEX_UPSELL_ATTEMPTS_META_KEY );
            $attemptKey = (string) $currentOffer;
            $packageHash = hash( 'sha256', wp_json_encode( $this->upsellPackageToStore( $upsellPackage ) ) );
            $attempt = $attempts[$attemptKey] ?? null;
            if ( is_array( $attempt ) && ( $attempt['package_hash'] ?? '' ) !== $packageHash ) {
                return array( 'result' => 'error' );
            }
            if ( ! is_array( $attempt ) ) {
                foreach ( $this->getUpsellPaymentIntentIds( $parentOrder ) as $recordedId ) {
                    $snapshot = $this->getUpsellPaymentIntentSnapshot( $parentOrder, $recordedId );
                    if ( $snapshot && (string) $snapshot['offer_id'] === $attemptKey ) {
                        return $this->paymentIntentResponse(
                            ( new RetrievePaymentIntent() )->setPaymentIntentId( $recordedId )->send(),
                            $parentOrder, $currentOffer
                        );
                    }
                }
                $attempt = array( 'create_request_id' => wp_generate_uuid4(), 'confirm_request_id' => wp_generate_uuid4(), 'package_hash' => $packageHash );
                $attempts[$attemptKey] = $attempt;
                $parentOrder->update_meta_data( self::AIRWALLEX_UPSELL_ATTEMPTS_META_KEY, wp_json_encode( $attempts ) );
                $parentOrder->save_meta_data();
            }
            if ( ! empty( $attempt['intent_id'] ) ) {
                $existingIntent = ( new RetrievePaymentIntent() )->setPaymentIntentId( $attempt['intent_id'] )->send();
                if ( ! $this->upsellIntentMatches( $existingIntent, $parentOrder, $currentOffer ) ) {
                    return array( 'result' => 'error' );
                }
                if ( ! in_array( $existingIntent->getStatus(), array( StructPaymentIntent::STATUS_CREATED, StructPaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD ), true ) ) {
                    return $this->paymentIntentResponse( $existingIntent, $parentOrder, $currentOffer );
                }
            }
            if ( ! $this->has_token( $parentOrder ) ) {
                return array( 'result' => 'error' );
            }


            $hasSubscription = false;
            $products = [];
            if ( !empty( $upsellPackage['products'] ) ) {
                foreach ( $upsellPackage['products'] as $productData ) {
                    if ($productData['qty'] <= 0) continue;
                    if ( class_exists('\WC_Subscriptions_Product') && \WC_Subscriptions_Product::is_subscription( $productData['id'] ) ) {
                        $hasSubscription = true;
                    }
                    $item = $productData['data'];
                    $product = [
                        'name'       => ( mb_strlen( $item->get_name() ) <= 120 ? $item->get_name() : mb_substr( $item->get_name(), 0, 117 ) . '...' ),
                        'quantity'   => $productData['qty'],
                        'sku'        => $item->get_sku(),
                        'type'       => $item->get_type(),
                        'unit_price' => $productData['price'],
                    ];
                    $productUrl = $item->get_permalink();
                    if ( ! empty( $productUrl ) ) {
                        $product['url'] = $productUrl;
                    }
                    $products[] = $product;
                }
            }

            try {
                if ( isset( $existingIntent ) ) {
                    $paymentIntent = $existingIntent;
                } else {
                    if ( ! empty( $attempt['creation_started'] ) ) {
                        return $this->upsellErrorResponse( $parentOrder, __( 'Payment initialization is unresolved. Please contact support before retrying.', 'airwallex-online-payments-gateway' ), 'Upsell create result is unknown; refusing another create request.', false );
                    }
                    $attempt['creation_started'] = true;
                    $attempts[$attemptKey] = $attempt;
                    $parentOrder->update_meta_data( self::AIRWALLEX_UPSELL_ATTEMPTS_META_KEY, wp_json_encode( $attempts ) );
                    $parentOrder->save_meta_data();
                    $createPaymentIntent = (new CreatePaymentIntent( $attempt['create_request_id'] ))
                        ->setAmount($upsellPackage['total'])
                        ->setCurrency($parentOrder->get_currency())
                        ->setMerchantOrderId((string) $parentOrder->get_id())
                        ->setOrder(['products' => $products])
                        ->setReferrerDataType(Card::CARD_REFERRER_DATA_TYPE)
                        ->setCustomerId($airwallexCustomerId)
                        ->setMetadata([
                            'is_funnelkit'   => 'yes',
                            'wp_order_id'    => (string) $parentOrder->get_id(),
                            'wfocu_offer_id' => (string) $currentOffer,
                        ]);
                    // Report the store origin as merchant_website_url (Mastercard AN 6022).
                    $merchantWebsiteUrl = Util::getMerchantWebsiteUrl();
                    if (!empty($merchantWebsiteUrl)) {
                        $createPaymentIntent = $createPaymentIntent->setMerchantWebsiteUrl($merchantWebsiteUrl);
                    }
                    $paymentIntent = $createPaymentIntent->send();
                    LogService::getInstance()->debug('Upsell payment intent created: ' . $paymentIntent->getId());
                }
            } catch (Exception $e) {
                LogService::getInstance()->error('FunnelKit Upsell create intent failed: ' . $e->getMessage());
                RemoteLog::error('FunnelKit Upsell create intent failed: ' . $e->getMessage(), RemoteLog::ON_PAYMENT_CREATION_ERROR);
                return $this->upsellErrorResponse(
                    $parentOrder,
                    __( "We couldn't process this upsell offer. Your original order is unaffected.", 'airwallex-online-payments-gateway' ),
                    $e->getMessage(),
                    false
                );
            }

            $currency = strtoupper( (string) $parentOrder->get_currency() );
            if (
                ! $paymentIntent->getId()
                || ! isset( $upsellPackage['total'] )
                || ! is_numeric( $upsellPackage['total'] )
                || strtoupper( (string) $paymentIntent->getCurrency() ) !== $currency
                || ! Util::amountsEqualAtCurrencyPrecision( (float) $paymentIntent->getAmount(), (float) $upsellPackage['total'], $currency )
            ) {
                $logMessage = 'Created upsell intent does not match the offer amount. Confirmation was skipped.';
                LogService::getInstance()->error( $logMessage );
                RemoteLog::error( $logMessage );
                return $this->upsellErrorResponse(
                    $parentOrder,
                    __( "We couldn't process this upsell offer. Your original order is unaffected.", 'airwallex-online-payments-gateway' ),
                    $logMessage,
                    true
                );
            }
            $storedPackage = $this->upsellPackageToStore( $upsellPackage );
            if ( null === $storedPackage ) {
                $logMessage = 'Created upsell intent has no package to book. Confirmation was skipped.';
                LogService::getInstance()->error( $logMessage );
                RemoteLog::error( $logMessage );
                return $this->upsellErrorResponse(
                    $parentOrder,
                    __( "We couldn't process this upsell offer. Your original order is unaffected.", 'airwallex-online-payments-gateway' ),
                    $logMessage,
                    true
                );
            }
            $this->rememberUpsellPaymentIntent( $parentOrder, $paymentIntent->getId(), $currentOffer, $upsellPackage['total'], $currency, $storedPackage );

            $attempt['intent_id'] = (string) $paymentIntent->getId();
            $attempts[$attemptKey] = $attempt;
            $parentOrder->update_meta_data( self::AIRWALLEX_UPSELL_ATTEMPTS_META_KEY, wp_json_encode( $attempts ) );
            $parentOrder->save_meta_data();
            $tokenId = $parentOrder->get_meta( self::AIRWALLEX_UPSELL_PAY_BY_TOKEN_META_KEY, true );
            
            if ( ! empty( $tokenId ) ) {
                $token = \WC_Payment_Tokens::get( $tokenId );
                $paymentConsentId = $token->get_token();
            } else if ($parentOrder->get_meta( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, true )) {
                $paymentConsentId = $parentOrder->get_meta( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, true );
            } else {
                $log = "Missing both payment consent and token. At least one is required.";
                return $this->upsellErrorResponse(
                    $parentOrder,
                    __( 'Missing both payment consent and token. At least one is required.', 'airwallex-online-payments-gateway' ),
                    $log,
                    true
                );
            }
            try {
                /** @var StructPaymentConsent $paymentConsent */
                $paymentConsent = (new RetrievePaymentConsent())->setPaymentConsentId($paymentConsentId)->send();

                if (empty($paymentConsent->getPaymentMethod()['id'])) {
                    throw new Exception(__( 'Invalid payment consent id: ', 'airwallex-online-payments-gateway' ) . $paymentConsentId);
                }
                LogService::getInstance()->debug('Upsell checkout by Payment Method ID: ' .$paymentConsent->getPaymentMethod()['id']);

                $intentConfirmRequest = ( new ConfirmPaymentIntentRequest( $attempt['confirm_request_id'] ) )
                    ->setPaymentIntentId( $paymentIntent->getId() )
                    ->setReturnUrl( WC()->api_request_url( self::THREEDS_RESULT_PAGE_ROUTE_SLUG ) );
                    
                if ($hasSubscription) {
                    $intentConfirmRequest->setPaymentConsent([
                        'next_triggered_by' => StructPaymentConsent::TRIGGERED_BY_MERCHANT,
                        'merchant_trigger_reason' => StructPaymentConsent::MERCHANT_TRIGGER_REASON_SCHEDULED,
                    ]);
                    $intentConfirmRequest->setPaymentMethod( [
                        'id' => $paymentConsent->getPaymentMethod()['id'] ?? '',
                        'type' => 'card',
                    ] );
                } else {
                    $intentConfirmRequest->setPaymentConsentId( $paymentConsentId );
                }
                $paymentIntentAfterCapture = $intentConfirmRequest->send();
            } catch (Exception $e) {
                RemoteLog::error('FunnelKit Upsell process payment failed: ' . $e->getMessage());
                LogService::getInstance()->error('Upsell failed:' . $e->getMessage());
                // The card may already have been charged. Leave the funnel open so the
                // succeeded webhook can record the offer from the snapshot saved before confirm.
                return $this->upsellErrorResponse(
                    $parentOrder,
                    __( "We couldn't complete this upsell payment. Your original order is unaffected. If you see a charge, please contact us.", 'airwallex-online-payments-gateway' ),
                    $e->getMessage(),
                    false
                );
            }

            return $this->paymentIntentResponse( $paymentIntentAfterCapture, $parentOrder, $currentOffer );
        }

        public function recordChargedUpsellFromWebhook( $order, $paymentIntent ) {
            if ( StructPaymentIntent::STATUS_SUCCEEDED !== $paymentIntent->getStatus() || ! class_exists( 'WFOCU_Core' ) ) {
                return;
            }
            $paymentIntentId = (string) $paymentIntent->getId();
            if ( $this->upsellPaymentIntentIsConsumed( $order, $paymentIntentId ) ) {
                return;
            }
            $snapshot = $this->getUpsellPaymentIntentSnapshot( $order, $paymentIntentId );
            if ( null === $snapshot || ! $this->upsellIntentMatches( $paymentIntent, $order, $snapshot['offer_id'] ) || null === $this->bookablePackageFromSnapshot( $snapshot ) ) {
                return;
            }
            $funnel     = WFOCU_Core();
            $sessionKey = (string) $order->get_meta( self::AIRWALLEX_UPSELL_FUNNEL_SESSION_META_KEY, true );
            if ( '' === $sessionKey || ! is_object( $funnel ) || ! is_object( $funnel->data ) || ! method_exists( $funnel->data, 'load_funnel_from_session' ) ) {
                throw new Exception( 'Upsell webhook could not restore the funnel session for intent ' . $paymentIntentId );
            }
            $funnel->data->transient_key = $sessionKey;
            $funnel->data->load_funnel_from_session();
            if ( (string) $funnel->data->get( 'current_offer' ) !== (string) $snapshot['offer_id'] ) {
                throw new Exception( 'Upsell webhook could not match the offer for intent ' . $paymentIntentId );
            }
            if ( false === $this->fulfillChargedUpsell( $order, $paymentIntent, $snapshot['offer_id'] ) && ! $this->upsellPaymentIntentIsConsumed( $order, $paymentIntentId ) ) {
                throw new Exception( 'Could not record upsell intent ' . $paymentIntentId );
            }
        }

        public function processPaymentIntentAndUpdateOrder( $paymentIntent, $parentOrder, $offerId ) {
            wp_send_json( $this->paymentIntentResponse( $paymentIntent, $parentOrder, $offerId ) );
        }

        private function paymentIntentResponse( $paymentIntent, $parentOrder, $offerId ) {
            if (empty($paymentIntent) || !$this->upsellIntentMatches( $paymentIntent, $parentOrder, $offerId )) {
                return $this->upsellErrorResponse(
                    $parentOrder,
                    __( "We couldn't process this upsell offer. Your original order is unaffected.", 'airwallex-online-payments-gateway' ),
                    'Upsell payment intent was empty or not bound to this offer.',
                    false
                );
            }
            $dataFromHandleUpsellCharge = [];
            if ( $paymentIntent->getStatus() === StructPaymentIntent::STATUS_SUCCEEDED ) {
                $fulfilled = $this->fulfillChargedUpsell( $parentOrder, $paymentIntent, $offerId );
                if ( false === $fulfilled ) {
                    $logMessage = 'Rejected upsell payment intent that is not bound to the current offer.';
                    LogService::getInstance()->error( $logMessage );
                    RemoteLog::error( $logMessage );
                    return $this->upsellErrorResponse(
                        $parentOrder,
                        __( "We couldn't verify this upsell payment. Your original order is unaffected.", 'airwallex-online-payments-gateway' ),
                        $logMessage,
                        false
                    );
                }
                $dataFromHandleUpsellCharge = $fulfilled;
            }
            return array(
                    'result'                => 'success',
                    'payment_intent_status' => $paymentIntent->getStatus(),
                    'payment_intent_id'     => $paymentIntent->getId(),
                    'next_action'           => $paymentIntent->getNextAction(),
                    'data'                  => $dataFromHandleUpsellCharge,
            );
        }

        private function upsellErrorResponse( $parentOrder, $customerMessage, $logMessage, $endFunnel ) {
            $this->handle_api_error( $customerMessage, $logMessage, $parentOrder );
            return array(
                'result' => 'error',
                'data'   => $endFunnel ? WFOCU_Core()->process_offer->_handle_upsell_charge( false ) : array( 'redirect_url' => '' ),
            );
        }


        private function cancelUpsellPaymentIntent( $paymentIntent ) {
            $paymentIntentId = (string) $paymentIntent->getId();
            try {
                $cancelled = ( new CancelPaymentIntent() )
                    ->setPaymentIntentId( $paymentIntentId )
                    ->setCancellationReason( 'abandoned' )
                    ->send();
                if ( $cancelled instanceof StructPaymentIntent && StructPaymentIntent::STATUS_CANCELLED === $cancelled->getStatus() ) {
                    return $cancelled;
                }
            } catch ( Exception $e ) {
                LogService::getInstance()->error( 'Upsell 3DS cancel failed: ' . $e->getMessage() );
            }
            try {
                return ( new RetrievePaymentIntent() )->setPaymentIntentId( $paymentIntentId )->send();
            } catch ( Exception $e ) {
                LogService::getInstance()->error( 'Upsell 3DS intent could not be retrieved after cancellation: ' . $e->getMessage() );
                return null;
            }
        }

        public function allow_check_action( $actions ) {
            $actions[] = 'wfocu_front_handle_fkwcs_airwallex_payments';
            return $actions;
        }

        public function has_token( $order ) {
            if ( $this->fulfillingOrderId === (string) $order->get_id() ) {
                return true;
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token id is cast to int and validated against the current user / gateway below.
            if ( ! empty( $_GET['token_id'] ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token id is cast to int and validated against the current user / gateway below.
                $tokenIdFromRequest = intval( $_GET['token_id'] );
                $token              = \WC_Payment_Tokens::get( $tokenIdFromRequest );
                if ( $token && $token->get_user_id() === get_current_user_id() && $token->get_gateway_id() === Card::GATEWAY_ID) {
                    $order->update_meta_data( self::AIRWALLEX_UPSELL_PAY_BY_TOKEN_META_KEY, $tokenIdFromRequest );
                    $order->save_meta_data();
                }
            }
            $paymentIntentId = $order->get_meta( OrderService::META_KEY_INTENT_ID );
            if (empty($paymentIntentId)) {
                return false;
            }
            try {
                /** @var StructPaymentIntent $paymentIntent */
                $paymentIntent = ( new RetrievePaymentIntent() )->setPaymentIntentId( $paymentIntentId )->send();
            } catch (Exception $e) {
                RemoteLog::error('Failed to fetch intent: ' . $e->getMessage());
                LogService::getInstance()->warning($e->getMessage());
                return false;
            }

            if ( ! $paymentIntent->isAuthorized() && ! $paymentIntent->isCaptured() ) {
                return false;
            }

            $order->read_meta_data(true);
            if (!$paymentIntent->getPaymentConsentId() && !$order->get_meta( self::AIRWALLEX_UPSELL_PAY_BY_TOKEN_META_KEY, true )) {
                return false;
            }

            $cardNumberType = $paymentIntent->getLatestPaymentAttempt()['payment_method']['card']['number_type'] ?? '';
            if ( ! Card::getInstance()->is_skip_cvc_enabled() && (empty( $cardNumberType ) || $cardNumberType === 'PAN') ) {
                $order->update_meta_data( self::AIRWALLEX_UPSELL_REQUIRES_CVC_META_KEY, 'yes' );
                $order->save_meta_data();
                return false;
            }

            return true;
        }


        public static function get_instance() {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public function filter_upsell_skip_reason( $order, $skip_key, $reason_messages, $edit_link, $contact_support, $upsell_s_link ) {
            $custom_note = '';

            // Check if the skip reason corresponds to Stripe UPE mode being incompatible
            if ( $skip_key === 6 ) {

                if ($order->get_meta( self::AIRWALLEX_UPSELL_REQUIRES_CVC_META_KEY, true ) === 'yes' ) {
                    $title = __( 'CVC Required.', 'airwallex-online-payments-gateway' );
                    $description = __( "The payment method token requires CVC, which isn't supported during upsell.", 'airwallex-online-payments-gateway' );
                } else {
                    $title = __( 'No token found.', 'airwallex-online-payments-gateway' );
                    $description = __( 'The shopper completed the purchase using a new card.', 'airwallex-online-payments-gateway' );
                }

                /* translators: 1: error icon URL, 2: upsell skipped heading, 3: failure title, 4: failure description. */
                $custom_note = sprintf( '<div style="display:flex;align-items:center;margin-bottom:4px;gap:4px;padding-left:20px !important;background: url(%1$s) no-repeat left !important;">
                        <strong style="font-size:13px;">%2$s</strong>
                    </div>
                    <strong>%3$s</strong> %4$s ',
                    esc_url( WFOCU_PLUGIN_URL . '/admin/assets/img/icon_error.svg' ),
                    __( 'Upsell Skipped', 'airwallex-online-payments-gateway' ),
                    $title,
                    $description
                );
            }

            return [
                'skip_id' => $skip_key,
                'note'    => ! empty( $custom_note ) ? $custom_note : ( $reason_messages[ $skip_key ] ?? '' )
            ];
        }

        public function threeDSReturnPage() {
            $code = "3DS-Success";
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 3DS return page reached via redirect; success flag is compared against a known literal.
            if (empty($_GET['succeeded']) || $_GET['succeeded'] !== 'true') {
                $code = "3DS-Error";
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 3DS return page reached via redirect; value is escaped via esc_js() at the output sink below.
            $paymentIntentId = isset( $_GET['payment_intent_id'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_intent_id'] ) ) : '';
            echo '<html lang="en">
                    <body>
                        <script type="text/javascript">
                            window.parent.postMessage({
                                code: "' . esc_js( $code ) . '",
                                payment_intent_id: "' . esc_js( $paymentIntentId ) . '"
                            }, window.location.origin);
                        </script>
                    </body>
                </html>';
            exit;
        }

        public function maybe_render_in_offer_transaction_scripts() {
            $order = WFOCU_Core()->data->get_current_order();

            if ( ! $order instanceof WC_Order ) {
                return;
            }

            if ( $this->get_key() !== $order->get_payment_method() ) {
                return;
            }
            ?>
            <script>
                (function ($) {
                    "use strict";
                    $(document).off('wfocu_external').on('wfocu_external', function (e, Bucket) {
                        if (0 !== Bucket.getTotal()) {
                            Bucket.inOfferTransaction = true;
                            let getBucketData = Bucket.getBucketSendData();

                            let postData = $.extend(getBucketData, {action: 'wfocu_front_handle_fkwcs_airwallex_payments'});

                            let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_airwallex_payments'), postData);

                            action.done(function (processPaymentResponse) {
                                if (processPaymentResponse.result === 'error') {
                                    Bucket.swal.show({
                                        'text': wfocu_vars.messages.offer_msg_pop_failure,
                                        'type': 'warning'
                                    });
                                    setTimeout(()=>{
                                        window.location = processPaymentResponse.data.redirect_url || wfocu_vars.order_received_url;
                                    }, 2500)
                                    return;
                                }
                                if (processPaymentResponse.payment_intent_status === 'SUCCEEDED') {
                                    Bucket.swal.show({
                                        'text': wfocu_vars.messages.offer_success_message_pop,
                                        'type': 'success'
                                    });
                                    setTimeout(()=>{
                                        window.location = processPaymentResponse.data.redirect_url || wfocu_vars.order_received_url;
                                    }, 2500)
                                    return;
                                }
                                let iframeContainer = document.createElement('div');
                                let iframe = null;
                                if (processPaymentResponse.payment_intent_status === 'REQUIRES_CUSTOMER_ACTION' && processPaymentResponse.next_action.url) {
                                    iframeContainer.style.position = "fixed";
                                    iframeContainer.style.top = "0";
                                    iframeContainer.style.left = "0";
                                    iframeContainer.style.width = "100vw";
                                    iframeContainer.style.height = "100vh";
                                    iframeContainer.style.padding = "0";
                                    iframeContainer.style.margin = "0";
                                    iframeContainer.style.boxSizing = "border-box";
                                    iframeContainer.style.zIndex = "9999999";
                                    iframeContainer.style.backgroundColor = "white";
                                    iframeContainer.style.display = 'flex';
                                    iframeContainer.style.flexDirection = 'column';

                                    let cancelButton = document.createElement('button');
                                    cancelButton.innerText = '✕';
                                    cancelButton.style.alignSelf = 'flex-end';
                                    cancelButton.style.margin = '10px';
                                    cancelButton.style.padding = '6px 12px';
                                    cancelButton.style.fontSize = '14px';
                                    cancelButton.style.cursor = 'pointer';
                                    cancelButton.style.backgroundColor = 'white';
                                    cancelButton.style.color = 'rgb(104, 112, 122)';
                                    cancelButton.style.border = 'none';
                                    cancelButton.style.borderRadius = '4px';
                                    cancelButton.style.zIndex = '10000000';

                                    cancelButton.onclick = function () {
                                        window.removeEventListener('message', onThreeDSMessage, false);
                                        document.body.removeChild(iframeContainer);

										const postDataWithPaymentIntentId = $.extend(Bucket.getBucketSendData(), {
											action: 'wfocu_front_handle_fkwcs_airwallex_payments',
											payment_intent_id: processPaymentResponse.payment_intent_id,
                                            is_3ds_cancelled: 'true'
										});
										let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_airwallex_payments'), postDataWithPaymentIntentId);
										action.done(function (processPaymentResponse) {
											var paid = processPaymentResponse.result !== 'error' && processPaymentResponse.payment_intent_status === 'SUCCEEDED';
											Bucket.swal.show({
												'text': paid ? wfocu_vars.messages.offer_success_message_pop : wfocu_vars.messages.offer_msg_pop_failure,
												'type': paid ? 'success' : 'warning'
											});
											setTimeout(function () {
												window.location = processPaymentResponse.data.redirect_url || wfocu_vars.order_received_url;
											}, 1500);
										});
                                    }
                                    iframeContainer.appendChild(cancelButton);

                                    iframe = document.createElement('iframe');
                                    iframe.src = processPaymentResponse.next_action.url;
                                    iframe.style.width = '100%';
                                    iframe.style.height = '100%';
                                    iframe.style.border = 'none';
                                    iframe.style.flex = '1';

                                    iframeContainer.appendChild(iframe);
                                    document.body.appendChild(iframeContainer);
                                } else {
                                    Bucket.swal.show({
                                        'text': wfocu_vars.messages.offer_msg_pop_failure,
                                        'type': 'warning'
                                    });
                                    setTimeout(()=>{
                                        window.location = processPaymentResponse.data.redirect_url || wfocu_vars.order_received_url;
                                    }, 2500)
                                }

                                const onThreeDSMessage = function(event) {
                                    if (!iframe || event.origin !== window.location.origin || event.source !== iframe.contentWindow
                                        || !event.data || event.data.code !== '3DS-Success'
                                        || typeof event.data.payment_intent_id !== 'string' || !event.data.payment_intent_id
                                        || event.data.payment_intent_id !== processPaymentResponse.payment_intent_id) {
                                        return;
                                    }
                                    window.removeEventListener('message', onThreeDSMessage, false);
                                    if (event.data.code === '3DS-Success') {
                                        const postDataWithPaymentIntentId = $.extend(Bucket.getBucketSendData(), {
                                            action: 'wfocu_front_handle_fkwcs_airwallex_payments',
                                            payment_intent_id: event.data.payment_intent_id
                                        });
                                        let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_airwallex_payments'), postDataWithPaymentIntentId);
                                        action.done(function (processPaymentResponse) {
                                            if (processPaymentResponse.result === 'error' || processPaymentResponse.payment_intent_status !== 'SUCCEEDED') {
                                                Bucket.swal.show({
                                                    'text': wfocu_vars.messages.offer_msg_pop_failure,
                                                    'type': 'warning'
                                                });
                                                iframeContainer.remove();
                                                setTimeout(function () {
                                                    window.location = processPaymentResponse.data.redirect_url || wfocu_vars.order_received_url;
                                                }, 1500);
                                                return;
                                            }
                                            Bucket.swal.show({
                                                'text': wfocu_vars.messages.offer_success_message_pop,
                                                'type': 'success'
                                            });
                                            iframeContainer.remove();

                                            setTimeout(function () {
                                                window.location = processPaymentResponse.data.redirect_url || wfocu_vars.order_received_url;
                                            }, 1500);
                                        });
                                    }
                                };
                                window.addEventListener('message', onThreeDSMessage, false);
                            });
                        }
                    });
                })(jQuery);
            </script>
            <?php
        }
    }

    FunnelKitUpsell::get_instance();
}
