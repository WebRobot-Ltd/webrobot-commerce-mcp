<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Audit log of every price change made through the MCP (table wrcmcp_price_log, see etc/db_schema.xml).
 */
class PriceLog
{
    public const TABLE = 'wrcmcp_price_log';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function add(array $row): void
    {
        $row['created_at'] = gmdate('Y-m-d H:i:s');
        $this->connection()->insert($this->table(), $row);
    }

    /** Last `update` of a product that has not been reverted yet. */
    public function lastOpen(int $productId): ?array
    {
        $c = $this->connection();
        $row = $c->fetchRow(
            $c->select()->from($this->table())
                ->where('product_id = ?', $productId)
                ->where('kind = ?', 'update')
                ->where('reverted = 0')
                ->order('id DESC')
                ->limit(1)
        );
        return $row ?: null;
    }

    public function markReverted(int $id): void
    {
        $this->connection()->update($this->table(), ['reverted' => 1], ['id = ?' => $id]);
    }

    /** Newest first. */
    public function recent(string $sku = '', int $limit = 50): array
    {
        $c = $this->connection();
        $select = $c->select()->from($this->table())->order('id DESC')->limit(max(1, min(200, $limit)));
        if ($sku !== '') {
            $select->where('sku = ?', $sku);
        }
        return $c->fetchAll($select);
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
