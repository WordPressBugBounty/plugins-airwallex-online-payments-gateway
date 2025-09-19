<?php
/**
 * Drop in payment template for shortcode
 *
 * @var $paymentIntentId
 * @var $orderId
 * @var $paymentIntentClientSecret
 * @var $confirmationUrl
 * @var $isSandbox
 * @var $order
 * @var $isSubscription
 * @var $airwallexCustomerId
 **/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_enqueue_style( 'airwallex-redirect-element-css' );

?>
	<div class="airwallex-content-drop-in <?php echo esc_attr( $shortcodeAtts['class'] ); ?>" style="<?php echo esc_attr( $shortcodeAtts['style'] ); ?>">
		<div class="airwallex-checkout airwallex-tpl-<?php echo esc_attr( $this->get_option( 'template' ) ); ?>">
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
