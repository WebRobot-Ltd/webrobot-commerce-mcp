<?php
/**
 * MCP transport: Streamable HTTP, POST only, JSON-RPC 2.0, JSON responses (no SSE).
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class WrcmcpServer
{
    const PROTOCOL = '2025-06-18';
    const VERSION = '0.1.0';

    /** @var Context */
    private $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function serve()
    {
        // Anything a module or the shop printed before us must not corrupt the JSON.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            header('Allow: POST');
            $this->send(405, ['error' => 'Method Not Allowed']);
            return;
        }
        if (!WrcmcpSettings::tokenMatches($this->presentedToken())) {
            header('WWW-Authenticate: Bearer');
            $this->send(401, ['error' => 'Unauthorized']);
            return;
        }

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body) || !$body) {
            $this->send(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
            return;
        }
        $actor = trim(strip_tags((string) $this->header('X-Actor'))) ?: 'mcp';
        $batch = array_keys($body) === range(0, count($body) - 1);
        $msgs = $batch ? $body : [$body];
        $out = [];
        foreach ($msgs as $m) {
            $r = is_array($m) ? $this->dispatch($m, $actor) : null;
            if ($r !== null) {
                $out[] = $r;
            }
        }
        if (!$out) {
            $this->send(202, null);
            return;
        }
        $this->send(200, $batch ? $out : $out[0]);
    }

    /** Bearer token; X-WebRobot-Token as a fallback for hosts that strip the Authorization header. */
    private function presentedToken()
    {
        $auth = (string) $this->header('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        return trim((string) $this->header('X-WebRobot-Token'));
    }

    private function header($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        if ($name === 'Authorization' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return $v;
                }
            }
        }
        return null;
    }

    private function send($status, $payload)
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        if ($payload === null) {
            header('Content-Length: 0');
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function dispatch(array $m, $actor)
    {
        $id = array_key_exists('id', $m) ? $m['id'] : null;
        $method = (string) ($m['method'] ?? '');
        $params = is_array($m['params'] ?? null) ? $m['params'] : [];
        $ok = function ($result) use ($id) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        };
        switch ($method) {
            case 'initialize':
                return $ok([
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'webrobot-commerce-mcp-prestashop', 'version' => self::VERSION],
                    'instructions' => sprintf(
                        'PrestaShop store catalog. Prices in %s, tax included. update_price changes the live price customers pay, within a %s%% limit set by the store.',
                        $this->context->currency->iso_code,
                        WrcmcpSettings::get('max_change_pct')
                    ),
                ]);
            case 'ping':
                return $ok(new stdClass());
            case 'tools/list':
                return $ok(['tools' => WrcmcpTools::definitions()]);
            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                try {
                    $tools = new WrcmcpTools($this->context);
                    $res = $tools->call($name, $args, $actor);
                    return $ok([
                        'content' => [['type' => 'text', 'text' => json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)]],
                        'structuredContent' => $res,
                        'isError' => false,
                    ]);
                } catch (WrcmcpToolError $e) {
                    return $ok(['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
                }
            default:
                if ($id === null) {
                    return null; // notification (e.g. notifications/initialized)
                }
                return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: $method"]];
        }
    }
}
