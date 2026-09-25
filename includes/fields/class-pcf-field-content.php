<?php
/**
 * Campos de contenido.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Convierte ID / array / URL en ID de adjunto. Si es una URL externa, la descarga
 * a la biblioteca (útil al rellenar contenido por prompts).
 */
function pcf_resolve_attachment_id( $value, $post_id = 0 ) {
	if ( is_array( $value ) ) {
		$value = pcf_maybe_get( $value, 'ID', pcf_maybe_get( $value, 'id', pcf_maybe_get( $value, 'url', '' ) ) );
	}
	if ( is_numeric( $value ) ) {
		return (int) $value;
	}
	if ( ! is_string( $value ) || '' === $value || ! preg_match( '#^https?://#', $value ) ) {
		return 0;
	}
	$id = attachment_url_to_postid( $value );
	if ( $id ) {
		return $id;
	}
	if ( ! apply_filters( 'pcf/sideload_remote_media', true, $value ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$parent = is_numeric( $post_id ) ? (int) $post_id : 0;
	$id     = media_sideload_image( $value, $parent, null, 'id' );
	if ( is_wp_error( $id ) ) {
		// No es imagen: intentar como archivo genérico.
		$tmp = download_url( $value );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}
		$file = array( 'name' => wp_basename( wp_parse_url( $value, PHP_URL_PATH ) ), 'tmp_name' => $tmp );
		$id   = media_handle_sideload( $file, $parent );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}
	}
	return (int) $id;
}

class PCF_Field_Image extends PCF_Field {
	protected function setup() {
		$this->name        = 'image';
		$this->label       = 'Imagen';
		$this->category    = 'content';
		$this->description = 'Imagen de la biblioteca de medios.';
		$this->settings    = array(
			'return_format' => self::s( 'string', 'array', 'Formato devuelto por get_field.', array( 'array', 'url', 'id' ) ),
			'library'       => self::s( 'string', 'all', 'Biblioteca.', array( 'all', 'uploadedTo' ) ),
			'preview_size'  => self::s( 'string', 'medium', 'Tamaño de vista previa.' ),
			'min_width'     => self::s( array( 'integer', 'string' ), '', 'Ancho mínimo px.' ),
			'min_height'    => self::s( array( 'integer', 'string' ), '', 'Alto mínimo px.' ),
			'max_width'     => self::s( array( 'integer', 'string' ), '', 'Ancho máximo px.' ),
			'max_height'    => self::s( array( 'integer', 'string' ), '', 'Alto máximo px.' ),
			'min_size'      => self::s( array( 'number', 'string' ), '', 'Tamaño mínimo MB.' ),
			'max_size'      => self::s( array( 'number', 'string' ), '', 'Tamaño máximo MB.' ),
			'mime_types'    => self::s( 'string', '', 'Extensiones permitidas separadas por coma (jpg,png,webp).' ),
		);
	}

	protected $media_type = 'image';

	public function value_format() {
		return 'ID de adjunto (integer) o URL (se importa a la biblioteca si es externa).';
	}

