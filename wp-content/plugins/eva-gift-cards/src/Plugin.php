<?php
// File: wp-content/plugins/eva-gift-cards/src/Plugin.php

namespace Eva\GiftCards;

use Eva\GiftCards\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Gift card repository.
	 *
	 * @var GiftCardRepository
	 */
	private $gift_card_repository;

	/**
	 * Redemption service.
	 *
	 * @var RedemptionService
	 */
	private $redemption_service;

	/**
	 * Product integration service.
	 *
	 * @var ProductIntegration
	 */
	private $product_integration;

	/**
	 * Checkout integration service.
	 *
	 * @var Checkout
	 */
	private $checkout;

	/**
	 * Order hooks.
	 *
	 * @var OrderHooks
	 */
	private $order_hooks;

	/**
	 * Admin menu.
	 *
	 * @var AdminMenu|null
	 */
	private $admin_menu;

	/**
	 * Store API integration.
	 *
	 * @var StoreApiIntegration|null
	 */
	private $store_api_integration;

	/**
	 * Get instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Init plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->gift_card_repository = new GiftCardRepository();
		$this->redemption_service   = new RedemptionService( $this->gift_card_repository );
		$this->product_integration  = new ProductIntegration();
		$this->checkout             = new Checkout( $this->redemption_service );
		$this->order_hooks          = new OrderHooks( $this->gift_card_repository );

		$this->product_integration->hooks();
		$this->checkout->hooks();
		$this->order_hooks->hooks();

		// Initialize Store API integration for WooCommerce Blocks.
		$this->store_api_integration = new StoreApiIntegration( $this->redemption_service );
		$this->store_api_integration->init();

		if ( is_admin() ) {
			$this->admin_menu = new AdminMenu( $this->gift_card_repository );
			$this->admin_menu->hooks();
		}
	}
}


