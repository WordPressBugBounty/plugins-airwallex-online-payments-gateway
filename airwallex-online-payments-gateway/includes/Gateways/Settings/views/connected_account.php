<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<tr valign="top">
	<th scope="row" class="titledesc">
		<label for="<?php echo esc_attr( $field_key ); ?>">
			<?php echo wp_kses_post( $data['title'] ); ?>
			<?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?>
		</label>
	</th>
	<td class="forminp">
		<fieldset>
			<legend class="screen-reader-text">
				<span><?php echo wp_kses_post( $data['title'] ); ?></span>
				<span><?php echo wp_kses_post($data['text']) ?></span>
			</legend>
			<span class="wc-airwallex-account-name"><?php echo wp_kses_post($data['text']) ?></span>
			<label for="<?php echo esc_attr( $field_key ); ?>">
				<button type="submit"
						class="<?php echo esc_attr( $data['class'] ); ?>"
						name="<?php echo esc_attr( $field_key ); ?>"
						id="<?php echo esc_attr( $field_key ); ?>"
						style="<?php echo esc_attr( $data['css'] ); ?>"
						value="<?php echo esc_attr( $field_key ); ?>"
						<?php disabled( $data['disabled'], true ); ?>
						<?php echo wp_kses_post( $this->get_custom_attribute_html( $data ) ); ?>>
						<span class="wc-airwallex-connect-button-label"><?php echo wp_kses_post( $data['label'] ); ?></span>
				</button>
			</label>
		</fieldset>
	</td>
</tr>