	public function render_field( $field ) {
		$id      = (int) $field['value'];
		$preview = '';
		if ( $id ) {
			if ( 'image' === $this->media_type ) {
				$preview = wp_get_attachment_image( $id, $field['preview_size'] ? $field['preview_size'] : 'thumbnail' );
			} else {
				$preview = '<span class="pcf-file-name">' . esc_html( wp_basename( (string) get_attached_file( $id ) ) ) . '</span>';
			}
		}
		printf(
			'<div class="pcf-media%s" data-type="%s" data-library="%s" data-mime="%s">',
			$id ? ' has-value' : '',
			esc_attr( $this->media_type ),
			esc_attr( $field['library'] ),
			esc_attr( $field['mime_types'] )
		);
		printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $field['input_name'] ), $id ? esc_attr( $id ) : '' );
		echo '<div class="pcf-media-preview">' . $preview . '</div>'; // phpcs:ignore
		printf(
			'<p><button type="button" class="button pcf-media-select">%s</button> <button type="button" class="button-link pcf-media-remove">%s</button></p>',
			esc_html( 'image' === $this->media_type ? __( 'Seleccionar imagen', 'pcf' ) : __( 'Seleccionar archivo', 'pcf' ) ),
			esc_html__( 'Quitar', 'pcf' )
		);
		echo '</div>';
	}

	public function update_value( $value, $post_id, $field ) {
		$id = pcf_resolve_attachment_id( $value, $post_id );
		return $id ? (string) $id : '';
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) ) {
			return false;
		}
		return $this->attachment_format( $value, $field['return_format'] );
	}

	public function validate_value( $valid, $value, $field ) {
		$id = is_numeric( $value ) ? (int) $value : 0;
		if ( ! $id ) {
			return $valid;
		}
		$file = get_attached_file( $id );
		if ( ! empty( $field['mime_types'] ) && $file ) {
			$ext     = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			$allowed = array_map( 'trim', explode( ',', strtolower( $field['mime_types'] ) ) );
			if ( ! in_array( $ext, $allowed, true ) ) {
				return sprintf( '%s: tipo de archivo no permitido (%s).', $field['label'], $ext );
			}
		}
		if ( 'image' === $this->media_type ) {
			$meta = wp_get_attachment_metadata( $id );
			$w    = (int) pcf_maybe_get( (array) $meta, 'width', 0 );
			$h    = (int) pcf_maybe_get( (array) $meta, 'height', 0 );
			foreach ( array( 'min_width' => array( $w, '<' ), 'min_height' => array( $h, '<' ), 'max_width' => array( $w, '>' ), 'max_height' => array( $h, '>' ) ) as $k => $cmp ) {
				if ( '' !== $field[ $k ] && $cmp[0] && ( '<' === $cmp[1] ? $cmp[0] < $field[ $k ] : $cmp[0] > $field[ $k ] ) ) {
					return sprintf( '%s: la imagen no cumple %s = %s px.', $field['label'], $k, $field[ $k ] );
				}
			}
		}
		if ( $file && file_exists( $file ) ) {
			$mb = filesize( $file ) / MB_IN_BYTES;
			if ( '' !== $field['max_size'] && $mb > (float) $field['max_size'] ) {
				return sprintf( '%s: supera %s MB.', $field['label'], $field['max_size'] );
			}
			if ( '' !== $field['min_size'] && $mb < (float) $field['min_size'] ) {
				return sprintf( '%s: menor a %s MB.', $field['label'], $field['min_size'] );
			}
		}
		return $valid;
	}
}

class PCF_Field_File extends PCF_Field_Image {
	protected $media_type = 'file';

	protected function setup() {
		parent::setup();
		$this->name        = 'file';
		$this->label       = 'Archivo';
		$this->description = 'Cualquier archivo de la biblioteca.';
		unset( $this->settings['preview_size'], $this->settings['min_width'], $this->settings['min_height'], $this->settings['max_width'], $this->settings['max_height'] );
		$this->settings['preview_size'] = self::s( 'string', 'thumbnail', 'No aplica en archivos.' );
		foreach ( array( 'min_width', 'min_height', 'max_width', 'max_height' ) as $k ) {
			$this->settings[ $k ] = self::s( 'string', '', 'No aplica.' );
		}
	}
}

class PCF_Field_Wysiwyg extends PCF_Field {
	protected function setup() {
		$this->name        = 'wysiwyg';
		$this->label       = 'Editor WYSIWYG';
		$this->category    = 'content';
		$this->description = 'Editor TinyMCE. El valor es HTML.';
		$this->settings    = array(
			'default_value' => self::s( 'string', '', 'HTML por defecto.' ),
			'tabs'          => self::s( 'string', 'all', 'Pestañas.', array( 'all', 'visual', 'text' ) ),
			'toolbar'       => self::s( 'string', 'full', 'Barra.', array( 'full', 'basic' ) ),
			'media_upload'  => self::s( array( 'integer', 'boolean' ), 1, 'Botón de medios.' ),
			'delay'         => self::s( array( 'integer', 'boolean' ), 0, 'Inicializar al hacer clic.' ),
		);
	}

	public function value_format() {
		return 'string HTML';
	}

	public function render_field( $field ) {
		// Los editores dentro de plantillas (repeater) no pueden inicializarse con wp_editor:
		// se usa textarea + wp.editor.initialize desde JS.
		printf(
			'<textarea class="pcf-wysiwyg"%s>%s</textarea>',
			pcf_esc_attrs(
				array(
					'id'           => $field['id'],
					'name'         => $field['input_name'],
					'rows'         => 10,
					'data-toolbar' => $field['toolbar'],
					'data-media'   => $field['media_upload'] ? 1 : 0,
					'data-tabs'    => $field['tabs'],
				)
			),
			esc_textarea( is_scalar( $field['value'] ) ? $field['value'] : '' )
		);
	}

	public function update_value( $value, $post_id, $field ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$value = apply_filters( 'pcf_the_content', $value );
		return $escape_html ? wp_kses_post( $value ) : $value;
	}
}

