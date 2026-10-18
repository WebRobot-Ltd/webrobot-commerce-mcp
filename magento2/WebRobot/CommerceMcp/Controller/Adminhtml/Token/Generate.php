<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Controller\Adminhtml\Token;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use WebRobot\CommerceMcp\Model\Config;

/**
 * Generate a new MCP token (revokes the previous one). Shown once, to this admin user, then gone: only
 * its hash is stored.
 */
class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'WebRobot_CommerceMcp::config';

    public function __construct(Context $context, private readonly Config $config)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $token = $this->config->regenerateToken();
        $this->messageManager->addWarningMessage(
            __('Your new token — copy it now, it will not be shown again: %1', $token)
        );
        return $this->resultRedirectFactory->create()
            ->setPath('adminhtml/system_config/edit', ['section' => 'webrobot_commercemcp']);
    }
}
