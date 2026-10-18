<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Module settings (Stores → Configuration → WebRobot → Commerce MCP, default scope).
 *
 * The token itself is never stored: only its SHA-256 hash, so a database dump does not hand out write
 * access to the store's prices.
 */
class Config
{
    public const XML_MAX_CHANGE_PCT = 'webrobot_commercemcp/general/max_change_pct';
    public const XML_READ_ONLY = 'webrobot_commercemcp/general/read_only';
    public const XML_BRAND = 'webrobot_commercemcp/attributes/brand';
    public const XML_MPN = 'webrobot_commercemcp/attributes/mpn';
    public const XML_EAN = 'webrobot_commercemcp/attributes/ean';
    public const XML_TOKEN_HASH = 'webrobot_commercemcp/connection/token_hash';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $writer,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {
    }

    public function maxChangePct(): float
    {
        $v = $this->scopeConfig->getValue(self::XML_MAX_CHANGE_PCT);
        return is_numeric($v) ? max(0.0, min(100.0, (float) $v)) : 15.0;
    }

    public function isReadOnly(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_READ_ONLY);
    }

    public function brandAttribute(): string
    {
        return $this->code(self::XML_BRAND, 'manufacturer');
    }

    public function mpnAttribute(): string
    {
        return $this->code(self::XML_MPN, 'mpn');
    }

    public function eanAttribute(): string
    {
        return $this->code(self::XML_EAN, 'ean');
    }

    public function hasToken(): bool
    {
        return (string) $this->scopeConfig->getValue(self::XML_TOKEN_HASH) !== '';
    }

    /** Generate a new token (revokes the previous one) and return it in clear — the only time it is visible. */
    public function regenerateToken(): string
    {
        $token = 'wrc_' . bin2hex(random_bytes(24));
        $this->writer->save(self::XML_TOKEN_HASH, hash('sha256', $token));
        $this->cacheTypeList->cleanType('config');
        $this->reinitableConfig->reinit();
        return $token;
    }

    public function tokenMatches(?string $presented): bool
    {
        $hash = (string) $this->scopeConfig->getValue(self::XML_TOKEN_HASH);
        if ($hash === '' || $presented === null || $presented === '') {
            return false;
        }
        return hash_equals($hash, hash('sha256', $presented));
    }

    private function code(string $path, string $default): string
    {
        $v = trim((string) $this->scopeConfig->getValue($path));
        return $v !== '' ? $v : $default;
    }
}
