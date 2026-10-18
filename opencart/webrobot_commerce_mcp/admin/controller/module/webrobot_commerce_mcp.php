<?php
namespace Opencart\Admin\Controller\Extension\WebrobotCommerceMcp\Module;
/**
 * Extensions → Modules → WebRobot Commerce MCP: endpoint, token, limits, audit log.
 */
class WebrobotCommerceMcp extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/webrobot_commerce_mcp/module/webrobot_commerce_mcp';
	private const PREFIX = 'module_webrobot_commerce_mcp_';

	public function index(): void {
		$this->load->language(self::ROUTE);
		$this->load->model(self::ROUTE);

		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=module')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link(self::ROUTE, $token)],
		];

		$data['save']  = $this->url->link(self::ROUTE . '.save', $token);
		$data['token'] = $this->url->link(self::ROUTE . '.token', $token);
		$data['back']  = $this->url->link('marketplace/extension', $token . '&type=module');

		$settings = $this->model_extension_webrobot_commerce_mcp_module_webrobot_commerce_mcp->getSettings();

		$data['status']         = (int)$settings[self::PREFIX . 'status'];
		$data['max_change_pct'] = (float)$settings[self::PREFIX . 'max_change_pct'];
		$data['read_only']      = (int)$settings[self::PREFIX . 'read_only'];
		$data['token_set']      = $settings[self::PREFIX . 'token_hash'] !== '';

		$data['endpoint'] = HTTP_CATALOG . 'index.php?route=extension/webrobot_commerce_mcp/module/mcp';
		$data['https']    = strpos($data['endpoint'], 'https://') === 0;

		$data['changes'] = $this->model_extension_webrobot_commerce_mcp_module_webrobot_commerce_mcp->getChanges(50);

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	public function save(): void {
		$this->load->language(self::ROUTE);

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$max = $this->request->post['max_change_pct'] ?? '15';

		if (!is_numeric($max) || (float)$max < 0 || (float)$max > 100) {
			$json['error']['max_change_pct'] = $this->language->get('error_max_change_pct');
		}

		if (!$json) {
			$this->load->model(self::ROUTE);

			$this->model_extension_webrobot_commerce_mcp_module_webrobot_commerce_mcp->editSettings([
				self::PREFIX . 'status'         => !empty($this->request->post['status']) ? 1 : 0,
				self::PREFIX . 'max_change_pct' => (float)$max,
				self::PREFIX . 'read_only'      => !empty($this->request->post['read_only']) ? 1 : 0,
			]);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/** Generates a new token; the clear value goes back to this response only, the store keeps its hash. */
	public function token(): void {
		$this->load->language(self::ROUTE);

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->load->model(self::ROUTE);

			$json['new_token'] = $this->model_extension_webrobot_commerce_mcp_module_webrobot_commerce_mcp->regenerateToken();
			$json['success']   = $this->language->get('text_token_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput(json_encode($json));
	}

	/** Called by Extensions → Modules → Install. */
	public function install(): void {
		if ($this->user->hasPermission('modify', 'extension/module')) {
			$this->load->model(self::ROUTE);

			$this->model_extension_webrobot_commerce_mcp_module_webrobot_commerce_mcp->install();
		}
	}

	/** Called by Extensions → Modules → Uninstall. */
	public function uninstall(): void {
		if ($this->user->hasPermission('modify', 'extension/module')) {
			$this->load->model(self::ROUTE);

			$this->model_extension_webrobot_commerce_mcp_module_webrobot_commerce_mcp->uninstall();
		}
	}
}
