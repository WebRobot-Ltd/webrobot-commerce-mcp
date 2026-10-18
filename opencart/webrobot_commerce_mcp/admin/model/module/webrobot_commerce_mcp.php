<?php
namespace Opencart\Admin\Model\Extension\WebrobotCommerceMcp\Module;
/**
 * Audit log of every price change made through the MCP, and the extension's settings.
 */
class WebrobotCommerceMcp extends \Opencart\System\Engine\Model {
	public const SETTING = 'module_webrobot_commerce_mcp';

	public const DEFAULTS = [
		'module_webrobot_commerce_mcp_status'         => 0,
		'module_webrobot_commerce_mcp_token_hash'     => '',
		'module_webrobot_commerce_mcp_max_change_pct' => 15,
		'module_webrobot_commerce_mcp_read_only'      => 0,
	];

	public function install(): void {
		// Everything needed to put the exact previous value back: which row was changed (base price
		// or a special), its value and — for OpenCart 4.1 specials — its type (fixed/percent/subtract).
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "wrcmcp_price_log` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			`at` datetime NOT NULL,
			`product_id` int(11) NOT NULL,
			`sku` varchar(191) NOT NULL,
			`old_price` decimal(15,4) NULL,
			`new_price` decimal(15,4) NULL,
			`kind` varchar(8) NOT NULL DEFAULT 'update',
			`target` varchar(16) NOT NULL DEFAULT 'base',
			`target_id` int(11) NULL,
			`old_value` decimal(15,4) NULL,
			`old_type` char(1) NULL,
			`reason` text NULL,
			`actor` varchar(191) NULL,
			`reverted` tinyint(1) NOT NULL DEFAULT '0',
			PRIMARY KEY (`id`),
			KEY `sku` (`sku`),
			KEY `product_id` (`product_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

		// Store the defaults, keeping any value already present.
		$this->editSettings([]);
	}

	public function uninstall(): void {
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "wrcmcp_price_log`");

		$this->load->model('setting/setting');

		$this->model_setting_setting->deleteSetting(self::SETTING);
	}

	public function getSettings(): array {
		$this->load->model('setting/setting');

		return array_merge(self::DEFAULTS, $this->model_setting_setting->getSetting(self::SETTING));
	}

	/** Merge values into the stored settings (OpenCart's editSetting replaces the whole group). */
	public function editSettings(array $values): void {
		$settings = $this->getSettings();

		foreach ($values as $key => $value) {
			if (array_key_exists($key, self::DEFAULTS)) {
				$settings[$key] = $value;
			}
		}

		$this->model_setting_setting->editSetting(self::SETTING, $settings);
	}

	/** Generate a new token (revokes the previous one) and return it in clear — the only time it is visible. */
	public function regenerateToken(): string {
		$token = 'wrc_' . bin2hex(random_bytes(24));

		$this->editSettings([self::SETTING . '_token_hash' => hash('sha256', $token)]);

		return $token;
	}

	public function getChanges(int $limit = 50): array {
		if (!$this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'wrcmcp_price_log') . "'")->num_rows) {
			return [];
		}

		return $this->db->query("SELECT * FROM `" . DB_PREFIX . "wrcmcp_price_log` ORDER BY `id` DESC LIMIT " . max(1, min(200, $limit)))->rows;
	}
}
