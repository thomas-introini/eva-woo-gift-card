<?php

namespace Eva\GiftCards;

use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;

defined( 'ABSPATH' ) || exit;

class StoreApiIntegration {

	/**
	 * Redemption service.
	 *
	 * @var RedemptionService
	 */
	private $redemption_service;

	/**
	 * Constructor.
	 *
	 * @param RedemptionService $redemption_service Redemption service.
	 */
	public function __construct( RedemptionService $redemption_service ) {
		$this->redemption_service = $redemption_service;
	}

	/**
	 * Initialize Store API integration.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action(
			'woocommerce_blocks_loaded',
			function () {
				if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema' ) ) {
					return;
				}

				$extend = StoreApi::container()->get( ExtendSchema::class );

				$extend->register_endpoint_data(
					array(
						'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
						'namespace'       => 'eva-gift-cards',
						'data_callback'   => array( $this, 'extend_cart_data' ),
						'schema_callback' => array( $this, 'extend_cart_schema' ),
						'schema_type'     => ARRAY_A,
					)
				);
			}
		);

		// Apply fee when cart is calculated.
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_gift_card_fee' ), 20 );
	}

	/**
	 * Extend cart data.
	 *
	 * @return array
	 */
	public function extend_cart_data() {
		$code = WC()->session ? WC()->session->get( 'eva_gift_card_code' ) : '';
		$amount = WC()->session ? WC()->session->get( 'eva_gift_card_amount_to_apply' ) : 0;

		return array(
			'gift_card_code'   => $code ? $code : '',
			'gift_card_amount' => $amount ? (float) $amount : 0,
		);
	}

	/**
	 * Extend cart schema.
	 *
	 * @return array
	 */
	public function extend_cart_schema() {
		return array(
			'gift_card_code'   => array(
				'description' => __( 'Codice carta regalo applicato', 'eva-gift-cards' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'gift_card_amount' => array(
				'description' => __( 'Importo carta regalo applicato', 'eva-gift-cards' ),
				'type'        => 'number',
				'context'     => array( 'view', 'edit' ),
			),
		);
	}

	/**
	 * Apply gift card as fee.
	 *
	 * @param \WC_Cart $cart Cart object.
	 * @return void
	 */
	public function apply_gift_card_fee( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) && ! defined( 'REST_REQUEST' ) ) {
			return;
		}

		if ( ! WC()->session ) {
			return;
		}

		$code   = WC()->session->get( 'eva_gift_card_code' );
		$amount = WC()->session->get( 'eva_gift_card_amount_to_apply' );

		if ( ! $code || ! $amount || $amount <= 0 ) {
			return;
		}

		$cart_total = (float) $cart->get_total( 'edit' );
		$currency   = get_woocommerce_currency();

		$result = $this->redemption_service->calculate_usable_amount( (string) $code, $cart_total, $currency );

		if ( 'valid' !== $result['status'] || $result['usable'] <= 0 ) {
			WC()->session->set( 'eva_gift_card_code', null );
			WC()->session->set( 'eva_gift_card_amount_to_apply', null );
			return;
		}

		$usable = min( (float) $amount, (float) $result['usable'] );

		if ( $usable <= 0 ) {
			return;
		}

		if ( $usable > $cart_total ) {
			$usable = $cart_total;
		}

		$label = sprintf(
			/* translators: %s: gift card code */
			__( 'Carta regalo (%s)', 'eva-gift-cards' ),
			(string) $code
		);

		$cart->add_fee( $label, -1 * $usable );

		WC()->session->set( 'eva_gift_card_amount_to_apply', $usable );
	}
}

