<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p style="font-weight: 600;"><?php echo esc_html( $data['title'] ); ?></p>
<span><?php esc_html_e('To use Apple Pay, you\'ll need to register your domain with Apple.', 'airwallex-online-payments-gateway'); ?></span><br/>
<span><?php esc_html_e('1. Select "Register" to add the Apple Pay domain association file to your server.', 'airwallex-online-payments-gateway'); ?></span><br/>
<span>
    <?php
        printf(
            /* translators: 1): Airwallex register domain URL open link tag, 2) Close tag */
            esc_html__('2. Go to %1$sAirwallex%2$s to specify the domain names that you\'ll register with Apple.', 'airwallex-online-payments-gateway'),
            wp_kses_post('<a href="' . esc_url($this->is_sandbox() ? self::DEMO_REGISTER_DOMAIN_URL : self::REGISTER_DOMAIN_URL) . '" target="_blank">' ),
            wp_kses_post('</a>')
        )
    ?>
</span>
<div class="wc-airwallex-settings-field-container">
    <label for="<?php echo esc_attr( $fieldKey ); ?>">
        <?php echo esc_html__('Register domain file', 'airwallex-online-payments-gateway'); ?>
    </label>
    <fieldset>
        <legend class="screen-reader-text">
            <span><?php echo esc_html__('Register domain file', 'airwallex-online-payments-gateway'); ?></span>
        </legend>
        <label for="<?php echo esc_attr( $fieldKey ); ?>">
            <button type="submit"
                    class="<?php echo esc_attr( $data['class'] ); ?>"
                    name="<?php echo esc_attr( $fieldKey ); ?>"
                    id="<?php echo esc_attr( $fieldKey ); ?>"
                    value="<?php echo esc_attr( $fieldKey ); ?>"
                    <?php disabled( $data['disabled'], true ); ?>>
                <?php echo wp_kses_post( $data['label'] ); ?>
            </button>
        </label>
        <br />
    </fieldset>
</div>
<hr>
