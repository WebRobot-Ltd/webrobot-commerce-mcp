# WebRobot Commerce MCP for Magento 2

A Magento Open Source / Adobe Commerce 2.4 module (`WebRobot_CommerceMcp`) that exposes the catalog as an
**MCP server** ([Model Context Protocol](https://modelcontextprotocol.io)) implementing the
[Commerce MCP contract](../spec/CONTRACT.md): a price-intelligence agent can read products and — within
the limits you set — update prices.

Tested on Magento Open Source 2.4.8, PHP 8.3, MariaDB 10.6, OpenSearch 2.11.

## Install

### Manual (app/code)

```bash
mkdir -p app/code/WebRobot
cp -r /path/to/webrobot-commerce-mcp/magento2/WebRobot/CommerceMcp app/code/WebRobot/
bin/magento module:enable WebRobot_CommerceMcp
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode only
bin/magento cache:flush
```

### Composer (path repository)

The module lives in a sub-folder of this repository, so point Composer at it with a `path` repository:

```bash
composer config repositories.webrobot-commerce-mcp path /path/to/webrobot-commerce-mcp/magento2/WebRobot/CommerceMcp
composer require webrobot/module-commerce-mcp:0.1.0
bin/magento module:enable WebRobot_CommerceMcp
bin/magento setup:upgrade
```

`setup:upgrade` creates the audit table `wrcmcp_price_log` (declarative schema, `etc/db_schema.xml`).

## Configure

**Stores → Configuration → WebRobot → Commerce MCP** (default scope):

| Setting | Default | |
|---|---|---|
| MCP endpoint | — | Shown for the default store view; paste it in the agent app. |
| Token | — | **Generate token** shows a new token **once**; only its SHA-256 hash is stored (`core_config_data`). Generating a new one revokes the old one. |
| Maximum change per update (%) | 15 | A larger change is refused by the store, whatever the agent asks. |
| Read-only | No | Catalog reading only: `update_price` and `revert_price` are refused. |
| Brand / MPN / EAN attribute code | `manufacturer` / `mpn` / `ean` | Product attributes used for `brand`, `mpn`, `ean`. Select attributes return their option label. |
| Price changes (last 50) | — | The audit log, with the reason the agent gave. |

From the command line (prints the token on stdout, once):

```bash
bin/magento webrobot:mcp:token
```

## Endpoint

```
POST https://your-store.example/webrobotmcp/mcp
Authorization: Bearer <token>
```

Streamable HTTP, JSON-RPC 2.0, JSON responses, protocol `2025-06-18`. `GET` answers `405`;
`notifications/initialized` answers `202` with an empty body; a missing or wrong token answers `401`.
If your web server strips the `Authorization` header, send the token as `X-WebRobot-Token: <token>`.
An optional `X-Actor` header is stored as the author of a change (default `mcp`).

Check an installation with the conformance script of this repository:

```bash
python3 conformance/check.py https://your-store.example/webrobotmcp/mcp <token>
python3 conformance/check.py https://your-store.example/webrobotmcp/mcp <token> --write <sku>   # also updates and reverts one price
```

## How Magento data maps to the contract

- **Products**: enabled simple, virtual and downloadable products assigned to the store's website. The
  children of a configurable product are products of their own; configurable (and bundle/grouped)
  parents are not listed and `get_product` on them answers "Unknown sku".
- Products are read in the **default store view** (names, URLs, option labels in that view).
- `price` is the price customers pay now for one unit, in the **base currency**, without tax: special
  price, tier price and catalog price rules applied. `regularPrice` is the `price` attribute.
- `brand` and `category` of a configurable child fall back to its parent when the child has none. `mpn`
  and `ean` never do: a child without its own code gets `null`, so a matcher never pairs one variant with
  another's code. `category` is the first (lowest id) category below the root categories.
- `inStock` is the product's stock status. `url` is the product URL; for a child that is not visible
  individually, the URL of its configurable parent.
- `query` in `list_products` matches name and SKU (substring) and the brand (option label for a select
  attribute).

## How prices are written

- `update_price` writes in the **default scope** (`store_id` 0). While a special price is active (set,
  within its dates and below the price) it changes `special_price`, which must stay below `price`;
  otherwise it changes `price`.
- The guard compares the new price with the price customers pay now: more than the maximum change
  → rejected. Read-only mode rejects every write.
- After writing, the module re-reads the price customers pay. If it is not the requested price — because
  a catalog price rule, a tier price or a website-scope price decides it — the write is undone and the
  call fails with an explanation, rather than leaving a change that has no effect.
- The price index of the product (and its configurable parents) is updated, and the block and full page
  caches of those products are cleaned.
- Every change goes to `wrcmcp_price_log` with the attribute, scope and raw value before/after.
  `revert_price` restores the value before the last change not yet reverted, so successive reverts walk
  back through the history. Reverts are logged too (reason `revert: …`).

## Troubleshooting

| Symptom | Fix |
|---|---|
| `404` on `/webrobotmcp/mcp` | Module not enabled or caches not flushed: `bin/magento module:status WebRobot_CommerceMcp`, `bin/magento cache:flush`. Store codes in URLs: use `/<store_code>/webrobotmcp/mcp`. |
| Always `401` with the right token | The `Authorization` header is stripped (Apache + PHP-FPM/CGI). Send `X-WebRobot-Token`, or pass the header through, e.g. `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`. |
| `401` after generating a token in the CLI | Config cache in another cache backend not shared: `bin/magento cache:clean config`. |
| `brand`/`mpn`/`ean` always `null` | Check the attribute codes in the settings and that the attributes are in the products' attribute set. |
| "Not changed: … customers would pay …" | A catalog price rule, tier price or website-scope price applies to the product; change it in Magento instead. |
| Price changed but the storefront shows the old one | An external full-page cache (Varnish/CDN) not purged by Magento; with Magento's Varnish integration the purge is automatic. |
| Class errors after install in production mode | `bin/magento setup:di:compile && bin/magento cache:flush`. |

Use the endpoint over **HTTPS** only in production.

## Files

```
WebRobot/CommerceMcp/
  registration.php, composer.json
  etc/module.xml, di.xml, config.xml, acl.xml, db_schema.xml, db_schema_whitelist.json
  etc/frontend/routes.xml         route webrobotmcp
  etc/adminhtml/routes.xml, system.xml
  etc/tools.json                  copy of spec/tools.json (conformance/sync-tools.sh)
  Controller/Mcp/Index.php        the endpoint (auth, 405/401/202)
  Controller/Adminhtml/Token/Generate.php
  Console/Command/TokenCommand.php
  Model/Server.php                JSON-RPC dispatch
  Model/Tools.php                 the five tools
  Model/Config.php, PriceLog.php, ToolError.php
  Block/Adminhtml/System/Config/  endpoint, token button, price log table
```

## License

MIT — see [LICENSE](../LICENSE).
