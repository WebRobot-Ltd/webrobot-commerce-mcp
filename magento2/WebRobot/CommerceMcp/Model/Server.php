<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Model;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * MCP over Streamable HTTP: JSON-RPC 2.0 in, JSON out (no SSE stream).
 */
class Server
{
    public const PROTOCOL = '2025-06-18';
    public const VERSION = '0.1.0';

    public function __construct(
        private readonly Tools $tools,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{0:int,1:array|null} HTTP status and JSON payload (null → empty body)
     */
    public function handle(string $raw, string $actor): array
    {
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return [400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]];
        }
        $batch = $body !== [] && array_is_list($body);
        $out = [];
        foreach ($batch ? $body : [$body] as $m) {
            $r = is_array($m) ? $this->dispatch($m, $actor) : null;
            if ($r !== null) {
                $out[] = $r;
            }
        }
        if (!$out) {
            return [202, null];
        }
        return [200, $batch ? $out : $out[0]];
    }

    private function dispatch(array $m, string $actor): ?array
    {
        $id = array_key_exists('id', $m) ? $m['id'] : null;
        $method = (string) ($m['method'] ?? '');
        $params = is_array($m['params'] ?? null) ? $m['params'] : [];
        $ok = static fn($result) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];

        switch ($method) {
            case 'initialize':
                return $ok([
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'webrobot-commerce-mcp-magento2', 'version' => self::VERSION],
                    'instructions' => sprintf(
                        'Magento store catalog. Prices in %s. update_price changes the live price customers pay, within a %s%% limit set by the store.',
                        $this->tools->currency(),
                        $this->config->maxChangePct()
                    ),
                ]);
            case 'ping':
                return $ok(new \stdClass());
            case 'tools/list':
                return $ok(['tools' => $this->tools->definitions()]);
            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                try {
                    $res = $this->tools->call($name, $args, $actor);
                    return $ok([
                        'content' => [['type' => 'text', 'text' => self::json($res)]],
                        'structuredContent' => $res,
                        'isError' => false,
                    ]);
                } catch (ToolError | LocalizedException $e) {
                    return $ok(['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
                } catch (\Throwable $e) {
                    $this->logger->error('WebRobot Commerce MCP: ' . $name . ' failed', ['exception' => $e]);
                    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Internal error']];
                }
            default:
                if ($id === null) {
                    return null; // notification (e.g. notifications/initialized)
                }
                return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "Method not found: $method"]];
        }
    }

    public static function json($value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
