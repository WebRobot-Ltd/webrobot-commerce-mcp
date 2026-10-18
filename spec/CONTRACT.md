# Commerce MCP contract (v1)

Every plugin in this repository exposes the same [Model Context Protocol](https://modelcontextprotocol.io)
server, so a price-intelligence or dynamic-pricing agent can work with any store the same way.

## Transport

- **Streamable HTTP**, one endpoint, `POST` only, JSON-RPC 2.0, JSON responses (no SSE stream).
  `GET` answers `405`.
- **Auth**: `Authorization: Bearer <token>`. The token is generated in the plugin's admin page and can be
  regenerated (which revokes the old one). Wrong or missing token → HTTP `401`.
- HTTPS only in production.
- Methods: `initialize`, `notifications/initialized` (→ HTTP `202`, empty body), `ping`, `tools/list`,
  `tools/call`. Protocol version `2025-06-18`.
- Tool results carry both `content: [{type: "text", text: <JSON>}]` and `structuredContent: <object>`.
  A failed call returns `isError: true` with a human-readable message in `content[0].text`.

## Product shape

```json
{
  "sku": "MZ-V9P1T0BW",
  "title": "Samsung 990 PRO 1 TB",
  "brand": "Samsung",
  "mpn": "MZ-V9P1T0BW",
  "ean": "0887276638331",
  "category": "SSD",
  "price": 111.0,
  "regularPrice": 119.0,
  "currency": "EUR",
  "inStock": true,
  "url": "https://store.example/product/samsung-990-pro-1tb"
}
```

`sku` is the store's own identifier and the key of every other tool. `brand`, `mpn`, `ean`, `category`,
`regularPrice` and `url` may be `null` when the store does not have them. `price` is the price the
customer pays now (sale price when on sale). Product variations are listed as products of their own.

## Tools

| Tool | Arguments | Result |
|---|---|---|
| `list_products` | `query?` (text on title/sku/brand), `limit?` 1–100 (default 50), `offset?` | `{total, items: [Product]}` |
| `get_product` | `sku` | `Product` |
| `update_price` | `sku`, `price` (> 0, store currency), `reason?` | `{sku, oldPrice, newPrice}` |
| `revert_price` | `sku`, `reason?` | `{sku, price}` — back to the price before the last `update_price` |
| `list_price_changes` | `sku?`, `limit?` 1–200 (default 50) | `{changes: [{at, sku, oldPrice, newPrice, reason, actor}]}` newest first |

### Guards the store enforces itself

These hold whatever the caller (and whatever AI agent behind it) asks:

- `update_price` is **rejected** when the new price differs from the current one by more than the
  maximum change set in the plugin's admin page (default **15%**).
- `update_price` can be **disabled** entirely in the admin page (read-only mode).
- Every change is written to the store's own audit log, visible in the admin page, with the `reason`
  the caller gave.

## Conformance

`conformance/check.py <endpoint> <token> [--write <sku>]` exercises the contract against a running
store. Without `--write` it is read-only.
