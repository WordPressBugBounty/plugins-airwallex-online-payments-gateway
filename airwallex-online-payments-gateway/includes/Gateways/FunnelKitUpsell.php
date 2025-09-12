<?php

namespace Airwallex\Gateways;

use Airwallex\Client\CardClient;
use Airwallex\Client\GatewayClient;
use Airwallex\Gateways\Settings\AirwallexSettingsTrait;
use Airwallex\Services\LogService;
use Airwallex\Services\OrderService;
use WC_Order;
use WFOCU_Gateway;
use WFOCU_AJAX_Controller;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Retrieve as RetrievePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentConsent\Retrieve as RetrievePaymentConsent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Confirm as ConfirmPaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\AWXClientAPI\PaymentIntent\Create as CreatePaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentIntent as StructPaymentIntent;
use Airwallex\PayappsPlugin\CommonLibrary\Struct\PaymentConsent as StructPaymentConsent;
use Exception;
use Airwallex\PayappsPlugin\CommonLibrary\Gateway\PluginService\Log as RemoteLog;
use Airwallex\Struct\PaymentIntent;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'WFOCU_Gateway' ) ) {
    class FunnelKitUpsell extends WFOCU_Gateway {
        use AirwallexGatewayTrait;
        use AirwallexSettingsTrait;

        const THREEDS_RESULT_PAGE_ROUTE_SLUG = 'airwallex_3ds_return_page';
        const AIRWALLEX_UPSELL_PAYMENT_INTENTS_META_KEY = '_tmp_airwallex_upsell_payment_intents';
        const AIRWALLEX_UPSELL_PAY_BY_TOKEN_META_KEY = '_tmp_airwallex_pay_by_token';
        const AIRWALLEX_UPSELL_REQUIRES_CVC_META_KEY = '_tmp_airwallex_requires_cvc';
        const FKWCS_SOURCE_ID_META_KEY = '_fkwcs_source_id';
        const GATEWAY_ID = 'airwallex_card';

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
                RemoteLog::error('FunnelKit Upsell create intent failed: ' . $e->getMessage());
                $this->handle_api_error(__($e->getMessage(), 'airwallex-online-payments-gateway'), $e->getMessage(), $order);
            }
        }

        public function getUpsellPaymentIntentIds($order) {
            $valuePaymentIntentIds = $order->get_meta( self::AIRWALLEX_UPSELL_PAYMENT_INTENTS_META_KEY, true );
            $paymentIntentIds = $valuePaymentIntentIds ? json_decode($valuePaymentIntentIds, true) : [];
            $paymentIntentIds = is_array( $paymentIntentIds ) ? $paymentIntentIds : [];
            return $paymentIntentIds;
        }

        public function process_refund_offer( $order ) {
            $refundData = wc_clean( $_POST );  // phpcs:ignore WordPress.Security.NonceVerification.Missing

            $txnId        = $refundData['txn_id'] ?? '';
            $amt          = $refundData['amt'] ?? '';
            $refundReason = $refundData['refund_reason'] ?? '';
            try {
                $refund = GatewayClient::getInstance()->createRefund( $txnId, $amt, $refundReason );
                return $refund && $refund->getId();
            } catch ( Exception $e ) {
                LogService::getInstance()->error( 'FunnelKit Upsell refund failed: ' . $e->getMessage() );
                return false;
            }
        }

        public function process_client_payment() {
            $currentOffer     = WFOCU_Core()->data->get( 'current_offer' );
            $currentOfferMeta = WFOCU_Core()->offers->get_offer_meta( $currentOffer );
            WFOCU_Core()->data->set( '_offer_result', true );
            $postedData = WFOCU_Core()->process_offer->parse_posted_data( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

            if ( false === WFOCU_AJAX_Controller::validate_charge_request( $postedData ) ) {
                wp_send_json( array(
                    'result' => 'error',
                ) );
            }

            WFOCU_Core()->process_offer->execute( $currentOfferMeta );

            $parentOrder = WFOCU_Core()->data->get_parent_order();

            $airwallexCustomerId = OrderService::getInstance()->getAirwallexCustomerId( get_current_user_id(), CardClient::getInstance() );

            $upsellPackage = WFOCU_Core()->data->get( '_upsell_package' );

            $paymentIntentId = filter_input( INPUT_POST, 'payment_intent_id' );
            if ($paymentIntentId) {
                try {
                    $paymentIntent = (new RetrievePaymentIntent())->setPaymentIntentId($paymentIntentId)->send();
                } catch (Exception $e) {
                    RemoteLog::error('Failed to fetch intent: ' . $e->getMessage());
                    $this->handle_api_error(__($e->getMessage(), 'airwallex-online-payments-gateway'), $e->getMessage(), $parentOrder);
                    wp_send_json( array(
                            'result' => 'error',
                            'data' => WFOCU_Core()->process_offer->_handle_upsell_charge( true ),
                    ) );
                    return;
                }

                if (filter_input( INPUT_POST, 'is_3ds_cancelled' ) === 'true') {
                    WFOCU_Core()->public->handle_failed_upsell();
                    $get_offer = WFOCU_Core()->offers->get_the_next_offer();
                    $data['redirect_url'] = WFOCU_Core()->public->get_the_upsell_url( $get_offer );
                    WFOCU_Core()->data->set( 'current_offer', $get_offer );
                    WFOCU_Core()->data->save();
                    $error = '3D Secure authentication was canceled by the user.';
                    $this->handle_api_error(__($error, 'airwallex-online-payments-gateway'), $error, $parentOrder);
                    wp_send_json( array(
                            'result'                => 'success',
                            'payment_intent_status' => $paymentIntent->getStatus(),
                            'next_action'           => $paymentIntent->getNextAction(),
                            'data'                  => $data
                    ) );
                    return;
                }

                $this->processPaymentIntentAndUpdateOrder( $paymentIntent, $parentOrder );
                return;
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
                    $products[] = [
                        'name'       => ( mb_strlen( $item->get_name() ) <= 120 ? $item->get_name() : mb_substr( $item->get_name(), 0, 117 ) . '...' ),
                        'quantity'   => $productData['qty'],
                        'sku'        => $item->get_sku(),
                        'type'       => $item->get_type(),
                        'unit_price' => $productData['price'],
                    ];
                }
            }

            try {
                $paymentIntent = (new CreatePaymentIntent())
                    ->setAmount($upsellPackage['total'])
                    ->setCurrency($parentOrder->get_currency())
                    ->setMerchantOrderId($parentOrder->get_id())
                    ->setOrder(['products' => $products])
                    ->setReferrerDataType(PaymentIntent::CARD_REFERRER_DATA_TYPE)
                    ->setCustomerId($airwallexCustomerId)
                    ->setMetaData(['is_funnelkit' => 'yes'])
                    ->send();
                LogService::getInstance()->debug('Upsell payment intent created: ' . $paymentIntent->getId());
                if ($paymentIntent->getId()) {
                    $paymentIntentIds = $this->getUpsellPaymentIntentIds($parentOrder);
                    $paymentIntentIds[] = $paymentIntent->getId();
                    $parentOrder->update_meta_data( self::AIRWALLEX_UPSELL_PAYMENT_INTENTS_META_KEY, json_encode($paymentIntentIds) );
                    $parentOrder->save_meta_data();
                }
            } catch (Exception $e) {
                RemoteLog::error('FunnelKit Upsell create intent failed: ' . $e->getMessage(), RemoteLog::ON_PAYMENT_CREATION_ERROR);
                $this->handle_api_error(__($e->getMessage(), 'airwallex-online-payments-gateway'), $e->getMessage(), $parentOrder);
                wp_send_json( array(
                        'result' => 'error',
                        'data' => WFOCU_Core()->process_offer->_handle_upsell_charge( true ),
                ) );
                return;
            }

            $tokenId = $parentOrder->get_meta( self::AIRWALLEX_UPSELL_PAY_BY_TOKEN_META_KEY, true );
            
            if ( ! empty( $tokenId ) ) {
                $token = \WC_Payment_Tokens::get( $tokenId );
                $paymentConsentId = $token->get_token();
            } else if ($parentOrder->get_meta( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, true )) {
                $paymentConsentId = $parentOrder->get_meta( OrderService::META_KEY_AIRWALLEX_CONSENT_ID, true );
            } else {
                $log = "Missing both payment consent and token. At least one is required.";
                $this->handle_api_error(__($log, 'airwallex-online-payments-gateway'), $log, $parentOrder);
                wp_send_json( array(
                        'result' => 'error',
                        'data' => WFOCU_Core()->process_offer->_handle_upsell_charge( true ),
                ) );
                return;
            }
            try {
                /** @var StructPaymentConsent $paymentConsent */
                $paymentConsent = (new RetrievePaymentConsent())->setPaymentConsentId($paymentConsentId)->send();

                if (empty($paymentConsent->getPaymentMethod()['id'])) {
                    throw new Exception(__("Invalid payment consent id: ") . $paymentConsentId);
                }
                LogService::getInstance()->debug('Upsell checkout by Payment Method ID: ' .$paymentConsent->getPaymentMethod()['id']);

                $intentConfirmRequest = ( new ConfirmPaymentIntent() )
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
                $this->handle_api_error(__($e->getMessage(), 'airwallex-online-payments-gateway'), $e->getMessage(), $parentOrder);
                wp_send_json( array(
                        'result' => 'error',
                        'data' => WFOCU_Core()->process_offer->_handle_upsell_charge( true ),
                ) );
                return;
            }

            $this->processPaymentIntentAndUpdateOrder( $paymentIntentAfterCapture, $parentOrder );
        }

        /**
         * @param $paymentIntent
         * @param $parentOrder
         *
         * @return void
         */
        public function processPaymentIntentAndUpdateOrder( $paymentIntent, $parentOrder ) {
            if (empty($paymentIntent) || !$paymentIntent->getId()) {
                wp_send_json( array(
                        'result'                => 'error',
                        'data'                  => WFOCU_Core()->process_offer->_handle_upsell_charge( true ),
                ) );
                return;
            }
            $dataFromHandleUpsellCharge = [];
            if ( $paymentIntent->getStatus() === StructPaymentIntent::STATUS_SUCCEEDED ) {
                WFOCU_Core()->data->set( '_transaction_id', $paymentIntent->getId() );
                $parentOrder->update_meta_data( self::FKWCS_SOURCE_ID_META_KEY, $paymentIntent->getId() );
                $parentOrder->set_payment_method( self::GATEWAY_ID );
                $parentOrder->save();
                $dataFromHandleUpsellCharge = WFOCU_Core()->process_offer->_handle_upsell_charge( true );
            }
            wp_send_json( array(
                    'result'                => 'success',
                    'payment_intent_status' => $paymentIntent->getStatus(),
                    'payment_intent_id'     => $paymentIntent->getId(),
                    'next_action'           => $paymentIntent->getNextAction(),
                    'data'                  => $dataFromHandleUpsellCharge,
            ) );
        }

        public function allow_check_action( $actions ) {
            $actions[] = 'wfocu_front_handle_fkwcs_airwallex_payments';
            return $actions;
        }

        public function has_token( $order ) {
            if (!empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'airwallex_webhook') !== false) {
                return true;
            }

            if ( ! empty( $_GET['token_id'] ) ) {
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
                    $title = 'CVC Required.';
                    $description = "The payment method token requires CVC, which isn't supported during upsell.";
                } else {
                    $title = 'No token found.';
                    $description = "The shopper completed the purchase using a new card.";
                }

                $custom_note = sprintf( '<div style="display:flex;align-items:center;margin-bottom:4px;gap:4px;padding-left:20px !important;background: url(%s) no-repeat left !important;">
                        <strong style="font-size:13px;">%s</strong>
                    </div>
                    <strong>%s</strong> %s ', 
                    esc_url( WFOCU_PLUGIN_URL . '/admin/assets/img/icon_error.svg' ), 
                    __( 'Upsell Skipped', 'woofunnels-upstroke-one-click-upsell' ), 
                    __( $title, 'airwallex-online-payments-gateway' ),
                    __( $description, 'airwallex-online-payments-gateway' )
                );
            }

            return [
                'skip_id' => $skip_key,
                'note'    => ! empty( $custom_note ) ? $custom_note : ( $reason_messages[ $skip_key ] ?? '' )
            ];
        }

        public function threeDSReturnPage() {
            $code = "3DS-Success";
            if (empty($_GET['succeeded']) || $_GET['succeeded'] !== 'true') {
                $code = "3DS-Error";
            }
            $paymentIntentId = $_GET['payment_intent_id'] ?? '';
            echo '<html lang="en">
                    <body>
                        <script type="text/javascript">
                            window.parent.postMessage({
                                code: "' . $code . '",
                                payment_intent_id: "' . $paymentIntentId . '"
                            });
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
                                        document.body.removeChild(iframeContainer);

										const postDataWithPaymentIntentId = $.extend(Bucket.getBucketSendData(), {
											action: 'wfocu_front_handle_fkwcs_airwallex_payments',
											payment_intent_id: processPaymentResponse.payment_intent_id,
                                            is_3ds_cancelled: 'true'
										});
										let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_airwallex_payments'), postDataWithPaymentIntentId);
										action.done(function (processPaymentResponse) {
											setTimeout(()=>{
												Bucket.swal.show({
													'text': wfocu_vars.messages.offer_msg_pop_failure,
													'type': 'warning'
												});
											}, 1500);

											setTimeout(function () {
												window.location = processPaymentResponse.data.redirect_url || wfocu_vars.order_received_url;
											}, 1500);
										});
                                    }
                                    iframeContainer.appendChild(cancelButton);

                                    let iframe = document.createElement('iframe');
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

                                window.addEventListener('message', function(event) {
                                    if (event.data.code === '3DS-Success') {
                                        const postDataWithPaymentIntentId = $.extend(Bucket.getBucketSendData(), {
                                            action: 'wfocu_front_handle_fkwcs_airwallex_payments',
                                            payment_intent_id: event.data.payment_intent_id
                                        });
                                        let action = $.post(wfocu_vars.wc_ajax_url.toString().replace('%%endpoint%%', 'wfocu_front_handle_fkwcs_airwallex_payments'), postDataWithPaymentIntentId);
                                        action.done(function (processPaymentResponse) {
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
                                }, false);
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
