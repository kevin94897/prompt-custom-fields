<?php
/**
 * Clase base para tipos de campo (equivalente a acf_field).
 *
 * Cada tipo declara $settings: definición de sus ajustes con tipo JSON y descripción.
 * Esa definición alimenta los defaults, la documentación que ve la IA por MCP
 * (list-field-types) y el JSON Schema.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

abstract class PCF_Field {

	/** @var string */
	public $name = '';

	/** @var string */
	public $label = '';

	/** @var string basic|content|choice|relational|advanced|layout */
	public $category = 'basic';

	/** @var string Descripción para la IA. */
	public $description = '';

	/** @var bool Si guarda valor. */
	public $has_value = true;

	/**
	 * Ajustes: nombre => [ 'type' => json-type, 'default' => x, 'description' => '', 'enum' => [] ].
	 *
	 * @var array
	 */
	public $settings = array();

	/** @var array Defaults derivados de $settings. */
	public $defaults = array();

	public function __construct() {
		$this->setup();
		foreach ( $this->settings as $k => $def ) {
			$this->defaults[ $k ] = pcf_maybe_get( $def, 'default', '' );
		}
	}

	abstract protected function setup();

	/**
	 * Descripción completa (para MCP).
	 */
	public function describe() {
		$settings = array();
		foreach ( $this->settings as $k => $def ) {
			$settings[ $k ] = array_filter(
				array(
					'type'        => pcf_maybe_get( $def, 'type', 'string' ),
					'default'     => pcf_maybe_get( $def, 'default', '' ),
					'description' => pcf_maybe_get( $def, 'description', '' ),
					'enum'        => pcf_maybe_get( $def, 'enum' ),
				),
				function ( $v ) {
					return null !== $v;
				}
			);
		}
		return array(
			'type'        => $this->name,
			'label'       => $this->label,
			'category'    => $this->category,
			'description' => $this->description,
			'has_value'   => $this->has_value,
			'value_format' => $this->value_format(),
			'settings'    => $settings,
		);
	}

	/**
	 * Descripción del formato de valor que acepta update-values (para la IA).
	 */
	public function value_format() {
		return 'string';
	}

	public function validate_field( $field ) {
		return $field;
	}

	public function load_value( $value, $post_id, $field ) {
		return $value;
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		return $value;
	}

	/**
	 * Devuelve lo que se guardará en meta, o null si el tipo gestiona su guardado.
	 */
	public function update_value( $value, $post_id, $field ) {
		return is_string( $value ) ? $value : ( is_scalar( $value ) ? (string) $value : $value );
	}

	public function delete_value( $post_id, $field ) {}

	public function validate_value( $valid, $value, $field ) {
		return $valid;
	}

	/**
	 * Renderiza el input HTML. $field incluye 'value' e 'input_name'.
	 */
	public function render_field( $field ) {
		printf(
			'<input type="text"%s />',
			pcf_esc_attrs(
				array(
					'id'    => $field['id'],
					'name'  => $field['input_name'],
					'value' => is_scalar( $field['value'] ) ? $field['value'] : '',
				)
			)
		);
	}

	/**
	 * Formato de retorno común para archivos/imágenes.
	 */
	protected function attachment_format( $id, $format ) {
		$id = (int) $id;
		if ( ! $id || ! get_post( $id ) ) {
			return false;
		}
		if ( 'id' === $format ) {
			return $id;
		}
		if ( 'url' === $format ) {
			return wp_get_attachment_url( $id );
		}
		return pcf_get_attachment( $id );
	}

	protected static function s( $type, $default, $description, $enum = null ) {
		$out = array( 'type' => $type, 'default' => $default, 'description' => $description );
		if ( $enum ) {
			$out['enum'] = $enum;
		}
		return $out;
	}
}

/**
 * Array de adjunto con el mismo formato que acf_get_attachment().
 */
function pcf_get_attachment( $id ) {
	$post = get_post( $id );
	if ( ! $post || 'attachment' !== $post->post_type ) {
		return false;
	}
	$meta  = wp_get_attachment_metadata( $post->ID );
	$url   = wp_get_attachment_url( $post->ID );
	$mime  = explode( '/', (string) $post->post_mime_type );
	$data  = array(
		'ID'          => $post->ID,
		'id'          => $post->ID,
		'title'       => $post->post_title,
		'filename'    => wp_basename( (string) get_attached_file( $post->ID ) ),
		'url'         => $url,
		'link'        => get_attachment_link( $post->ID ),
		'alt'         => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
		'author'      => $post->post_author,
		'description' => $post->post_content,
		'caption'     => $post->post_excerpt,
		'name'        => $post->post_name,
		'status'      => $post->post_status,
		'uploaded_to' => $post->post_parent,
		'date'        => $post->post_date_gmt,
		'modified'    => $post->post_modified_gmt,
		'menu_order'  => $post->menu_order,
		'mime_type'   => $post->post_mime_type,
		'type'        => $mime[0],
		'subtype'     => pcf_maybe_get( $mime, 1, '' ),
		'icon'        => wp_mime_type_icon( $post->ID ),
		'width'       => 0,
		'height'      => 0,
		'sizes'       => array(),
	);
	if ( 'image' === $data['type'] && is_array( $meta ) ) {
		$data['width']  = (int) pcf_maybe_get( $meta, 'width', 0 );
		$data['height'] = (int) pcf_maybe_get( $meta, 'height', 0 );
		foreach ( get_intermediate_image_sizes() as $size ) {
			$src = wp_get_attachment_image_src( $post->ID, $size );
			if ( $src ) {
				$data['sizes'][ $size ]             = $src[0];
				$data['sizes'][ $size . '-width' ]  = $src[1];
				$data['sizes'][ $size . '-height' ] = $src[2];
			}
		}
	}
	return $data;
}
