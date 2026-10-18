<?php
/**
 * WebRobot Commerce MCP for PrestaShop.
 *
 * Exposes the catalog as an MCP server (Model Context Protocol) so a price-intelligence agent can read
 * products and, within the limits set here, update prices. Contract: spec/CONTRACT.md.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/WrcmcpSettings.php';
require_once __DIR__ . '/classes/WrcmcpLog.php';
require_once __DIR__ . '/classes/WrcmcpTools.php';
require_once __DIR__ . '/classes/WrcmcpServer.php';

class WebrobotCommerceMcp extends Module
{
    public function __construct()
    {
        $this->name = 'webrobotcommercemcp';
        $this->tab = 'administration';
        $this->version = '0.1.0';
        $this->author = 'WebRobot';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'WebRobot Commerce MCP';
        $this->description = 'Exposes your catalog as an MCP server so a price-intelligence agent can read products and, within the limits you set, update prices.';
        $this->confirmUninstall = 'The price change log will be deleted. Continue?';
    }

    public function install()
    {
        return parent::install()
            && WrcmcpLog::install()
            && WrcmcpSettings::install();
    }

    public function uninstall()
    {
        return WrcmcpLog::uninstall()
            && WrcmcpSettings::uninstall()
            && parent::uninstall();
    }

    /** Generate a new token (revokes the previous one); returns it in clear — the only time it is visible. */
    public function regenerateToken()
    {
        return WrcmcpSettings::regenerateToken();
    }

    public function getEndpointUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'mcp', [], null, null, null, false);
    }

    /** Configuration page: endpoint, token, limits, audit log. */
    public function getContent()
    {
        $out = '';
        $newToken = null;

        if (Tools::isSubmit('wrcmcp_token')) {
            // Shown once, in this response only: only the hash is stored.
            $newToken = $this->regenerateToken();
        } elseif (Tools::isSubmit('wrcmcp_save')) {
            $pct = (float) str_replace(',', '.', (string) Tools::getValue('max_change_pct', 15));
            WrcmcpSettings::set('max_change_pct', max(0, min(100, $pct)));
            WrcmcpSettings::set('read_only', Tools::getValue('read_only') ? 1 : 0);
            $out .= $this->displayConfirmation('Settings saved.');
        }

        $endpoint = $this->getEndpointUrl();
        $legacy = $this->context->link->getBaseLink() . 'index.php?fc=module&module=' . $this->name . '&controller=mcp';
        $action = $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
        $hasToken = WrcmcpSettings::get('token_hash') !== '';
        $h = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };

        if ($newToken) {
            $out .= '<div class="alert alert-warning"><p><strong>Your new token — copy it now, it will not be shown again:</strong></p>'
                . '<p><code style="font-size:14px;user-select:all">' . $h($newToken) . '</code></p></div>';
        }

        $out .= '<div class="panel"><h3>Connection</h3>'
            . '<p>Connect this store to a price-intelligence agent: paste the endpoint and the token in the app.</p>'
            . '<table class="table"><tr><th style="width:220px">MCP endpoint</th><td><code style="user-select:all">' . $h($endpoint) . '</code>'
            . (strpos($endpoint, 'https://') !== 0 ? '<p class="text-danger">The endpoint is not HTTPS: use it only for tests.</p>' : '')
            . '<p class="help-block">Without friendly URLs: <code style="user-select:all">' . $h($legacy) . '</code></p></td></tr>'
            . '<tr><th>Token</th><td>' . ($hasToken ? 'Set (hidden). Generating a new one revokes the current one.' : '<strong>Not set yet.</strong>')
            . '<form method="post" action="' . $h($action) . '" style="margin-top:8px">'
            . '<button type="submit" name="wrcmcp_token" value="1" class="btn btn-default">' . ($hasToken ? 'Generate a new token' : 'Generate token') . '</button>'
            . '</form></td></tr></table></div>';

        $out .= '<div class="panel"><h3>Limits</h3><form method="post" action="' . $h($action) . '" class="form-horizontal">'
            . '<div class="form-group"><label class="control-label col-lg-3" for="max_change_pct">Maximum change per update (%)</label>'
            . '<div class="col-lg-3"><input id="max_change_pct" name="max_change_pct" type="number" min="0" max="100" step="0.5" value="' . $h(WrcmcpSettings::get('max_change_pct')) . '">'
            . '<p class="help-block">A price change larger than this is refused by the store, whatever the agent asks.</p></div></div>'
            . '<div class="form-group"><label class="control-label col-lg-3">Read-only</label><div class="col-lg-9"><label>'
            . '<input name="read_only" type="checkbox" value="1"' . (WrcmcpSettings::get('read_only') ? ' checked' : '') . '> Allow reading the catalog only (no price changes)</label></div></div>'
            . '<div class="panel-footer"><button type="submit" name="wrcmcp_save" value="1" class="btn btn-default pull-right"><i class="process-icon-save"></i> Save</button></div>'
            . '</form></div>';

        $rows = '';
        foreach (WrcmcpLog::recent('', 50) as $r) {
            $rows .= '<tr><td>' . $h($r['at']) . '</td><td>' . $h($r['sku']) . '</td><td>' . $h($r['old_price']) . '</td><td>'
                . $h($r['new_price']) . '</td><td>' . $h($r['reason']) . '</td><td>' . $h($r['actor']) . '</td></tr>';
        }
        $out .= '<div class="panel"><h3>Price changes (last 50)</h3><table class="table"><thead><tr><th>When (UTC)</th><th>SKU</th><th>Old</th><th>New</th><th>Reason</th><th>By</th></tr></thead><tbody>'
            . ($rows ?: '<tr><td colspan="6">No changes yet.</td></tr>') . '</tbody></table></div>';

        return $out;
    }
}
