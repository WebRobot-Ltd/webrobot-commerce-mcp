=== WebRobot Commerce MCP ===
Contributors: webrobot
Tags: woocommerce, mcp, pricing, price monitoring, ai
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Expose your WooCommerce catalog as an MCP server, so a price-intelligence agent can read products and update prices within the limits you set.

== Description ==

The plugin adds one endpoint, `/wp-json/webrobot-mcp/v1/mcp`, speaking the Model Context Protocol
(Streamable HTTP, JSON-RPC). Five tools: list_products, get_product, update_price, revert_price,
list_price_changes.

You stay in control:

* the token is generated in WooCommerce → Commerce MCP and can be revoked by generating a new one; only its hash is stored;
* a maximum change per update (default 15%) is enforced by the store itself;
* read-only mode disables price changes;
* every change is logged with the reason the agent gave.

Prices are changed on the field customers pay: the sale price while a sale runs, otherwise the regular
price. Product variations are exposed as products of their own; EAN comes from WooCommerce's GTIN field
(or a meta key you choose), the manufacturer code from a meta key, the brand from a brand taxonomy.

== Installation ==

1. Upload the `webrobot-commerce-mcp` folder to `/wp-content/plugins/` and activate it.
2. Open WooCommerce → Commerce MCP, generate a token and copy it.
3. Paste the endpoint and the token in your price-intelligence app.

If your host strips the `Authorization` header, the plugin also accepts the token in an `X-WebRobot-Token` header.

== Changelog ==

= 0.1.0 =
* First release: the five tools of the Commerce MCP contract v1, token, change limit, read-only mode, audit log.
