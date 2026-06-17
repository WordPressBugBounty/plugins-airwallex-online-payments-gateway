<?php
defined('ABSPATH') || exit();

global $current_section;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook name is intentionally namespaced with the 'wc_airwallex' prefix.
$airwallexLpmTabs = apply_filters( 'wc_airwallex_local_gateways_tab', array() );
ksort($airwallexLpmTabs);
?>
<div class="airwallex-settings-nav-local-payment-methods">
	<?php foreach ( $airwallexLpmTabs as $airwallexLpmTabId => $airwallexLpmTab ) : ?>
		<a
		class="awx-nav-link 
		<?php
		if ( $current_section === $airwallexLpmTabId ) {
			echo esc_html( 'awx-nav-link-active' );
		}
		?>
		"
		href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . esc_attr($airwallexLpmTabId) ) ); ?>"><?php echo esc_attr( $airwallexLpmTab ); ?></a>
	<?php endforeach; ?>
</div>
<div class="clear"></div>
