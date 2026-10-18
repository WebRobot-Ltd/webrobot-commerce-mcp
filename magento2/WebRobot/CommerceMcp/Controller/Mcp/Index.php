<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Controller\Mcp;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use WebRobot\CommerceMcp\Model\Config;
use WebRobot\CommerceMcp\Model\Server;

/**
 * POST /webrobotmcp/mcp — the MCP endpoint (Streamable HTTP, JSON-RPC 2.0, JSON responses).
 *
 * Authenticated by its own bearer token, so the storefront form-key CSRF check does not apply here.
 * GET is routed here only to answer 405.
 */
class Index implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly Server $server
    ) {
    }

    public function execute()
    {
        if (!$this->request->isPost()) {
            return $this->respond(405, ['error' => 'Method Not Allowed'])->setHeader('Allow', 'POST', true);
        }
        if (!$this->config->tokenMatches($this->presentedToken())) {
            return $this->respond(401, ['error' => 'Unauthorized'])->setHeader('WWW-Authenticate', 'Bearer', true);
        }
        $actor = trim(strip_tags((string) ($this->request->getHeader('X-Actor') ?: 'mcp')));
        [$status, $payload] = $this->server->handle((string) $this->request->getContent(), mb_substr($actor ?: 'mcp', 0, 191));
        return $this->respond($status, $payload);
    }

    /** Bearer token; X-WebRobot-Token as a fallback for hosts that strip the Authorization header. */
    private function presentedToken(): string
    {
        $auth = (string) ($this->request->getHeader('Authorization')
            ?: $this->request->getServer('HTTP_AUTHORIZATION')
            ?: $this->request->getServer('REDIRECT_HTTP_AUTHORIZATION'));
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        return trim((string) $this->request->getHeader('X-WebRobot-Token'));
    }

    private function respond(int $status, ?array $payload): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Cache-Control', 'no-store', true);
        if ($payload === null) {
            return $result->setContents('');
        }
        $result->setHeader('Content-Type', 'application/json', true);
        return $result->setContents(Server::json($payload));
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
