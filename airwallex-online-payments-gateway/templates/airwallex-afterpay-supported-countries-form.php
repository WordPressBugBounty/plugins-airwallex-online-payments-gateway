<?php

defined( 'ABSPATH' ) || exit();

?>

<div class="wc-airwallex-afterpay-supported-countries-form" style="display: none;">
    <div class="awx-choose-afterpay-region-title"><?php esc_html_e( 'Choose your Afterpay account region', 'airwallex-online-payments-gateway' ); ?></div>

    <div style="margin: 10px 0;"><?php esc_html_e( 'If you don’t have an account yet, choose the region that you will create your account from.', 'airwallex-online-payments-gateway' ); ?></div>
    <div class="awx-afterpay-countries">
        <div class="input-icon">
            <img src="<?php echo esc_url( AIRWALLEX_PLUGIN_URL . '/assets/images/select_arrow.svg' ); ?>" alt="arrow" />
        </div>
        <div>
            <input readonly type="text" placeholder="<?php echo esc_attr__( 'Afterpay account region', 'airwallex-online-payments-gateway' ); ?>" />
        </div>
        <div class="countries" style="display: none">
            <ul>
                <li data-value="US"><?php esc_html_e( 'United States', 'airwallex-online-payments-gateway' ); ?></li>
                <li data-value="AU"><?php esc_html_e( 'Australia', 'airwallex-online-payments-gateway' ); ?></li>
                <li data-value="NZ"><?php esc_html_e( 'New Zealand', 'airwallex-online-payments-gateway' ); ?></li>
                <li data-value="GB"><?php esc_html_e( 'United Kingdom', 'airwallex-online-payments-gateway' ); ?></li>
                <li data-value="CA"><?php esc_html_e( 'Canada', 'airwallex-online-payments-gateway' ); ?></li>
            </ul>
        </div>
    </div>
</div>
