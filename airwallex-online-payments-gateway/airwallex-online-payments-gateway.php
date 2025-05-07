<?php
/**
 * Plugin Name: Airwallex Online Payments Gateway
 * Plugin URI: https://www.airwallex.com
 * Description: Official Airwallex Plugin
 * Author: Airwallex
 * Author URI: https://www.airwallex.com
 * License: GPLv3 or later
 * Version: 1.19.0
 * Requires at least: 4.5
 * Tested up to: 6.7.2
 * Requires PHP: 7.3
 * WC requires at least: 3.0
 * WC tested up to: 9.7.1
 * Text Domain: airwallex-online-payments-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Airwallex\PayappsPlugin\CommonLibrary\Cache\CacheManager;
use Airwallex\PayappsPlugin\CommonLibrary\Configuration\Init as CommonLibraryInit;
use Airwallex\Services\CacheService;
use Airwallex\Services\Util;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Required minimums and constants
 */
define( 'AIRWALLEX_VERSION', '1.19.0' );
define( 'AIRWALLEX_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'AIRWALLEX_PLUGIN_PATH', __DIR__ . '/' );
define( 'AIRWALLEX_PLUGIN_NAME', 'airwallex-online-payments-gateway' );

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

function airwallex_init() {
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', function () {
			echo '<div class="error"><p><strong>' . esc_html__('Airwallex requires WooCommerce to be installed and active.', 'airwallex-online-payments-gateway') . '</strong></p></div>';
		});
		return;
	}

	$autoloader = AIRWALLEX_PLUGIN_PATH . '/vendor/autoload.php';
    if ( file_exists( $autoloader ) && PHP_VERSION_ID >= 50600 ) {
        require_once $autoloader;
    } else {
        return;
    }

    CommonLibraryInit::getInstance([
        'env' => Util::getEnvironment(),
        'client_id' => Util::getClientId(),
        'api_key' => Util::getApiKey(),
        'plugin_type' => 'woo_commerce',
        'plugin_version' => AIRWALLEX_VERSION,
        'platform_version' => json_encode([
            'woo_version' => WC_VERSION ?? '',
            'wp_version' => get_bloginfo( 'version' ),
        ]),
    ]);

    CacheManager::setInstance(new CacheService());

    $airwallex = \Airwallex\Main::getInstance();
    $airwallex->init();
}

add_action( 'plugins_loaded', 'airwallex_init' );
