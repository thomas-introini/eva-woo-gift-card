<?php
// File: wp-content/plugins/eva-gift-cards/src/OrderHooks.php

namespace Eva\GiftCards;

defined('ABSPATH') || exit;

class OrderHooks
{

	/**
	 * Gift card repository.
	 *
	 * @var GiftCardRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param GiftCardRepository $repository Repository.
	 */
	public function __construct(GiftCardRepository $repository)
	{
		$this->repository = $repository;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void
	{
		add_action('woocommerce_order_status_processing', array($this, 'handle_order_paid'), 20, 1);

		if (is_admin()) {
			add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
			add_action('save_post_shop_order', array($this, 'save_order_meta_box'), 10, 2);
		}
	}

	/**
	 * Handle order paid (processing or completed).
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function handle_order_paid(int $order_id): void
	{
		$order = wc_get_order($order_id);

		if (! $order) {
			return;
		}

		$this->maybe_create_gift_cards($order);
		$this->maybe_deduct_gift_card_balance($order);
	}

	/**
	 * Create gift cards for gift card products in the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function maybe_create_gift_cards($order): void
	{
		$created = $order->get_meta('_eva_gift_cards_created', true);

		if ($created) {
			$order->add_order_note('[Eva Gift Cards] Carte regalo già create in precedenza.');
			return;
		}

		$currency        = $order->get_currency();
		$purchaser_email = $order->get_billing_email();
		$codes           = array();
		$debug_info      = array();

		foreach ($order->get_items('line_item') as $item_id => $item) {
			$product = $item->get_product();

			if (! $product instanceof \WC_Product) {
				$debug_info[] = sprintf('Item #%d: prodotto non valido', $item_id);
				continue;
			}

			$product_id      = $product->get_id();
			$product_name    = $product->get_name();
			$is_gift_card    = 'yes' === $product->get_meta('_eva_is_gift_card', true);
			$gift_card_flag  = $product->get_meta('_eva_is_gift_card', true);

			$debug_info[] = sprintf(
				'Item #%d: %s (ID: %d, Type: %s, Flag: "%s", IsGiftCard: %s)',
				$item_id,
				$product_name,
				$product_id,
				$product->get_type(),
				$gift_card_flag,
				$is_gift_card ? 'SI' : 'NO'
			);

			if (! $is_gift_card && 'variation' === $product->get_type()) {
				$parent_id = $product->get_parent_id();
				if ($parent_id) {
					$parent = wc_get_product($parent_id);
					if ($parent && 'yes' === $parent->get_meta('_eva_is_gift_card', true)) {
						$is_gift_card = true;
						$debug_info[] = sprintf('  → Parent product (ID: %d) è gift card', $parent_id);
					}
				}
			}

			if (! $is_gift_card) {
				continue;
			}

			$amount_per_unit = 0.0;

			if ('variation' === $product->get_type()) {
				$variation_amount = $product->get_meta('_eva_gift_card_amount', true);
				$debug_info[] = sprintf('  → Variation amount meta: "%s"', $variation_amount);
				if ('' !== $variation_amount) {
					$amount_per_unit = (float) $variation_amount;
				}
			}

			if ($amount_per_unit <= 0) {
				$product_amount = $product->get_meta('_eva_gift_card_amount', true);
				$debug_info[] = sprintf('  → Product amount meta: "%s"', $product_amount);
				if ('' !== $product_amount) {
					$amount_per_unit = (float) $product_amount;
				}
			}

			$debug_info[] = sprintf('  → Amount per unit: %.2f', $amount_per_unit);

			if ($amount_per_unit <= 0) {
				$debug_info[] = '  → SKIPPED: amount <= 0';
				continue;
			}

			$quantity        = (int) $item->get_quantity();
			$initial_amount  = $amount_per_unit * $quantity;
			$recipient_email = $item->get_meta('_eva_gift_card_recipient_email', true);
			if (! $recipient_email) {
				$recipient_email = $purchaser_email;
			}

			$code = $this->generate_unique_code();

			$data = array(
				'code'             => $code,
				'initial_amount'   => $initial_amount,
				'remaining_amount' => $initial_amount,
				'currency'         => $currency,
				'status'           => 'active',
				'order_id'         => $order->get_id(),
				'order_item_id'    => $item_id,
				'purchaser_email'  => $purchaser_email ? $purchaser_email : null,
				'recipient_email'  => $recipient_email ? $recipient_email : null,
			);

			$gift_card_id = $this->repository->create_gift_card($data);

			if ($gift_card_id) {
				$codes[] = $code;
				$item->add_meta_data('_eva_gift_card_code', $code, true);
				$item->save();
				$debug_info[] = sprintf('  → CREATED: %s (ID: %d, Amount: %.2f)', $code, $gift_card_id, $initial_amount);
			} else {
				global $wpdb;
				$db_error = $wpdb->last_error ? $wpdb->last_error : 'unknown error';
				$debug_info[] = sprintf('  → ERROR: failed to create gift card in DB: %s', $db_error);
			}
		}

		// Add debug note.
		if (! empty($debug_info)) {
			$order->add_order_note('[Eva Gift Cards Debug]' . "\n" . implode("\n", $debug_info));
		}

		if (! empty($codes)) {
			$order->update_meta_data('_eva_gift_card_codes', $codes);
			$order->update_meta_data('_eva_gift_cards_created', 1);
			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: %d: number of gift cards created */
					__('Create %d carte regalo.', 'eva-gift-cards'),
					count($codes)
				)
			);
		} else {
			$order->add_order_note('[Eva Gift Cards] Nessun prodotto gift card trovato in questo ordine.');
		}
	}

	/**
	 * Deduct gift card balance used on the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function maybe_deduct_gift_card_balance($order): void
	{
		$redeemed = $order->get_meta('_eva_gift_card_redeemed', true);

		if ($redeemed) {
			return;
		}

		$code   = $order->get_meta('_eva_gift_card_code', true);
		$amount = (float) $order->get_meta('_eva_gift_card_amount_used', true);

		error_log('Eva Gift Cards: maybe_deduct_gift_card_balance - Code: ' . $code . ', Amount: ' . $amount);

		if (! $code || $amount <= 0) {
			return;
		}

		$gift_card = $this->repository->get_by_code($code);

		if (! $gift_card) {
			return;
		}

		$remaining = (float) $gift_card['remaining_amount'];
		$new_rem   = max(0.0, $remaining - $amount);

		$this->repository->update_balance($code, $new_rem);

		if ($new_rem <= 0) {
			$this->repository->mark_used_up($code);
		}

		$order->update_meta_data('_eva_gift_card_redeemed', 1);

		$currency = $order->get_currency();

		$order->add_order_note(
			sprintf(
				/* translators: 1: gift card code, 2: amount used, 3: remaining amount */
				__('Carta regalo %1$s utilizzata: %2$s, credito residuo: %3$s', 'eva-gift-cards'),
				$code,
				wc_price(
					$amount,
					array(
						'currency' => $currency,
					)
				),
				wc_price(
					$new_rem,
					array(
						'currency' => $currency,
					)
				)
			)
		);

		$order->save();

		// TODO: implement automatic re-credit on refunds or cancellations via hooks and filters if needed.
	}

	/**
	 * Generate unique gift card code.
	 *
	 * @return string
	 */
	private function generate_unique_code(): string
	{
		$attempts = 0;

		do {
			$code = 'EVA-' . strtoupper(wp_generate_password(16, false, false));
			$existing = $this->repository->get_by_code($code);
			$attempts++;
		} while ($existing && $attempts < 5);

		return $code;
	}

	/**
	 * Add order meta box for gift cards.
	 *
	 * @return void
	 */
	public function add_order_meta_box(): void
	{
		$screen = wc_get_page_screen_id('shop-order');

		add_meta_box(
			'eva_gift_cards_order_meta',
			__('Carte regalo Eva', 'eva-gift-cards'),
			array($this, 'render_order_meta_box'),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render order meta box content.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function render_order_meta_box($post): void
	{
		$order = wc_get_order($post->ID);

		if (! $order) {
			echo '<p>' . esc_html__('Nessuna carta regalo associata a questo ordine.', 'eva-gift-cards') . '</p>';
			return;
		}

		wp_nonce_field('eva_gift_cards_order_meta', 'eva_gift_cards_order_meta_nonce');

		$codes = $order->get_meta('_eva_gift_card_codes', true);
		if (! is_array($codes)) {
			$codes = array();
		}

		$gift_cards = $this->repository->get_by_order_codes($order->get_id(), $codes);

		if (empty($gift_cards)) {
			echo '<p>' . esc_html__('Nessuna carta regalo associata a questo ordine.', 'eva-gift-cards') . '</p>';
		} else {
			echo '<h4>' . esc_html__('Carte regalo generate da questo ordine', 'eva-gift-cards') . '</h4>';
			echo '<div class="eva-gift-cards-table-wrap" style="max-width:100%;overflow-x:auto;">';
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__('Codice', 'eva-gift-cards') . '</th>';
			echo '<th>' . esc_html__('Importo iniziale', 'eva-gift-cards') . '</th>';
			echo '<th>' . esc_html__('Importo residuo', 'eva-gift-cards') . '</th>';
			echo '<th>' . esc_html__('Stato', 'eva-gift-cards') . '</th>';
			echo '<th>' . esc_html__('Data invio', 'eva-gift-cards') . '</th>';
			echo '<th>' . esc_html__('Email destinatario', 'eva-gift-cards') . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ($gift_cards as $gift_card) {
				echo '<tr>';
				echo '<td><code>' . esc_html($gift_card['code']) . '</code></td>';
				echo '<td>' . wp_kses_post(
					wc_price(
						$gift_card['initial_amount'],
						array(
							'currency' => $gift_card['currency'],
						)
					)
				) . '</td>';
				echo '<td>' . wp_kses_post(
					wc_price(
						$gift_card['remaining_amount'],
						array(
							'currency' => $gift_card['currency'],
						)
					)
				) . '</td>';
				$status_label = '';
				switch ($gift_card['status']) {
					case 'active':
						$status_label = __('Attiva', 'eva-gift-cards');
						break;
					case 'used_up':
						$status_label = __('Esaurita', 'eva-gift-cards');
						break;
					case 'cancelled':
						$status_label = __('Annullata', 'eva-gift-cards');
						break;
					default:
						$status_label = (string) $gift_card['status'];
						break;
				}
				echo '<td>' . esc_html($status_label) . '</td>';
				// Scheduled send date from order item meta, formatted as dd/mm/yyyy when present.
				$send_date_display = '-';
				if (! empty($gift_card['order_item_id'])) {
					$ymd = (string) wc_get_order_item_meta((int) $gift_card['order_item_id'], '_eva_gift_card_send_date', true);
					if ('' !== $ymd) {
						if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $ymd)) {
							try {
								$dt = new \DateTimeImmutable($ymd);
								$send_date_display = $dt->format('d/m/Y');
							} catch (\Exception $e) {
								$send_date_display = $ymd;
							}
						} else {
							$send_date_display = $ymd;
						}
					}
				}
				echo '<td>' . esc_html($send_date_display) . '</td>';
				echo '<td>' . esc_html($gift_card['recipient_email']) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
			echo '</div>';
		}

		// Show information about a gift card used to pay for this order, if any.
		$used_code   = $order->get_meta('_eva_gift_card_code', true);
		$used_amount = (float) $order->get_meta('_eva_gift_card_amount_used', true);

		if ($used_code && $used_amount > 0) {
			$used_gift_card = $this->repository->get_by_code($used_code);

			echo '<h4>' . esc_html__('Carta regalo utilizzata su questo ordine', 'eva-gift-cards') . '</h4>';
			echo '<div class="eva-gift-cards-table-wrap" style="max-width:100%;overflow-x:auto;">';
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__('Codice', 'eva-gift-cards') . '</th>';
			echo '<th>' . esc_html__('Importo utilizzato', 'eva-gift-cards') . '</th>';

			if ($used_gift_card) {
				echo '<th>' . esc_html__('Importo residuo', 'eva-gift-cards') . '</th>';
			}

			echo '</tr></thead>';
			echo '<tbody><tr>';
			echo '<td><code>' . esc_html($used_code) . '</code></td>';
			echo '<td>' . wp_kses_post(
				wc_price(
					$used_amount,
					array(
						'currency' => $order->get_currency(),
					)
				)
			) . '</td>';

			if ($used_gift_card) {
				echo '<td>' . wp_kses_post(
					wc_price(
						$used_gift_card['remaining_amount'],
						array(
							'currency' => $used_gift_card['currency'],
						)
					)
				) . '</td>';
			}

			echo '</tr></tbody>';
			echo '</table>';
			echo '</div>';
		}

		$sent = $order->get_meta('_eva_gift_card_pdf_sent', true);
?>
		<p>
			<label>
				<input type="checkbox" name="eva_gift_card_pdf_sent" value="1" <?php checked($sent, 'yes'); ?> />
				<?php echo esc_html(__('PDF carta regalo inviato', 'eva-gift-cards')); ?>
			</label>
		</p>
<?php
	}

	/**
	 * Save order meta box.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function save_order_meta_box($post_id, $post): void
	{
		if ('shop_order' !== $post->post_type && 'woocommerce_page_wc-orders' !== $post->post_type) {
			return;
		}

		if (! isset($_POST['eva_gift_cards_order_meta_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['eva_gift_cards_order_meta_nonce']), 'eva_gift_cards_order_meta')) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if (! current_user_can('manage_woocommerce')) {
			return;
		}

		$order = wc_get_order($post_id);
		if (! $order) {
			return;
		}

		$sent = isset($_POST['eva_gift_card_pdf_sent']) && '1' === $_POST['eva_gift_card_pdf_sent']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order->update_meta_data('_eva_gift_card_pdf_sent', $sent ? 'yes' : 'no');
		$order->save();
	}
}
