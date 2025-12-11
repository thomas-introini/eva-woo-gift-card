<?php
// File: wp-content/plugins/eva-gift-cards/src/ProductIntegration.php

namespace Eva\GiftCards;

use function add_action;
use function add_filter;
use function delete_post_meta;
use function esc_attr;
use function esc_html;
use function get_post_meta;
use function is_email;
use function sanitize_email;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function update_post_meta;
use function wp_kses_post;
use function wp_timezone;
use function wp_unslash;

defined('ABSPATH') || exit;

class ProductIntegration
{

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void
	{
		// Product data tab for simple/variable products.
		add_action('woocommerce_product_options_general_product_data', array($this, 'add_general_product_fields'));
		add_action('woocommerce_admin_process_product_object', array($this, 'save_product_meta'));

		// Variation fields for gift card amount.
		add_action('woocommerce_product_after_variable_attributes', array($this, 'add_variation_fields'), 10, 3);
		add_action('woocommerce_save_product_variation', array($this, 'save_variation_fields'), 10, 2);

		// Recipient email field on product page.
		add_action('woocommerce_before_add_to_cart_button', array($this, 'render_recipient_email_field'));
		add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_add_to_cart'), 10, 5);
		add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);
		add_action('woocommerce_checkout_create_order_line_item', array($this, 'store_recipient_email_on_order_item'), 10, 4);

		// Show recipient email and custom message in cart/checkout line item.
		add_filter('woocommerce_get_item_data', array($this, 'display_item_data'), 10, 2);

