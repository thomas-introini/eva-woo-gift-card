<?php
// File: wp-content/plugins/eva-gift-cards/src/Activation.php

namespace Eva\GiftCards;

defined( 'ABSPATH' ) || exit;

class Activation {

	const DB_VERSION_OPTION = 'eva_gift_cards_db_version';
	const DB_VERSION        = '1.0.0';

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_or_update_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Create or update database table.
	 *
	 * @return void
	 */
	private static function create_or_update_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'eva_giftcards';
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(64) NOT NULL,
			initial_amount DECIMAL(10,2) NOT NULL,
			remaining_amount DECIMAL(10,2) NOT NULL,
			currency CHAR(3) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			order_id BIGINT UNSIGNED NOT NULL,
			order_item_id BIGINT UNSIGNED NULL,
			purchaser_email VARCHAR(255) NULL,
			recipient_email VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY order_id (order_id),
			KEY recipient_email (recipient_email),
			KEY purchaser_email (purchaser_email),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}


