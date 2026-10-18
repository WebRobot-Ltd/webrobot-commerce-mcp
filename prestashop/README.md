# PrestaShop module

`webrobotcommercemcp` exposes a PrestaShop 8.x store as an MCP server implementing the
[Commerce MCP contract](../spec/CONTRACT.md): `list_products`, `get_product`, `update_price`,
`revert_price`, `list_price_changes`.

Requires PrestaShop 8.0+ (tested on 8.2, PHP 8.1).

## Install

1. Zip the module folder so that the archive contains `webrobotcommercemcp/` at its root:
   ```bash
   cd prestashop && zip -r webrobotcommercemcp.zip webrobotcommercemcp
   ```
2. Back office → **Modules → Module Manager → Upload a module**, drop the zip.
   From a shell you can instead copy the folder into `modules/` and run, as the web server user:
   ```bash
   sudo -u www-data php bin/console prestashop:module install webrobotcommercemcp
   ```
3. Click **Configure** on the module.

Installing creates the table `PREFIX_wrcmcp_price_log` (the audit log) and three configuration values
(`WRCMCP_TOKEN_HASH`, `WRCMCP_MAX_CHANGE_PCT`, `WRCMCP_READ_ONLY`). Uninstalling removes all of them.

## Configure

The configuration page shows:

- **MCP endpoint** — the URL to paste in the price-intelligence app.
- **Token** — *Generate token* shows it once; copy it then. Only its SHA-256 hash is stored, so it cannot
  be shown again: generating a new one revokes the previous one.
- **Maximum change per update (%)** — default 15. A larger change is refused by the store.
- **Read-only** — catalog reading only, `update_price` and `revert_price` are refused.
- **Price changes** — the last 50 entries of the audit log.

## Endpoint

| Friendly URLs | URL |
|---|---|
| on | `https://your-store.example/<lang>/module/webrobotcommercemcp/mcp` (e.g. `/en/…`; without the language part on single-language shops) |
| off | `https://your-store.example/index.php?fc=module&module=webrobotcommercemcp&controller=mcp` |

Both work in either case. `POST` only (JSON-RPC 2.0, JSON responses); `GET` answers `405`. Send the
token as `Authorization: Bearer <token>`, or as `X-WebRobot-Token: <token>` if something in front of
PrestaShop strips the `Authorization` header. An optional `X-Actor` header names the caller in the log.

The endpoint does not use the customer session: no cookie is written, no CSRF token is needed, nothing
of the theme is rendered. It keeps answering while the shop is in maintenance mode (like the PrestaShop
webservice); use read-only mode if you want to freeze prices.

Check an installation with the conformance script:

```bash
python3 conformance/check.py "<endpoint>" <token>
python3 conformance/check.py "<endpoint>" <token> --write <sku>   # also updates and reverts one price
```

## How the catalog maps to the contract

| Field | Source |
|---|---|
| items | products without combinations, and every combination of products with combinations (the parent is not listed); active products of the current shop |
| `sku` | the product or combination **reference**; `id:<id_product>` or `id:<id_product>-<id_product_attribute>` when the reference is empty or shared by several items (the demo data, and many shops, copy the parent reference to every combination) |
| `title` | product name in the default language; for combinations `Name : Size - M, Color - Red` |
| `brand` | manufacturer name |
| `mpn` | the MPN field of the product or combination |
| `ean` | the EAN-13 field of the product or combination |
| `category` | default category name |
| `price` | what an anonymous visitor pays now for one unit: `Product::getPriceStatic` with taxes and reductions, default currency, default country |
| `regularPrice` | the same without reductions |
| `inStock` | available quantity > 0 (always true when stock management is off) |
| `url` | product page (with the combination anchor) |

A combination without its own EAN or MPN gets `null`, not the parent's code, so a matcher never pairs a
variant with another variant's identifier.

## How prices are changed

- `update_price` takes the **tax-included** price customers should pay. The difference with the current
  price is converted to tax-excluded with the product's tax rules for the shop's default country, and
  added to the **base price** (products without combinations) or to the **combination's price impact**
  (combinations — the other combinations of the product do not move). If another proportional factor
  applies (e.g. a group reduction), one correction step makes the result exact.
- When a **specific price or a catalog price rule** applies to the product, `update_price` is refused
  (“an active discount applies; change it in PrestaShop”): changing the base price underneath a discount
  would not give the price the agent asked for, so the discount stays the merchant's call.
- Every change is logged with the old and new customer price **and** the stored value before and after,
  so `revert_price` restores exactly the base price or impact there was before the last change not yet
  reverted. Calling it again walks further back.
- Changes are written through PrestaShop's own `Product` / `Combination` objects, so the usual hooks run.

## Troubleshooting

- **401 with the right token** — your web server drops the `Authorization` header. Use the
  `X-WebRobot-Token` header, or make sure the `.htaccess` generated by PrestaShop (it forwards
  `HTTP_AUTHORIZATION`) is in place.
- **404 / HTML page instead of JSON** — the module is not installed or not enabled for this shop; or a
  friendly URL is used while URL rewriting is not working: try the `index.php?fc=module…` URL.
- **500 right after installing from the command line** — the console was run as root and left cache
  files the web server cannot write: `chown -R www-data:www-data var/` (or run the console as the web
  server user).
- **301 redirect** — HTTPS is enabled in the shop and the endpoint was called over `http://`: use the
  `https://` URL.
- **Prices look wrong by the tax amount** — prices are always tax included, computed for the shop's
  default country. Check *International → Taxes* and the product's tax rule.
- **Multistore** — the endpoint works on the shop its URL belongs to; prices and the audit log are per
  shop, settings are global.
