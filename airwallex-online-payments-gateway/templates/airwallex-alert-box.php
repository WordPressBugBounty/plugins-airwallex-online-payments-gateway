<?php
/**
 * @var $airwallexAlertAdditionalClass string
 * @var $airwallexAlertType            string
 * @var $airwallexAlertText            string
 * @var $airwallexAlertTitle           string
 * @var $airwallexAlterDisplay         string
 * @var $airwallexAlterShowDismiss     string
 */
defined( 'ABSPATH' ) || exit();

$airwallexAlertBoxClass = '';
$airwallexAlertBoxIcon  = '';
switch ($airwallexAlertType ?? '') {
    case 'critical':
        $airwallexAlertBoxClass = 'wc-airwallex-error';
        $airwallexAlertBoxIcon = 'critical_filled.svg';
        break;
    case 'warning':
        $airwallexAlertBoxClass = 'wc-airwallex-warning';
        $airwallexAlertBoxIcon = 'warning_filled.svg';
        break;
    case 'success':
        $airwallexAlertBoxClass = 'wc-airwallex-success';
        $airwallexAlertBoxIcon = 'green_tick_filled.svg';
        break;
    default:
        $airwallexAlertBoxClass = 'wc-airwallex-info';
        $airwallexAlertBoxIcon = 'info_filled.svg';
        break;
}
?>

<div class="<?php echo esc_attr(!empty($airwallexAlertTitle) ? 'wc-airwallex-alert-box-with-title' : 'wc-airwallex-alert-box') ?> <?php echo esc_attr( $airwallexAlertBoxClass ); ?> <?php echo esc_attr($airwallexAlertAdditionalClass ?? ''); ?>" style="display: none;">
    <div class="wc-airwallex-alert-box-icon"><img src="<?php echo esc_url( AIRWALLEX_PLUGIN_URL . '/assets/images/' . $airwallexAlertBoxIcon ); ?>"></img></div>
    <div class="wc-airwallex-alert-box-content">
        <?php if (!empty($airwallexAlertTitle)) : ?>
            <p style="font-weight: bold;"><?php echo esc_html($airwallexAlertTitle); ?></p>
        <?php endif; ?>
        <p><?php echo wp_kses_post($airwallexAlertText ?? ''); ?></p>
    </div>
</div>
