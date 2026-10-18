<?php
/**
 * Audit log of every price change made through the MCP (table PREFIX_wrcmcp_price_log).
 *
 * old_price / new_price are the prices customers pay (shown to the caller); field + old_value /
 * new_value are what was actually written (the product base price or the combination price impact,
 * tax excluded), so revert_price restores the exact stored value.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class WrcmcpLog
{
    public static function table()
    {
        return _DB_PREFIX_ . 'wrcmcp_price_log';
    }

    public static function install()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `at` datetime NOT NULL,
            `kind` varchar(8) NOT NULL DEFAULT \'update\',
            `id_shop` int(10) unsigned NOT NULL DEFAULT 0,
            `id_product` int(10) unsigned NOT NULL,
            `id_product_attribute` int(10) unsigned NOT NULL DEFAULT 0,
            `sku` varchar(191) NOT NULL,
            `old_price` decimal(20,6) NULL,
            `new_price` decimal(20,6) NULL,
            `field` varchar(16) NOT NULL,
            `old_value` decimal(20,6) NOT NULL,
            `new_value` decimal(20,6) NOT NULL,
            `reason` text NULL,
            `actor` varchar(191) NULL,
            `reverted` tinyint(1) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `sku` (`sku`),
            KEY `product` (`id_product`, `id_product_attribute`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public static function uninstall()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::table() . '`');
    }

    public static function add(array $row)
    {
        $row['at'] = gmdate('Y-m-d H:i:s');
        foreach (['sku', 'field', 'kind', 'reason', 'actor'] as $k) {
            $row[$k] = pSQL(Tools::substr((string) ($row[$k] ?? ''), 0, $k === 'reason' ? 2000 : 191));
        }
        Db::getInstance()->insert('wrcmcp_price_log', $row);
        return (int) Db::getInstance()->Insert_ID();
    }

    /** Last update of a product/combination that has not been reverted yet. */
    public static function lastOpen($idProduct, $idProductAttribute, $idShop)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . self::table() . '` WHERE `kind` = \'update\' AND `reverted` = 0'
            . ' AND `id_product` = ' . (int) $idProduct . ' AND `id_product_attribute` = ' . (int) $idProductAttribute
            . ' AND `id_shop` = ' . (int) $idShop . ' ORDER BY `id` DESC');
        return $row ?: null;
    }

    public static function markReverted($id)
    {
        Db::getInstance()->update('wrcmcp_price_log', ['reverted' => 1], '`id` = ' . (int) $id);
    }

    public static function recent($sku = '', $limit = 50)
    {
        $limit = max(1, min(200, (int) $limit));
        $where = $sku !== '' ? ' WHERE `sku` = \'' . pSQL($sku) . '\'' : '';
        return Db::getInstance()->executeS('SELECT * FROM `' . self::table() . '`' . $where . ' ORDER BY `id` DESC LIMIT ' . $limit) ?: [];
    }
}
