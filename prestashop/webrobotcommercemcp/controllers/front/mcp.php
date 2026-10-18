<?php
/**
 * MCP endpoint: index.php?fc=module&module=webrobotcommercemcp&controller=mcp
 * (friendly URL: /module/webrobotcommercemcp/mcp). Streamable HTTP, JSON-RPC 2.0, JSON responses.
 *
 * Authentication is the MCP token (Bearer), not the customer session: no cookie, no CSRF token, no
 * theme output.
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class WebrobotCommerceMcpMcpModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $auth = false;
    public $guestAllowed = true;
    public $display_header = false;
    public $display_footer = false;
    public $content_only = true;

    public function init()
    {
        parent::init();
        $this->context->cookie->disallowWriting();
        // Prices in the shop's default currency, taxes of the shop's default country, names in the
        // default language: what an anonymous visitor of the shop sees, whatever the request headers say.
        $this->context->currency = Currency::getCurrencyInstance((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $this->context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        $this->context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
    }

    /** A token-authenticated API: it answers during maintenance like the PrestaShop webservice. */
    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    public function postProcess()
    {
        $server = new WrcmcpServer($this->context);
        $server->serve();
        exit;
    }

    public function initContent()
    {
    }

    public function display()
    {
        return true;
    }
}
