#!/bin/sh
# Keep the tool definitions of every plugin in sync with spec/tools.json.
set -e
cd "$(dirname "$0")/.."
cp spec/tools.json woocommerce/webrobot-commerce-mcp/includes/tools.json
