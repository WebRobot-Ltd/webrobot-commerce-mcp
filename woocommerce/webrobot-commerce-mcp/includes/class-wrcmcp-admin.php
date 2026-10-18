<?php
defined( 'ABSPATH' ) || exit;

/** WooCommerce → Commerce MCP: endpoint, token, limits, audit log. */
class WRCMCP_Admin {

	const SLUG = 'webrobot-commerce-mcp';

	public static function init() {
		add_action( 'admin_menu', function () {
			add_submenu_page( 'woocommerce', 'Commerce MCP', 'Commerce MCP', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page' ) );
		} );
		add_action( 'admin_post_wrcmcp_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_wrcmcp_token', array( __CLASS__, 'token' ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'wrcmcp_save' );
		WRCMCP_Settings::update( array(
			'max_change_pct' => max( 0, min( 100, (float) ( $_POST['max_change_pct'] ?? 15 ) ) ),
			'read_only'      => ! empty( $_POST['read_only'] ),
			'mpn_meta_key'   => sanitize_key( wp_unslash( $_POST['mpn_meta_key'] ?? '_mpn' ) ),
			'ean_meta_key'   => sanitize_key( wp_unslash( $_POST['ean_meta_key'] ?? '_ean' ) ),
			'brand_taxonomy' => sanitize_key( wp_unslash( $_POST['brand_taxonomy'] ?? '' ) ),
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&saved=1' ) );
		exit;
	}

	public static function token() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'wrcmcp_token' );
		// Shown once, to this user only, then gone: only the hash is stored.
		set_transient( 'wrcmcp_new_token_' . get_current_user_id(), WRCMCP_Settings::regenerate_token(), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&token=1' ) );
		exit;
	}

	public static function page() {
		$s        = WRCMCP_Settings::get();
		$endpoint = rest_url( 'webrobot-mcp/v1/mcp' );
		$new      = get_transient( 'wrcmcp_new_token_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'wrcmcp_new_token_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1>WebRobot Commerce MCP</h1>
			<p>Connect this store to a price-intelligence agent: paste the endpoint and the token in the app.</p>

			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p>Settings saved.</p></div>
			<?php endif; ?>
			<?php if ( $new ) : ?>
				<div class="notice notice-warning"><p><strong>Your new token — copy it now, it will not be shown again:</strong></p>
					<p><code style="font-size:14px;user-select:all"><?php echo esc_html( $new ); ?></code></p></div>
			<?php endif; ?>

			<h2>Connection</h2>
			<table class="form-table">
				<tr><th>MCP endpoint</th><td><code style="user-select:all"><?php echo esc_html( $endpoint ); ?></code>
					<?php if ( 0 !== strpos( $endpoint, 'https://' ) ) : ?><p style="color:#b32d2e">The endpoint is not HTTPS: use it only for tests.</p><?php endif; ?></td></tr>
				<tr><th>Token</th><td>
					<?php echo $s['token_hash'] ? 'Set (hidden). Generating a new one revokes the current one.' : '<strong>Not set yet.</strong>'; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
						<?php wp_nonce_field( 'wrcmcp_token' ); ?><input type="hidden" name="action" value="wrcmcp_token">
						<?php submit_button( $s['token_hash'] ? 'Generate a new token' : 'Generate token', 'secondary', 'submit', false ); ?>
					</form></td></tr>
			</table>

			<h2>Limits and fields</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wrcmcp_save' ); ?><input type="hidden" name="action" value="wrcmcp_save">
				<table class="form-table">
					<tr><th><label for="max_change_pct">Maximum change per update (%)</label></th>
						<td><input id="max_change_pct" name="max_change_pct" type="number" min="0" max="100" step="0.5" value="<?php echo esc_attr( $s['max_change_pct'] ); ?>">
						<p class="description">A price change larger than this is refused by the store, whatever the agent asks.</p></td></tr>
					<tr><th>Read-only</th><td><label><input name="read_only" type="checkbox" value="1" <?php checked( $s['read_only'] ); ?>> Allow reading the catalog only (no price changes)</label></td></tr>
					<tr><th><label for="mpn_meta_key">Manufacturer code (MPN) meta key</label></th>
						<td><input id="mpn_meta_key" name="mpn_meta_key" value="<?php echo esc_attr( $s['mpn_meta_key'] ); ?>"></td></tr>
					<tr><th><label for="ean_meta_key">EAN meta key</label></th>
						<td><input id="ean_meta_key" name="ean_meta_key" value="<?php echo esc_attr( $s['ean_meta_key'] ); ?>">
						<p class="description">Used when the product has no GTIN in WooCommerce's own field.</p></td></tr>
					<tr><th><label for="brand_taxonomy">Brand taxonomy</label></th>
						<td><input id="brand_taxonomy" name="brand_taxonomy" value="<?php echo esc_attr( $s['brand_taxonomy'] ); ?>" placeholder="product_brand">
						<p class="description">Empty = try product_brand, pa_brand, pwb-brand.</p></td></tr>
				</table>
				<?php submit_button( 'Save' ); ?>
			</form>

			<h2>Price changes</h2>
			<table class="widefat striped"><thead><tr><th>When (UTC)</th><th>SKU</th><th>Old</th><th>New</th><th>Reason</th><th>By</th></tr></thead><tbody>
			<?php foreach ( WRCMCP_Log::recent( '', 50 ) as $r ) : ?>
				<tr><td><?php echo esc_html( $r->at ); ?></td><td><?php echo esc_html( $r->sku ); ?></td>
					<td><?php echo esc_html( $r->old_price ); ?></td><td><?php echo esc_html( $r->new_price ); ?></td>
					<td><?php echo esc_html( $r->reason ); ?></td><td><?php echo esc_html( $r->actor ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}
}
