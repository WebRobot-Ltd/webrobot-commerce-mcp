<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use WebRobot\CommerceMcp\Model\Config;

/**
 * Token status and a "Generate token" button. The button POSTs (with the admin form key) to
 * webrobotmcp/token/generate; it renders no input, so saving the section never touches the token.
 */
class TokenButton extends Field
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly SecureHtmlRenderer $secure,
        array $data = []
    ) {
        parent::__construct($context, $data, $secure);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $has = $this->config->hasToken();
        $id = 'wrcmcp-generate-token';
        $url = $this->getUrl('webrobotmcp/token/generate');
        $confirm = $has ? (string) __('This revokes the current token. Continue?') : '';
        $js = sprintf(
            "(function(){if(%s&&!window.confirm(%s)){return;}var f=document.createElement('form');f.method='post';f.action=%s;"
            . "var i=document.createElement('input');i.type='hidden';i.name='form_key';i.value=window.FORM_KEY;f.appendChild(i);"
            . "document.body.appendChild(f);f.submit();})()",
            $has ? 'true' : 'false',
            json_encode($confirm),
            json_encode($url)
        );
        $status = $has
            ? __('Set (hidden). Generating a new one revokes the current one.')
            : __('Not set yet.');
        return '<p>' . $this->escapeHtml($status) . '</p>'
            . '<button type="button" class="action-default scalable" id="' . $id . '"><span>'
            . $this->escapeHtml($has ? __('Generate a new token') : __('Generate token'))
            . '</span></button>'
            . $this->secure->renderEventListenerAsTag('onclick', $js, '#' . $id)
            . '<p class="note"><span>' . $this->escapeHtml(__('Or from the command line: bin/magento webrobot:mcp:token')) . '</span></p>';
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
