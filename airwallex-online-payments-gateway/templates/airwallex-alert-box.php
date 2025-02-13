<?php
/**
 * @var $awxAlertAdditionalClass string
 * @var $awxAlertType            string
 * @var $awxAlertText            string
 * @var $awxAlertTitle           string
 * @var $awxAlterDisplay         string
 * @var $awxAlterShowDismiss     string
 */
defined( 'ABSPATH' ) || exit();

$awxAlertBoxClass = '';
$awxAlertBoxIcon  = '';
switch ($awxAlertType) {
    case 'critical':
        $awxAlertBoxClass = 'wc-airwallex-error';
        $awxAlertBoxIcon = 'critical_filled.svg';
        break;
    case 'warning':
        $awxAlertBoxClass = 'wc-airwallex-warning';
        $awxAlertBoxIcon = 'warning_filled.svg';
        break;
    case 'success':
        $awxAlertBoxClass = 'wc-airwallex-success';
        $awxAlertBoxIcon = 'green_tick_filled.svg';
        break;
    default:
        $awxAlertBoxClass = 'wc-airwallex-info';
        $awxAlertBoxIcon = 'info_filled.svg';
        break;
}
?>

<div class="<?php echo esc_attr($awxAlertTitle ? 'wc-airwallex-alert-box-with-title' : 'wc-airwallex-alert-box') ?> <?php echo esc_attr( $awxAlertBoxClass ); ?> <?php echo esc_attr($awxAlertAdditionalClass); ?>" style="display: none;">
    <div class="wc-airwallex-alert-box-icon"><img src="<?php echo esc_url( AIRWALLEX_PLUGIN_URL . '/assets/images/' . $awxAlertBoxIcon ); ?>"></img></div>
    <div class="wc-airwallex-alert-box-content">
        <?php if ($awxAlertTitle) : ?>
            <p style="font-weight: bold;"><?php echo esc_html($awxAlertTitle); ?></p>
        <?php endif; ?>
        <p><?php echo wp_kses_post($awxAlertText); ?></p>
    </div>
</div>