		// Show recipient email and message in order recap / view order / emails.
		add_action('woocommerce_order_item_meta_end', array($this, 'render_order_item_meta'), 10, 4);
	}

	/**
	 * Add general product fields.
	 *
	 * @return void
	 */
	public function add_general_product_fields(): void
	{
		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_eva_is_gift_card',
				'label'       => __('Questo prodotto è una carta regalo', 'eva-gift-cards'),
				'desc_tip'    => true,
				'description' => __('Contrassegna questo prodotto come carta regalo utilizzabile come credito nel negozio.', 'eva-gift-cards'),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_eva_gift_card_amount',
				'label'             => __('Valore carta regalo (importo)', 'eva-gift-cards'),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'desc_tip'          => true,
				'description'       => __('Importo della carta regalo per questo prodotto semplice. Per prodotti variabili, imposta l\'importo su ogni variazione.', 'eva-gift-cards'),
			)
		);

		woocommerce_wp_textarea_input(
			array(
				'id'          => '_eva_gift_card_message',
				'label'       => __('Messaggio personalizzato PDF', 'eva-gift-cards'),
				'desc_tip'    => true,
				'description' => __('Testo che verrà inserito nel PDF della carta regalo al momento della generazione.', 'eva-gift-cards'),
				'rows'        => 4,
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
	public function save_product_meta($product): void
	{
		$is_gift_card = isset($_POST['_eva_is_gift_card']) && 'yes' === $_POST['_eva_is_gift_card']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$amount_raw  = isset($_POST['_eva_gift_card_amount']) ? wp_unslash($_POST['_eva_gift_card_amount']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$product->update_meta_data('_eva_is_gift_card', $is_gift_card ? 'yes' : 'no');

		$amount = wc_format_decimal($amount_raw);

		if (! empty($amount)) {
			$product->update_meta_data('_eva_gift_card_amount', $amount);
		} else {
			$product->delete_meta_data('_eva_gift_card_amount');
		}

		$message_raw = isset($_POST['_eva_gift_card_message']) ? wp_unslash($_POST['_eva_gift_card_message']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$message     = wp_kses_post($message_raw);

		if ('' !== trim($message)) {
			$product->update_meta_data('_eva_gift_card_message', $message);
		} else {
			$product->delete_meta_data('_eva_gift_card_message');
		}

		if ('yes' === $product->get_meta('_eva_is_gift_card', true)) {
			if ('variable' !== $product->get_type()) {
				if (empty($amount) || (float) $amount <= 0) {
					if (class_exists('\WC_Admin_Meta_Boxes')) {
						\WC_Admin_Meta_Boxes::add_error(__('Imposta un importo valido per la carta regalo.', 'eva-gift-cards'));
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
	public function add_variation_fields($loop, $variation_data, $variation): void
	{ // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$amount = get_post_meta($variation->ID, '_eva_gift_card_amount', true);
?>
		<div class="form-row form-row-full">
			<label>
				<?php echo esc_html(__('Valore carta regalo (importo)', 'eva-gift-cards')); ?>
				<input
					type="number"
					name="<?php echo esc_attr('_eva_gift_card_amount[' . $loop . ']'); ?>"
					value="<?php echo esc_attr($amount); ?>"
					step="0.01"
					min="0" />
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
	public function save_variation_fields($variation_id, $i): void
	{
		$posted = isset($_POST['_eva_gift_card_amount'][$i]) ? wp_unslash($_POST['_eva_gift_card_amount'][$i]) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$amount = wc_format_decimal($posted);

		if (! empty($amount) && (float) $amount > 0) {
			update_post_meta($variation_id, '_eva_gift_card_amount', $amount);
		} else {
			delete_post_meta($variation_id, '_eva_gift_card_amount');
		}
	}

	/**
	 * Check if product or variation is gift card.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	private function is_gift_card_product($product): bool
	{
		if (! $product) {
			return false;
		}

		if ('yes' === $product->get_meta('_eva_is_gift_card', true)) {
			return true;
		}

		if ('variation' === $product->get_type()) {
			$parent_id = $product->get_parent_id();
			if ($parent_id) {
				$parent = wc_get_product($parent_id);
				if ($parent && 'yes' === $parent->get_meta('_eva_is_gift_card', true)) {
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
	public function get_gift_card_amount_for_product($product, int $variation_id = 0): float
	{
		if (! $product) {
			return 0.0;
		}

		$amount = 0.0;

		if ($variation_id) {
			$variation_product = wc_get_product($variation_id);
			if ($variation_product) {
				$amount_meta = $variation_product->get_meta('_eva_gift_card_amount', true);
				if ('' !== $amount_meta) {
					$amount = (float) $amount_meta;
				}
			}
		}

		if ($amount <= 0) {
			$amount_meta = $product->get_meta('_eva_gift_card_amount', true);
			if ('' !== $amount_meta) {
				$amount = (float) $amount_meta;
			}
		}

		return $amount > 0 ? $amount : 0.0;
	}

	/**
	 * Format a stored ISO date (YYYY-MM-DD) into dd/mm/yyyy for display.
	 *
	 * @param string|null $ymd Date string in Y-m-d format.
	 * @return string
	 */
	private function format_display_date($ymd): string
	{
		$ymd = (string) $ymd;
		if ('' === $ymd) {
			return '';
		}
		if (! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $ymd)) {
			return $ymd;
		}
		try {
			$dt = new \DateTimeImmutable($ymd);
			return $dt->format('d/m/Y');
		} catch (\Exception $e) {
			return $ymd;
		}
	}

	/**
	 * Render recipient email field on single product page.
	 *
	 * @return void
	 */
	public function render_recipient_email_field(): void
	{
		global $product;

		if (! $product instanceof \WC_Product) {
			return;
		}

		if (! $this->is_gift_card_product($product)) {
			return;
		}

		$value = isset($_POST['eva_gift_card_recipient_email']) ? sanitize_email(wp_unslash($_POST['eva_gift_card_recipient_email'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$message_value = isset($_POST['eva_gift_card_message']) ? sanitize_textarea_field(wp_unslash($_POST['eva_gift_card_message'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$scheduled_value = isset($_POST['eva_gift_card_send_date']) ? sanitize_text_field(wp_unslash($_POST['eva_gift_card_send_date'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$today_min = current_time('Y-m-d');

		echo '<div class="eva-gift-card-fields">';

		echo '<div class="eva-gift-card-recipient-email">';
		woocommerce_form_field(
			'eva_gift_card_recipient_email',
			array(
				'type'        => 'email',
				'required'    => false,
				'class'       => array('form-row-wide'),
				'label'       => __('Email del destinatario', 'eva-gift-cards'),
				'placeholder' => __('Lascia vuoto per inviare al tuo indirizzo', 'eva-gift-cards'),
			),
			$value
		);
		echo '</div>';

		echo '<div class="eva-gift-card-message">';
		woocommerce_form_field(
			'eva_gift_card_message',
			array(
				'type'        => 'textarea',
				'required'    => false,
				'class'       => array('form-row-wide'),
				'label'       => __('Messaggio personalizzato', 'eva-gift-cards'),
				'placeholder' => __('Scrivi un messaggio da includere nel PDF della carta regalo', 'eva-gift-cards'),
			),
			$message_value
		);
		echo '</div>';

		// Scheduled send date (optional, only relevant when recipient email is provided).
		echo '<div class="eva-gift-card-send-date">';
		woocommerce_form_field(
			'eva_gift_card_send_date',
			array(
				'type'              => 'date',
				'required'          => false,
				'class'             => array('form-row-first'),
				'label'             => __('Data invio programmata', 'eva-gift-cards'),
				'placeholder'       => '',
				'custom_attributes' => array_filter(
					array(
						'min'      => $today_min,
						'disabled' => empty($value) ? 'disabled' : null,
					)
				),
			),
			$scheduled_value
		);
		echo '</div>';

		// Lightweight, product-scoped script: keeps the date field disabled until a recipient email is set
		// and enforces min=today in the client (server-side validation remains authoritative).
		echo '<script>
document.addEventListener("DOMContentLoaded",function(){var e=document.getElementById("eva_gift_card_recipient_email"),t=document.getElementById("eva_gift_card_send_date");if(!e||!t)return;function n(){var n=(new Date).toISOString().slice(0,10);t.min=n;var a=(e.value||"").trim()==="";t.disabled=a;if(a){t.value=""}}e.addEventListener("input",n);e.addEventListener("change",n);n()});
</script>';
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
	public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = array())
	{ // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$product = wc_get_product($variation_id ? $variation_id : $product_id);

		if (! $this->is_gift_card_product($product)) {
			return $passed;
		}

		$email = isset($_POST['eva_gift_card_recipient_email']) ? sanitize_email(wp_unslash($_POST['eva_gift_card_recipient_email'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$message = isset($_POST['eva_gift_card_message']) ? sanitize_textarea_field(wp_unslash($_POST['eva_gift_card_message'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$send_date_raw = isset($_POST['eva_gift_card_send_date']) ? sanitize_text_field(wp_unslash($_POST['eva_gift_card_send_date'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Email is optional: only validate format if provided.
		if (! empty($email) && ! is_email($email)) {
			wc_add_notice(__('Inserisci un\'email del destinatario valida per la carta regalo oppure lascia vuoto.', 'eva-gift-cards'), 'error');
			return false;
		}

		$amount = $this->get_gift_card_amount_for_product($product, (int) $variation_id);

		if ($amount <= 0) {
			wc_add_notice(__('Imposta un importo valido per la carta regalo.', 'eva-gift-cards'), 'error');
			return false;
		}

		// Optional message length guard.
		if (! empty($message) && mb_strlen($message) > 1000) {
			wc_add_notice(__('Il messaggio personalizzato è troppo lungo (max 1000 caratteri).', 'eva-gift-cards'), 'error');
			return false;
		}

		// Scheduled date validation: only relevant if recipient email is provided and a date is set.
		if (! empty($email) && '' !== $send_date_raw) {
			if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $send_date_raw)) {
				wc_add_notice(__('La data programmata non è valida.', 'eva-gift-cards'), 'error');
				return false;
			}

			try {
				$tz     = wp_timezone();
				$chosen = new \DateTimeImmutable($send_date_raw . ' 00:00:00', $tz);
				$today  = new \DateTimeImmutable('today', $tz);
			} catch (\Exception $e) {
				wc_add_notice(__('La data programmata non è valida.', 'eva-gift-cards'), 'error');
				return false;
			}

			if ($chosen < $today) {
				wc_add_notice(__('La data di invio non può essere nel passato.', 'eva-gift-cards'), 'error');
				return false;
			}
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
	public function add_cart_item_data($cart_item_data, $product_id, $variation_id): array
	{
		$product = wc_get_product($variation_id ? $variation_id : $product_id);

		if (! $this->is_gift_card_product($product)) {
			return $cart_item_data;
		}

		$email = isset($_POST['eva_gift_card_recipient_email']) ? sanitize_email(wp_unslash($_POST['eva_gift_card_recipient_email'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$message = isset($_POST['eva_gift_card_message']) ? sanitize_textarea_field(wp_unslash($_POST['eva_gift_card_message'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$send_date_raw = isset($_POST['eva_gift_card_send_date']) ? sanitize_text_field(wp_unslash($_POST['eva_gift_card_send_date'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ($email) {
			$cart_item_data['eva_gift_card_recipient_email'] = $email;
		}

		if ('' !== $message) {
			$cart_item_data['eva_gift_card_message'] = $message;
		}

		// Only store the scheduled date if an email is set and date is non-empty and valid format.
		if (! empty($email) && '' !== $send_date_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $send_date_raw)) {
			$cart_item_data['eva_gift_card_send_date'] = $send_date_raw;
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
	public function store_recipient_email_on_order_item($item, $cart_item_key, $values, $order): void
	{ // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if (isset($values['eva_gift_card_recipient_email'])) {
			$item->add_meta_data(
				'_eva_gift_card_recipient_email',
				sanitize_email($values['eva_gift_card_recipient_email']),
				true
			);
		}

		if (isset($values['eva_gift_card_message'])) {
			$item->add_meta_data(
				'_eva_gift_card_message',
				sanitize_textarea_field($values['eva_gift_card_message']),
				true
			);
		}

		if (isset($values['eva_gift_card_send_date'])) {
			$item->add_meta_data(
				'_eva_gift_card_send_date',
				sanitize_text_field($values['eva_gift_card_send_date']),
				true
			);
		}
	}

	/**
	 * Display recipient email and message in cart/checkout line item data.
	 *
	 * @param array<string,mixed> $item_data Existing item data.
	 * @param array<string,mixed> $cart_item Cart item.
	 * @return array<string,mixed>
	 */
	public function display_item_data($item_data, $cart_item)
	{
		$product = isset($cart_item['data']) ? $cart_item['data'] : null;

		if (! $product instanceof \WC_Product) {
			return $item_data;
		}

		if (! $this->is_gift_card_product($product)) {
			return $item_data;
		}

		$email = isset($cart_item['eva_gift_card_recipient_email']) ? sanitize_email($cart_item['eva_gift_card_recipient_email']) : '';
		if (! empty($email)) {
			$item_data[] = array(
				'name'  => __('Email destinatario', 'eva-gift-cards'),
				'value' => esc_html($email),
			);
		}

		$message = isset($cart_item['eva_gift_card_message']) ? sanitize_textarea_field($cart_item['eva_gift_card_message']) : '';
		if ('' !== $message) {
			$item_data[] = array(
				'name'    => __('Messaggio', 'eva-gift-cards'),
				'value'   => esc_html($message),
				'display' => wp_kses_post(nl2br(esc_html($message))),
			);
		}

		$send_date = isset($cart_item['eva_gift_card_send_date']) ? sanitize_text_field($cart_item['eva_gift_card_send_date']) : '';
		if ('' !== $send_date) {
			$item_data[] = array(
				'name'  => __('Data invio', 'eva-gift-cards'),
				'value' => esc_html($this->format_display_date($send_date)),
			);
		}

		return $item_data;
	}

	/**
	 * Render recipient email and message in order item meta (order received, view order, emails).
	 *
	 * @param int                 $item_id   Item ID.
	 * @param \WC_Order_Item      $item      Order item.
	 * @param \WC_Order           $order     Order.
	 * @param bool                $plain_text Whether plain text (emails) or HTML.
	 * @return void
	 */
	public function render_order_item_meta($item_id, $item, $order, $plain_text): void
	{
		$product = $item->get_product();

		if (! $product instanceof \WC_Product) {
			return;
		}

		if (! $this->is_gift_card_product($product)) {
			return;
		}

		$email   = $item->get_meta('_eva_gift_card_recipient_email', true);
		$message = $item->get_meta('_eva_gift_card_message', true);
		$send_date = $item->get_meta('_eva_gift_card_send_date', true);

		if (empty($email) && '' === (string) $message && '' === (string) $send_date) {
			return;
		}

		if ($plain_text) {
			if (! empty($email)) {
				echo sprintf("%s: %s\n", __('Email destinatario', 'eva-gift-cards'), $email); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ('' !== (string) $message) {
				echo sprintf("%s: %s\n", __('Messaggio', 'eva-gift-cards'), $message); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ('' !== (string) $send_date) {
				echo sprintf("%s: %s\n", __('Data invio', 'eva-gift-cards'), $this->format_display_date($send_date)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			return;
		}

		echo '<div class="eva-gift-card-order-meta">';
		if (! empty($email)) {
			echo '<p><strong>' . esc_html(__('Email destinatario', 'eva-gift-cards')) . ':</strong> ' . esc_html($email) . '</p>';
		}
		if ('' !== (string) $message) {
			echo '<p><strong>' . esc_html(__('Messaggio', 'eva-gift-cards')) . ':</strong><br />' . wp_kses_post(nl2br(esc_html($message))) . '</p>';
		}
		if ('' !== (string) $send_date) {
			echo '<p><strong>' . esc_html(__('Data invio', 'eva-gift-cards')) . ':</strong> ' . esc_html($this->format_display_date($send_date)) . '</p>';
		}
		echo '</div>';
	}
}
