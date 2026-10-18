# WebRobot Commerce MCP for OpenCart

An OpenCart 4 extension that exposes the store catalog as an MCP server implementing the
[Commerce MCP contract](../spec/CONTRACT.md): a price-intelligence agent can read products and, within
the limits you set, update prices.

Tested on OpenCart **4.1.0.4** and **4.0.2.3** (PHP 8.2, MariaDB 11.4). OpenCart 3.x is not supported.

## Install

1. Build the package from this folder: the zip must be named `webrobot_commerce_mcp.ocmod.zip` and contain
   `admin/`, `catalog/` and `install.json` at its root.

   ```bash
   cd opencart/webrobot_commerce_mcp && zip -r ../webrobot_commerce_mcp.ocmod.zip .
   ```

2. In the admin: **Extensions → Installer → Upload**, pick the zip, then click the green **Install** button
   next to "WebRobot Commerce MCP" in the list. The files land in `extension/webrobot_commerce_mcp/`.
3. **Extensions → Extensions → choose "Modules"**, find **WebRobot Commerce MCP** and click **Install**
   (the green +). This creates the audit table `{DB_PREFIX}wrcmcp_price_log` and the default settings.
4. Click **Edit** (the pencil). Turn **Status** on, set the limits, **Save**.
5. Click **Generate token**. The token is shown once in the field next to the button — copy it now.
   The store keeps only its SHA-256 hash; generating a new one revokes the old one.

Uninstalling the module (Extensions → Modules → Uninstall) drops the audit table and removes the
settings, token included.

Without the web installer (for example in a scripted setup), copy the folder to
`<opencart>/extension/webrobot_commerce_mcp/`, then do steps 3–5: the module only appears in the list
once its files are in `extension/`.

## Endpoint

```
https://your-store.example/index.php?route=extension/webrobot_commerce_mcp/module/mcp
```

The admin page shows the exact URL. `POST` only (JSON-RPC 2.0, Streamable HTTP, JSON responses);
`GET` answers `405`. Send the token as `Authorization: Bearer <token>`, or as `X-WebRobot-Token: <token>`
when the server strips the `Authorization` header. An optional `X-Actor` header is recorded in the audit
log (default `mcp`).

```bash
python3 conformance/check.py "https://your-store.example/index.php?route=extension/webrobot_commerce_mcp/module/mcp" <token>
python3 conformance/check.py "<endpoint>" <token> --write <sku>   # also updates and reverts one price
```

## How the contract maps to OpenCart

| Field | Source |
|---|---|
| `sku` | The product's SKU; if empty, its model; if that is empty too, `id:<product_id>`. Every tool accepts any of the three forms. |
| `title` | Product name in the store's default language |
| `brand` | Manufacturer |
| `mpn`, `ean` | The MPN and EAN identifiers (4.0: `mpn`/`ean` columns; 4.1: product codes `MPN`/`EAN`) |
| `category` | First category of the product (for a variant without categories, its master's) |
| `price` | What the customer pays now: the active special price for the default customer group, otherwise the base price |
| `regularPrice` | The base price |
| `currency` | The store's default currency |
| `inStock` | Quantity above 0, or stock not tracked for the product (*Subtract Stock* = No) |
| `url` | Storefront product link (SEO URL when SEO URLs are enabled) |

Only enabled products, available from today and assigned to the store are listed.

**Prices are without tax.** OpenCart stores prices without tax and adds the tax class at display time,
so `price`, `regularPrice` and `update_price` all work on the stored amount, before tax. An agent that
compares with prices including VAT must add the tax itself.

**Specials.** When a special price applies (default customer group, quantity 1, within its dates — the
same rule the storefront uses), `update_price` changes that special and the new price must stay below the
base price; otherwise it changes the base price. On OpenCart 4.1 a percentage or amount-off special
becomes a fixed-price special when changed; `revert_price` restores its original type and value.
Quantity discounts are not read or changed.

**Variants.** OpenCart 4 variant products (`master_id`) are products of their own, with their own SKU and
price. Changing the master's price does not change its variants. If a variant does not override the
price, saving the master later in the admin copies the master's price over the variant's.

**Guards.** `update_price` is refused when the new price is more than the maximum change (default 15%)
away from the current price, and when read-only mode is on; `revert_price` is refused in read-only mode
too. Each change and each revert is written to the audit table with the caller's reason; the admin page
shows the last 50. `revert_price` restores the value before the most recent change not yet reverted, so
repeated reverts walk back through the history.

## Troubleshooting

- **401 with the right token** — the web server drops the `Authorization` header (common with
  PHP-FPM/CGI). Use `X-WebRobot-Token: <token>`, or pass the header through, on Apache for example
  with `CGIPassAuth On` or
  `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` in `.htaccess`.
- **503 "disabled"** — turn Status on in the module's settings.
- **404 / HTML page instead of JSON** — the module is not installed under Extensions → Modules (the
  catalog side only loads installed extensions), or the store is in maintenance mode.
- **"Unknown sku"** — the product is disabled, not yet available, not assigned to the store, or its SKU
  differs; try `list_products` with a `query`, or the `id:<product_id>` form.
- **Endpoint not HTTPS** — the admin page warns about it: use plain HTTP only for tests.
