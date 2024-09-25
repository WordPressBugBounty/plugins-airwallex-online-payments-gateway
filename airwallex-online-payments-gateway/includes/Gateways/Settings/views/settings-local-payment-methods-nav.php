<?php
defined('ABSPATH') || exit();

global $current_section;
$awxLpmTabs = apply_filters( 'wc_airwallex_local_gateways_tab', array() );
ksort($awxLpmTabs);
?>
<div class="airwallex-settings-nav-local-payment-methods">
	<?php foreach ( $awxLpmTabs as $awxLpmTabId => $awxLpmTab ) : ?>
		<a
		class="awx-nav-link 
		<?php
		if ( $current_section === $awxLpmTabId ) {
			echo esc_html( 'awx-nav-link-active' );
		}
		?>
		"
		href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . esc_attr($awxLpmTabId) ) ); ?>"><?php echo esc_attr( $awxLpmTab ); ?></a>
	<?php endforeach; ?>
</div>
<div class="clear"></div>
