<?php
defined( 'ABSPATH' ) || exit;

/**
 * MCP endpoint: POST /wp-json/webrobot-mcp/v1/mcp — Streamable HTTP, JSON-RPC 2.0, JSON responses.
 */
class WRCMCP_Server {

	const PROTOCOL = '2025-06-18';

	public static function init() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'webrobot-mcp/v1', '/mcp', array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'authorize' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => function () {
						return new WP_REST_Response( array( 'error' => 'Method Not Allowed' ), 405, array( 'Allow' => 'POST' ) );
					},
					'permission_callback' => '__return_true',
				),
			) );
		} );
	}

	/** Bearer token; X-WebRobot-Token as a fallback for hosts that strip the Authorization header. */
	public static function authorize( WP_REST_Request $req ) {
		$auth  = (string) $req->get_header( 'authorization' );
		$token = preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ? $m[1] : (string) $req->get_header( 'x_webrobot_token' );
		if ( WRCMCP_Settings::token_matches( $token ) ) {
			return true;
		}
		return new WP_Error( 'wrcmcp_unauthorized', 'Unauthorized', array( 'status' => 401 ) );
	}

	public static function handle( WP_REST_Request $req ) {
		$body = json_decode( $req->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32700, 'message' => 'Parse error' ) ), 400 );
		}
		$actor = sanitize_text_field( (string) ( $req->get_header( 'x_actor' ) ?: 'mcp' ) );
		$batch = array_keys( $body ) === range( 0, count( $body ) - 1 );
		$msgs  = $batch ? $body : array( $body );
		$out   = array();
		foreach ( $msgs as $m ) {
			$r = is_array( $m ) ? self::dispatch( $m, $actor ) : null;
			if ( null !== $r ) {
				$out[] = $r;
			}
		}
		if ( ! $out ) {
			return new WP_REST_Response( null, 202 );
		}
		return new WP_REST_Response( $batch ? $out : $out[0], 200 );
	}

	private static function dispatch( array $m, $actor ) {
		$id     = array_key_exists( 'id', $m ) ? $m['id'] : null;
		$method = (string) ( $m['method'] ?? '' );
		$params = is_array( $m['params'] ?? null ) ? $m['params'] : array();
		$ok     = function ( $result ) use ( $id ) {
			return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
		};
		switch ( $method ) {
			case 'initialize':
				return $ok( array(
					'protocolVersion' => self::PROTOCOL,
					'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
					'serverInfo'      => array( 'name' => 'webrobot-commerce-mcp-woocommerce', 'version' => WRCMCP_VERSION ),
					'instructions'    => sprintf( 'WooCommerce store catalog. Prices in %s. update_price changes the live price customers pay, within a %s%% limit set by the store.',
						get_woocommerce_currency(), WRCMCP_Settings::get( 'max_change_pct' ) ),
				) );
			case 'ping':
				return $ok( new stdClass() );
			case 'tools/list':
				return $ok( array( 'tools' => WRCMCP_Tools::definitions() ) );
			case 'tools/call':
				$name = (string) ( $params['name'] ?? '' );
				$args = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();
				try {
					$res = WRCMCP_Tools::call( $name, $args, $actor );
					return $ok( array(
						'content'           => array( array( 'type' => 'text', 'text' => wp_json_encode( $res ) ) ),
						'structuredContent' => $res,
						'isError'           => false,
					) );
				} catch ( WRCMCP_Tool_Error $e ) {
					return $ok( array( 'content' => array( array( 'type' => 'text', 'text' => $e->getMessage() ) ), 'isError' => true ) );
				}
			default:
				if ( null === $id ) {
					return null; // notification (e.g. notifications/initialized)
				}
				return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => -32601, 'message' => "Method not found: $method" ) );
		}
	}
}
