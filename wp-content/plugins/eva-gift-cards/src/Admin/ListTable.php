<?php
// File: wp-content/plugins/eva-gift-cards/src/Admin/ListTable.php

namespace Eva\GiftCards\Admin;

use Eva\GiftCards\GiftCardRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ListTable extends \WP_List_Table {

	/**
	 * Gift card repository.
	 *
	 * @var GiftCardRepository
	 */
	private $repository;

	/**
	 * Current status filter.
	 *
	 * @var string
	 */
	private $status_filter = '';

	/**
	 * Constructor.
	 *
	 * @param GiftCardRepository $repository Repository.
	 */
	public function __construct( GiftCardRepository $repository ) {
		parent::__construct(
			array(
				'singular' => 'giftcard',
				'plural'   => 'giftcards',
				'ajax'     => false,
			)
		);

		$this->repository = $repository;
	}

	/**
	 * Get columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'code'            => __( 'Codice', 'eva-gift-cards' ),
			'initial_amount'  => __( 'Importo iniziale', 'eva-gift-cards' ),
			'remaining_amount'=> __( 'Importo residuo', 'eva-gift-cards' ),
			'currency'        => __( 'Valuta', 'eva-gift-cards' ),
			'status'          => __( 'Stato', 'eva-gift-cards' ),
			'order_id'        => __( 'Ordine origine', 'eva-gift-cards' ),
			'purchaser_email' => __( 'Email acquirente', 'eva-gift-cards' ),
			'recipient_email' => __( 'Email destinatario', 'eva-gift-cards' ),
			'created_at'      => __( 'Data creazione', 'eva-gift-cards' ),
		);
	}

	/**
	 * Prepare items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$current_page = $this->get_pagenum();
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->status_filter = $status;

		$filters = array(
			'search' => $search,
			'status' => $status,
		);

		$total_items = $this->repository->count( $filters );
		$items       = $this->repository->search( $filters, $current_page, $per_page );

		$this->items = $items;

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at'      => array( 'created_at', true ),
			'remaining_amount'=> array( 'remaining_amount', false ),
		);
	}

	/**
	 * Default column rendering.
	 *
	 * @param array  $item        Item.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'code':
				$link = esc_url(
					add_query_arg(
						array(
							'post'   => (int) $item['order_id'],
							'action' => 'edit',
						),
						admin_url( 'post.php' )
					)
				);

				$actions = array();

				if ( ! empty( $item['order_id'] ) ) {
					$actions['view_order'] = sprintf(
						'<a href="%s">%s</a>',
						$link,
						esc_html__( 'Vedi ordine', 'eva-gift-cards' )
					);
				}

				$code_html = '<code>' . esc_html( $item['code'] ) . '</code>';

				return $code_html . $this->row_actions( $actions );

			case 'initial_amount':
				return wp_kses_post(
					wc_price(
						$item['initial_amount'],
						array(
							'currency' => $item['currency'],
						)
					)
				);

			case 'remaining_amount':
				return wp_kses_post(
					wc_price(
						$item['remaining_amount'],
						array(
							'currency' => $item['currency'],
						)
					)
				);

			case 'currency':
				return esc_html( strtoupper( (string) $item['currency'] ) );

			case 'status':
				switch ( $item['status'] ) {
					case 'active':
						return esc_html__( 'Attiva', 'eva-gift-cards' );
					case 'used_up':
					 return esc_html__( 'Esaurita', 'eva-gift-cards' );
					case 'cancelled':
						return esc_html__( 'Annullata', 'eva-gift-cards' );
					default:
						return esc_html( (string) $item['status'] );
				}

			case 'order_id':
				if ( empty( $item['order_id'] ) ) {
					return '-';
				}
				$url = esc_url(
					add_query_arg(
						array(
							'post'   => (int) $item['order_id'],
							'action' => 'edit',
						),
						admin_url( 'post.php' )
					)
				);
				return sprintf(
					'<a href="%s">%s</a>',
					$url,
					esc_html( '#' . (int) $item['order_id'] )
				);

			case 'purchaser_email':
			case 'recipient_email':
				return esc_html( (string) $item[ $column_name ] );

			case 'created_at':
				$timestamp = strtotime( $item['created_at'] );
				if ( ! $timestamp ) {
					return esc_html( (string) $item['created_at'] );
				}
				return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
		}

		return '';
	}

	/**
	 * Get views (status filters).
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$current = $this->status_filter;

		$statuses = array(
			''          => __( 'Tutti', 'eva-gift-cards' ),
			'active'    => __( 'Attive', 'eva-gift-cards' ),
			'used_up'   => __( 'Esaurite', 'eva-gift-cards' ),
			'cancelled' => __( 'Annullate', 'eva-gift-cards' ),
		);

		$views = array();

		foreach ( $statuses as $key => $label ) {
			$url = add_query_arg(
				array(
					'page'   => 'eva-gift-cards',
					'status' => $key,
				),
				admin_url( 'admin.php' )
			);

			$class = (string) $key === (string) $current ? ' class="current"' : '';

			$views[ $key ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url ),
				$class,
				esc_html( $label )
			);
		}

		return $views;
	}
}


