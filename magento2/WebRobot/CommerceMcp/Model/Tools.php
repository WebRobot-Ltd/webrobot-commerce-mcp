<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexer;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The five tools of the Commerce MCP contract (spec/CONTRACT.md). Every method returns an array (the
 * structured result) or throws ToolError (returned to the caller as isError).
 *
 * Products are read in the store view of the request (the default store view for /webrobotmcp/mcp);
 * prices are written in the default scope (store_id 0).
 */
class Tools
{
    /** Product types that carry a price of their own. Configurable/bundle/grouped parents are not products here. */
    private const TYPES = ['simple', 'virtual', 'downloadable'];

    private const EPSILON = 0.005;

    private array $parents = [];
    private array $categoryNames = [];

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductResource $productResource,
        private readonly ProductAction $productAction,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly Configurable $configurableType,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly EavConfig $eavConfig,
        private readonly ResourceConnection $resource,
        private readonly PriceIndexer $priceIndexer,
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager,
        private readonly CacheInterface $cache,
        private readonly Config $config,
        private readonly PriceLog $log
    ) {
    }

    public function definitions(): array
    {
        $json = file_get_contents(__DIR__ . '/../etc/tools.json');
        return json_decode((string) $json, true) ?: [];
    }

    public function call(string $name, array $args, string $actor): array
    {
        switch ($name) {
            case 'list_products':
                return $this->listProducts($args);
            case 'get_product':
                return $this->view($this->productBySku((string) ($args['sku'] ?? ''), true));
            case 'update_price':
                return $this->updatePrice($args, $actor);
            case 'revert_price':
                return $this->revertPrice($args, $actor);
            case 'list_price_changes':
                return $this->listPriceChanges($args);
        }
        throw new ToolError("Unknown tool: $name");
    }

    public function currency(): string
    {
        return (string) $this->store()->getBaseCurrencyCode();
    }

    // ── Read ────────────────────────────────────────────────────────────────

    private function listProducts(array $a): array
    {
        $limit = max(1, min(100, (int) ($a['limit'] ?? 50)));
        $offset = max(0, (int) ($a['offset'] ?? 0));
        $query = trim((string) ($a['query'] ?? ''));
        $store = $this->store();

        $c = $this->productCollectionFactory->create();
        $c->setStoreId((int) $store->getId())
            ->addStoreFilter($store)
            ->addAttributeToFilter('type_id', ['in' => self::TYPES])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED);
        if ($query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $or = [['attribute' => 'name', 'like' => $like], ['attribute' => 'sku', 'like' => $like]];
            $brand = $this->brandCondition($like);
            if ($brand) {
                $or[] = $brand;
            }
            $c->addAttributeToFilter($or, null, 'left');
        }

        $total = (int) $c->getSize();
        $select = clone $c->getSelect();
        $select->reset(Select::COLUMNS)->reset(Select::ORDER)->reset(Select::LIMIT_COUNT)->reset(Select::LIMIT_OFFSET)
            ->columns('e.entity_id')->order('e.entity_id ASC')->limit($limit, $offset);
        $ids = $this->resource->getConnection()->fetchCol($select);

        $items = [];
        foreach ($ids as $id) {
            try {
                $items[] = $this->view($this->productRepository->getById((int) $id, false, (int) $store->getId()));
            } catch (NoSuchEntityException $e) {
                continue;
            }
        }
        return ['total' => $total, 'items' => $items];
    }

    /** Brand match for the text filter: option labels for a select attribute, the value itself otherwise. */
    private function brandCondition(string $like): ?array
    {
        $code = $this->config->brandAttribute();
        $attr = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        if (!$attr || !$attr->getId()) {
            return null;
        }
        if ($attr->getFrontendInput() === 'select') {
            $conn = $this->resource->getConnection();
            $ids = $conn->fetchCol(
                $conn->select()
                    ->from(['v' => $this->resource->getTableName('eav_attribute_option_value')], ['option_id'])
                    ->join(['o' => $this->resource->getTableName('eav_attribute_option')], 'o.option_id = v.option_id', [])
                    ->where('o.attribute_id = ?', (int) $attr->getId())
                    ->where('v.value LIKE ?', $like)
                    ->distinct()
            );
            return $ids ? ['attribute' => $code, 'in' => array_map('intval', $ids)] : null;
        }
        if (in_array($attr->getBackendType(), ['varchar', 'text'], true)) {
            return ['attribute' => $code, 'like' => $like];
        }
        return null;
    }

    private function productBySku(string $sku, bool $fresh): Product
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new ToolError('sku is required');
        }
        $store = $this->store();
        try {
            /** @var Product $p */
            $p = $this->productRepository->get($sku, false, (int) $store->getId(), $fresh);
        } catch (NoSuchEntityException $e) {
            throw new ToolError("Unknown sku $sku");
        }
        if (!in_array($p->getTypeId(), self::TYPES, true)
            || (int) $p->getStatus() !== Status::STATUS_ENABLED
            || !in_array((int) $store->getWebsiteId(), array_map('intval', (array) $p->getWebsiteIds()), true)) {
            throw new ToolError("Unknown sku $sku");
        }
        return $p;
    }

    private function view(Product $p): array
    {
        $parent = $this->parentOf($p);
        $attr = function (string $code) use ($p, $parent): ?string {
            $v = $this->attributeValue($p, $code);
            if ($v === null && $parent) {
                $v = $this->attributeValue($parent, $code);
            }
            return $v;
        };

        $regular = $p->getData('price');
        $url = null;
        if ((int) $p->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE) {
            $url = $p->getProductUrl();
        } elseif ($parent && (int) $parent->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE) {
            $url = $parent->getProductUrl();
        }

        return [
            'sku' => (string) $p->getSku(),
            'title' => trim(strip_tags((string) $p->getName())),
            'brand' => $attr($this->config->brandAttribute()),
            // Identifiers are the child's own, never the parent's: a matcher would otherwise pair one
            // variant with another variant's code. Brand and category may come from the parent.
            'mpn' => $this->attributeValue($p, $this->config->mpnAttribute()),
            'ean' => $this->attributeValue($p, $this->config->eanAttribute()),
            'category' => $this->categoryOf($p) ?? ($parent ? $this->categoryOf($parent) : null),
            'price' => $this->finalPrice($p),
            'regularPrice' => $regular === null || $regular === '' ? null : (float) $regular,
            'currency' => $this->currency(),
            'inStock' => (int) $this->stockRegistry->getStockStatus((int) $p->getId())->getStockStatus() === 1,
            'url' => $url ?: null,
        ];
    }

    /** The price customers pay now (base currency, qty 1): special price, tier price and catalog rules applied. */
    private function finalPrice(Product $p): ?float
    {
        if ($p->getData('price') === null || $p->getData('price') === '') {
            return null;
        }
        return (float) $p->getPriceModel()->getFinalPrice(1, $p);
    }

    /** A special price that customers pay today: set, within its dates, and below the regular price. */
    private function specialPriceActive(Product $p): bool
    {
        $special = $p->getData('special_price');
        if ($special === null || $special === '' || $special === false) {
            return false;
        }
        if (!$this->timezone->isScopeDateInInterval($this->store(), $p->getSpecialFromDate(), $p->getSpecialToDate())) {
            return false;
        }
        return (float) $special < (float) $p->getData('price');
    }

    private function attributeValue(Product $p, string $code): ?string
    {
        if ($code === '') {
            return null;
        }
        $attribute = $p->getResource()->getAttribute($code);
        $raw = $p->getData($code);
        if (!$attribute || $raw === null || $raw === '' || $raw === false) {
            return null;
        }
        if ($attribute->usesSource()) {
            $text = $p->getAttributeText($code);
            if (is_array($text)) {
                $text = implode(', ', $text);
            }
            $text = (string) $text;
            return $text !== '' ? $text : null;
        }
        return is_scalar($raw) ? (string) $raw : null;
    }

    /** First category (by id) below the root categories, in the store view. */
    private function categoryOf(Product $p): ?string
    {
        $ids = array_map('intval', (array) $p->getCategoryIds());
        if (!$ids) {
            return null;
        }
        sort($ids);
        $key = implode(',', $ids);
        if (!array_key_exists($key, $this->categoryNames)) {
            $c = $this->categoryCollectionFactory->create()
                ->setStoreId((int) $this->store()->getId())
                ->addAttributeToSelect('name')
                ->addIdFilter($ids)
                ->addAttributeToFilter('level', ['gteq' => 2])
                ->setOrder('entity_id', 'ASC')
                ->setPageSize(1);
            $first = $c->getFirstItem();
            $name = $first && $first->getId() ? trim((string) $first->getName()) : '';
            $this->categoryNames[$key] = $name !== '' ? $name : null;
        }
        return $this->categoryNames[$key];
    }

    /** The configurable product a child belongs to (first enabled one), or null. */
    private function parentOf(Product $p): ?Product
    {
        $id = (int) $p->getId();
        if (!array_key_exists($id, $this->parents)) {
            $this->parents[$id] = null;
            foreach ($this->configurableType->getParentIdsByChild($id) as $parentId) {
                try {
                    /** @var Product $parent */
                    $parent = $this->productRepository->getById((int) $parentId, false, (int) $this->store()->getId());
                } catch (NoSuchEntityException $e) {
                    continue;
                }
                if ((int) $parent->getStatus() === Status::STATUS_ENABLED) {
                    $this->parents[$id] = $parent;
                    break;
                }
            }
        }
        return $this->parents[$id];
    }

    // ── Write ───────────────────────────────────────────────────────────────

    private function updatePrice(array $a, string $actor): array
    {
        if ($this->config->isReadOnly()) {
            throw new ToolError('This store is read-only: price changes are disabled in the module settings.');
        }
        $p = $this->productBySku((string) ($a['sku'] ?? ''), true);
        $price = isset($a['price']) && is_numeric($a['price']) ? (float) $a['price'] : 0.0;
        if (!is_finite($price) || $price <= 0) {
            throw new ToolError('price must be a positive number');
        }
        $price = round($price, 2);
        $current = (float) $this->finalPrice($p);
        $max = $this->config->maxChangePct();
        if ($current > 0 && abs($price / $current - 1) * 100 > $max + 1e-9) {
            throw new ToolError(sprintf(
                'Rejected: %s is more than %s%% away from the current price %s (limit set in the store).',
                $price,
                $max,
                $current
            ));
        }

        // The price customers pay: the special price while it is active, otherwise the regular one.
        $field = $this->specialPriceActive($p) ? 'special_price' : 'price';
        if ($field === 'special_price' && $price >= (float) $p->getData('price')) {
            throw new ToolError('Rejected: while a special price is active the new price must stay below the regular price.');
        }
        $storeId = Store::DEFAULT_STORE_ID;
        $old = $this->rawValue((int) $p->getId(), $field, $storeId);

        $this->write($p, $field, $price, $storeId);
        $after = $this->productBySku((string) $p->getSku(), true);
        $now = (float) $this->finalPrice($after);
        if (abs($now - $price) > self::EPSILON) {
            // Something else decides the price customers pay (a catalog price rule, a tier price, a
            // website-scope price): undo and say so rather than leave a change that has no effect.
            $this->write($p, $field, $old, $storeId);
            throw new ToolError(sprintf(
                'Not changed: after setting %s to %s customers would pay %s, because a catalog price rule, tier price or website-scope price applies to this product.',
                $field,
                $price,
                $now
            ));
        }

        $this->log->add([
            'product_id' => (int) $p->getId(),
            'sku' => (string) $p->getSku(),
            'kind' => 'update',
            'field' => $field,
            'store_id' => $storeId,
            'old_value' => $old,
            'new_value' => $price,
            'old_price' => $current,
            'new_price' => $now,
            'reason' => (string) ($a['reason'] ?? ''),
            'actor' => $actor,
        ]);
        return ['sku' => (string) $p->getSku(), 'oldPrice' => $current, 'newPrice' => $now];
    }

    private function revertPrice(array $a, string $actor): array
    {
        if ($this->config->isReadOnly()) {
            throw new ToolError('This store is read-only: price changes are disabled in the module settings.');
        }
        $p = $this->productBySku((string) ($a['sku'] ?? ''), true);
        $last = $this->log->lastOpen((int) $p->getId());
        if (!$last) {
            throw new ToolError('No price change to revert for this sku.');
        }
        $before = (float) $this->finalPrice($p);
        $field = (string) $last['field'];
        $storeId = (int) $last['store_id'];
        $current = $this->rawValue((int) $p->getId(), $field, $storeId);

        $this->write($p, $field, $last['old_value'] === null ? null : (float) $last['old_value'], $storeId);
        $this->log->markReverted((int) $last['id']);
        $now = (float) $this->finalPrice($this->productBySku((string) $p->getSku(), true));
        $this->log->add([
            'product_id' => (int) $p->getId(),
            'sku' => (string) $p->getSku(),
            'kind' => 'revert',
            'field' => $field,
            'store_id' => $storeId,
            'old_value' => $current,
            'new_value' => $last['old_value'],
            'old_price' => $before,
            'new_price' => $now,
            'reason' => 'revert: ' . (string) ($a['reason'] ?? ''),
            'actor' => $actor,
        ]);
        return ['sku' => (string) $p->getSku(), 'price' => $now];
    }

    private function listPriceChanges(array $a): array
    {
        $rows = $this->log->recent(trim((string) ($a['sku'] ?? '')), (int) ($a['limit'] ?? 50));
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'at' => gmdate('c', (int) strtotime($r['created_at'] . ' UTC')),
                'sku' => $r['sku'],
                'oldPrice' => $r['old_price'] === null ? null : (float) $r['old_price'],
                'newPrice' => $r['new_price'] === null ? null : (float) $r['new_price'],
                'reason' => $r['reason'],
                'actor' => $r['actor'],
            ];
        }
        return ['changes' => $out];
    }

    private function rawValue(int $productId, string $field, int $storeId): ?float
    {
        $v = $this->productResource->getAttributeRawValue($productId, $field, $storeId);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Write one price attribute, then reindex the price and flush the caches of the product and its parents. */
    private function write(Product $p, string $field, ?float $value, int $storeId): void
    {
        $id = (int) $p->getId();
        $this->productAction->updateAttributes([$id], [$field => $value], $storeId);
        $ids = array_merge([$id], array_map('intval', $this->configurableType->getParentIdsByChild($id)));
        $this->priceIndexer->reindexList($ids);
        // Block cache (price boxes) and full page cache: a mass attribute update does neither by itself.
        $this->cache->clean(array_map(static fn($i) => Product::CACHE_TAG . '_' . $i, $ids));
        $this->cacheContext->registerEntities(Product::CACHE_TAG, $ids);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
    }

    private function store(): StoreInterface
    {
        return $this->storeManager->getStore();
    }
}
