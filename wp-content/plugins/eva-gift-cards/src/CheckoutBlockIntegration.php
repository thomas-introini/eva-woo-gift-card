<?php

namespace Eva\GiftCards;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

class CheckoutBlockIntegration implements IntegrationInterface {

	/**
	 * Get the name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'eva-gift-cards';
	}

	/**
	 * Initialize the integration.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->register_block_frontend_scripts();
		$this->register_block_editor_scripts();
	}

	/**
	 * Register frontend scripts.
	 *
	 * @return void
	 */
	public function register_block_frontend_scripts() {
		$script_path       = '/build/checkout-block.js';
		$script_asset_path = EVA_GIFT_CARDS_PLUGIN_DIR . 'build/checkout-block.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => filemtime( EVA_GIFT_CARDS_PLUGIN_DIR . 'build/checkout-block.js' ),
			);

		wp_register_script(
			'eva-gift-cards-checkout-block',
			plugins_url( $script_path, EVA_GIFT_CARDS_PLUGIN_FILE ),
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_localize_script(
			'eva-gift-cards-checkout-block',
			'evaGiftCardsData',
			array(
				'apiUrl'      => rest_url( 'eva-gift-cards/v1/apply-code' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'currentCode' => WC()->session ? WC()->session->get( 'eva_gift_card_code' ) : '',
			)
		);
	}

	/**
	 * Register editor scripts.
	 *
	 * @return void
	 */
	public function register_block_editor_scripts() {
		$this->register_block_frontend_scripts();
	}

	/**
	 * Get script handles to enqueue.
	 *
	 * @return array
	 */
	public function get_script_handles() {
		return array( 'eva-gift-cards-checkout-block' );
	}

	/**
	 * Get editor script handles.
	 *
	 * @return array
	 */
	public function get_editor_script_handles() {
		return array( 'eva-gift-cards-checkout-block' );
	}

	/**
	 * Get data to pass to the block.
	 *
	 * @return array
	 */
	public function get_script_data() {
		return array(
			'title'       => __( 'Codice carta regalo', 'eva-gift-cards' ),
			'description' => __( 'Inserisci il tuo codice carta regalo', 'eva-gift-cards' ),
		);
	}
}

