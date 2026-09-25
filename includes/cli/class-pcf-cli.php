<?php
/**
 * Transporte 3: WP-CLI.
 *
 *   wp pcf operations [--format=table|json]
 *   wp pcf describe <operacion>
 *   wp pcf run <operacion> [--input=<json>|-] [--input-file=<ruta>] [--pretty] --user=admin
 *   wp pcf sync | status | export | import-acf | mcp-config
 *
 * El servidor MCP en Node usa "wp pcf operations --format=json" y "wp pcf run".
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_CLI {

	/**
	 * Lista las operaciones disponibles.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table o json (json incluye los JSON Schema).
	 * ---
	 * default: table
	 * ---
	 */
	public function operations( $args, $assoc ) {
		$ops = array_map( array( 'PCF_Operations', 'describe' ), array_values( PCF_Operations::all() ) );
		if ( 'json' === $assoc['format'] ) {
			WP_CLI::line( wp_json_encode( array( 'plugin' => 'prompt-custom-fields', 'version' => PCF_VERSION, 'site' => home_url(), 'operations' => $ops ) ) );
			return;
		}
		$rows = array();
		foreach ( $ops as $op ) {
			$rows[] = array(
				'name'     => $op['name'],
				'group'    => $op['group'],
				'readonly' => $op['annotations']['readOnlyHint'] ? 'sí' : '',
				'label'    => $op['label'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'group', 'readonly', 'label' ) );
	}

	/**
	 * Describe una operación y su esquema de entrada.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Nombre de la operación.
	 */
	public function describe( $args ) {
		$op = PCF_Operations::get( $args[0] );
		if ( ! $op ) {
			WP_CLI::error( 'Operación desconocida.' );
		}
		WP_CLI::line( pcf_json_encode( PCF_Operations::describe( $op ) ) );
	}

	/**
	 * Ejecuta una operación.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Nombre de la operación (ver `wp pcf operations`).
	 *
	 * [--input=<json>]
	 * : Entrada JSON. Usa "-" para leer de STDIN.
	 *
	 * [--input-file=<path>]
	 * : Archivo JSON con la entrada.
	 *
	 * [--pretty]
	 * : JSON indentado.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pcf run list-field-groups --user=admin
	 *     wp pcf run create-field-group --input='{"title":"Hero","location":"post_type:page","fields":[{"label":"Título","type":"text"}]}' --user=admin
	 *     echo '{"object":2,"values":{"titulo":"Hola"}}' | wp pcf run update-values --input=- --user=admin
	 */
	public function run( $args, $assoc ) {
		$raw = '{}';
		if ( ! empty( $assoc['input-file'] ) ) {
			if ( ! is_readable( $assoc['input-file'] ) ) {
				$this->fail( 'pcf_input_file', 'No se puede leer ' . $assoc['input-file'] );
			}
			$raw = file_get_contents( $assoc['input-file'] ); // phpcs:ignore
		} elseif ( isset( $assoc['input'] ) ) {
			$raw = '-' === $assoc['input'] ? stream_get_contents( STDIN ) : $assoc['input'];
		}
		$input = json_decode( (string) $raw, true );
		if ( ! is_array( $input ) ) {
			$this->fail( 'pcf_invalid_json', 'La entrada no es un objeto JSON válido.' );
		}
		if ( ! get_current_user_id() ) {
			WP_CLI::warning( 'Sin --user: se ejecuta sin permisos. Usa --user=admin.' );
		}
		$result = PCF_Operations::execute( $args[0], $input );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_code(), implode( ' ', $result->get_error_messages() ), $result->get_error_data() );
		}
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ( isset( $assoc['pretty'] ) ? JSON_PRETTY_PRINT : 0 );
		WP_CLI::line( wp_json_encode( array( 'ok' => true, 'result' => $result ), $flags ) );
	}

	protected function fail( $code, $message, $data = null ) {
		WP_CLI::line( wp_json_encode( array( 'ok' => false, 'error' => array( 'code' => $code, 'message' => $message, 'data' => $data ) ), JSON_UNESCAPED_UNICODE ) );
		WP_CLI::halt( 1 );
	}

	/**
	 * Estado de sincronización de Local JSON.
	 *
	 * @subcommand status
	 */
	public function status() {
		$items = PCF_Local_JSON::status();
		if ( ! $items ) {
			WP_CLI::line( 'No hay JSON locales ni grupos en BD.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'key', 'kind', 'title', 'status' ) );
	}

	/**
	 * Importa a BD los JSON locales pendientes.
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Keys concretas.
	 */
	public function sync( $args ) {
		$res = PCF_Local_JSON::sync( $args );
		foreach ( $res['errors'] as $k => $e ) {
			WP_CLI::warning( "$k: $e" );
		}
		WP_CLI::success( sprintf( '%d elemento(s) sincronizado(s).', count( $res['synced'] ) ) );
	}

	/**
	 * Exporta definiciones.
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Keys (vacío = todo).
	 *
	 * [--format=<format>]
	 * : json o php.
	 * ---
	 * default: json
	 * ---
	 *
	 * [--acf]
	 * : En PHP, usar nombres de función de ACF.
	 */
	public function export( $args, $assoc ) {
		if ( 'php' === $assoc['format'] ) {
			WP_CLI::line( PCF_Exporter::to_php( $args, isset( $assoc['acf'] ) ) );
			return;
		}
		WP_CLI::line( PCF_Exporter::to_json( $args ) );
	}

	/**
	 * Importa definiciones de ACF.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : database, acf_api, acf_json o json.
	 * ---
	 * default: database
	 * ---
	 *
	 * [--file=<path>]
	 * : Archivo JSON exportado desde ACF (implica source=json).
	 *
	 * [--path=<dir>]
	 * : Carpeta acf-json.
	 *
	 * [--overwrite]
	 * : Sobrescribir existentes.
	 *
	 * [--dry-run]
	 * : Sólo mostrar qué se importaría.
	 *
	 * [--deactivate-acf]
	 * : Desactivar los grupos originales de ACF.
	 *
	 * @subcommand import-acf
	 */
	public function import_acf( $args, $assoc ) {
		$in = array(
			'source'         => $assoc['source'],
			'path'           => pcf_maybe_get( $assoc, 'path', '' ),
			'overwrite'      => isset( $assoc['overwrite'] ),
			'dry_run'        => isset( $assoc['dry-run'] ),
			'deactivate_acf' => isset( $assoc['deactivate-acf'] ),
		);
		if ( ! empty( $assoc['file'] ) ) {
			$in['source'] = 'json';
			$in['json']   = file_get_contents( $assoc['file'] ); // phpcs:ignore
		}
		$importer = new PCF_ACF_Importer();
		$res      = $importer->run( $in );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		if ( $res['imported'] ) {
			WP_CLI\Utils\format_items( 'table', $res['imported'], array( 'kind', 'key', 'title' ) );
		}
		foreach ( $res['skipped'] as $s ) {
			WP_CLI::log( "Omitido {$s['key']}: {$s['reason']}" );
		}
		foreach ( $res['warnings'] as $w ) {
			WP_CLI::warning( $w );
		}
		foreach ( $res['errors'] as $e ) {
			WP_CLI::warning( "{$e['key']}: {$e['error']}" );
		}
		$msg = sprintf( '%d elemento(s) %s.', count( $res['imported'] ), $res['dry_run'] ? 'se importarían' : 'importados' );
		WP_CLI::success( $msg );
	}

	/**
	 * Imprime la configuración MCP para Claude Desktop / Claude Code / Cursor.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : adapter-stdio, adapter-http, node-rest o node-cli.
	 * ---
	 * default: all
	 * ---
	 *
	 * [--user=<user>]
	 * : (global) usuario a usar en los ejemplos.
	 *
	 * @subcommand mcp-config
	 */
	public function mcp_config( $args, $assoc ) {
		$configs = PCF_MCP_Config::all( wp_get_current_user()->user_login ? wp_get_current_user()->user_login : 'admin' );
		foreach ( $configs as $id => $c ) {
			if ( 'all' !== $assoc['mode'] && $assoc['mode'] !== $id ) {
				continue;
			}
			WP_CLI::line( WP_CLI::colorize( "%G# {$c['title']}%n" ) );
			WP_CLI::line( $c['help'] );
			WP_CLI::line( pcf_json_encode( $c['config'] ) );
			WP_CLI::line( '' );
		}
	}
}

WP_CLI::add_command( 'pcf', 'PCF_CLI' );
