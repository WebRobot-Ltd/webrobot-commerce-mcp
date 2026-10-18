<?php
/**
 * Plugin Name:       WebRobot Commerce MCP
 * Plugin URI:        https://github.com/WebRobot-Ltd/webrobot-commerce-mcp
 * Description:       Exposes your WooCommerce catalog as an MCP server (Model Context Protocol) so a price-intelligence agent can read products and, within the limits you set, update prices.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WebRobot
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       webrobot-commerce-mcp
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WRCMCP_VERSION', '0.1.0' );
define( 'WRCMCP_FILE', __FILE__ );
define( 'WRCMCP_OPTION', 'wrcmcp_settings' );

require_once __DIR__ . '/includes/class-wrcmcp-settings.php';
require_once __DIR__ . '/includes/class-wrcmcp-log.php';
require_once __DIR__ . '/includes/class-wrcmcp-tools.php';
require_once __DIR__ . '/includes/class-wrcmcp-server.php';
require_once __DIR__ . '/includes/class-wrcmcp-admin.php';

register_activation_hook( __FILE__, array( 'WRCMCP_Log', 'install' ) );

// HPOS: the plugin touches products only, never orders.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WebRobot Commerce MCP needs WooCommerce.', 'webrobot-commerce-mcp' ) . '</p></div>';
		} );
		return;
	}
	WRCMCP_Server::init();
	WRCMCP_Admin::init();
} );
