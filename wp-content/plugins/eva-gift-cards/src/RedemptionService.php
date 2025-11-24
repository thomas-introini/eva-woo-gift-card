<?php
// File: wp-content/plugins/eva-gift-cards/src/RedemptionService.php

namespace Eva\GiftCards;

defined( 'ABSPATH' ) || exit;

class RedemptionService {

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
	public function __construct( GiftCardRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Calculate usable amount for a gift card.
	 *
	 * @param string $code       Gift card code.
	 * @param float  $cart_total Cart total.
	 * @param string $currency   Store currency.
	 * @return array{status:string,usable:float,gift_card:?array,message:?string}
	 */
	public function calculate_usable_amount( string $code, float $cart_total, string $currency ): array {
		$gift_card = $this->repository->get_by_code( $code );

		if ( ! $gift_card ) {
			return array(
				'status'    => 'invalid',
				'usable'    => 0.0,
				'gift_card' => null,
				'message'   => __( 'Questa carta regalo non è valida o è già stata utilizzata interamente.', 'eva-gift-cards' ),
			);
		}

		if ( 'active' !== $gift_card['status'] ) {
			$message = __( 'Questa carta regalo non è valida o è già stata utilizzata interamente.', 'eva-gift-cards' );
			if ( 'used_up' === $gift_card['status'] ) {
				$message = __( 'Questa carta regalo non ha più credito residuo.', 'eva-gift-cards' );
			}

			return array(
				'status'    => 'invalid',
				'usable'    => 0.0,
				'gift_card' => $gift_card,
				'message'   => $message,
			);
		}

		if ( (float) $gift_card['remaining_amount'] <= 0 ) {
			return array(
				'status'    => 'invalid',
				'usable'    => 0.0,
				'gift_card' => $gift_card,
				'message'   => __( 'Questa carta regalo non ha più credito residuo.', 'eva-gift-cards' ),
			);
		}

		if ( strtoupper( $currency ) !== strtoupper( (string) $gift_card['currency'] ) ) {
			return array(
				'status'    => 'invalid',
				'usable'    => 0.0,
				'gift_card' => $gift_card,
				'message'   => __( 'La valuta della carta regalo non corrisponde alla valuta del negozio.', 'eva-gift-cards' ),
			);
		}

		if ( $cart_total <= 0 ) {
			return array(
				'status'    => 'invalid',
				'usable'    => 0.0,
				'gift_card' => $gift_card,
				'message'   => __( 'Non è possibile applicare la carta regalo a un ordine con totale pari a zero.', 'eva-gift-cards' ),
			);
		}

		$remaining = (float) $gift_card['remaining_amount'];
		$usable    = min( $remaining, $cart_total );

		return array(
			'status'    => 'valid',
			'usable'    => $usable,
			'gift_card' => $gift_card,
			'message'   => null,
		);
	}
}


