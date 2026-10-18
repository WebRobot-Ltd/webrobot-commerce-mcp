<?php
namespace Opencart\Catalog\Controller\Extension\WebrobotCommerceMcp\Module;
/**
 * MCP endpoint: POST index.php?route=extension/webrobot_commerce_mcp/module/mcp
 * Streamable HTTP, JSON-RPC 2.0, JSON responses (no SSE stream). See spec/CONTRACT.md.
 */
class Mcp extends \Opencart\System\Engine\Controller {
	public const PROTOCOL = '2025-06-18';
	public const VERSION = '0.1.0';

	public function index(): void {
		$this->load->model('extension/webrobot_commerce_mcp/module/mcp');

		$model = $this->model_extension_webrobot_commerce_mcp_module_mcp;

		if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
			$this->response->addHeader('Allow: POST');
			$this->send(405, ['error' => 'Method Not Allowed']);

			return;
		}

		if (!(int)$model->setting('status', 0)) {
			$this->send(503, ['error' => 'The Commerce MCP extension is disabled in the store admin.']);

			return;
		}

		if (!$this->authorized((string)$model->setting('token_hash', ''))) {
			$this->response->addHeader('WWW-Authenticate: Bearer');
			$this->send(401, ['error' => 'Unauthorized']);

			return;
		}

		$body = json_decode((string)file_get_contents('php://input'), true);

		if (!is_array($body)) {
			$this->send(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);

			return;
		}

		$actor = $this->header('X-Actor');
		$actor = $actor === '' ? 'mcp' : mb_substr(preg_replace('/[^\P{C}]/u', '', $actor) ?? 'mcp', 0, 191);

		$batch = $body !== [] && array_keys($body) === range(0, count($body) - 1);
		$out   = [];

		foreach ($batch ? $body : [$body] as $message) {
			$reply = is_array($message) ? $this->dispatch($message, $model, $actor) : null;

			if ($reply !== null) {
				$out[] = $reply;
			}
		}

		if (!$out) {
			// Only notifications (e.g. notifications/initialized): accepted, no body.
			http_response_code(202);
			$this->response->setOutput('');

			return;
		}

		$this->send(200, $batch ? $out : $out[0]);
	}

	private function dispatch(array $m, $model, string $actor): ?array {
		$id     = array_key_exists('id', $m) ? $m['id'] : null;
		$method = (string)($m['method'] ?? '');
		$params = is_array($m['params'] ?? null) ? $m['params'] : [];
		$ok     = fn ($result): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];

		switch ($method) {
			case 'initialize':
				return $ok([
					'protocolVersion' => self::PROTOCOL,
					'capabilities'    => ['tools' => ['listChanged' => false]],
					'serverInfo'      => ['name' => 'webrobot-commerce-mcp-opencart', 'version' => self::VERSION],
					'instructions'    => sprintf('OpenCart store catalog. Prices in %s, without tax (as OpenCart stores them). update_price changes the live price customers pay, within a %s%% limit set by the store.', $this->config->get('config_currency'), $model->maxChangePct()),
				]);
			case 'ping':
				return $ok(new \stdClass());
			case 'tools/list':
				return $ok(['tools' => $model->definitions()]);
			case 'tools/call':
				$name = (string)($params['name'] ?? '');
				$args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

				try {
					$res = $model->call($name, $args, $actor);

					return $ok([
						'content'           => [['type' => 'text', 'text' => json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
						'structuredContent' => $res,
						'isError'           => false,
					]);
				} catch (\Opencart\Catalog\Model\Extension\WebrobotCommerceMcp\Module\ToolError $e) {
					return $ok(['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
				}
			default:
				if ($id === null) {
					return null; // notification (e.g. notifications/initialized)
				}

				return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]];
		}
	}

	/** Bearer token; X-WebRobot-Token as a fallback for hosts that strip the Authorization header. */
	private function authorized(string $hash): bool {
		$token = '';

		if (preg_match('/^Bearer\s+(\S+)$/i', $this->header('Authorization'), $m)) {
			$token = $m[1];
		}

		if ($token === '') {
			$token = $this->header('X-WebRobot-Token');
		}

		return $hash !== '' && $token !== '' && hash_equals($hash, hash('sha256', $token));
	}

	/** A request header, read from the raw server variables (Apache/FPM put Authorization in different places). */
	private function header(string $name): string {
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

		foreach ([$key, 'REDIRECT_' . $key] as $k) {
			if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') {
				return trim((string)$_SERVER[$k]);
			}
		}

		if (function_exists('getallheaders')) {
			foreach ((array)getallheaders() as $k => $v) {
				if (strcasecmp((string)$k, $name) === 0) {
					return trim((string)$v);
				}
			}
		}

		return '';
	}

	private function send(int $status, $payload): void {
		http_response_code($status);

		$this->response->addHeader('Content-Type: application/json; charset=utf-8');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}
}
