<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<tr valign="top" style="display: none;" id="wc-airwallex-connect-api-key-button-row">
	<th scope="row" class="titledesc"></th>
	<td class="forminp" style="padding-top: 0">
		<button type="button"
				class="wc-airwallex-connect-api-key-button <?php echo esc_attr( $data['class'] ); ?>"
				id="wc-airwallex-connect-via-api-key-button"
				style="<?php echo esc_attr( $data['css'] ); ?>"
			>
			<span class="wc-airwallex-connect-api-key-button-label"><?php echo wp_kses_post( $data['label_via_api_key'] ); ?></span>
		</button>
		<p class="description wc-airwallex-connection-test-message" style="margin-top: 10px; display: none;"></p>
	</td>
</tr>
