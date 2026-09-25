<?php
/**
 * Genera configuraciones listas para copiar en Claude Desktop, Claude Code, Cursor, etc.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_MCP_Config {

	public static function node_server_path() {
		return wp_normalize_path( PCF_PATH . 'mcp-server/dist/pcf-mcp-server.mjs' );
	}

	public static function env_wrapper_path() {
		return wp_normalize_path( PCF_PATH . 'mcp-server/wp-env.js' );
	}

	public static function wp_path() {
		return untrailingslashit( wp_normalize_path( ABSPATH ) );
	}

	/**
	 * @return array id => [title, help, config, claude_code]
	 */
	public static function all( $user = 'admin' ) {
		$site  = home_url();
		$envf  = wp_normalize_path( PCF_PATH . 'mcp-server/.local-env.json' );
		$out   = array();

		$out['node-rest'] = array(
			'title'       => 'A · Servidor Node + REST (recomendado para Local)',
			'help'        => 'Requiere Node 18+ y una Application Password (Usuarios → Perfil → Contraseñas de aplicación). El servidor viene empaquetado: no hace falta npm install. Si el sitio usa HTTPS con el certificado de Local, añade PCF_INSECURE_TLS=1.',
			'config'      => array(
				'mcpServers' => array(
					'pcf' => array(
						'command' => 'node',
						'args'    => array( self::node_server_path() ),
						'env'     => array(
							'PCF_BACKEND'     => 'rest',
							'WP_URL'          => $site,
							'WP_USER'         => $user,
							'WP_APP_PASSWORD' => 'xxxx xxxx xxxx xxxx xxxx xxxx',
						),
					),
				),
			),
			'claude_code' => sprintf( 'claude mcp add pcf -e PCF_BACKEND=rest -e WP_URL=%s -e WP_USER=%s -e "WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx" -- node "%s"', $site, $user, self::node_server_path() ),
		);

		$out['node-cli'] = array(
			'title'       => 'B · Servidor Node + WP-CLI (sin contraseñas, usa el shell de Local)',
			'help'        => 'En Local: clic derecho en el sitio → "Open site shell" y ejecuta: node "' . wp_normalize_path( PCF_PATH . 'mcp-server/capture-env.js' ) . '". Eso guarda PATH/PHPRC/MYSQL_HOME de Local en ' . $envf . ' para que wp funcione fuera de ese shell.',
			'config'      => array(
				'mcpServers' => array(
					'pcf' => array(
						'command' => 'node',
						'args'    => array( self::node_server_path() ),
						'env'     => array(
							'PCF_BACKEND' => 'cli',
							'WP_PATH'     => self::wp_path(),
							'WP_USER'     => $user,
						),
					),
				),
			),
			'claude_code' => sprintf( 'claude mcp add pcf -e PCF_BACKEND=cli -e "WP_PATH=%s" -e WP_USER=%s -- node "%s"', self::wp_path(), $user, self::node_server_path() ),
		);

		$out['adapter-http'] = array(
			'title'       => 'C · MCP Adapter oficial por HTTP',
			'help'        => 'Requiere el plugin MCP Adapter (github.com/WordPress/mcp-adapter) activo. Endpoint propio de PCF: cada operación es una tool directa. Alternativa: /wp-json/mcp/mcp-adapter-default-server (discover/execute).',
			'config'      => array(
				'mcpServers' => array(
					'pcf' => array(
						'command' => 'npx',
						'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
						'env'     => array(
							'WP_API_URL'      => rest_url( 'mcp/' . PCF_Abilities::ROUTE ),
							'WP_API_USERNAME' => $user,
							'WP_API_PASSWORD' => 'xxxx xxxx xxxx xxxx xxxx xxxx',
						),
					),
				),
			),
			'claude_code' => sprintf( 'claude mcp add pcf -e WP_API_URL=%s -e WP_API_USERNAME=%s -e "WP_API_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx" -- npx -y @automattic/mcp-wordpress-remote@latest', rest_url( 'mcp/' . PCF_Abilities::ROUTE ), $user ),
		);

		$out['adapter-stdio'] = array(
			'title'       => 'D · MCP Adapter oficial por STDIO (WP-CLI)',
			'help'        => 'Requiere el plugin MCP Adapter. wp-env.js carga el entorno de Local capturado con capture-env.js (ver modo B) y lanza WP-CLI.',
			'config'      => array(
				'mcpServers' => array(
					'pcf' => array(
						'command' => 'node',
						'args'    => array( self::env_wrapper_path(), 'wp', '--path=' . self::wp_path(), 'mcp-adapter', 'serve', '--server=' . PCF_Abilities::SERVER_ID, '--user=' . $user ),
					),
				),
			),
			'claude_code' => sprintf( 'claude mcp add pcf -- node "%s" wp "--path=%s" mcp-adapter serve --server=%s --user=%s', self::env_wrapper_path(), self::wp_path(), PCF_Abilities::SERVER_ID, $user ),
		);

		return apply_filters( 'pcf/mcp/client_configs', $out, $user );
	}
}
