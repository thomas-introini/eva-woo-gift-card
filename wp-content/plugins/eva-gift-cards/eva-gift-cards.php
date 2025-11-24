<?php
// File: wp-content/plugins/eva-gift-cards/eva-gift-cards.php

/**
 * Plugin Name: Eva Gift Cards
 * Plugin URI: https://example.com
 * Description: Gift card store credit system for WooCommerce.
 * Version: 1.0.0
 * Author: Eva
 * Text Domain: eva-gift-cards
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EVA_GIFT_CARDS_PLUGIN_FILE' ) ) {
	define( 'EVA_GIFT_CARDS_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'EVA_GIFT_CARDS_PLUGIN_DIR' ) ) {
	define( 'EVA_GIFT_CARDS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

require_once EVA_GIFT_CARDS_PLUGIN_DIR . 'src/autoload.php';

use Eva\GiftCards\Activation;
use Eva\GiftCards\Plugin;

/**
 * Load plugin textdomain.
 */
function eva_gift_cards_load_textdomain() {
	load_plugin_textdomain(
		'eva-gift-cards',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'eva_gift_cards_load_textdomain', 5 );

register_activation_hook( __FILE__, array( Activation::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>';
						esc_html_e( 'Il plugin Eva Gift Cards richiede WooCommerce attivo.', 'eva-gift-cards' );
						echo '</p></div>';
					}
				}
			);
			return;
		}

		Plugin::instance()->init();
	},
	20
);


