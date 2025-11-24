<?php
// File: wp-content/plugins/eva-gift-cards/src/GiftCardRepository.php

namespace Eva\GiftCards;

use wpdb;

defined( 'ABSPATH' ) || exit;

class GiftCardRepository {

	/**
	 * WordPress database object.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . 'eva_giftcards';
	}

	/**
	 * Create gift card.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return int
	 */
	public function create_gift_card( array $data ): int {
		$defaults = array(
			'code'             => '',
			'initial_amount'   => 0.0,
			'remaining_amount' => 0.0,
			'currency'         => '',
			'status'           => 'active',
			'order_id'         => 0,
			'order_item_id'    => null,
			'purchaser_email'  => null,
			'recipient_email'  => null,
			'created_at'       => current_time( 'mysql', true ),
			'updated_at'       => current_time( 'mysql', true ),
		);

		$data = array_merge( $defaults, $data );

		$inserted = $this->wpdb->insert(
			$this->table_name,
			array(
				'code'             => $data['code'],
				'initial_amount'   => $data['initial_amount'],
				'remaining_amount' => $data['remaining_amount'],
				'currency'         => $data['currency'],
				'status'           => $data['status'],
				'order_id'         => $data['order_id'],
				'order_item_id'    => $data['order_item_id'],
				'purchaser_email'  => $data['purchaser_email'],
				'recipient_email'  => $data['recipient_email'],
				'created_at'       => $data['created_at'],
				'updated_at'       => $data['updated_at'],
			),
			array(
				'%s',
				'%f',
				'%f',
				'%s',
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			// Log the error for debugging.
			if ( $this->wpdb->last_error ) {
				error_log( 'Eva Gift Cards DB Error: ' . $this->wpdb->last_error );
			}
			return 0;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Get gift card by code.
	 *
	 * @param string $code Code.
	 * @return array<string,mixed>|null
	 */
	public function get_by_code( string $code ): ?array {
		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE code = %s",
			$code
		);

		$result = $this->wpdb->get_row( $query, ARRAY_A );

		return $result ?: null;
	}

	/**
	 * Update gift card balance.
	 *
	 * @param string $code          Code.
	 * @param float  $new_remaining New remaining amount.
	 * @return bool
	 */
	public function update_balance( string $code, float $new_remaining ): bool {
		$updated = $this->wpdb->update(
			$this->table_name,
			array(
				'remaining_amount' => $new_remaining,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array(
				'code' => $code,
			),
			array(
				'%f',
				'%s',
			),
			array(
				'%s',
			)
		);

		return false !== $updated;
	}

	/**
	 * Mark gift card as used up.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public function mark_used_up( string $code ): bool {
		$updated = $this->wpdb->update(
			$this->table_name,
			array(
				'status'     => 'used_up',
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'code' => $code,
			),
			array(
				'%s',
				'%s',
			),
			array(
				'%s',
			)
		);

		return false !== $updated;
	}

	/**
	 * Search gift cards.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $page    Page.
	 * @param int                 $per_page Per page.
	 * @return array<int,array<string,mixed>>
	 */
	public function search( array $filters, int $page, int $per_page ): array {
		$where   = array();
		$params  = array();
		$offset  = max( 0, ( $page - 1 ) * $per_page );
		$status  = isset( $filters['status'] ) ? $filters['status'] : '';
		$search  = isset( $filters['search'] ) ? $filters['search'] : '';

		if ( $status && in_array( $status, array( 'active', 'used_up', 'cancelled' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $search ) {
			$like     = '%' . $this->wpdb->esc_like( $search ) . '%';
			$where[]  = '(code LIKE %s OR purchaser_email LIKE %s OR recipient_email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$query = "SELECT * FROM {$this->table_name} {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";

		$params[] = $per_page;
		$params[] = $offset;

		$prepared = $this->wpdb->prepare( $query, $params );

		$results = $this->wpdb->get_results( $prepared, ARRAY_A );

		return $results ?: array();
	}

	/**
	 * Count gift cards.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		$where  = array();
		$params = array();

		$status = isset( $filters['status'] ) ? $filters['status'] : '';
		$search = isset( $filters['search'] ) ? $filters['search'] : '';

		if ( $status && in_array( $status, array( 'active', 'used_up', 'cancelled' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $search ) {
			$like     = '%' . $this->wpdb->esc_like( $search ) . '%';
			$where[]  = '(code LIKE %s OR purchaser_email LIKE %s OR recipient_email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$query = "SELECT COUNT(*) FROM {$this->table_name} {$where_sql}";

		$prepared = $this->wpdb->prepare( $query, $params );

		$count = $this->wpdb->get_var( $prepared );

		return (int) $count;
	}

	/**
	 * Get gift cards by order id using codes from order meta.
	 *
	 * @param int   $order_id   Order ID.
	 * @param array $codes      Codes.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_order_codes( int $order_id, array $codes ): array {
		if ( empty( $codes ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );

		$params = $codes;
		array_unshift( $params, $order_id );

		$query = $this->wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE order_id = %d AND code IN ({$placeholders}) ORDER BY created_at ASC",
			$params
		);

		$results = $this->wpdb->get_results( $query, ARRAY_A );

		return $results ?: array();
	}
}


