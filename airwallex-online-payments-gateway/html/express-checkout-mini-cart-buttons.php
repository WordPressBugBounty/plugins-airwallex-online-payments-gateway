<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<span id="awx-express-checkout-wrapper" class="awx-ec-mini-cart-container">
	<span class="awx-express-checkout-error"></span>
	<span id="awx-express-checkout-button" class="awx-express-checkout-button">
		<?php if ($this->isMethodEnabled('apple_pay')) : ?>
			<span id="awx-ec-apple-pay-btn" class="awx-ec-button awx-apple-pay-btn"></span>
		<?php endif; ?>
		<?php if ($this->isMethodEnabled('google_pay')) : ?>
			<span id="awx-ec-google-pay-btn" class="awx-ec-button awx-google-pay-btn"></span>
		<?php endif; ?>
	</span>
</span>
