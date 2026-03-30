<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $current_section;
$airwallexAdminNavTabs = apply_filters( 'wc_airwallex_settings_nav_tabs', array() );
$awxTabsCnt            = count( $airwallexAdminNavTabs );
$awxCurrentIdx         = 0;
$awxActiveTabExist     = false;
?>
<div class="wc-airwallex-settings-logo">
	<img class="airwallex-logo" src="<?php echo esc_attr(AIRWALLEX_PLUGIN_URL . '/assets/images/logo.svg'); ?>"/>
	<span id="awx-account-connected" style="display: none; padding: 3px 8px; font-weight:bold; border-radius:3px; background-color:#E0F7E7">Connected</span>
	<span id="awx-account-not-connected" style="display: none; padding: 3px 8px; font-weight:bold; border-radius:3px; background-color:#FFADAD">Not Connected</span>
</div>
<div class="wc-airwallex-connect-wrapper" style="display: <?php echo esc_attr( $this->isConnected() ? 'none' : 'block' ); ?>;">
	<div class="wc-airwallex-connect-banner">
		<img class="airwallex-rocket" src="<?php echo esc_attr(AIRWALLEX_PLUGIN_URL . '/assets/images/airwallex_rocket.svg'); ?>" />
		<div class="wc-airwallex-connect-banner-text">
			<div style="font-weight: 700; font-size: 1.3em; margin-bottom: 5px"><?php esc_html_e('Activate your Airwallex plug-in', 'airwallex-online-payments-gateway'); ?></div>
			<div><?php esc_html_e('Before you can receive payments with Airwallex, you need to connect your Airwallex account.', 'airwallex-online-payments-gateway'); ?></div>
			<div><?php esc_html_e('Add your Airwallex credentials to your WooCommerce store to activate your plug-in.', 'airwallex-online-payments-gateway'); ?></div>
		</div>
		<div>
			<div class="wc-airwallex-connect-banner-button" onclick="window.open('https://www.airwallex.com/signup?utm_source=gtm-partner&utm_medium=partner_referral&utm_campaign=v01_glbl_multi_ib_dg_prtmk_bofu_woo_global&utm_content=in_app_banner', '_blank', 'noopener')"><?php esc_html_e('Get credentials', 'airwallex-online-payments-gateway'); ?></div>
		</div>
	</div>
</div>
<div class="airwallex-settings-nav">
	<?php
	foreach ( $airwallexAdminNavTabs as $awxTabId => $awxTab ) :
		++$awxCurrentIdx;
		?>
		<a class="nav-tab 
		<?php 
		if ( $current_section === $awxTabId || ( ! $awxActiveTabExist && $awxTabsCnt === $awxCurrentIdx ) ) {
			echo esc_attr( 'nav-tab-active' );
			$awxActiveTabExist = true;
		}
		?>
		"
		   href="<?php echo esc_url(admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $awxTabId )); ?>"><?php echo esc_attr( $awxTab ); ?></a>
	<?php endforeach; ?>
</div>
<div class="clear"></div>
