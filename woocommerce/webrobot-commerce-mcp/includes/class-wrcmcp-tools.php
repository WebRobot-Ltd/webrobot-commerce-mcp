<?php
defined( 'ABSPATH' ) || exit;

/**
 * The five tools of the Commerce MCP contract (spec/CONTRACT.md). Every method returns an array (the
 * structured result) or throws WRCMCP_Tool_Error (returned to the caller as isError).
 */
class WRCMCP_Tool_Error extends Exception {}

class WRCMCP_Tools {

	public static function definitions() {
		$json = file_get_contents( __DIR__ . '/tools.json' );
		return json_decode( $json, true );
	}

	public static function call( $name, array $args, $actor ) {
		switch ( $name ) {
			case 'list_products':
				return self::list_products( $args );
			case 'get_product':
				return self::product_view( self::product_by_sku( $args['sku'] ?? '' ) );
			case 'update_price':
				return self::update_price( $args, $actor );
			case 'revert_price':
				return self::revert_price( $args, $actor );
			case 'list_price_changes':
				return self::list_price_changes( $args );
		}
		throw new WRCMCP_Tool_Error( "Unknown tool: $name" );
	}

	// ── Read ────────────────────────────────────────────────────────────────

	private static function list_products( array $a ) {
		$limit  = max( 1, min( 100, (int) ( $a['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $a['offset'] ?? 0 ) );
		$query  = trim( (string) ( $a['query'] ?? '' ) );

		$base = array(
			'status' => 'publish',
			// Variations are products of their own; variable parents carry no price of their own.
			'type'   => array( 'simple', 'variation', 'external' ),
			'return' => 'ids',
			'orderby' => 'ID',
			'order'   => 'ASC',
		);

		if ( '' === $query ) {
			$res = wc_get_products( $base + array( 'limit' => $limit, 'offset' => $offset, 'paginate' => true ) );
			$ids = $res->products;
			$total = (int) $res->total;
		} else {
			// Title / content search plus a SKU match, merged.
			$by_text = get_posts( array(
				'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'publish',
				's' => $query, 'fields' => 'ids', 'posts_per_page' => 500, 'orderby' => 'ID', 'order' => 'ASC',
			) );
			$by_sku = wc_get_products( $base + array( 'limit' => 500, 'sku' => $query ) );
			$all = array_values( array_unique( array_merge( $by_text, $by_sku ) ) );
			$all = array_values( array_filter( $all, function ( $id ) {
				$p = wc_get_product( $id );
				return $p && ! $p->is_type( 'variable' ) && ! $p->is_type( 'grouped' );
			} ) );
			$total = count( $all );
			$ids = array_slice( $all, $offset, $limit );
		}

		$items = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				$items[] = self::product_view( $p );
			}
		}
		return array( 'total' => $total, 'items' => $items );
	}

	public static function sku_of( WC_Product $p ) {
		$sku = $p->get_sku();
		return '' !== $sku ? $sku : 'id:' . $p->get_id();
	}

	private static function product_by_sku( $sku ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			throw new WRCMCP_Tool_Error( 'sku is required' );
		}
		$id = 0 === strpos( $sku, 'id:' ) ? (int) substr( $sku, 3 ) : wc_get_product_id_by_sku( $sku );
		$p  = $id ? wc_get_product( $id ) : null;
		if ( ! $p || 'publish' !== get_post_status( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() )
			|| $p->is_type( 'variable' ) || $p->is_type( 'grouped' ) ) {
			throw new WRCMCP_Tool_Error( "Unknown sku $sku" );
		}
		return $p;
	}

	private static function product_view( WC_Product $p ) {
		$s      = WRCMCP_Settings::get();
		$parent = $p->get_parent_id() ? wc_get_product( $p->get_parent_id() ) : null;
		$meta_of = function ( $key ) use ( $p, $parent ) {
			if ( ! $key ) {
				return null;
			}
			$v = $p->get_meta( $key );
			if ( ( '' === $v || null === $v ) && $parent ) {
				$v = $parent->get_meta( $key );
			}
			return '' === $v || null === $v ? null : (string) $v;
		};

		$ean = method_exists( $p, 'get_global_unique_id' ) ? $p->get_global_unique_id() : '';
		if ( '' === $ean ) {
			$ean = $meta_of( $s['ean_meta_key'] );
		}

		$terms_of = function ( $taxonomy ) use ( $p, $parent ) {
			$id = $parent ? $parent->get_id() : $p->get_id();
			$t  = taxonomy_exists( $taxonomy ) ? wp_get_post_terms( $id, $taxonomy, array( 'fields' => 'names' ) ) : array();
			return is_array( $t ) && $t ? $t[0] : null;
		};
		$brand = null;
		foreach ( array_filter( array( $s['brand_taxonomy'], 'product_brand', 'pa_brand', 'pwb-brand' ) ) as $tax ) {
			$brand = $terms_of( $tax );
			if ( $brand ) {
				break;
			}
		}

		$price   = $p->get_price();
		$regular = $p->get_regular_price();
		return array(
			'sku'          => self::sku_of( $p ),
			'title'        => wp_strip_all_tags( $p->get_name() ),
			'brand'        => $brand,
			'mpn'          => $meta_of( $s['mpn_meta_key'] ),
			'ean'          => $ean ? (string) $ean : null,
			'category'     => $terms_of( 'product_cat' ),
			'price'        => '' === $price ? null : (float) $price,
			'regularPrice' => '' === $regular ? null : (float) $regular,
			'currency'     => get_woocommerce_currency(),
			'inStock'      => $p->is_in_stock(),
			'url'          => get_permalink( $p->get_id() ),
		);
	}

	// ── Write ───────────────────────────────────────────────────────────────

	private static function update_price( array $a, $actor ) {
		if ( WRCMCP_Settings::get( 'read_only' ) ) {
			throw new WRCMCP_Tool_Error( 'This store is read-only: price changes are disabled in the plugin settings.' );
		}
		$p     = self::product_by_sku( $a['sku'] ?? '' );
		$price = isset( $a['price'] ) ? (float) $a['price'] : 0.0;
		if ( ! is_finite( $price ) || $price <= 0 ) {
			throw new WRCMCP_Tool_Error( 'price must be a positive number' );
		}
		$price   = round( $price, wc_get_price_decimals() );
		$current = (float) $p->get_price();
		$max     = max( 0, (float) WRCMCP_Settings::get( 'max_change_pct' ) );
		if ( $current > 0 && abs( $price / $current - 1 ) * 100 > $max + 1e-9 ) {
			throw new WRCMCP_Tool_Error( sprintf(
				'Rejected: %s is more than %s%% away from the current price %s (limit set in the store).',
				$price, $max, $current
			) );
		}

		// The price customers pay: the sale price while a sale is running, otherwise the regular one.
		$field = $p->is_on_sale() ? 'sale' : 'regular';
		$old   = 'sale' === $field ? (float) $p->get_sale_price() : (float) $p->get_regular_price();
		if ( 'sale' === $field ) {
			if ( $p->get_regular_price() !== '' && $price >= (float) $p->get_regular_price() ) {
				throw new WRCMCP_Tool_Error( 'Rejected: during a sale the new price must stay below the regular price.' );
			}
			$p->set_sale_price( $price );
		} else {
			$p->set_regular_price( $price );
		}
		$p->save();
		WRCMCP_Log::add( $p->get_id(), self::sku_of( $p ), $old, $price, $field, $a['reason'] ?? '', $actor );
		return array( 'sku' => self::sku_of( $p ), 'oldPrice' => $current, 'newPrice' => (float) wc_get_product( $p->get_id() )->get_price() );
	}

	private static function revert_price( array $a, $actor ) {
		if ( WRCMCP_Settings::get( 'read_only' ) ) {
			throw new WRCMCP_Tool_Error( 'This store is read-only: price changes are disabled in the plugin settings.' );
		}
		$p    = self::product_by_sku( $a['sku'] ?? '' );
		$last = WRCMCP_Log::last_open( $p->get_id() );
		if ( ! $last ) {
			throw new WRCMCP_Tool_Error( 'No price change to revert for this sku.' );
		}
		if ( 'sale' === $last->field ) {
			$p->set_sale_price( null === $last->old_price ? '' : (float) $last->old_price );
		} else {
			$p->set_regular_price( (float) $last->old_price );
		}
		$p->save();
		WRCMCP_Log::mark_reverted( $last->id );
		$now = (float) wc_get_product( $p->get_id() )->get_price();
		WRCMCP_Log::add( $p->get_id(), self::sku_of( $p ), $last->new_price, $now, $last->field, 'revert: ' . ( $a['reason'] ?? '' ), $actor );
		return array( 'sku' => self::sku_of( $p ), 'price' => $now );
	}

	private static function list_price_changes( array $a ) {
		$rows = WRCMCP_Log::recent( trim( (string) ( $a['sku'] ?? '' ) ), (int) ( $a['limit'] ?? 50 ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'at'       => mysql2date( 'c', $r->at, false ),
				'sku'      => $r->sku,
				'oldPrice' => null === $r->old_price ? null : (float) $r->old_price,
				'newPrice' => null === $r->new_price ? null : (float) $r->new_price,
				'reason'   => $r->reason,
				'actor'    => $r->actor,
			);
		}
		return array( 'changes' => $out );
	}
}
