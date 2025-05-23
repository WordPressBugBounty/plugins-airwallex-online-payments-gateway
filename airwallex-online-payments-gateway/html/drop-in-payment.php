<?php
/**
 * Drop in payment template
 *
 * @var $paymentIntentId
 * @var $orderId
 * @var $paymentIntentClientSecret
 * @var $confirmationUrl
 * @var $isSandbox
 * @var $gateway
 * @var $order
 * @var $isSubscription
 * @var $airwallexCustomerId
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_style( 'airwallex-redirect-element-css' );

//prevent errors when using Avada theme and Fusion Builder
//@codingStandardsIgnoreStart
//if (class_exists('Fusion_Template_Builder')) {
	global $post;
	$post = 0;
	do_action( 'wp' );
//}
//@codingStandardsIgnoreEnd

get_header( 'shop' );
?>
	<div class="airwallex-content-drop-in">
		<div class="airwallex-checkout airwallex-tpl-<?php echo esc_attr( $gateway->get_option( 'template' ) ); ?>">
			<div class="airwallex-col-1">
				<div class="cart-heading"><?php echo esc_html__( 'Summary', 'airwallex-online-payments-gateway' ); ?></div>
				<?php
					require __DIR__ . '/inc/order.php';
				?>
			</div>
			<div class="airwallex-col-2">
				<div class="payment-section">
					<div id="airwallex-error-message" style="display:none;">
						<?php echo esc_html__( 'Your payment could not be authenticated', 'airwallex-online-payments-gateway' ); ?>
					</div>
					<div id="airwallex-drop-in"></div>
					<svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 130.2 130.2" id="success-check" style="display:none;">
						<circle class="path circle" fill="none" stroke="#73AF55" stroke-width="6" stroke-miterlimit="10" cx="65.1" cy="65.1" r="62.1"/>
						<polyline class="path check" fill="none" stroke="#73AF55" stroke-width="6" stroke-linecap="round" stroke-miterlimit="10" points="100.2,40.2 51.5,88.8 29.8,67.5 "/>
					</svg>
					<div id="success-message" style="display:none;">
						<?php echo esc_html__( 'Please hold on while your order is completed', 'airwallex-online-payments-gateway' ); ?>
					</div>
				</div>
			</div>
		</div>
	</div>

<?php

$airwallexMethods         = $gateway->get_option( 'methods' );
$airwallexMerchantCountry = strtoupper( substr( $paymentIntentId, 4, 2 ) );
if ( $order->has_billing_address() ) {
	$airwallexBillingAddress     = array(
		'city'         => $order->get_billing_city(),
		'country_code' => $order->get_billing_country(),
		'postcode'     => $order->get_billing_postcode(),
		'state'        => $order->get_billing_state() ? $order->get_billing_state() : $order->get_shipping_state(),
		'street'       => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
	);
	$airwallexBilling['billing'] = array(
		'first_name'   => $order->get_billing_first_name(),
		'last_name'    => $order->get_billing_last_name(),
		'email'        => $order->get_billing_email(),
		'phone_number' => $order->get_billing_phone(),
	);
	if ( ! empty( $airwallexBillingAddress['city'] ) && ! empty( $airwallexBillingAddress['country_code'] ) && ! empty( $airwallexBillingAddress['street'] ) ) {
		$airwallexBilling['billing']['address'] = $airwallexBillingAddress;
	}
}

$airwallexElementConfiguration = array(
	'intent_id'               => $paymentIntentId,
	'client_secret'           => $paymentIntentClientSecret,
	'currency'                => $order->get_currency(),
	'country_code'            => $order->get_billing_country(),
	'autoCapture'             => true,
	'applePayRequestOptions'  => array(
		'countryCode' => $airwallexMerchantCountry,
	),
	'googlePayRequestOptions' => array(
		'countryCode' => $airwallexMerchantCountry,
	),
	'style'                   => array(
		'variant'     => 'bootstrap',
		'popupWidth'  => 400,
		'popupHeight' => 549,
		'base'        => array(
			'color' => 'black',
		),
	),
	'shopper_name'            => $order->get_formatted_billing_full_name(),
	'shopper_phone'           => $order->get_billing_phone(),
	'shopper_email'           => $order->get_billing_email(),
)
+ ( $airwallexCustomerId ? array( 'customer_id' => $airwallexCustomerId ) : array() )
+ ( $isSubscription ? array(
	'mode'             => 'recurring',
	'recurringOptions' => array(
		'card' => array(
			'next_triggered_by'       => 'merchant',
			'merchant_trigger_reason' => 'scheduled',
			'currency'                => $order->get_currency(),
		),
	),
) : array() )
+ ( ! empty( $airwallexMethods ) && is_array( $airwallexMethods ) ? array(
	'methods' => $airwallexMethods,
) : array() )
+ ( isset( $airwallexBilling ) ? $airwallexBilling : array() );

$airwallexRedirectElScriptData = [
    'elementType' => 'dropIn',
    'elementOptions' => $airwallexElementConfiguration,
    'containerId' => 'airwallex-drop-in',
    'orderId' => $order->get_id(),
    'paymentIntentId' => $paymentIntentId,
];
wp_enqueue_script('airwallex-redirect-js');
wp_add_inline_script('airwallex-redirect-js', 'var awxRedirectElData=' . wp_json_encode($airwallexRedirectElScriptData), 'before');
get_footer( 'shop' );
