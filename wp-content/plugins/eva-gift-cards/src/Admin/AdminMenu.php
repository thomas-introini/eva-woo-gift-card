<?php
// File: wp-content/plugins/eva-gift-cards/src/Admin/AdminMenu.php

namespace Eva\GiftCards\Admin;

use Eva\GiftCards\GiftCardRepository;

defined( 'ABSPATH' ) || exit;

class AdminMenu {

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Carte regalo Eva', 'eva-gift-cards' ),
			__( 'Carte regalo', 'eva-gift-cards' ),
			'manage_woocommerce',
			'eva-gift-cards',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Non hai i permessi sufficienti per accedere a questa pagina.', 'eva-gift-cards' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$list_table = new ListTable( $this->repository );
		$list_table->prepare_items();

		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap">
			<h1><?php echo esc_html( __( 'Carte regalo Eva', 'eva-gift-cards' ) ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="eva-gift-cards" />
				<?php
				$list_table->views();

				$list_table->search_box( __( 'Cerca per codice o email', 'eva-gift-cards' ), 'eva-gift-cards-search' );
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}
}


