<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings. The token itself is never stored: only its SHA-256 hash, so a database dump does not
 * hand out write access to the store's prices.
 */
class WRCMCP_Settings {

	const DEFAULTS = array(
		'token_hash'     => '',
		'max_change_pct' => 15,
		'read_only'      => false,
		'mpn_meta_key'   => '_mpn',
		'ean_meta_key'   => '_ean',
		'brand_taxonomy' => '',
	);

	public static function get( $key = null ) {
		$s = wp_parse_args( get_option( WRCMCP_OPTION, array() ), self::DEFAULTS );
		return null === $key ? $s : ( $s[ $key ] ?? null );
	}

	public static function update( array $values ) {
		$s = self::get();
		foreach ( $values as $k => $v ) {
			if ( array_key_exists( $k, self::DEFAULTS ) ) {
				$s[ $k ] = $v;
			}
		}
		update_option( WRCMCP_OPTION, $s, false );
	}

	/** Generate a new token (revokes the previous one) and return it in clear — the only time it is visible. */
	public static function regenerate_token() {
		$token = 'wrc_' . bin2hex( random_bytes( 24 ) );
		self::update( array( 'token_hash' => hash( 'sha256', $token ) ) );
		return $token;
	}

	public static function token_matches( $presented ) {
		$hash = self::get( 'token_hash' );
		if ( ! $hash || ! is_string( $presented ) || '' === $presented ) {
			return false;
		}
		return hash_equals( $hash, hash( 'sha256', $presented ) );
	}
}
