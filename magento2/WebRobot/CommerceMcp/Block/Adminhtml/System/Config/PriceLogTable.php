<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use WebRobot\CommerceMcp\Model\PriceLog;

/** The last 50 price changes made through the MCP, full width. */
class PriceLogTable extends Field
{
    public function __construct(Context $context, private readonly PriceLog $log, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        $rows = $this->log->recent('', 50);
        $h = fn($v) => $this->escapeHtml((string) $v);
        $html = '<table class="data-grid" style="width:100%"><thead><tr>';
        foreach (['When (UTC)', 'SKU', 'Field', 'Old', 'New', 'Reason', 'By'] as $col) {
            $html .= '<th class="data-grid-th">' . $h(__($col)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        if (!$rows) {
            $html .= '<tr><td colspan="7">' . $h(__('No price changes yet.')) . '</td></tr>';
        }
        foreach ($rows as $r) {
            $html .= '<tr><td>' . $h($r['created_at']) . '</td><td>' . $h($r['sku']) . '</td><td>' . $h($r['field'])
                . '</td><td>' . $h($this->money($r['old_price'])) . '</td><td>' . $h($this->money($r['new_price']))
                . '</td><td>' . $h($r['reason']) . '</td><td>' . $h($r['actor']) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        return '<tr id="row_' . $h($element->getHtmlId()) . '"><td colspan="4">' . $html . '</td></tr>';
    }

    private function money($v): string
    {
        return $v === null ? '' : number_format((float) $v, 2, '.', '');
    }
}
