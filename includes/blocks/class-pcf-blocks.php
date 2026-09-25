<?php
/**
 * Bloques con campos (equivalente a ACF Blocks v2/v3).
 *
 * Fuentes:
 *  - Carpetas con block.json que tengan la clave "pcf" (o "acf", para bloques
 *    heredados de ACF) dentro de /blocks del tema (ajuste blocks_dirs).
 *  - pcf_register_block_type() / acf_register_block_type().
 *
 * Los datos se guardan en el atributo "data" con el mismo formato que ACF
 * ({"nombre": valor, "_nombre": "field_…"}), así el contenido de ACF sigue funcionando.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Blocks {

	/** @var array name => settings */
	public static $blocks = array();

	/** @var bool Si ya se ejecutó register_all. */
	public static $ran = false;

	/** @var array Bloques registrados por PHP antes de init. */
	public static $queue = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_all' ), 7 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
	}

	public static function get_blocks() {
		return self::$blocks;
	}

	/**
	 * Carpetas de bloques con block.json.
	 */
	public static function scan_dirs() {
		$found = array();
		foreach ( (array) pcf_get_setting( 'blocks_dirs' ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( glob( trailingslashit( $dir ) . '*/block.json' ) as $json ) {
				$data = pcf_json_decode( file_get_contents( $json ) ); // phpcs:ignore
				if ( ! $data || empty( $data['name'] ) ) {
					continue;
				}
				$conf = isset( $data['pcf'] ) ? $data['pcf'] : ( isset( $data['acf'] ) && ! class_exists( 'ACF' ) ? $data['acf'] : null );
				if ( null === $conf ) {
					continue;
				}
				$found[ $data['name'] ] = array( 'dir' => dirname( $json ), 'json' => $data, 'conf' => (array) $conf );
			}
		}
		return $found;
	}

	public static function register_all() {
		foreach ( self::scan_dirs() as $name => $info ) {
			if ( WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}
			$conf     = $info['conf'];
			$template = pcf_maybe_get( $conf, 'renderTemplate', 'render.php' );
			$settings = array(
				'name'            => $name,
				'title'           => pcf_maybe_get( $info['json'], 'title', $name ),
				'description'     => pcf_maybe_get( $info['json'], 'description', '' ),
				'mode'            => pcf_maybe_get( $conf, 'mode', 'preview' ),
				'render_template' => path_is_absolute( $template ) ? $template : trailingslashit( $info['dir'] ) . $template,
				'render_callback' => pcf_maybe_get( $conf, 'renderCallback' ),
				'dir'             => $info['dir'],
				'jsx'             => ! empty( $info['json']['supports']['jsx'] ),
				'source'          => 'block.json',
			);
			$args     = array(
				'render_callback'       => function ( $attributes, $content = '', $wp_block = null ) use ( $name ) {
					return PCF_Blocks::render( $name, $attributes, $content, $wp_block );
				},
				'attributes'            => self::attributes( $settings ),
				'editor_script_handles' => array( 'pcf-blocks' ),
			);
			$type = register_block_type( $info['dir'], $args );
			if ( $type ) {
				self::$blocks[ $name ] = $settings;
			}
		}

		self::$ran = true;
		foreach ( self::$queue as $block ) {
			self::register_php_block( $block );
		}
		self::$queue = array();
	}

	protected static function attributes( $settings ) {
		return array(
			'data'      => array( 'type' => 'object', 'default' => array() ),
			'mode'      => array( 'type' => 'string', 'default' => $settings['mode'] ),
			'name'      => array( 'type' => 'string', 'default' => '' ),
			'align'     => array( 'type' => 'string', 'default' => '' ),
			'className' => array( 'type' => 'string', 'default' => '' ),
			'anchor'    => array( 'type' => 'string', 'default' => '' ),
		);
	}

	/**
	 * Registro por PHP (API v1 de ACF).
	 */
	public static function register_php_block( $block ) {
		$block = wp_parse_args(
			$block,
			array(
				'name'            => '',
				'title'           => '',
				'description'     => '',
				'category'        => 'common',
				'icon'            => 'block-default',
				'mode'            => 'preview',
				'keywords'        => array(),
				'supports'        => array(),
				'post_types'      => array(),
				'render_template' => '',
				'render_callback' => '',
				'enqueue_style'   => '',
				'enqueue_script'  => '',
				'example'         => array(),
				'namespace'       => 'pcf',
			)
		);
		if ( ! $block['name'] ) {
			return false;
		}
		$name = false === strpos( $block['name'], '/' ) ? $block['namespace'] . '/' . sanitize_title( $block['name'] ) : $block['name'];

		if ( $block['render_template'] && ! path_is_absolute( $block['render_template'] ) ) {
			$block['render_template'] = locate_template( $block['render_template'] );
		}
		$settings = array(
			'name'            => $name,
			'title'           => $block['title'],
			'description'     => $block['description'],
			'mode'            => $block['mode'],
			'render_template' => $block['render_template'],
			'render_callback' => $block['render_callback'],
			'jsx'             => ! empty( $block['supports']['jsx'] ),
			'enqueue_style'   => $block['enqueue_style'],
			'enqueue_script'  => $block['enqueue_script'],
			'source'          => 'php',
		);
		$category_ok = in_array( $block['category'], wp_list_pluck( get_default_block_categories(), 'slug' ), true );
		$type        = register_block_type(
			$name,
			array(
				'title'                 => $block['title'],
				'description'           => $block['description'],
				'category'              => $category_ok ? $block['category'] : 'widgets',
				'icon'                  => is_string( $block['icon'] ) ? $block['icon'] : 'block-default',
				'keywords'              => (array) $block['keywords'],
				'supports'              => array_merge( array( 'html' => false ), (array) $block['supports'] ),
				'attributes'            => self::attributes( $settings ),
				'editor_script_handles' => array( 'pcf-blocks' ),
				'render_callback'       => function ( $attributes, $content = '', $wp_block = null ) use ( $name ) {
					return PCF_Blocks::render( $name, $attributes, $content, $wp_block );
				},
			)
		);
		if ( $type ) {
			self::$blocks[ $name ] = $settings;
		}
		return $type ? $settings : false;
	}

	/**
	 * Renderizado de servidor (front y preview del editor).
	 */
	public static function render( $name, $attributes, $content = '', $wp_block = null ) {
		$settings = pcf_maybe_get( self::$blocks, $name );
		if ( ! $settings ) {
			return '';
		}
		$data = (array) pcf_maybe_get( $attributes, 'data', array() );
		$id   = 'block_' . substr( md5( $name . wp_json_encode( $data ) . pcf_maybe_get( $attributes, 'anchor', '' ) ), 0, 13 );

		$previous                          = pcf_maybe_get( pcf()->block_data, '__current' );
		pcf()->block_data[ $id ]           = $data;
		pcf()->block_data[ $id ]['__block'] = $name;
		self::normalize_key_data( $id, $data );
		pcf()->block_data['__current']     = $id;

		$block = array_merge(
			$settings,
			$attributes,
			array(
				'id'   => $id,
				'name' => $name,
				'data' => $data,
			)
		);
		$block['className'] = preg_replace( '/\s+/', ' ', 'wp-block-' . str_replace( '/', '-', $name ) . ' ' . pcf_maybe_get( $attributes, 'className', '' ) . ( ! empty( $attributes['align'] ) ? ' align' . $attributes['align'] : '' ) );
		$block['className'] = trim( $block['className'] );

		$is_preview = defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $_GET['context'] ) && 'edit' === $_GET['context']; // phpcs:ignore
		$post_id    = get_the_ID();
		if ( ! $post_id && ! empty( $_GET['post_id'] ) ) { // phpcs:ignore
			$post_id = (int) $_GET['post_id']; // phpcs:ignore
		}
		$context = $wp_block instanceof WP_Block ? $wp_block->context : array();

		ob_start();
		if ( $settings['render_callback'] && is_callable( $settings['render_callback'] ) ) {
			call_user_func( $settings['render_callback'], $block, $content, $is_preview, $post_id, $wp_block, $context );
		} elseif ( $settings['render_template'] && file_exists( $settings['render_template'] ) ) {
			// Variables disponibles en la plantilla: $block, $content, $is_preview, $post_id, $context, $wp_block.
			include $settings['render_template'];
		} elseif ( $is_preview ) {
			printf( '<p style="padding:1em;border:1px dashed">%s</p>', esc_html( sprintf( __( 'Falta la plantilla del bloque %s.', 'pcf' ), $name ) ) );
		}
		$html = ob_get_clean();

		// Soporte de <InnerBlocks /> en plantillas.
		$html = preg_replace( '#<InnerBlocks[^>]*/>#i', $is_preview ? '' : $content, $html );

		pcf()->block_data['__current'] = $previous;
		unset( pcf()->block_data[ $id ] );

		if ( ! $is_preview ) {
			if ( ! empty( $settings['enqueue_style'] ) ) {
				wp_enqueue_style( 'pcf-block-' . sanitize_key( $name ), $settings['enqueue_style'], array(), PCF_VERSION );
			}
			if ( ! empty( $settings['enqueue_script'] ) ) {
				wp_enqueue_script( 'pcf-block-' . sanitize_key( $name ), $settings['enqueue_script'], array(), PCF_VERSION, true );
			}
		}
		return $html;
	}

	/**
	 * Datos indexados por field_key ({"field_abc": valor, "field_rep": {"row-0": {...}}})
	 * se convierten al formato por nombre usando la misma lógica de guardado.
	 */
	protected static function normalize_key_data( $id, $data ) {
		foreach ( $data as $k => $v ) {
			if ( ! is_string( $k ) || 0 !== strpos( $k, 'field_' ) ) {
				continue;
			}
			$field = pcf_get_field( $k );
			if ( $field ) {
				unset( pcf()->block_data[ $id ][ $k ] );
				pcf_update_value( $v, $id, $field );
			}
		}
	}

	/**
	 * Definiciones de campos por bloque para el editor.
	 */
	public static function editor_config() {
		$config = array();
		foreach ( self::$blocks as $name => $settings ) {
			$fields = pcf_get_block_fields( $name );
			$fields = self::prepare_fields_for_js( $fields );
			$config[ $name ] = array(
				'name'   => $name,
				'title'  => $settings['title'],
				'mode'   => $settings['mode'],
				'jsx'    => $settings['jsx'],
				'fields' => $fields,
			);
		}
		return $config;
	}

	protected static function prepare_fields_for_js( $fields ) {
		$out = array();
		foreach ( $fields as $f ) {
			if ( 'clone' === $f['type'] ) {
				$cloned = pcf_get_field_type( 'clone' )->get_cloned_fields( $f );
				if ( 'seamless' === $f['display'] && empty( $f['prefix_name'] ) ) {
					$out = array_merge( $out, self::prepare_fields_for_js( $cloned ) );
					continue;
				}
				$f['type']       = 'group';
				$f['sub_fields'] = $cloned;
			}
			if ( ! empty( $f['sub_fields'] ) ) {
				$f['sub_fields'] = self::prepare_fields_for_js( $f['sub_fields'] );
			}
			if ( ! empty( $f['layouts'] ) ) {
				foreach ( $f['layouts'] as $i => $l ) {
					$f['layouts'][ $i ]['sub_fields'] = self::prepare_fields_for_js( $l['sub_fields'] );
				}
			}
			if ( in_array( $f['type'], array( 'post_object', 'relationship', 'page_link' ), true ) ) {
				$f['choices'] = array();
				foreach ( get_posts( array( 'post_type' => $f['post_type'] ? (array) $f['post_type'] : 'any', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
					$f['choices'][ $p->ID ] = get_the_title( $p );
				}
			} elseif ( 'taxonomy' === $f['type'] ) {
				$f['choices'] = array();
				$terms        = get_terms( array( 'taxonomy' => $f['taxonomy'], 'hide_empty' => false, 'number' => 300 ) );
				foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
					$f['choices'][ $t->term_id ] = $t->name;
				}
			} elseif ( 'user' === $f['type'] ) {
				$f['choices'] = array();
				foreach ( get_users( array( 'number' => 200, 'role__in' => (array) $f['role'] ) ) as $u ) {
					$f['choices'][ $u->ID ] = $u->display_name;
				}
			}
			$out[] = $f;
		}
		return $out;
	}

	public static function enqueue_editor() {
		if ( ! self::$blocks ) {
			return;
		}
		wp_add_inline_script( 'pcf-blocks', 'window.pcfBlocks = ' . wp_json_encode( self::editor_config() ) . ';', 'before' );
		wp_enqueue_style( 'pcf-blocks', PCF_URL . 'assets/css/pcf-blocks.css', array(), PCF_VERSION );
	}

	/**
	 * Crea un bloque en el tema (block.json + render.php [+ style.css]).
	 */
	public static function scaffold( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'name'        => '',
				'title'       => '',
				'description' => '',
				'category'    => 'theme',
				'icon'        => 'block-default',
				'keywords'    => array(),
				'supports'    => array( 'align' => array( 'wide', 'full' ), 'anchor' => true ),
				'mode'        => 'preview',
				'namespace'   => 'pcf',
				'template'    => '',
				'style'       => '',
				'dir'         => '',
				'overwrite'   => false,
			)
		);
		$slug = sanitize_title( false !== strpos( $args['name'], '/' ) ? substr( $args['name'], strpos( $args['name'], '/' ) + 1 ) : $args['name'] );
		if ( ! $slug ) {
			return new WP_Error( 'invalid_name', 'Falta name del bloque.' );
		}
		$name = false !== strpos( $args['name'], '/' ) ? $args['name'] : $args['namespace'] . '/' . $slug;
		$base = $args['dir'] ? $args['dir'] : get_stylesheet_directory() . '/blocks';
		$dir  = trailingslashit( $base ) . $slug;
		if ( file_exists( $dir . '/block.json' ) && ! $args['overwrite'] ) {
			return new WP_Error( 'exists', sprintf( 'El bloque ya existe en %s. Usa overwrite=true para reemplazarlo.', $dir ) );
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mkdir', 'No se pudo crear ' . $dir );
		}
		$json = array(
			'$schema'     => 'https://schemas.wp.org/trunk/block.json',
			'apiVersion'  => 3,
			'name'        => $name,
			'title'       => $args['title'] ? $args['title'] : ucwords( str_replace( '-', ' ', $slug ) ),
			'category'    => $args['category'],
			'icon'        => $args['icon'],
			'description' => $args['description'],
			'keywords'    => (array) $args['keywords'],
			'supports'    => (array) $args['supports'],
			'pcf'         => array( 'mode' => $args['mode'], 'renderTemplate' => 'render.php' ),
		);
		if ( $args['style'] ) {
			$json['style'] = 'file:./style.css';
			file_put_contents( $dir . '/style.css', $args['style'] ); // phpcs:ignore
		}
		file_put_contents( $dir . '/block.json', pcf_json_encode( $json ) ); // phpcs:ignore

		$template = $args['template'];
		if ( ! $template ) {
			$template = "<?php\n/**\n * Bloque: {$json['title']}\n *\n * @var array \$block      Ajustes y atributos del bloque.\n * @var string \$content   Contenido interno (InnerBlocks).\n * @var bool \$is_preview  true en el editor.\n * @var int \$post_id      Post actual.\n */\n\$anchor = ! empty( \$block['anchor'] ) ? ' id=\"' . esc_attr( \$block['anchor'] ) . '\"' : '';\n?>\n<section class=\"<?php echo esc_attr( \$block['className'] ); ?>\"<?php echo \$anchor; ?>>\n\t<?php /* Usa get_field( 'nombre' ) o pcf_value( 'nombre' ) para leer los campos del bloque. */ ?>\n</section>\n";
		}
		file_put_contents( $dir . '/render.php', $template ); // phpcs:ignore

		return array( 'name' => $name, 'dir' => $dir, 'files' => array_values( array_filter( array( $dir . '/block.json', $dir . '/render.php', $args['style'] ? $dir . '/style.css' : '' ) ) ) );
	}
}

PCF_Blocks::init();

add_action(
	'init',
	function () {
		wp_register_script(
			'pcf-blocks',
			PCF_URL . 'assets/js/pcf-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n', 'wp-data' ),
			PCF_VERSION,
			true
		);
	},
	1
);

function pcf_register_block_type( $block, $namespace = null ) {
	if ( null !== $namespace ) {
		$block['namespace'] = $namespace;
	}
	if ( PCF_Blocks::$ran ) {
		return PCF_Blocks::register_php_block( $block );
	}
	PCF_Blocks::$queue[] = $block;
	return $block;
}

function pcf_get_block_fields( $name ) {
	$fields = array();
	foreach ( pcf_get_field_groups( array( 'block' => $name ) ) as $group ) {
		$fields = array_merge( $fields, $group['fields'] );
	}
	return $fields;
}
