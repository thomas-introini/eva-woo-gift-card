<?php
// File: wp-content/plugins/eva-gift-cards/src/Checkout.php

namespace Eva\GiftCards;

defined('ABSPATH') || exit;

class Checkout
{

	/**
	 * Session key for gift card code.
	 *
	 * @var string
	 */
	const SESSION_CODE_KEY = 'eva_gift_card_code';

	/**
	 * Session key for amount to apply.
	 *
	 * @var string
	 */
	const SESSION_AMOUNT_KEY = 'eva_gift_card_amount_to_apply';

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
	public function __construct(RedemptionService $redemption_service)
	{
		$this->redemption_service = $redemption_service;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void
	{
		// 1) Frontend JS for the block checkout.
		add_action('wp_enqueue_scripts', array($this, 'enqueue_block_scripts'));

		// 2) Apply the discount as a fee on the cart (used by Store API too).
		add_action('woocommerce_cart_calculate_fees', array($this, 'apply_gift_card_fee'), 20);

		// 3) Make sure the fee is considered when Store API updates the order.
		add_action(
			'woocommerce_store_api_cart_update_order_from_request',
			array($this, 'apply_gift_card_fee_store_api'),
			20,
			2
		);

		// 4) Store meta when a Blocks checkout order is created.
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			array($this, 'store_gift_card_on_order_blocks'),
			20,
			1
		);

		// 5) One way to apply/remove the code:
		//    EITHER keep AJAX...
		add_action('wp_ajax_eva_apply_gift_card', array($this, 'ajax_apply_gift_card'));
		add_action('wp_ajax_nopriv_eva_apply_gift_card', array($this, 'ajax_apply_gift_card'));

		//    ...OR, cleaner: use your REST route from JS and drop AJAX.
		add_action('rest_api_init', array($this, 'register_rest_routes'));
	}


