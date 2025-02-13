<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<label style="display: none;" id="wc-airwallex-connect-api-key-buttons" for="<?php echo esc_attr( $field_key ); ?>">
	<button type="submit"
			class="wc-airwallex-connect-api-key-button <?php echo esc_attr( $data['class'] ); ?>"
			name="<?php echo esc_attr( $field_key ); ?>"
			id="wc-airwallex-connect-via-api-key-button"
			style="<?php echo esc_attr( $data['css'] ); ?>"
		>
		<span class="wc-airwallex-connect-api-key-button-label"><?php echo wp_kses_post( $data['label_via_api_key'] ); ?></span>
	</button>
	<button type="submit"
			class="wc-airwallex-connect-flow-button <?php echo esc_attr( $data['class'] ); ?>"
			name="<?php echo esc_attr( $field_key ); ?>"
			id="wc-airwallex-connect-flow-button"
			style="border: none; background-color: transparent;"
		>
		<span class="wc-airwallex-connect-flow-button-label"><?php echo wp_kses_post( $data['label_via_connection_flow'] ); ?></span>
	</button>
	<button type="submit"
			class="wc-airwallex-connect-cancel-button <?php echo esc_attr( $data['class'] ); ?>"
			name="<?php echo esc_attr( $field_key ); ?>"
			id="wc-airwallex-connect-cancel-button"
			style="display: none; border: none; background-color: transparent;"
		>
		<span class="wc-airwallex-connect-cancel-button-label"><?php echo wp_kses_post( $data['label_cancel'] ); ?></span>
	</button>
</label>
