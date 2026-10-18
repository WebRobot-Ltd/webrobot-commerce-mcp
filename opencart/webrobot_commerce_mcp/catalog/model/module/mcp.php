<?php
namespace Opencart\Catalog\Model\Extension\WebrobotCommerceMcp\Module;
/**
 * The five tools of the Commerce MCP contract (spec/CONTRACT.md).
 *
 * Every tool returns an array (the structured result) or throws ToolError (returned to the caller as
 * isError). Works with both OpenCart 4.0.2.x (sku/ean/mpn columns on `product`, specials in
 * `product_special`) and 4.1.x (identifiers in `product_code`, specials in `product_discount` with
 * `special` = 1); the schema is detected at runtime.
 *
 * Prices are the amounts OpenCart stores, i.e. without tax.
 */
class ToolError extends \Exception {}

class Mcp extends \Opencart\System\Engine\Model {
	public const SETTING = 'module_webrobot_commerce_mcp';

	private ?array $schema = null;

	public function definitions(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/tools.json'), true) ?: [];
	}

	public function setting(string $key, $default = null) {
		$value = $this->config->get(self::SETTING . '_' . $key);

		return $value === null ? $default : $value;
	}

	public function maxChangePct(): float {
		$v = $this->setting('max_change_pct', 15);

		return max(0.0, (float)($v === '' ? 15 : $v));
	}

	public function call(string $name, array $args, string $actor): array {
		switch ($name) {
			case 'list_products':
				return $this->listProducts($args);
			case 'get_product':
				return $this->view($this->productBySku((string)($args['sku'] ?? '')));
			case 'update_price':
				return $this->updatePrice($args, $actor);
			case 'revert_price':
				return $this->revertPrice($args, $actor);
			case 'list_price_changes':
				return $this->listPriceChanges($args);
		}

		throw new ToolError('Unknown tool: ' . $name);
	}

	// ── Schema ──────────────────────────────────────────────────────────────

