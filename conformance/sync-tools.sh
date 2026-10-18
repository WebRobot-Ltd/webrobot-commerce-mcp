#!/bin/sh
# Keep the tool definitions of every plugin in sync with spec/tools.json.
set -e
cd "$(dirname "$0")/.."
cp spec/tools.json woocommerce/webrobot-commerce-mcp/includes/tools.json
cp spec/tools.json prestashop/webrobotcommercemcp/tools.json
cp spec/tools.json opencart/webrobot_commerce_mcp/catalog/model/module/tools.json
cp spec/tools.json magento2/WebRobot/CommerceMcp/etc/tools.json
