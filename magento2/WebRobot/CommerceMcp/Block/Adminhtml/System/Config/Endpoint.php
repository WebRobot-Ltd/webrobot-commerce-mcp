<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Shows the MCP endpoint of the default store view (read-only, nothing is saved). */
class Endpoint extends Field
{
    public function __construct(Context $context, private readonly StoreManagerInterface $stores, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $store = $this->stores->getDefaultStoreView();
        $url = $store ? rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/') . '/webrobotmcp/mcp' : '';
        $html = '<code style="user-select:all">' . $this->escapeHtml($url) . '</code>';
        if (!str_starts_with($url, 'https://')) {
            $html .= '<p class="note" style="color:#e22626">' . $this->escapeHtml(__('The endpoint is not HTTPS: use it only for tests.')) . '</p>';
        }
        return $html;
    }

    protected function _isInheritCheckboxRequired(AbstractElement $element)
    {
        return false;
    }

    protected function _renderScopeLabel(AbstractElement $element)
    {
        return '';
    }
}