	/**
	 * AJAX handler to apply gift card (uses WC session properly).
	 *
	 * @return void
	 */
	public function ajax_apply_gift_card(): void
	{
		check_ajax_referer('eva-gift-cards-nonce', 'nonce');

		// Ensure session is initialized.
		if (! WC()->session || ! WC()->session->has_session()) {
			WC()->session->set_customer_session_cookie(true);
		}

		$code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';

		error_log('Eva Gift Cards: AJAX - Received code: ' . $code);

		if (empty($code)) {
			WC()->session->set(self::SESSION_CODE_KEY, null);
			WC()->session->set(self::SESSION_AMOUNT_KEY, null);
			wp_send_json_success(array('message' => ''));
		}

		if (! WC()->cart) {
			wp_send_json_error(array('message' => __('Carrello non disponibile.', 'eva-gift-cards')));
		}

		$cart_total = (float) WC()->cart->get_total('edit');
		$currency   = get_woocommerce_currency();

		$result = $this->redemption_service->calculate_usable_amount($code, $cart_total, $currency);

		if ('valid' !== $result['status'] || $result['usable'] <= 0) {
			WC()->session->set(self::SESSION_CODE_KEY, null);
			WC()->session->set(self::SESSION_AMOUNT_KEY, null);

			error_log('Eva Gift Cards: AJAX - Invalid code');

			wp_send_json_error(
				array(
					'message' => $result['message'] ?? __('Questa carta regalo non è valida o è già stata utilizzata interamente.', 'eva-gift-cards'),
				)
			);
		}

		WC()->session->set(self::SESSION_CODE_KEY, $code);
		WC()->session->set(self::SESSION_AMOUNT_KEY, $result['usable']);

		error_log('Eva Gift Cards: AJAX - Saved to session - Code: ' . $code . ', Amount: ' . $result['usable']);
		error_log('Eva Gift Cards: AJAX - Session ID: ' . WC()->session->get_customer_id());

		// Verify it was saved.
		$verify_code = WC()->session->get(self::SESSION_CODE_KEY);
		error_log('Eva Gift Cards: AJAX - Verified code from session: ' . ($verify_code ? $verify_code : 'empty'));

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: formatted amount */
					__('Carta regalo applicata! Sconto: %s', 'eva-gift-cards'),
					wc_price($result['usable'])
				),
			)
		);
	}

	/**
	 * Apply gift card fee for Store API (WooCommerce Blocks).
	 *
	 * @param \WC_Cart         $cart    Cart object.
	 * @param \WP_REST_Request $request Request object.
	 * @return void
	 */
	public function apply_gift_card_fee_store_api($cart, $request): void
	{
		// Ensure session exists for Store API.
		if (! WC()->session || ! WC()->session->has_session()) {
			WC()->session->set_customer_session_cookie(true);
		}

		error_log(
			'Eva Gift Cards: Store API - customer_id=' . WC()->session->get_customer_id() .
				', route=' . $request->get_route()
		);

		$this->apply_gift_card_fee($cart);
	}

	/**
	 * Enqueue scripts for block checkout.
	 *
	 * @return void
	 */
	public function enqueue_block_scripts(): void
	{
		if (! has_block('woocommerce/checkout') && ! is_checkout()) {
			return;
		}

		// Load custom CSS (if any) configured in admin.
		$custom_css = (string) get_option('eva_gc_custom_css', '');
		if ($custom_css) {
			wp_add_inline_style('woocommerce-general', $custom_css);
		}

		// Resolve admin-configured colors with sane defaults.
		$style_box_bg      = (string) get_option('eva_gc_box_bg_color', '#f0f8ff');
		$style_box_border  = (string) get_option('eva_gc_box_border_color', '#0073aa');
		$style_apply_bg    = (string) get_option('eva_gc_apply_btn_bg_color', '#0073aa');
		$style_apply_text  = (string) get_option('eva_gc_apply_btn_text_color', '#ffffff');
		$style_remove      = (string) get_option('eva_gc_remove_btn_color', '#d63638');

		wp_enqueue_script(
			'eva-gift-cards-block-checkout',
			plugins_url('assets/block-checkout.js', EVA_GIFT_CARDS_PLUGIN_FILE),
			array('jquery'),
			'1.0.1',
			true
		);

		// Ensure WooCommerce session is initialized.
		if (! WC()->session || ! WC()->session->has_session()) {
			error_log('Eva Gift Cards: Enqueue - No session, setting cookie');
			WC()->session->set_customer_session_cookie(true);
		}
		error_log('Eva Gift Cards: Enqueue - Session ID: ' . WC()->session->get_customer_id());

		$current_code = '';
		if (WC()->session) {
			$current_code = WC()->session->get(self::SESSION_CODE_KEY);
			error_log('Eva Gift Cards: Enqueue - Session code: ' . ($current_code ? $current_code : 'empty'));
		}

		wp_localize_script(
			'eva-gift-cards-block-checkout',
			'evaGiftCardsData',
			array(
				'ajaxUrl'     => admin_url('admin-ajax.php'),
				'nonce'       => wp_create_nonce('eva-gift-cards-nonce'),
				'currentCode' => $current_code ? $current_code : '',
				'labels'      => array(
					'title'       => __('Hai un codice carta regalo?', 'eva-gift-cards'),
					'description' => __('Inserisci il tuo codice carta regalo qui sotto.', 'eva-gift-cards'),
					'placeholder' => __('EVA-XXXXXXXXXXXXXXXX', 'eva-gift-cards'),
					'button'      => __('Applica', 'eva-gift-cards'),
					'applying'    => __('Applicando...', 'eva-gift-cards'),
					'remove'      => __('Rimuovi', 'eva-gift-cards'),
					'removing'    => __('Rimuovendo...', 'eva-gift-cards'),
					'applied'     => __('Carta regalo applicata:', 'eva-gift-cards'),
				),
				'styles'      => array(
					'boxBg'        => $style_box_bg,
					'boxBorder'    => $style_box_border,
					'applyBtnBg'   => $style_apply_bg,
					'applyBtnText' => $style_apply_text,
					'removeColor'  => $style_remove,
				),
			)
		);
	}

	/**
	 * Register REST routes for block checkout.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void
	{
		register_rest_route(
			'eva-gift-cards/v1',
			'/apply-code',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'rest_apply_gift_card'),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST endpoint to apply gift card code.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_apply_gift_card($request)
	{
		// Ensure WooCommerce is initialized.
		if (! WC()->session) {
			if (! WC()->initialize_session()) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'message' => __('Impossibile inizializzare la sessione.', 'eva-gift-cards'),
					),
					500
				);
			}
		}

		if (! WC()->cart) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __('Carrello non disponibile.', 'eva-gift-cards'),
				),
				500
			);
		}

		$code = sanitize_text_field($request->get_param('code'));

		if (empty($code)) {
			WC()->session->set(self::SESSION_CODE_KEY, null);
			WC()->session->set(self::SESSION_AMOUNT_KEY, null);
			WC()->cart->calculate_totals();
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => '',
				),
				200
			);
		}

		$cart_total = (float) WC()->cart->get_total('edit');
		$currency   = get_woocommerce_currency();

		$result = $this->redemption_service->calculate_usable_amount($code, $cart_total, $currency);

		if ('valid' !== $result['status'] || $result['usable'] <= 0) {
			WC()->session->set(self::SESSION_CODE_KEY, null);
			WC()->session->set(self::SESSION_AMOUNT_KEY, null);
			WC()->cart->calculate_totals();

			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => $result['message'] ?? __('Questa carta regalo non è valida o è già stata utilizzata interamente.', 'eva-gift-cards'),
				),
				200
			);
		}

		WC()->session->set(self::SESSION_CODE_KEY, $code);
		WC()->session->set(self::SESSION_AMOUNT_KEY, $result['usable']);

		// Save session explicitly.
		WC()->session->save_data();

		// Recalculate cart totals to apply the fee.
		WC()->cart->calculate_totals();

		error_log('Eva Gift Cards: REST API - Saved to session - Code: ' . $code . ', Amount: ' . $result['usable']);
		error_log('Eva Gift Cards: REST API - Session ID: ' . WC()->session->get_customer_id());
		error_log(
			'Eva Gift Cards: REST API - Saved to session - Code: ' . $code .
				', Amount: ' . $result['usable'] .
				', Session ID: ' . WC()->session->get_customer_id()
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: formatted amount */
					__('Carta regalo applicata! Sconto: %s', 'eva-gift-cards'),
					wc_price($result['usable'])
				),
			),
			200
		);
	}


	/**
	 * Enqueue styles for checkout field.
	 *
	 * @return void
	 */
	public function enqueue_styles(): void
	{
		if (! is_checkout()) {
			return;
		}

		wp_add_inline_style(
			'woocommerce-general',
			'.eva-gift-card-field { margin: 20px 0; padding: 20px; background: #f7f7f7; border: 1px solid #ddd; border-radius: 4px; }'
		);
	}

	/**
	 * Render gift card code field on checkout.
	 *
	 * @return void
	 */
	public function render_gift_card_field(): void
	{
		if (! WC()->cart) {
			return;
		}

		$session_code = WC()->session ? WC()->session->get(self::SESSION_CODE_KEY) : '';
		$value        = isset($_POST['eva_gift_card_code']) ? sanitize_text_field(wp_unslash($_POST['eva_gift_card_code'])) : $session_code; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		echo '<div class="eva-gift-card-field">';
		echo '<h3>' . esc_html__('Codice carta regalo', 'eva-gift-cards') . '</h3>';

		woocommerce_form_field(
			'eva_gift_card_code',
			array(
				'type'        => 'text',
				'required'    => false,
				'class'       => array('form-row-wide'),
				'input_class' => array('input-text'),
				'label'       => __('Codice carta regalo', 'eva-gift-cards'),
				'placeholder' => __('Inserisci il codice della carta regalo', 'eva-gift-cards'),
			),
			$value
		);

		echo '</div>';
	}

	/**
	 * Render gift card field in alternate location (before checkout form).
	 *
	 * @return void
	 */
	public function render_gift_card_field_alternate(): void
	{
		if (! WC()->cart) {
			return;
		}

		$session_code = WC()->session ? WC()->session->get(self::SESSION_CODE_KEY) : '';

		echo '<div class="eva-gift-card-field-alternate" style="margin: 20px 0; padding: 20px; background: #f0f8ff; border: 2px solid #0073aa; border-radius: 4px;">';
		echo '<h3 style="margin-top: 0;">' . esc_html__('Hai un codice carta regalo?', 'eva-gift-cards') . '</h3>';
		echo '<p>' . esc_html__('Inserisci il tuo codice carta regalo qui sotto. Lo sconto verrà applicato automaticamente al tuo ordine.', 'eva-gift-cards') . '</p>';

		woocommerce_form_field(
			'eva_gift_card_code',
			array(
				'type'        => 'text',
				'required'    => false,
				'class'       => array('form-row-wide'),
				'input_class' => array('input-text'),
				'label'       => __('Codice carta regalo', 'eva-gift-cards'),
				'placeholder' => __('EVA-XXXXXXXXXXXXXXXX', 'eva-gift-cards'),
			),
			$session_code
		);

		echo '</div>';
	}

	/**
	 * Validate gift card code on checkout process.
	 *
	 * @return void
	 */
	public function validate_gift_card_on_checkout(): void
	{
		if (! WC()->cart || ! WC()->session) {
			return;
		}

		// For Store API / Blocks, validation is handled via our REST endpoints,
		// not this classic checkout hook.
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return;
		}

		$code = '';
		if (isset($_POST['eva_gift_card_code'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$code = sanitize_text_field(wp_unslash($_POST['eva_gift_card_code'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$code = trim($code);

		// IMPORTANT: do NOT clear session when code is empty.
		// On Blocks checkout this is always empty and we would kill the applied gift card.
		if ('' === $code) {
			return;
		}

		$cart_total = (float) WC()->cart->get_cart_contents_total(); // or get_subtotal()
		$currency   = get_woocommerce_currency();

		$result = $this->redemption_service->calculate_usable_amount($code, $cart_total, $currency);

		if ('valid' !== $result['status'] || $result['usable'] <= 0) {
			WC()->session->set(self::SESSION_CODE_KEY, null);
			WC()->session->set(self::SESSION_AMOUNT_KEY, null);

			if (! empty($result['message'])) {
				wc_add_notice($result['message'], 'error');
			} else {
				wc_add_notice(
					__('Questa carta regalo non è valida o è già stata utilizzata interamente.', 'eva-gift-cards'),
					'error'
				);
			}

			return;
		}

		WC()->session->set(self::SESSION_CODE_KEY, $code);
		WC()->session->set(self::SESSION_AMOUNT_KEY, $result['usable']);
	}


	/**
	 * Apply gift card as negative fee.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	public function apply_gift_card_fee($cart): void
	{
		// Don't run in admin except ajax/REST.
		if (is_admin() && ! defined('DOING_AJAX') && ! defined('REST_REQUEST')) {
			return;
		}

		if (! WC()->session) {
			error_log('Eva Gift Cards: No session in apply_gift_card_fee');
			return;
		}

		$code   = WC()->session->get(self::SESSION_CODE_KEY);
		$amount = (float) WC()->session->get(self::SESSION_AMOUNT_KEY);

		error_log(
			'Eva Gift Cards: apply_gift_card_fee called - Code: ' . ($code ?: 'none') .
				', Amount: ' . ($amount ?: 'none')
		);

		if (! $code || $amount <= 0) {
			return;
		}

		// Use items total, not already-discounted total.
		$cart_total = (float) $cart->get_cart_contents_total();
		if ($cart_total <= 0) {
			return;
		}

		// Cap usable amount to cart total.
		$usable = min($amount, $cart_total);

		if ($usable <= 0) {
			return;
		}

		$label = sprintf(
			/* translators: %s: gift card code */
			__('Carta regalo (%s)', 'eva-gift-cards'),
			(string) $code
		);

		// Avoid duplicate fees in the same calculation: remove existing fee with same label.
		foreach ($cart->get_fees() as $fee_key => $fee) {
			if ($fee->name === $label) {
				unset($cart->fees_api()->fees[$fee_key]);
			}
		}

		error_log('Eva Gift Cards: Adding fee - Label: ' . $label . ', Amount: -' . $usable);

		$cart->add_fee($label, -1 * $usable);

		// Store the final usable amount actually applied (for order meta).
		WC()->session->set(self::SESSION_AMOUNT_KEY, $usable);
	}


	/**
	 * Store gift card usage on order meta.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Data.
	 * @return void
	 */
	public function store_gift_card_on_order($order, $data): void
	{
		if (! WC()->session) {
			error_log('Eva Gift Cards: store_gift_card_on_order - no session');
			return;
		}

		$code   = WC()->session->get(self::SESSION_CODE_KEY);
		$amount = WC()->session->get(self::SESSION_AMOUNT_KEY);

		error_log(
			'Eva Gift Cards: store_gift_card_on_order - Code: ' . ($code ?: 'none') .
				', Amount: ' . ($amount ?: 'none')
		);

		if (! $code || ! $amount || $amount <= 0) {
			return;
		}

		$order->update_meta_data('_eva_gift_card_code', (string) $code);
		$order->update_meta_data('_eva_gift_card_amount_used', (float) $amount);
		$order->save();

		// Clear session after storing on order.
		WC()->session->set(self::SESSION_CODE_KEY, null);
		WC()->session->set(self::SESSION_AMOUNT_KEY, null);
	}

	/**
	 * Store gift card usage on order meta for blocks checkout.
	 *
	 * @param \WC_Order $order   Order.
	 * @return void
	 */
	public function store_gift_card_on_order_blocks($order): void
	{
		if (! WC()->session) {
			error_log('Eva Gift Cards: store_gift_card_on_order_blocks - no session');
			return;
		}

		$code   = WC()->session->get(self::SESSION_CODE_KEY);
		$amount = WC()->session->get(self::SESSION_AMOUNT_KEY);

		error_log(
			'Eva Gift Cards: store_gift_card_on_order_blocks - Code: ' . ($code ?: 'none') .
				', Amount: ' . ($amount ?: 'none')
		);

		if (! $code || ! $amount || $amount <= 0) {
			return;
		}

		$order->update_meta_data('_eva_gift_card_code', (string) $code);
		$order->update_meta_data('_eva_gift_card_amount_used', (float) $amount);
		$order->save();

		// Clear after storing.
		WC()->session->set(self::SESSION_CODE_KEY, null);
		WC()->session->set(self::SESSION_AMOUNT_KEY, null);
	}
}
