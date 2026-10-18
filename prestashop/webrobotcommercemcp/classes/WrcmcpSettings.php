<?php
/**
 * Module settings (global Configuration values). The token itself is never stored: only its SHA-256
 * hash, so a database dump does not hand out write access to the store's prices.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class WrcmcpSettings
{
    const KEYS = [
        'token_hash' => 'WRCMCP_TOKEN_HASH',
        'max_change_pct' => 'WRCMCP_MAX_CHANGE_PCT',
        'read_only' => 'WRCMCP_READ_ONLY',
    ];

    const DEFAULTS = [
        'token_hash' => '',
        'max_change_pct' => 15,
        'read_only' => 0,
    ];

    public static function install()
    {
        foreach (self::DEFAULTS as $k => $v) {
            if (Configuration::getGlobalValue(self::KEYS[$k]) === false) {
                Configuration::updateGlobalValue(self::KEYS[$k], $v);
            }
        }
        return true;
    }

    public static function uninstall()
    {
        foreach (self::KEYS as $key) {
            Configuration::deleteByName($key);
        }
        return true;
    }

    public static function get($key)
    {
        $v = Configuration::getGlobalValue(self::KEYS[$key]);
        if ($v === false || $v === null) {
            $v = self::DEFAULTS[$key];
        }
        switch ($key) {
            case 'max_change_pct':
                return (float) $v;
            case 'read_only':
                return (bool) (int) $v;
            default:
                return (string) $v;
        }
    }

    public static function set($key, $value)
    {
        Configuration::updateGlobalValue(self::KEYS[$key], $value);
    }

    /** Generate a new token (revokes the previous one) and return it in clear — the only time it is visible. */
    public static function regenerateToken()
    {
        $token = 'wrc_' . bin2hex(random_bytes(24));
        self::set('token_hash', hash('sha256', $token));
        return $token;
    }

    public static function tokenMatches($presented)
    {
        $hash = self::get('token_hash');
        if ($hash === '' || !is_string($presented) || $presented === '') {
            return false;
        }
        return hash_equals($hash, hash('sha256', $presented));
    }
}
