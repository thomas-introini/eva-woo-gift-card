jQuery(document).ready(function($) {
	console.log('Eva Gift Cards: Script loaded');
	console.log('Eva Gift Cards Data:', window.evaGiftCardsData);
	console.log('Eva Gift Cards: Current code from session:', window.evaGiftCardsData.currentCode);

	// Wait for checkout block to load
	const checkInterval = setInterval(function() {
		const checkoutBlock = $('.wp-block-woocommerce-checkout');

		if (checkoutBlock.length && !$('#eva-gift-card-field').length) {
			console.log('Eva Gift Cards: Checkout block found, injecting field');
			clearInterval(checkInterval);
			injectGiftCardField();
		}
	}, 500);

	// Stop checking after 10 seconds
	setTimeout(function() {
		clearInterval(checkInterval);
		console.log('Eva Gift Cards: Stopped checking for checkout block');
	}, 10000);

	function injectGiftCardField() {
		if (!window.evaGiftCardsData || !window.evaGiftCardsData.labels) {
			console.error('Eva Gift Cards: Data not loaded properly');
			return;
		}

		const labels = window.evaGiftCardsData.labels;
		const currentCode = window.evaGiftCardsData.currentCode || '';

		console.log('Eva Gift Cards: Injecting field with labels', labels);

		const fieldHTML = `
			<div id="eva-gift-card-field" style="margin: 20px 0; padding: 20px; background: #f0f8ff; border: 2px solid #0073aa; border-radius: 4px;">
				<h3 style="margin-top: 0;">${labels.title}</h3>
				<p>${labels.description}</p>
				<div style="display: flex; gap: 10px; margin-bottom: 10px;">
					<input
						type="text"
						id="eva_gift_card_code_input"
						name="eva_gift_card_code"
						value="${currentCode}"
						placeholder="${labels.placeholder}"
						style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"
					/>
					<button
						type="button"
						id="eva_apply_gift_card"
						style="padding: 10px 20px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;"
					>
						${labels.button}
					</button>
				</div>
				<div id="eva-gift-card-message" style="margin: 0;"></div>
			</div>
		`;

		// Try multiple insertion points
		const insertionPoints = [
			'.wc-block-components-totals-wrapper',
			'.wc-block-checkout__main',
			'.wp-block-woocommerce-checkout-order-summary-block',
			'.wp-block-woocommerce-checkout'
		];

		for (const selector of insertionPoints) {
			const target = $(selector).first();
			if (target.length) {
				target.before(fieldHTML);
				break;
			}
		}

		// Handle apply button click
		$(document).on('click', '#eva_apply_gift_card', function(e) {
			e.preventDefault();
			console.log('Eva Gift Cards: Apply button clicked');

			const button = $(this);
			const input = $('#eva_gift_card_code_input');
			const message = $('#eva-gift-card-message');
			const code = input.val().trim();

			console.log('Eva Gift Cards: Applying code:', code);
			console.log('Eva Gift Cards: AJAX URL:', window.evaGiftCardsData.ajaxUrl);

			button.prop('disabled', true).text(labels.applying);
			message.html('');

			$.ajax({
				url: window.evaGiftCardsData.ajaxUrl,
				method: 'POST',
				data: {
					action: 'eva_apply_gift_card',
					nonce: window.evaGiftCardsData.nonce,
					code: code
				},
				success: function(response) {
					console.log('Eva Gift Cards: Response:', response);
					if (response.success) {
						message.html('<span style="color: green;">' + response.data.message + '</span>');
						// Reload to update cart totals
						setTimeout(function() {
							window.location.reload();
						}, 1000);
					} else {
						message.html('<span style="color: red;">' + response.data.message + '</span>');
						button.prop('disabled', false).text(labels.button);
					}
				},
				error: function(xhr, status, error) {
					console.error('Eva Gift Cards: AJAX error:', status, error, xhr);
					message.html('<span style="color: red;">Errore durante l\'applicazione del codice</span>');
					button.prop('disabled', false).text(labels.button);
				}
			});
		});

		console.log('Eva Gift Cards: Field injected and event handler attached');
	}
});
