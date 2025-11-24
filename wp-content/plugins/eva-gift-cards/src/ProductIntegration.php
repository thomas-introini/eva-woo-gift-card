<?php
// File: wp-content/plugins/eva-gift-cards/src/ProductIntegration.php

namespace Eva\GiftCards;

defined( 'ABSPATH' ) || exit;

class ProductIntegration {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		// Product data tab for simple/variable products.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_general_product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_meta' ) );

		// Variation fields for gift card amount.
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );

		// Recipient email field on product page.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_recipient_email_field' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'store_recipient_email_on_order_item' ), 10, 4 );
	}

	/**
	 * Add general product fields.
	 *
	 * @return void
	 */
	public function add_general_product_fields(): void {
		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_eva_is_gift_card',
				'label'       => __( 'Questo prodotto è una carta regalo', 'eva-gift-cards' ),
				'desc_tip'    => true,
				'description' => __( 'Contrassegna questo prodotto come carta regalo utilizzabile come credito nel negozio.', 'eva-gift-cards' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_eva_gift_card_amount',
				'label'             => __( 'Valore carta regalo (importo)', 'eva-gift-cards' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'desc_tip'          => true,
				'description'       => __( 'Importo della carta regalo per questo prodotto semplice. Per prodotti variabili, imposta l\'importo su ogni variazione.', 'eva-gift-cards' ),
			)
		);

		echo '</div>';
	}

	/**
	 * Save product meta.
	 *
	 * @param \WC_Product $product Product object.
	 * @return void
	 */
	public function save_product_meta( $product ): void {
		$is_gift_card = isset( $_POST['_eva_is_gift_card'] ) && 'yes' === $_POST['_eva_is_gift_card']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$amount_raw  = isset( $_POST['_eva_gift_card_amount'] ) ? wp_unslash( $_POST['_eva_gift_card_amount'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$product->update_meta_data( '_eva_is_gift_card', $is_gift_card ? 'yes' : 'no' );

		$amount = wc_format_decimal( $amount_raw );

		if ( ! empty( $amount ) ) {
			$product->update_meta_data( '_eva_gift_card_amount', $amount );
		} else {
			$product->delete_meta_data( '_eva_gift_card_amount' );
		}

		if ( 'yes' === $product->get_meta( '_eva_is_gift_card', true ) ) {
			if ( 'variable' !== $product->get_type() ) {
				if ( empty( $amount ) || (float) $amount <= 0 ) {
					if ( class_exists( '\WC_Admin_Meta_Boxes' ) ) {
						\WC_Admin_Meta_Boxes::add_error( __( 'Imposta un importo valido per la carta regalo.', 'eva-gift-cards' ) );
					}
				}
			}
		}
	}

	/**
	 * Add variation fields for gift card amount.
	 *
	 * @param int         $loop           Loop index.
	 * @param array       $variation_data Variation data.
	 * @param \WP_Post    $variation      Variation post.
	 * @return void
	 */
	public function add_variation_fields( $loop, $variation_data, $variation ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$amount = get_post_meta( $variation->ID, '_eva_gift_card_amount', true );
		?>
		<div class="form-row form-row-full">
			<label>
				<?php echo esc_html( __( 'Valore carta regalo (importo)', 'eva-gift-cards' ) ); ?>
				<input
					type="number"
					name="<?php echo esc_attr( '_eva_gift_card_amount[' . $loop . ']' ); ?>"
					value="<?php echo esc_attr( $amount ); ?>"
					step="0.01"
					min="0"
				/>
			</label>
		</div>
		<?php
	}

	/**
	 * Save variation fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Index.
	 * @return void
	 */
	public function save_variation_fields( $variation_id, $i ): void {
		$posted = isset( $_POST['_eva_gift_card_amount'][ $i ] ) ? wp_unslash( $_POST['_eva_gift_card_amount'][ $i ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$amount = wc_format_decimal( $posted );

		if ( ! empty( $amount ) && (float) $amount > 0 ) {
			update_post_meta( $variation_id, '_eva_gift_card_amount', $amount );
		} else {
			delete_post_meta( $variation_id, '_eva_gift_card_amount' );
		}
	}

	/**
	 * Check if product or variation is gift card.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	private function is_gift_card_product( $product ): bool {
		if ( ! $product ) {
			return false;
		}

		if ( 'yes' === $product->get_meta( '_eva_is_gift_card', true ) ) {
			return true;
		}

		if ( 'variation' === $product->get_type() ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$parent = wc_get_product( $parent_id );
				if ( $parent && 'yes' === $parent->get_meta( '_eva_is_gift_card', true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get gift card amount for product or variation.
	 *
	 * @param \WC_Product $product Product.
	 * @param int         $variation_id Variation ID.
	 * @return float
	 */
	public function get_gift_card_amount_for_product( $product, int $variation_id = 0 ): float {
		if ( ! $product ) {
			return 0.0;
		}

		$amount = 0.0;

		if ( $variation_id ) {
			$variation_product = wc_get_product( $variation_id );
			if ( $variation_product ) {
				$amount_meta = $variation_product->get_meta( '_eva_gift_card_amount', true );
				if ( '' !== $amount_meta ) {
					$amount = (float) $amount_meta;
				}
			}
		}

		if ( $amount <= 0 ) {
			$amount_meta = $product->get_meta( '_eva_gift_card_amount', true );
			if ( '' !== $amount_meta ) {
				$amount = (float) $amount_meta;
			}
		}

		return $amount > 0 ? $amount : 0.0;
	}

	/**
	 * Render recipient email field on single product page.
	 *
	 * @return void
	 */
	public function render_recipient_email_field(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if ( ! $this->is_gift_card_product( $product ) ) {
			return;
		}

		$value = isset( $_POST['eva_gift_card_recipient_email'] ) ? sanitize_email( wp_unslash( $_POST['eva_gift_card_recipient_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		echo '<div class="eva-gift-card-recipient-email">';
		woocommerce_form_field(
			'eva_gift_card_recipient_email',
			array(
				'type'        => 'email',
				'required'    => true,
				'class'       => array( 'form-row-wide' ),
				'label'       => __( 'Email del destinatario', 'eva-gift-cards' ),
				'placeholder' => __( 'Inserisci l\'email del destinatario', 'eva-gift-cards' ),
			),
			$value
		);
		echo '</div>';
	}

	/**
	 * Validate add to cart.
	 *
	 * @param bool        $passed       Passed.
	 * @param int         $product_id   Product ID.
	 * @param int         $quantity     Quantity.
	 * @param int         $variation_id Variation ID.
	 * @param array       $variations   Variations.
	 * @return bool
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );

		if ( ! $this->is_gift_card_product( $product ) ) {
			return $passed;
		}

		$email = isset( $_POST['eva_gift_card_recipient_email'] ) ? sanitize_email( wp_unslash( $_POST['eva_gift_card_recipient_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( empty( $email ) || ! is_email( $email ) ) {
			wc_add_notice( __( 'Inserisci un\'email del destinatario valida per la carta regalo.', 'eva-gift-cards' ), 'error' );
			return false;
		}

		$amount = $this->get_gift_card_amount_for_product( $product, (int) $variation_id );

		if ( $amount <= 0 ) {
			wc_add_notice( __( 'Imposta un importo valido per la carta regalo.', 'eva-gift-cards' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Add recipient email to cart item data.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ): array {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );

		if ( ! $this->is_gift_card_product( $product ) ) {
			return $cart_item_data;
		}

		$email = isset( $_POST['eva_gift_card_recipient_email'] ) ? sanitize_email( wp_unslash( $_POST['eva_gift_card_recipient_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $email ) {
			$cart_item_data['eva_gift_card_recipient_email'] = $email;
		}

		return $cart_item_data;
	}

	/**
	 * Store recipient email on order item.
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Values.
	 * @param \WC_Order              $order         Order.
	 * @return void
	 */
	public function store_recipient_email_on_order_item( $item, $cart_item_key, $values, $order ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( isset( $values['eva_gift_card_recipient_email'] ) ) {
			$item->add_meta_data(
				'_eva_gift_card_recipient_email',
				sanitize_email( $values['eva_gift_card_recipient_email'] ),
				true
			);
		}
	}
}


