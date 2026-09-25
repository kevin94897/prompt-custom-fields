<?php
/**
 * Local JSON: guarda cada grupo/post type/taxonomía/options page como
 * {key}.json en /pcf-json del tema (versionable en git) y los carga como locales.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Local_JSON {

	/** @var array key => ['file' => ..., 'data' => ...] */
	public static $files = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'load' ), 4 );
		add_action( 'pcf/update_field_group', array( __CLASS__, 'save_item' ) );
		add_action( 'pcf/delete_field_group', array( __CLASS__, 'delete_item' ) );
		foreach ( array_keys( pcf_internal_kinds() ) as $kind ) {
			add_action( "pcf/update_{$kind}", array( __CLASS__, 'save_item' ) );
			add_action( "pcf/delete_{$kind}", array( __CLASS__, 'delete_item' ) );
		}
	}

	public static function enabled() {
		return (bool) pcf_get_setting( 'json' );
	}

	public static function save_path() {
		return untrailingslashit( pcf_get_setting( 'save_json' ) );
	}

	public static function load() {
		self::$files = array();
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( (array) pcf_get_setting( 'load_json' ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( glob( trailingslashit( $dir ) . '*.json' ) as $file ) {
				$data = pcf_json_decode( file_get_contents( $file ) ); // phpcs:ignore
				if ( ! $data || empty( $data['key'] ) ) {
					continue;
				}
				self::$files[ $data['key'] ] = array( 'file' => $file, 'data' => $data );
				if ( 0 === strpos( $data['key'], 'group_' ) ) {
					$data['local_file'] = $file;
					$data['modified']   = (int) pcf_maybe_get( $data, 'modified', filemtime( $file ) );
					pcf_add_local_field_group( $data, 'json' );
				}
			}
		}
	}

	/**
	 * Escribe el JSON de un elemento guardado en BD.
	 */
	public static function save_item( $item ) {
		if ( ! self::enabled() || empty( $item['key'] ) || ! apply_filters( 'pcf/json/save', true, $item ) ) {
			return;
		}
		$path = self::save_path();
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return;
		}
		$data = $item;
		unset( $data['ID'], $data['local'], $data['local_source'], $data['local_file'], $data['local_modified'] );
		$data['modified'] = isset( $item['modified'] ) ? (int) $item['modified'] : time();

		$file = trailingslashit( $path ) . sanitize_file_name( $item['key'] ) . '.json';
		file_put_contents( $file, pcf_json_encode( $data ) ); // phpcs:ignore
		self::$files[ $item['key'] ] = array( 'file' => $file, 'data' => $data );
	}

	public static function delete_item( $item ) {
		if ( ! self::enabled() || empty( $item['key'] ) ) {
			return;
		}
		$file = trailingslashit( self::save_path() ) . sanitize_file_name( $item['key'] ) . '.json';
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
		unset( self::$files[ $item['key'] ] );
	}

	/**
	 * Estado de sincronización de todos los JSON.
	 *
	 * @return array Lista con key, title, type, file, status (sync|new|synced|db_only).
	 */
	public static function status() {
		$out = array();
		foreach ( self::$files as $key => $info ) {
			$data  = $info['data'];
			$kind  = self::kind_for_key( $key );
			$db    = self::get_db_item( $kind, $key );
			$jsonm = (int) pcf_maybe_get( $data, 'modified', 0 );
			if ( ! $db ) {
				$status = 'new';
			} elseif ( $jsonm > (int) pcf_maybe_get( $db, 'modified', 0 ) ) {
				$status = 'sync';
			} else {
				$status = 'synced';
			}
			$out[] = array(
				'key'      => $key,
				'kind'     => $kind,
				'title'    => pcf_maybe_get( $data, 'title', $key ),
				'file'     => $info['file'],
				'status'   => $status,
				'modified' => $jsonm,
			);
		}
		foreach ( pcf_get_field_groups() as $g ) {
			if ( ! empty( $g['ID'] ) && ! isset( self::$files[ $g['key'] ] ) ) {
				$out[] = array( 'key' => $g['key'], 'kind' => 'field_group', 'title' => $g['title'], 'file' => '', 'status' => 'db_only' );
			}
		}
		return $out;
	}

	public static function kind_for_key( $key ) {
		if ( 0 === strpos( $key, 'group_' ) ) {
			return 'field_group';
		}
		if ( 0 === strpos( $key, 'post_type_' ) ) {
			return 'post_type';
		}
		if ( 0 === strpos( $key, 'taxonomy_' ) ) {
			return 'taxonomy';
		}
		if ( 0 === strpos( $key, 'ui_options_page_' ) ) {
			return 'options_page';
		}
		return 'unknown';
	}

	private static function get_db_item( $kind, $key ) {
		if ( 'field_group' === $kind ) {
			$g = pcf_get_field_group( $key );
			return ( $g && ! empty( $g['ID'] ) ) ? $g : null;
		}
		if ( 'unknown' === $kind ) {
			return null;
		}
		return pcf_get_internal_item( $kind, $key );
	}

	/**
	 * Importa JSON -> BD. $keys vacío = todos los pendientes (new|sync).
	 */
	public static function sync( $keys = array() ) {
		$done   = array();
		$errors = array();
		foreach ( self::status() as $row ) {
			if ( $keys && ! in_array( $row['key'], $keys, true ) ) {
				continue;
			}
			if ( ! $keys && ! in_array( $row['status'], array( 'new', 'sync' ), true ) ) {
				continue;
			}
			if ( empty( self::$files[ $row['key'] ] ) ) {
				continue;
			}
			$data = self::$files[ $row['key'] ]['data'];
			unset( $data['modified'], $data['ID'] );
			if ( 'field_group' === $row['kind'] ) {
				$res = pcf_update_field_group( $data );
			} elseif ( 'unknown' !== $row['kind'] ) {
				$res = pcf_update_internal_item( $row['kind'], $data );
			} else {
				continue;
			}
			if ( is_wp_error( $res ) ) {
				$errors[ $row['key'] ] = $res->get_error_message();
			} else {
				$done[] = $row['key'];
			}
		}
		return array( 'synced' => $done, 'errors' => $errors );
	}
}

PCF_Local_JSON::init();