// Filtros tipo the_content sin efectos colaterales de plugins.
add_filter( 'pcf_the_content', 'capital_P_dangit', 11 );
add_filter( 'pcf_the_content', 'wptexturize' );
add_filter( 'pcf_the_content', 'convert_smilies', 20 );
add_filter( 'pcf_the_content', 'wpautop' );
add_filter( 'pcf_the_content', 'shortcode_unautop' );
add_filter( 'pcf_the_content', 'do_shortcode', 11 );
add_filter( 'pcf_the_content', 'wp_filter_content_tags' );

class PCF_Field_Oembed extends PCF_Field {
	protected function setup() {
		$this->name        = 'oembed';
		$this->label       = 'oEmbed';
		$this->category    = 'content';
		$this->description = 'URL de YouTube, Vimeo, etc. get_field devuelve el HTML embebido.';
		$this->settings    = array(
			'width'  => self::s( array( 'integer', 'string' ), '', 'Ancho.' ),
			'height' => self::s( array( 'integer', 'string' ), '', 'Alto.' ),
		);
	}

	public function value_format() {
		return 'string URL';
	}

	public function render_field( $field ) {
		printf( '<input type="url" id="%s" name="%s" value="%s" placeholder="https://" />', esc_attr( $field['id'] ), esc_attr( $field['input_name'] ), esc_attr( is_scalar( $field['value'] ) ? $field['value'] : '' ) );
	}

	public function update_value( $value, $post_id, $field ) {
		return esc_url_raw( (string) $value );
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) ) {
			return $value;
		}
		$args = array_filter( array( 'width' => $field['width'], 'height' => $field['height'] ) );
		$html = wp_oembed_get( $value, $args );
		return $html ? $html : $value;
	}
}

class PCF_Field_Gallery extends PCF_Field_Image {
	protected function setup() {
		parent::setup();
		$this->name        = 'gallery';
		$this->label       = 'Galería';
		$this->description = 'Varias imágenes ordenables.';
		$this->settings['min']    = self::s( array( 'integer', 'string' ), '', 'Mínimo de imágenes.' );
		$this->settings['max']    = self::s( array( 'integer', 'string' ), '', 'Máximo de imágenes.' );
		$this->settings['insert'] = self::s( 'string', 'append', 'Dónde insertar.', array( 'append', 'prepend' ) );
		$this->settings['preview_size']['default'] = 'thumbnail';
	}

	public function value_format() {
		return 'array de IDs de adjunto o URLs';
	}

	public function render_field( $field ) {
		$ids = array_filter( array_map( 'intval', pcf_get_array( $field['value'] ) ) );
		printf(
			'<div class="pcf-gallery" data-name="%s" data-library="%s" data-max="%s" data-insert="%s">',
			esc_attr( $field['input_name'] ),
			esc_attr( $field['library'] ),
			esc_attr( $field['max'] ),
			esc_attr( $field['insert'] )
		);
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		echo '<ul class="pcf-gallery-items">';
		foreach ( $ids as $id ) {
			printf(
				'<li data-id="%1$d"><input type="hidden" name="%2$s[]" value="%1$d" />%3$s<button type="button" class="pcf-gallery-remove" aria-label="%4$s">&times;</button></li>',
				(int) $id,
				esc_attr( $field['input_name'] ),
				wp_get_attachment_image( $id, 'thumbnail' ),
				esc_attr__( 'Quitar', 'pcf' )
			);
		}
		echo '</ul>';
		printf( '<p><button type="button" class="button pcf-gallery-add">%s</button></p></div>', esc_html__( 'Añadir a la galería', 'pcf' ) );
	}

	public function update_value( $value, $post_id, $field ) {
		$ids = array();
		foreach ( pcf_get_array( $value ) as $v ) {
			$id = pcf_resolve_attachment_id( $v, $post_id );
			if ( $id ) {
				$ids[] = (string) $id;
			}
		}
		return $ids ? $ids : '';
	}

	public function load_value( $value, $post_id, $field ) {
		return is_array( $value ) ? $value : ( $value ? pcf_get_array( maybe_unserialize( $value ) ) : $value );
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) ) {
			return false;
		}
		$out = array();
		foreach ( (array) $value as $id ) {
			$item = $this->attachment_format( $id, $field['return_format'] );
			if ( $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	public function validate_value( $valid, $value, $field ) {
		$count = count( array_filter( pcf_get_array( $value ) ) );
		if ( '' !== $field['min'] && $count < (int) $field['min'] ) {
			return sprintf( '%s: mínimo %d imágenes.', $field['label'], $field['min'] );
		}
		if ( '' !== $field['max'] && $count > (int) $field['max'] ) {
			return sprintf( '%s: máximo %d imágenes.', $field['label'], $field['max'] );
		}
		return $valid;
	}
}
