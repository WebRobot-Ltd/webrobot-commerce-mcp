<?php
defined( 'ABSPATH' ) || exit;

/** Audit log of every price change made through the MCP. */
class WRCMCP_Log {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wrcmcp_price_log';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			at datetime NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			sku varchar(191) NOT NULL,
			old_price decimal(19,4) NULL,
			new_price decimal(19,4) NULL,
			field varchar(16) NOT NULL DEFAULT 'regular',
			reason text NULL,
			actor varchar(191) NULL,
			reverted tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY sku (sku),
			KEY product_id (product_id)
		) $charset;" );
	}

	public static function add( $product_id, $sku, $old, $new, $field, $reason, $actor ) {
		global $wpdb;
		$wpdb->insert( self::table(), array(
			'at'         => current_time( 'mysql', true ),
			'product_id' => (int) $product_id,
			'sku'        => (string) $sku,
			'old_price'  => $old,
			'new_price'  => $new,
			'field'      => $field,
			'reason'     => (string) $reason,
			'actor'      => (string) $actor,
		) );
	}

	/** Last change of a product that has not been reverted yet. */
	public static function last_open( $product_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE product_id = %d AND reverted = 0 AND new_price IS NOT NULL ORDER BY id DESC LIMIT 1',
			$product_id
		) );
	}

	public static function mark_reverted( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'reverted' => 1 ), array( 'id' => (int) $id ) );
	}

	public static function recent( $sku = '', $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 200, (int) $limit ) );
		if ( '' !== $sku ) {
			return $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE sku = %s ORDER BY id DESC LIMIT %d', $sku, $limit
			) );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ) );
	}
}
