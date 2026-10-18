<?php
/**
 * The five tools of the Commerce MCP contract (spec/CONTRACT.md). Every method returns an array (the
 * structured result) or throws WrcmcpToolError (returned to the caller as isError).
 *
 * What is a "product": a product without combinations, or one combination of a product (the parent of
 * a product with combinations is not listed: it has no price customers pay of its own).
 *
 * Prices: `price` is what an anonymous visitor pays now for one unit, tax included, in the default
 * currency (Product::getPriceStatic with reductions); `regularPrice` is the same without reductions.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class WrcmcpToolError extends Exception
{
}

class WrcmcpTools
{
    /** @var Context */
    private $context;
    private $idShop;
    private $idLang;
    private $precision;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->idShop = (int) $context->shop->id;
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $this->precision = (int) $context->getComputingPrecision();
    }

    public static function definitions()
    {
        // Decoded as objects so that empty JSON objects stay objects when re-encoded.
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/tools.json'));
    }

    public function call($name, array $args, $actor)
    {
        try {
            switch ($name) {
                case 'list_products':
                    return $this->listProducts($args);
                case 'get_product':
                    $ref = $this->resolve($args['sku'] ?? '');
                    return $this->view($ref[0], $ref[1]);
                case 'update_price':
                    return $this->updatePrice($args, $actor);
                case 'revert_price':
                    return $this->revertPrice($args, $actor);
                case 'list_price_changes':
                    return $this->listPriceChanges($args);
            }
        } catch (WrcmcpToolError $e) {
            throw $e;
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('webrobotcommercemcp ' . $name . ': ' . $e->getMessage(), 3, null, 'Module', 0, true);
            throw new WrcmcpToolError('Store error while running ' . $name . ': ' . $e->getMessage());
        }
        throw new WrcmcpToolError("Unknown tool: $name");
    }

    // ── Read ────────────────────────────────────────────────────────────────

    private function listProducts(array $a)
    {
        $limit = max(1, min(100, (int) ($a['limit'] ?? 50)));
        $offset = max(0, (int) ($a['offset'] ?? 0));
        $query = trim((string) ($a['query'] ?? ''));

        $idShop = $this->idShop;
        $idLang = $this->idLang;
        $like = $query === '' ? '' : '\'%' . pSQL(addcslashes($query, '%_\\')) . '%\'';
        $text = function ($refColumn) use ($like) {
            return $like === '' ? '' : " AND (pl.name LIKE $like OR $refColumn LIKE $like OR m.name LIKE $like)";
        };
        $p = _DB_PREFIX_;
        $common = "FROM `{$p}product` p
            INNER JOIN `{$p}product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = $idShop AND ps.active = 1
            LEFT JOIN `{$p}product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = $idLang AND pl.id_shop = $idShop
            LEFT JOIN `{$p}manufacturer` m ON m.id_manufacturer = p.id_manufacturer";
        $union = "SELECT p.id_product, 0 AS id_product_attribute $common
                WHERE NOT EXISTS (SELECT 1 FROM `{$p}product_attribute` x WHERE x.id_product = p.id_product)" . $text('p.reference') . "
            UNION ALL
            SELECT p.id_product, pa.id_product_attribute $common
                INNER JOIN `{$p}product_attribute` pa ON pa.id_product = p.id_product
                INNER JOIN `{$p}product_attribute_shop` pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = $idShop
                WHERE 1" . $text('pa.reference');

        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $total = (int) $db->getValue("SELECT COUNT(*) FROM ($union) t");
        $rows = $db->executeS("SELECT * FROM ($union) t ORDER BY id_product ASC, id_product_attribute ASC LIMIT $offset, $limit") ?: [];

        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->view((int) $r['id_product'], (int) $r['id_product_attribute']);
        }
        return ['total' => $total, 'items' => $items];
    }

    /**
     * sku → [id_product, id_product_attribute]. A combination reference wins over a product reference;
     * `id:<id_product>` and `id:<id_product>-<id_product_attribute>` address items without a reference.
     */
    private function resolve($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            throw new WrcmcpToolError('sku is required');
        }
        $p = _DB_PREFIX_;
        $db = Db::getInstance();
        $idP = 0;
        $idPA = 0;
        if (preg_match('/^id:(\d+)(?:-(\d+))?$/', $sku, $m)) {
            $idP = (int) $m[1];
            $idPA = isset($m[2]) ? (int) $m[2] : 0;
        } else {
            if ($this->referenceCount($sku) > 1) {
                throw new WrcmcpToolError("Ambiguous sku $sku: several products or combinations share this reference; use the id:<id_product>-<id_product_attribute> sku from list_products.");
            }
            $row = $db->getRow("SELECT pa.id_product, pa.id_product_attribute FROM `{$p}product_attribute` pa
                WHERE pa.reference = '" . pSQL($sku) . "'");
            if ($row) {
                $idP = (int) $row['id_product'];
                $idPA = (int) $row['id_product_attribute'];
            } else {
                $idP = (int) $db->getValue("SELECT p.id_product FROM `{$p}product` p WHERE p.reference = '" . pSQL($sku) . "'
                    AND NOT EXISTS (SELECT 1 FROM `{$p}product_attribute` x WHERE x.id_product = p.id_product)");
            }
        }
        $active = $idP ? (int) $db->getValue("SELECT active FROM `{$p}product_shop` WHERE id_product = $idP AND id_shop = {$this->idShop}") : 0;
        $hasCombinations = $idP ? (bool) $db->getValue("SELECT 1 FROM `{$p}product_attribute` WHERE id_product = $idP") : false;
        $comboOk = $idPA
            ? (bool) $db->getValue("SELECT 1 FROM `{$p}product_attribute` pa INNER JOIN `{$p}product_attribute_shop` pas
                ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = {$this->idShop}
                WHERE pa.id_product = $idP AND pa.id_product_attribute = $idPA")
            : !$hasCombinations;
        if (!$active || !$comboOk) {
            throw new WrcmcpToolError("Unknown sku $sku");
        }
        return [$idP, $idPA];
    }

    /**
     * How many listable items (products without combinations, combinations) carry this reference. Many
     * shops copy the parent reference to every combination: such a reference is not a usable key.
     */
    private function referenceCount($reference)
    {
        $p = _DB_PREFIX_;
        $ref = pSQL((string) $reference);
        return (int) Db::getInstance()->getValue("SELECT
            (SELECT COUNT(*) FROM `{$p}product_attribute` WHERE reference = '$ref')
          + (SELECT COUNT(*) FROM `{$p}product` p WHERE p.reference = '$ref'
                AND NOT EXISTS (SELECT 1 FROM `{$p}product_attribute` x WHERE x.id_product = p.id_product))");
    }

    /** The item's reference when it identifies it alone, otherwise id:<id_product>[-<id_product_attribute>]. */
    private function skuOf($idP, $idPA, $reference)
    {
        if ((string) $reference !== '' && $this->referenceCount($reference) === 1) {
            return (string) $reference;
        }
        return $idPA ? "id:$idP-$idPA" : "id:$idP";
    }

    /** Price customers pay now (with reductions) or without reductions, tax included, rounded to the currency. */
    private function gross($idP, $idPA, $withReductions = true, &$specificPrice = null)
    {
        $specificPrice = null;
        $v = Product::getPriceStatic($idP, true, $idPA, 6, null, false, $withReductions, 1, false, null, null, null,
            $specificPrice, true, true, $this->context);
        return (float) Tools::ps_round((float) $v, $this->precision);
    }

    private function view($idP, $idPA)
    {
        $product = new Product($idP, false, $this->idLang, $this->idShop);
        $combination = $idPA ? new Combination($idPA, null, $this->idShop) : null;
        $reference = $combination ? $combination->reference : $product->reference;
        // Identifiers are per item: a combination without its own EAN/MPN gets null, not its parent's code,
        // so that a matcher never pairs a variant with another variant's code.
        $ean = $combination ? $combination->ean13 : $product->ean13;
        $mpn = $combination ? $combination->mpn : $product->mpn;
        $brand = $product->id_manufacturer ? Manufacturer::getNameById((int) $product->id_manufacturer) : '';
        $category = $product->id_category_default ? new Category((int) $product->id_category_default, $this->idLang, $this->idShop) : null;
        $title = $idPA ? Product::getProductName($idP, $idPA, $this->idLang) : $product->name;
        $inStock = !Configuration::get('PS_STOCK_MANAGEMENT')
            || StockAvailable::getQuantityAvailableByProduct($idP, $idPA, $this->idShop) > 0;
        $price = $this->gross($idP, $idPA, true);
        $regular = $this->gross($idP, $idPA, false);

        return [
            'sku' => $this->skuOf($idP, $idPA, $reference),
            'title' => trim(strip_tags((string) $title)),
            'brand' => $brand !== '' && $brand !== false ? (string) $brand : null,
            'mpn' => (string) $mpn !== '' ? (string) $mpn : null,
            'ean' => (string) $ean !== '' ? (string) $ean : null,
            'category' => $category && Validate::isLoadedObject($category) && (string) $category->name !== '' ? (string) $category->name : null,
            'price' => $price,
            'regularPrice' => $regular,
            'currency' => (string) $this->context->currency->iso_code,
            'inStock' => (bool) $inStock,
            'url' => $this->context->link->getProductLink($product, null, null, null, $this->idLang, $this->idShop, $idPA),
        ];
    }

    // ── Write ───────────────────────────────────────────────────────────────

    private function assertWritable()
    {
        if (WrcmcpSettings::get('read_only')) {
            throw new WrcmcpToolError('This store is read-only: price changes are disabled in the module settings.');
        }
    }

    /** The stored value update_price changes: the base price (tax excl.) or the combination price impact. */
    private function storedValue($idP, $idPA)
    {
        if ($idPA) {
            $c = new Combination($idPA, null, $this->idShop);
            return ['impact', (float) $c->price];
        }
        $p = new Product($idP, false, null, $this->idShop);
        return ['base', (float) $p->price];
    }

    private function writeStoredValue($idP, $idPA, $field, $value)
    {
        $value = round((float) $value, 6);
        if ($field === 'impact') {
            $c = new Combination($idPA, null, $this->idShop);
            $c->price = $value;
            $ok = $c->update();
        } else {
            if ($value < 0) {
                throw new WrcmcpToolError('Rejected: the base price would become negative.');
            }
            $p = new Product($idP, false, null, $this->idShop);
            $p->price = $value;
            $ok = $p->update();
        }
        if (!$ok) {
            throw new WrcmcpToolError('The store could not save the new price.');
        }
        Product::flushPriceCache();
    }

    /** Remove the taxes of the product for the shop's default country from a tax-included amount. */
    private function net($idP, $amount)
    {
        $address = new Address();
        $address->id_country = (int) $this->context->country->id;
        $address->id_state = 0;
        $address->postcode = '0';
        $calculator = TaxManagerFactory::getManager($address, Product::getIdTaxRulesGroupByIdProduct($idP, $this->context))->getTaxCalculator();
        return (float) $calculator->removeTaxes((float) $amount);
    }

    private function updatePrice(array $a, $actor)
    {
        $this->assertWritable();
        list($idP, $idPA) = $this->resolve($a['sku'] ?? '');
        $target = isset($a['price']) && is_numeric($a['price']) ? (float) $a['price'] : 0.0;
        if (!is_finite($target) || $target <= 0) {
            throw new WrcmcpToolError('price must be a positive number');
        }
        $target = (float) Tools::ps_round($target, $this->precision);

        $current = $this->gross($idP, $idPA, true, $specific);
        if ($specific) {
            throw new WrcmcpToolError('Rejected: an active discount (specific price or catalog price rule) applies to this product; change it in PrestaShop.');
        }
        $max = max(0, WrcmcpSettings::get('max_change_pct'));
        if ($current > 0 && abs($target / $current - 1) * 100 > $max + 1e-9) {
            throw new WrcmcpToolError(sprintf(
                'Rejected: %s is more than %s%% away from the current price %s (limit set in the store).',
                $target, $max, $current
            ));
        }

        list($field, $old) = $this->storedValue($idP, $idPA);
        // Taxes are removed from the difference, so ecotax and the rest of the price stay as they are.
        $value = $old + $this->net($idP, $target) - $this->net($idP, $current);
        $this->writeStoredValue($idP, $idPA, $field, $value);
        $now = $this->gross($idP, $idPA, true);
        if (abs($now - $target) >= 0.5 / pow(10, $this->precision) && abs($now - $current) > 0) {
            // Another linear factor applies (e.g. a group reduction): one correction step makes it exact.
            $value += ($target - $now) * ($value - $old) / ($now - $current);
            $this->writeStoredValue($idP, $idPA, $field, $value);
            $now = $this->gross($idP, $idPA, true);
        }
        $reference = $idPA ? (new Combination($idPA))->reference : (new Product($idP))->reference;
        $sku = $this->skuOf($idP, $idPA, $reference);
        WrcmcpLog::add([
            'kind' => 'update', 'id_shop' => $this->idShop, 'id_product' => $idP, 'id_product_attribute' => $idPA,
            'sku' => $sku, 'old_price' => $current, 'new_price' => $now, 'field' => $field,
            'old_value' => $old, 'new_value' => round($value, 6), 'reason' => (string) ($a['reason'] ?? ''), 'actor' => $actor,
        ]);
        return ['sku' => $sku, 'oldPrice' => $current, 'newPrice' => $now];
    }

    private function revertPrice(array $a, $actor)
    {
        $this->assertWritable();
        list($idP, $idPA) = $this->resolve($a['sku'] ?? '');
        $last = WrcmcpLog::lastOpen($idP, $idPA, $this->idShop);
        if (!$last) {
            throw new WrcmcpToolError('No price change to revert for this sku.');
        }
        $before = $this->gross($idP, $idPA, true);
        list($field, $old) = $this->storedValue($idP, $idPA);
        $this->writeStoredValue($idP, $idPA, $last['field'], (float) $last['old_value']);
        WrcmcpLog::markReverted($last['id']);
        $now = $this->gross($idP, $idPA, true);
        WrcmcpLog::add([
            'kind' => 'revert', 'id_shop' => $this->idShop, 'id_product' => $idP, 'id_product_attribute' => $idPA,
            'sku' => $last['sku'], 'old_price' => $before, 'new_price' => $now, 'field' => $last['field'],
            'old_value' => $old, 'new_value' => (float) $last['old_value'],
            'reason' => 'revert: ' . (string) ($a['reason'] ?? ''), 'actor' => $actor,
        ]);
        return ['sku' => $last['sku'], 'price' => $now];
    }

    private function listPriceChanges(array $a)
    {
        $rows = WrcmcpLog::recent(trim((string) ($a['sku'] ?? '')), (int) ($a['limit'] ?? 50));
        $out = [];
        foreach ($rows as $r) {
            $at = new DateTime($r['at'], new DateTimeZone('UTC'));
            $out[] = [
                'at' => $at->format('c'),
                'sku' => $r['sku'],
                'oldPrice' => $r['old_price'] === null ? null : (float) $r['old_price'],
                'newPrice' => $r['new_price'] === null ? null : (float) $r['new_price'],
                'reason' => $r['reason'],
                'actor' => $r['actor'],
            ];
        }
        return ['changes' => $out];
    }
}