	private function schema(): array {
		if ($this->schema === null) {
			$legacy = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product` LIKE 'sku'")->num_rows > 0;

			if ($this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product_discount` LIKE 'special'")->num_rows) {
				$special = 'discount'; // 4.1+
			} elseif ($this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'product_special') . "'")->num_rows) {
				$special = 'table';    // 4.0.x
			} else {
				$special = '';
			}

			$this->schema = ['legacy_codes' => $legacy, 'special' => $special];
		}

		return $this->schema;
	}

	/** SQL expression of an identifier (SKU, EAN, MPN) of product `p`. */
	private function codeExpr(string $code): string {
		if ($this->schema()['legacy_codes']) {
			return "`p`.`" . strtolower($code) . "`";
		}

		return "(SELECT `pc`.`value` FROM `" . DB_PREFIX . "product_code` `pc` WHERE `pc`.`product_id` = `p`.`product_id` AND `pc`.`code` = '" . $this->db->escape($code) . "' AND `pc`.`value` <> '' ORDER BY `pc`.`product_code_id` ASC LIMIT 1)";
	}

	/** Products a customer can see in this store. */
	private function visibleSql(): string {
		return "`p`.`status` = '1' AND `p`.`date_available` <= NOW() AND EXISTS (SELECT 1 FROM `" . DB_PREFIX . "product_to_store` `p2s` WHERE `p2s`.`product_id` = `p`.`product_id` AND `p2s`.`store_id` = '" . (int)$this->config->get('config_store_id') . "')";
	}

	private function fromSql(): string {
		return " FROM `" . DB_PREFIX . "product` `p`"
			. " LEFT JOIN `" . DB_PREFIX . "product_description` `pd` ON (`pd`.`product_id` = `p`.`product_id` AND `pd`.`language_id` = '" . (int)$this->config->get('config_language_id') . "')"
			. " LEFT JOIN `" . DB_PREFIX . "manufacturer` `m` ON (`m`.`manufacturer_id` = `p`.`manufacturer_id`)";
	}

	// ── Read ────────────────────────────────────────────────────────────────

	private function listProducts(array $a): array {
		$limit  = max(1, min(100, (int)($a['limit'] ?? 50)));
		$offset = max(0, (int)($a['offset'] ?? 0));
		$query  = trim((string)($a['query'] ?? ''));

		$where = " WHERE " . $this->visibleSql();

		if ($query !== '') {
			$like = "'%" . $this->db->escape(addcslashes($query, '%_\\')) . "%'";

			$where .= " AND (`pd`.`name` LIKE " . $like . " OR `p`.`model` LIKE " . $like . " OR " . $this->codeExpr('SKU') . " LIKE " . $like . " OR `m`.`name` LIKE " . $like . ")";
		}

		$total = (int)$this->db->query("SELECT COUNT(*) AS `total`" . $this->fromSql() . $where)->row['total'];

		$rows = $this->db->query("SELECT `p`.`product_id`" . $this->fromSql() . $where . " ORDER BY `p`.`product_id` ASC LIMIT " . $offset . ", " . $limit)->rows;

		$items = [];

		foreach ($rows as $row) {
			$product = $this->product((int)$row['product_id']);

			if ($product) {
				$items[] = $this->view($product);
			}
		}

		return ['total' => $total, 'items' => $items];
	}

	/** Raw product row (with name, brand and identifiers), or [] when not visible. */
	private function product(int $product_id): array {
		$query = $this->db->query("SELECT `p`.*, `pd`.`name` AS `x_name`, `m`.`name` AS `x_brand`, " . $this->codeExpr('SKU') . " AS `x_sku`, " . $this->codeExpr('EAN') . " AS `x_ean`, " . $this->codeExpr('MPN') . " AS `x_mpn`" . $this->fromSql() . " WHERE `p`.`product_id` = '" . $product_id . "' AND " . $this->visibleSql());

		return $query->row;
	}

	public function skuOf(array $p): string {
		if ((string)$p['x_sku'] !== '') {
			return (string)$p['x_sku'];
		}

		if ((string)$p['model'] !== '') {
			return (string)$p['model'];
		}

		return 'id:' . (int)$p['product_id'];
	}

	private function productBySku(string $sku): array {
		$sku = trim($sku);

		if ($sku === '') {
			throw new ToolError('sku is required');
		}

		$id = 0;

		if (strpos($sku, 'id:') === 0) {
			$id = (int)substr($sku, 3);
		} else {
			$esc = "'" . $this->db->escape($sku) . "'";

			$row = $this->db->query("SELECT `p`.`product_id`" . $this->fromSql() . " WHERE " . $this->visibleSql() . " AND " . $this->codeExpr('SKU') . " = " . $esc . " ORDER BY `p`.`product_id` ASC LIMIT 1")->row;

			if (!$row) {
				// Fallback identifier: the model, for products without a SKU.
				$row = $this->db->query("SELECT `p`.`product_id`" . $this->fromSql() . " WHERE " . $this->visibleSql() . " AND COALESCE(" . $this->codeExpr('SKU') . ", '') = '' AND `p`.`model` = " . $esc . " ORDER BY `p`.`product_id` ASC LIMIT 1")->row;
			}

			if ($row) {
				$id = (int)$row['product_id'];
			}
		}

		$product = $id ? $this->product($id) : [];

		if (!$product) {
			throw new ToolError('Unknown sku ' . $sku);
		}

		return $product;
	}

	/** The special price that applies now to the default customer group, as OpenCart's storefront computes it. */
	private function activeSpecial(array $p): ?array {
		$mode = $this->schema()['special'];
		$cg   = (int)$this->config->get('config_customer_group_id');
		$date = "(`date_start` = '0000-00-00' OR `date_start` < NOW()) AND (`date_end` = '0000-00-00' OR `date_end` > NOW())";

		if ($mode === 'discount') {
			$row = $this->db->query("SELECT `product_discount_id` AS `id`, `price`, `type` FROM `" . DB_PREFIX . "product_discount` WHERE `product_id` = '" . (int)$p['product_id'] . "' AND `customer_group_id` = '" . $cg . "' AND `quantity` = '1' AND `special` = '1' AND " . $date . " ORDER BY `priority` ASC, `price` ASC LIMIT 1")->row;
		} elseif ($mode === 'table') {
			$row = $this->db->query("SELECT `product_special_id` AS `id`, `price`, 'F' AS `type` FROM `" . DB_PREFIX . "product_special` WHERE `product_id` = '" . (int)$p['product_id'] . "' AND `customer_group_id` = '" . $cg . "' AND " . $date . " ORDER BY `priority` ASC, `price` ASC LIMIT 1")->row;
		} else {
			$row = [];
		}

		if (!$row) {
			return null;
		}

		$base  = (float)$p['price'];
		$value = (float)$row['price'];

		switch ((string)$row['type']) {
			case 'P':
				$effective = $base - ($base * ($value / 100));
				break;
			case 'S':
				$effective = $base - $value;
				break;
			default:
				$effective = $value;
		}

		return ['id' => (int)$row['id'], 'type' => (string)$row['type'], 'value' => $value, 'price' => $effective];
	}

	/** Price the customer pays now (special when one applies), without tax. */
	private function currentPrice(array $p): float {
		$special = $this->activeSpecial($p);

		return $special ? $special['price'] : (float)$p['price'];
	}

	private function category(int $product_id): ?string {
		$row = $this->db->query("SELECT `cd`.`name` FROM `" . DB_PREFIX . "product_to_category` `p2c` LEFT JOIN `" . DB_PREFIX . "category_description` `cd` ON (`cd`.`category_id` = `p2c`.`category_id` AND `cd`.`language_id` = '" . (int)$this->config->get('config_language_id') . "') WHERE `p2c`.`product_id` = '" . $product_id . "' AND `cd`.`name` IS NOT NULL ORDER BY `p2c`.`category_id` ASC LIMIT 1")->row;

		return $row ? html_entity_decode((string)$row['name'], ENT_QUOTES, 'UTF-8') : null;
	}

	private function decimals(): int {
		$row = $this->db->query("SELECT `decimal_place` FROM `" . DB_PREFIX . "currency` WHERE `code` = '" . $this->db->escape((string)$this->config->get('config_currency')) . "'")->row;

		return $row ? (int)$row['decimal_place'] : 2;
	}

	private function view(array $p): array {
		$special = $this->activeSpecial($p);
		$master  = (int)($p['master_id'] ?? 0);

		// Variants (OpenCart 4 `master_id`) are products of their own; fall back to the master for
		// the descriptive fields a variant may leave empty.
		$category = $this->category((int)$p['product_id']);

		if ($category === null && $master) {
			$category = $this->category($master);
		}

		$text = function ($v): ?string {
			$v = $v === null ? '' : trim(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'));

			return $v === '' ? null : $v;
		};

		return [
			'sku'          => $this->skuOf($p),
			'title'        => (string)$text(strip_tags(html_entity_decode((string)$p['x_name'], ENT_QUOTES, 'UTF-8'))),
			'brand'        => $text($p['x_brand']),
			'mpn'          => $text($p['x_mpn']),
			'ean'          => $text($p['x_ean']),
			'category'     => $category,
			'price'        => round($special ? $special['price'] : (float)$p['price'], 4),
			'regularPrice' => round((float)$p['price'], 4),
			'currency'     => (string)$this->config->get('config_currency'),
			// In stock: quantity on hand, or stock not tracked for this product.
			'inStock'      => (int)$p['quantity'] > 0 || !(int)$p['subtract'],
			'url'          => $this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . (int)$p['product_id'], true),
		];
	}

	// ── Write ───────────────────────────────────────────────────────────────

	private function assertWritable(): void {
		if ((int)$this->setting('read_only', 0)) {
			throw new ToolError('This store is read-only: price changes are disabled in the extension settings.');
		}
	}

	private function updatePrice(array $a, string $actor): array {
		$this->assertWritable();

		$p     = $this->productBySku((string)($a['sku'] ?? ''));
		$price = isset($a['price']) && is_numeric($a['price']) ? (float)$a['price'] : 0.0;

		if (!is_finite($price) || $price <= 0) {
			throw new ToolError('price must be a positive number');
		}

		$price   = round($price, $this->decimals());
		$special = $this->activeSpecial($p);
		$current = $special ? $special['price'] : (float)$p['price'];
		$max     = $this->maxChangePct();

		if ($current > 0 && abs($price / $current - 1) * 100 > $max + 1e-9) {
			throw new ToolError(sprintf('Rejected: %s is more than %s%% away from the current price %s (limit set in the store).', $price, $max, round($current, 4)));
		}

		$product_id = (int)$p['product_id'];

		// The price customers pay: the special while one applies, otherwise the base price.
		if ($special) {
			if ($price >= (float)$p['price']) {
				throw new ToolError('Rejected: while a special price applies the new price must stay below the base price ' . round((float)$p['price'], 4) . '.');
			}

			$table = $this->schema()['special'] === 'discount' ? 'product_discount' : 'product_special';
			$key   = $table . '_id';
			$set   = "`price` = '" . (float)$price . "'" . ($table === 'product_discount' ? ", `type` = 'F'" : '');

			$this->db->query("UPDATE `" . DB_PREFIX . $table . "` SET " . $set . " WHERE `" . $key . "` = '" . (int)$special['id'] . "'");

			$this->log($product_id, $this->skuOf($p), $current, $price, 'update', 'special', $special['id'], $special['value'], $special['type'], $a['reason'] ?? '', $actor);
		} else {
			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET `price` = '" . (float)$price . "', `date_modified` = NOW() WHERE `product_id` = '" . $product_id . "'");

			$this->log($product_id, $this->skuOf($p), $current, $price, 'update', 'base', null, (float)$p['price'], null, $a['reason'] ?? '', $actor);
		}

		$this->cache->delete('product');

		return ['sku' => $this->skuOf($p), 'oldPrice' => round($current, 4), 'newPrice' => round($this->currentPrice($this->product($product_id)), 4)];
	}

	private function revertPrice(array $a, string $actor): array {
		$this->assertWritable();

		$p          = $this->productBySku((string)($a['sku'] ?? ''));
		$product_id = (int)$p['product_id'];
		$before     = $this->currentPrice($p);

		$last = $this->db->query("SELECT * FROM `" . DB_PREFIX . "wrcmcp_price_log` WHERE `product_id` = '" . $product_id . "' AND `kind` = 'update' AND `reverted` = '0' ORDER BY `id` DESC LIMIT 1")->row;

		if (!$last) {
			throw new ToolError('No price change to revert for this sku.');
		}

		if ($last['target'] === 'special') {
			$table = $this->schema()['special'] === 'discount' ? 'product_discount' : 'product_special';
			$key   = $table . '_id';

			if (!$this->db->query("SELECT 1 FROM `" . DB_PREFIX . $table . "` WHERE `" . $key . "` = '" . (int)$last['target_id'] . "'")->num_rows) {
				throw new ToolError('Cannot revert: the special price changed by the last update no longer exists.');
			}

			$set = "`price` = '" . (float)$last['old_value'] . "'" . ($table === 'product_discount' && $last['old_type'] ? ", `type` = '" . $this->db->escape((string)$last['old_type']) . "'" : '');

			$this->db->query("UPDATE `" . DB_PREFIX . $table . "` SET " . $set . " WHERE `" . $key . "` = '" . (int)$last['target_id'] . "'");
		} else {
			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET `price` = '" . (float)$last['old_value'] . "', `date_modified` = NOW() WHERE `product_id` = '" . $product_id . "'");
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "wrcmcp_price_log` SET `reverted` = '1' WHERE `id` = '" . (int)$last['id'] . "'");

		$this->cache->delete('product');

		$now = $this->currentPrice($this->product($product_id));

		$this->log($product_id, $this->skuOf($p), $before, $now, 'revert', (string)$last['target'], $last['target_id'] === null ? null : (int)$last['target_id'], null, null, 'revert: ' . ($a['reason'] ?? ''), $actor);

		return ['sku' => $this->skuOf($p), 'price' => round($now, 4)];
	}

	private function log(int $product_id, string $sku, ?float $old, ?float $new, string $kind, string $target, ?int $target_id, ?float $old_value, ?string $old_type, $reason, string $actor): void {
		$num = fn (?float $v): string => $v === null ? 'NULL' : "'" . (float)$v . "'";

		$this->db->query("INSERT INTO `" . DB_PREFIX . "wrcmcp_price_log` SET"
			. " `at` = UTC_TIMESTAMP(),"
			. " `product_id` = '" . $product_id . "',"
			. " `sku` = '" . $this->db->escape(mb_substr($sku, 0, 191)) . "',"
			. " `old_price` = " . $num($old) . ","
			. " `new_price` = " . $num($new) . ","
			. " `kind` = '" . $this->db->escape($kind) . "',"
			. " `target` = '" . $this->db->escape($target) . "',"
			. " `target_id` = " . ($target_id === null ? 'NULL' : "'" . (int)$target_id . "'") . ","
			. " `old_value` = " . $num($old_value) . ","
			. " `old_type` = " . ($old_type === null ? 'NULL' : "'" . $this->db->escape(substr($old_type, 0, 1)) . "'") . ","
			. " `reason` = '" . $this->db->escape(mb_substr(trim((string)$reason), 0, 1000)) . "',"
			. " `actor` = '" . $this->db->escape(mb_substr($actor, 0, 191)) . "'");
	}

	private function listPriceChanges(array $a): array {
		$limit = max(1, min(200, (int)($a['limit'] ?? 50)));
		$sku   = trim((string)($a['sku'] ?? ''));

		$sql = "SELECT * FROM `" . DB_PREFIX . "wrcmcp_price_log`";

		if ($sku !== '') {
			$sql .= " WHERE `sku` = '" . $this->db->escape($sku) . "'";
		}

		$changes = [];

		foreach ($this->db->query($sql . " ORDER BY `id` DESC LIMIT " . $limit)->rows as $r) {
			$changes[] = [
				'at'       => gmdate('c', (int)strtotime($r['at'] . ' UTC')),
				'sku'      => $r['sku'],
				'oldPrice' => $r['old_price'] === null ? null : (float)$r['old_price'],
				'newPrice' => $r['new_price'] === null ? null : (float)$r['new_price'],
				'reason'   => $r['reason'],
				'actor'    => $r['actor'],
			];
		}

		return ['changes' => $changes];
	}
}
