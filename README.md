# WebRobot Commerce MCP

Open-source plugins that expose an e-commerce store as an **MCP server**
([Model Context Protocol](https://modelcontextprotocol.io)), so a price-intelligence agent can read the
catalog and — within limits the store owner sets — update prices.

Every plugin implements the same contract: [`spec/CONTRACT.md`](spec/CONTRACT.md).

| Platform | Folder | Status |
|---|---|---|
| WooCommerce | [`woocommerce/`](woocommerce/) | v0.1 |
| PrestaShop | [`prestashop/`](prestashop/) | planned |
| Magento 2 | [`magento2/`](magento2/) | planned |
| OpenCart | [`opencart/`](opencart/) | planned |

Shopify already ships an official MCP server.

## What the agent can do

| Tool | What it does |
|---|---|
| `list_products` | Catalog with price, stock, EAN, manufacturer code, brand |
| `get_product` | One product by SKU |
| `update_price` | Change the price customers pay |
| `revert_price` | Undo the last change of a product |
| `list_price_changes` | Audit log of the changes |

## What the store owner keeps under control

- **The token** is generated in the plugin's admin page and can be revoked at any time by generating a
  new one. The plugin stores only its hash.
- **A maximum change per update** (default 15%): a larger change is refused by the store itself, whatever
  the agent asks.
- **Read-only mode**: catalog reading only, no price changes.
- **An audit log** of every change, with the reason the agent gave.

## Check an installation

```bash
python3 conformance/check.py https://your-store.example/wp-json/webrobot-mcp/v1/mcp <token>
python3 conformance/check.py <endpoint> <token> --write <sku>   # also updates and reverts one price
```

## License

MIT — see [LICENSE](LICENSE).
