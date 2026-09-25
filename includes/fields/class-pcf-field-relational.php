<?php
/**
 * Campos relacionales.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Field_Link extends PCF_Field {
	protected function setup() {
		$this->name        = 'link';
		$this->label       = 'Enlace';
		$this->category    = 'relational';
		$this->description = 'URL + título + target.';
		$this->settings    = array(
			'return_format' => self::s( 'string', 'array', 'Formato devuelto.', array( 'array', 'url' ) ),
		);
	}

	public function value_format() {
		return 'object {"url":"…","title":"…","target":"" | "_blank"} o string URL';
	}

	/**
	 * Se apoya en el modal nativo de WordPress ("Insertar/editar enlace", el
	 * mismo del editor): así el botón se arma con buscador de contenido
	 * publicado, texto del enlace y "abrir en una pestaña nueva", sin que el
	 * plugin tenga que mantener su propio buscador.
	 *
	 * Los valores viven en tres hidden (url/title/target) que son los que se
	 * envían; el modal solo los rellena (ver pcf-input.js). Sin JavaScript el
	 * campo sigue siendo editable: los hidden pasan a inputs normales.
	 */
	public function render_field( $field ) {
		$v      = wp_parse_args( is_array( $field['value'] ) ? $field['value'] : array( 'url' => (string) $field['value'] ), array( 'url' => '', 'title' => '', 'target' => '' ) );
		$n      = $field['input_name'];
		$blank  = '_blank' === $v['target'];
		$tiene  = '' !== $v['url'];
		$titulo = '' !== $v['title'] ? $v['title'] : $v['url'];

		printf( '<div class="pcf-link%s" data-pcf-link>', $tiene ? ' has-value' : '' );

		// type="text" y no "url": el destino puede ser un ancla (#contacto) o una
		// ruta relativa, que la validación nativa de type="url" rechaza. Como el
		// input va oculto detrás del modal, el navegador no podría enfocarlo para
		// avisar y bloquearía el guardado de toda la pantalla ("An invalid form
		// control … is not focusable"). El saneado real lo hace update_value()
		// con esc_url_raw().
		printf( '<input type="text" inputmode="url" class="pcf-link__input" name="%s[url]" value="%s" placeholder="%s" data-pcf-link-url />', esc_attr( $n ), esc_attr( $v['url'] ), esc_attr__( 'URL', 'pcf' ) );
		printf( '<input type="text" class="pcf-link__input" name="%s[title]" value="%s" placeholder="%s" data-pcf-link-title />', esc_attr( $n ), esc_attr( $v['title'] ), esc_attr__( 'Texto del botón', 'pcf' ) );
		printf( '<label class="pcf-link__input"><input type="checkbox" name="%s[target]" value="_blank"%s data-pcf-link-target /> %s</label>', esc_attr( $n ), checked( true, $blank, false ), esc_html__( 'Nueva pestaña', 'pcf' ) );

		echo '<div class="pcf-link__box">';
		echo '<div class="pcf-link__preview">';
		printf( '<span class="pcf-link__label" data-pcf-link-label>%s</span>', esc_html( $titulo ) );
		printf( '<span class="pcf-link__url" data-pcf-link-href>%s</span>', esc_html( $v['url'] ) );
		printf( '<span class="pcf-link__blank" data-pcf-link-blank%s>%s</span>', $blank ? '' : ' hidden', esc_html__( 'Se abre en una pestaña nueva', 'pcf' ) );
		echo '</div>';
		printf( '<p class="pcf-link__empty">%s</p>', esc_html__( 'Sin enlace', 'pcf' ) );
		echo '<p class="pcf-link__actions">';
		printf( '<button type="button" class="button" data-pcf-link-edit>%s</button>', esc_html__( 'Seleccionar enlace', 'pcf' ) );
		printf( '<button type="button" class="button-link pcf-link__remove" data-pcf-link-clear>%s</button>', esc_html__( 'Quitar', 'pcf' ) );
		echo '</p>';
		echo '</div>';

		echo '</div>';
	}

	public function update_value( $value, $post_id, $field ) {
		if ( is_string( $value ) ) {
			$value = array( 'url' => $value );
		}
		$value = wp_parse_args( (array) $value, array( 'url' => '', 'title' => '', 'target' => '' ) );
		if ( '' === $value['url'] ) {
			return '';
		}
		return array(
			'title'  => sanitize_text_field( $value['title'] ),
			'url'    => esc_url_raw( $value['url'] ),
			'target' => '_blank' === $value['target'] ? '_blank' : '',
		);
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) ) {
			return $value;
		}
		if ( 'url' === $field['return_format'] ) {
			return is_array( $value ) ? $value['url'] : $value;
		}
		return is_array( $value ) ? $value : array( 'title' => '', 'url' => $value, 'target' => '' );
	}
}

/**
 * Resuelve ID / slug / título a ID de post.
 */
function pcf_resolve_post_id( $value, $post_types = array() ) {
	if ( $value instanceof WP_Post ) {
		return $value->ID;
	}
	if ( is_array( $value ) ) {
		$value = pcf_maybe_get( $value, 'ID', pcf_maybe_get( $value, 'id', 0 ) );
	}
	if ( is_numeric( $value ) ) {
		return (int) $value;
	}
	if ( ! is_string( $value ) || '' === $value ) {
		return 0;
	}
	$types = $post_types ? (array) $post_types : array_keys( get_post_types( array( 'public' => true ) ) );
	$post  = get_page_by_path( $value, OBJECT, $types );
	if ( $post ) {
		return $post->ID;
	}
	$q = get_posts( array( 'post_type' => $types, 'title' => $value, 'posts_per_page' => 1, 'post_status' => 'any', 'fields' => 'ids' ) );
	return $q ? (int) $q[0] : 0;
}

abstract class PCF_Field_Posts_Base extends PCF_Field {

	public function value_format() {
		return 'ID de post (o slug/título), o array de ellos si es múltiple';
	}

	protected function query_args( $field ) {
		$args = array(
			'post_type'      => $field['post_type'] ? (array) $field['post_type'] : array_keys( pcf_get_post_type_choices() ),
			'post_status'    => ! empty( $field['post_status'] ) ? (array) $field['post_status'] : array( 'publish', 'draft', 'private', 'future' ),
			'posts_per_page' => apply_filters( 'pcf/fields/posts_limit', 300, $field ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( ! empty( $field['taxonomy'] ) ) {
			$tax_query = array( 'relation' => 'OR' );
			foreach ( (array) $field['taxonomy'] as $pair ) {
				if ( false === strpos( $pair, ':' ) ) {
					continue;
				}
				list( $tax, $term ) = explode( ':', $pair, 2 );
				$tax_query[]        = array( 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $term );
			}
			if ( count( $tax_query ) > 1 ) {
				$args['tax_query'] = $tax_query; // phpcs:ignore
			}
		}
		return apply_filters( "pcf/fields/{$this->name}/query", $args, $field );
	}

	protected function get_choices( $field ) {
		$out = array();
		foreach ( get_posts( $this->query_args( $field ) ) as $p ) {
			$pt                                = get_post_type_object( $p->post_type );
			$label                             = $pt ? $pt->labels->singular_name : $p->post_type;
			$title                             = get_the_title( $p ) ? get_the_title( $p ) : '(#' . $p->ID . ')';
			$out[ $label ][ $p->ID ]           = $title . ( 'publish' !== $p->post_status ? ' (' . $p->post_status . ')' : '' );
		}
		return $out;
	}

	protected function render_select( $field, $multiple ) {
		$values = array_map( 'intval', pcf_get_array( $field['value'] ) );
		if ( $multiple ) {
			printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		}
		printf( '<select class="pcf-filterable" id="%s" name="%s"%s>', esc_attr( $field['id'] ), esc_attr( $field['input_name'] . ( $multiple ? '[]' : '' ) ), $multiple ? ' multiple size="8"' : '' );
		if ( ! $multiple ) {
			echo '<option value="">— ' . esc_html__( 'Seleccionar', 'pcf' ) . ' —</option>';
		}
		foreach ( $this->get_choices( $field ) as $group => $items ) {
			printf( '<optgroup label="%s">', esc_attr( $group ) );
			foreach ( $items as $id => $title ) {
				printf( '<option value="%d"%s>%s</option>', (int) $id, selected( in_array( (int) $id, $values, true ), true, false ), esc_html( $title ) );
			}
			echo '</optgroup>';
		}
		echo '</select>';
	}

	public function update_value( $value, $post_id, $field ) {
		$ids = array();
		foreach ( pcf_get_array( $value ) as $v ) {
			$id = pcf_resolve_post_id( $v, $field['post_type'] );
			if ( $id ) {
				$ids[] = (string) $id;
			}
		}
		if ( $this->is_multi( $field ) ) {
			return $ids ? $ids : '';
		}
		return $ids ? $ids[0] : '';
	}

	protected function is_multi( $field ) {
		return ! empty( $field['multiple'] );
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) ) {
			return false;
		}
		$ids = array_filter( array_map( 'intval', pcf_get_array( $value ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$item = $this->format_one( $id, $field );
			if ( $item ) {
				$out[] = $item;
			}
		}
		return $this->is_multi( $field ) ? $out : ( $out ? $out[0] : false );
	}

	protected function format_one( $id, $field ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		return 'id' === $field['return_format'] ? $id : $post;
	}

	protected function post_settings() {
		return array(
			'post_type'   => self::s( 'array', array(), 'Post types permitidos (vacío = todos).' ),
			'post_status' => self::s( 'array', array(), 'Estados permitidos.' ),
			'taxonomy'    => self::s( 'array', array(), 'Filtro por término: ["category:noticias"].' ),
		);
	}
}

class PCF_Field_Post_Object extends PCF_Field_Posts_Base {
	protected function setup() {
		$this->name        = 'post_object';
		$this->label       = 'Objeto de post';
		$this->category    = 'relational';
		$this->description = 'Selecciona uno o varios posts.';
		$this->settings    = array_merge(
			$this->post_settings(),
			array(
				'return_format'        => self::s( 'string', 'object', 'Formato devuelto.', array( 'object', 'id' ) ),
				'multiple'             => self::s( array( 'integer', 'boolean' ), 0, 'Múltiple.' ),
				'allow_null'           => self::s( array( 'integer', 'boolean' ), 0, 'Permitir vacío.' ),
				'ui'                   => self::s( array( 'integer', 'boolean' ), 1, 'UI mejorada.' ),
				'bidirectional'        => self::s( array( 'integer', 'boolean' ), 0, 'Relación bidireccional.' ),
				'bidirectional_target' => self::s( 'array', array(), 'Keys de campos destino bidireccionales.' ),
			)
		);
	}

	public function render_field( $field ) {
		$this->render_select( $field, ! empty( $field['multiple'] ) );
	}

	public function update_value( $value, $post_id, $field ) {
		$new = parent::update_value( $value, $post_id, $field );
		pcf_sync_bidirectional( $post_id, $field, $new );
		return $new;
	}
}

class PCF_Field_Page_Link extends PCF_Field_Posts_Base {
	protected function setup() {
		$this->name        = 'page_link';
		$this->label       = 'Enlace a página';
		$this->category    = 'relational';
		$this->description = 'Selecciona post(s); get_field devuelve la URL.';
		$this->settings    = array_merge(
			$this->post_settings(),
			array(
				'allow_null'     => self::s( array( 'integer', 'boolean' ), 0, 'Permitir vacío.' ),
				'allow_archives' => self::s( array( 'integer', 'boolean' ), 1, 'Permitir archivos (no implementado en UI).' ),
				'multiple'       => self::s( array( 'integer', 'boolean' ), 0, 'Múltiple.' ),
			)
		);
	}

	public function render_field( $field ) {
		$this->render_select( $field, ! empty( $field['multiple'] ) );
	}

	protected function format_one( $id, $field ) {
		return get_permalink( $id );
	}

	public function update_value( $value, $post_id, $field ) {
		// Se aceptan URLs de archivo tal cual.
		if ( is_string( $value ) && preg_match( '#^https?://#', $value ) ) {
			$id = url_to_postid( $value );
			return $id ? (string) $id : esc_url_raw( $value );
		}
		return parent::update_value( $value, $post_id, $field );
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( is_string( $value ) && preg_match( '#^https?://#', $value ) ) {
			return $value;
		}
		return parent::format_value( $value, $post_id, $field, $escape_html );
	}
}

class PCF_Field_Relationship extends PCF_Field_Posts_Base {
	protected function setup() {
		$this->name        = 'relationship';
		$this->label       = 'Relación';
		$this->category    = 'relational';
		$this->description = 'Lista ordenable de posts relacionados.';
		$this->settings    = array_merge(
			$this->post_settings(),
			array(
				'filters'              => self::s( 'array', array( 'search', 'post_type', 'taxonomy' ), 'Filtros visibles.' ),
				'elements'             => self::s( 'array', array(), 'Elementos (featured_image).' ),
				'min'                  => self::s( array( 'integer', 'string' ), '', 'Mínimo.' ),
				'max'                  => self::s( array( 'integer', 'string' ), '', 'Máximo.' ),
				'return_format'        => self::s( 'string', 'object', 'Formato devuelto.', array( 'object', 'id' ) ),
				'bidirectional'        => self::s( array( 'integer', 'boolean' ), 0, 'Relación bidireccional.' ),
				'bidirectional_target' => self::s( 'array', array(), 'Keys de campos destino bidireccionales.' ),
			)
		);
	}

	protected function is_multi( $field ) {
		return true;
	}

	public function render_field( $field ) {
		$values = array_map( 'intval', pcf_get_array( $field['value'] ) );
		printf( '<div class="pcf-relationship" data-name="%s" data-max="%s">', esc_attr( $field['input_name'] ), esc_attr( $field['max'] ) );
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		echo '<div class="pcf-rel-col"><input type="search" class="pcf-rel-search" placeholder="' . esc_attr__( 'Buscar…', 'pcf' ) . '" /><ul class="pcf-rel-choices">';
		foreach ( $this->get_choices( $field ) as $group => $items ) {
			foreach ( $items as $id => $title ) {
				printf( '<li data-id="%d"%s><span>%s</span> <small>%s</small></li>', (int) $id, in_array( (int) $id, $values, true ) ? ' class="is-disabled"' : '', esc_html( $title ), esc_html( $group ) );
			}
		}
		echo '</ul></div><div class="pcf-rel-col"><ul class="pcf-rel-values">';
		foreach ( $values as $id ) {
			printf( '<li data-id="%1$d"><input type="hidden" name="%2$s[]" value="%1$d" /><span>%3$s</span> <button type="button" class="pcf-rel-remove">&times;</button></li>', (int) $id, esc_attr( $field['input_name'] ), esc_html( get_the_title( $id ) ) );
		}
		echo '</ul></div></div>';
	}

	public function update_value( $value, $post_id, $field ) {
		$new = parent::update_value( $value, $post_id, $field );
		pcf_sync_bidirectional( $post_id, $field, $new );
		return $new;
	}

	public function validate_value( $valid, $value, $field ) {
		$count = count( array_filter( pcf_get_array( $value ) ) );
		if ( '' !== $field['min'] && $count < (int) $field['min'] ) {
			return sprintf( '%s: mínimo %d elementos.', $field['label'], $field['min'] );
		}
		if ( '' !== $field['max'] && $count > (int) $field['max'] ) {
			return sprintf( '%s: máximo %d elementos.', $field['label'], $field['max'] );
		}
		return $valid;
	}
}

/**
 * Relaciones bidireccionales (mismo concepto que ACF 6.2).
 */
function pcf_sync_bidirectional( $post_id, $field, $new_value ) {
	static $running = false;
	if ( $running || empty( $field['bidirectional'] ) || empty( $field['bidirectional_target'] ) || ! is_numeric( $post_id ) ) {
		return;
	}
	$running = true;
	$old     = array_map( 'intval', pcf_get_array( pcf_get_metadata( $post_id, $field['name'] ) ) );
	$new     = array_map( 'intval', pcf_get_array( $new_value ) );
	foreach ( (array) $field['bidirectional_target'] as $target_key ) {
		$target = pcf_get_field( $target_key );
		if ( ! $target ) {
			continue;
		}
		foreach ( array_diff( $new, $old ) as $related ) {
			$current   = array_map( 'intval', pcf_get_array( pcf_get_metadata( $related, $target['name'] ) ) );
			$current[] = (int) $post_id;
			pcf_update_value( array_unique( $current ), $related, $target );
		}
		foreach ( array_diff( $old, $new ) as $related ) {
			$current = array_diff( array_map( 'intval', pcf_get_array( pcf_get_metadata( $related, $target['name'] ) ) ), array( (int) $post_id ) );
			pcf_update_value( array_values( $current ), $related, $target );
		}
	}
	$running = false;
}

class PCF_Field_Taxonomy extends PCF_Field {
	protected function setup() {
		$this->name        = 'taxonomy';
		$this->label       = 'Taxonomía';
		$this->category    = 'relational';
		$this->description = 'Selecciona términos de una taxonomía.';
		$this->settings    = array(
			'taxonomy'      => self::s( 'string', 'category', 'Taxonomía.' ),
			'field_type'    => self::s( 'string', 'checkbox', 'Interfaz.', array( 'checkbox', 'multi_select', 'radio', 'select' ) ),
			'allow_null'    => self::s( array( 'integer', 'boolean' ), 0, 'Permitir vacío.' ),
			'add_term'      => self::s( array( 'integer', 'boolean' ), 1, 'Crear términos nuevos si se pasan por nombre.' ),
			'save_terms'    => self::s( array( 'integer', 'boolean' ), 0, 'Asignar los términos al post.' ),
			'load_terms'    => self::s( array( 'integer', 'boolean' ), 0, 'Cargar los términos asignados al post.' ),
			'return_format' => self::s( 'string', 'id', 'Formato devuelto.', array( 'id', 'object' ) ),
			'multiple'      => self::s( array( 'integer', 'boolean' ), 0, 'Múltiple (derivado de field_type).' ),
		);
	}

	public function value_format() {
		return 'ID de término (o slug/nombre), o array de ellos según field_type';
	}

	public function validate_field( $field ) {
		$field['multiple'] = in_array( $field['field_type'], array( 'checkbox', 'multi_select' ), true ) ? 1 : 0;
		return $field;
	}

	public function load_value( $value, $post_id, $field ) {
		if ( ! empty( $field['load_terms'] ) && is_numeric( $post_id ) ) {
			$ids   = wp_get_object_terms( $post_id, $field['taxonomy'], array( 'fields' => 'ids', 'orderby' => 'none' ) );
			$value = is_wp_error( $ids ) ? $value : array_map( 'strval', $ids );
		}
		return $value;
	}

	public function render_field( $field ) {
		$terms  = get_terms( array( 'taxonomy' => $field['taxonomy'], 'hide_empty' => false ) );
		$values = array_map( 'intval', pcf_get_array( $field['value'] ) );
		if ( is_wp_error( $terms ) ) {
			echo '<p>' . esc_html( $terms->get_error_message() ) . '</p>';
			return;
		}
		$multi = ! empty( $field['multiple'] );
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		$name = $field['input_name'] . ( $multi ? '[]' : '' );
		if ( in_array( $field['field_type'], array( 'select', 'multi_select' ), true ) ) {
			printf( '<select name="%s"%s>', esc_attr( $name ), $multi ? ' multiple size="6"' : '' );
			if ( ! $multi ) {
				echo '<option value="">—</option>';
			}
			foreach ( $terms as $t ) {
				printf( '<option value="%d"%s>%s</option>', (int) $t->term_id, selected( in_array( (int) $t->term_id, $values, true ), true, false ), esc_html( $t->name ) );
			}
			echo '</select>';
			return;
		}
		$type = 'checkbox' === $field['field_type'] ? 'checkbox' : 'radio';
		echo '<ul class="pcf-choice-list pcf-terms">';
		foreach ( $terms as $t ) {
			printf( '<li><label><input type="%s" name="%s" value="%d"%s /> %s</label></li>', esc_attr( $type ), esc_attr( $name ), (int) $t->term_id, checked( in_array( (int) $t->term_id, $values, true ), true, false ), esc_html( $t->name ) );
		}
		echo '</ul>';
	}

	public function update_value( $value, $post_id, $field ) {
		$ids = array();
		foreach ( pcf_get_array( $value ) as $v ) {
			if ( $v instanceof WP_Term ) {
				$ids[] = $v->term_id;
				continue;
			}
			if ( is_array( $v ) ) {
				$v = pcf_maybe_get( $v, 'term_id', pcf_maybe_get( $v, 'id', '' ) );
			}
			if ( is_numeric( $v ) ) {
				$ids[] = (int) $v;
				continue;
			}
			if ( ! is_string( $v ) || '' === $v ) {
				continue;
			}
			$term = get_term_by( 'slug', $v, $field['taxonomy'] );
			$term = $term ? $term : get_term_by( 'name', $v, $field['taxonomy'] );
			if ( $term ) {
				$ids[] = $term->term_id;
			} elseif ( ! empty( $field['add_term'] ) ) {
				$new = wp_insert_term( $v, $field['taxonomy'] );
				if ( ! is_wp_error( $new ) ) {
					$ids[] = $new['term_id'];
				}
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! empty( $field['save_terms'] ) && is_numeric( $post_id ) ) {
			wp_set_object_terms( (int) $post_id, $ids, $field['taxonomy'], false );
		}
		$ids = array_map( 'strval', $ids );
		if ( ! empty( $field['multiple'] ) ) {
			return $ids ? $ids : '';
		}
		return $ids ? $ids[0] : '';
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) ) {
			return false;
		}
		$out = array();
		foreach ( pcf_get_array( $value ) as $id ) {
			$term = get_term( (int) $id, $field['taxonomy'] );
			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = 'object' === $field['return_format'] ? $term : (int) $id;
			}
		}
		return ! empty( $field['multiple'] ) ? $out : ( $out ? $out[0] : false );
	}
}

class PCF_Field_User extends PCF_Field {
	protected function setup() {
		$this->name        = 'user';
		$this->label       = 'Usuario';
		$this->category    = 'relational';
		$this->description = 'Selecciona usuario(s).';
		$this->settings    = array(
			'role'          => self::s( 'array', array(), 'Roles permitidos.' ),
			'allow_null'    => self::s( array( 'integer', 'boolean' ), 0, 'Permitir vacío.' ),
			'multiple'      => self::s( array( 'integer', 'boolean' ), 0, 'Múltiple.' ),
			'return_format' => self::s( 'string', 'array', 'Formato devuelto.', array( 'array', 'object', 'id' ) ),
		);
	}

	public function value_format() {
		return 'ID de usuario (o login/email), o array';
	}

	public function render_field( $field ) {
		$users  = get_users( array( 'role__in' => (array) $field['role'], 'number' => 300, 'orderby' => 'display_name' ) );
		$values = array_map( 'intval', pcf_get_array( $field['value'] ) );
		$multi  = ! empty( $field['multiple'] );
		if ( $multi ) {
			printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		}
		printf( '<select name="%s"%s>', esc_attr( $field['input_name'] . ( $multi ? '[]' : '' ) ), $multi ? ' multiple size="6"' : '' );
		if ( ! $multi ) {
			echo '<option value="">—</option>';
		}
		foreach ( $users as $u ) {
			printf( '<option value="%d"%s>%s (%s)</option>', (int) $u->ID, selected( in_array( (int) $u->ID, $values, true ), true, false ), esc_html( $u->display_name ), esc_html( $u->user_login ) );
		}
		echo '</select>';
	}

	public function update_value( $value, $post_id, $field ) {
		$ids = array();
		foreach ( pcf_get_array( $value ) as $v ) {
			if ( $v instanceof WP_User ) {
				$ids[] = $v->ID;
			} elseif ( is_array( $v ) ) {
				$ids[] = (int) pcf_maybe_get( $v, 'ID', 0 );
			} elseif ( is_numeric( $v ) ) {
				$ids[] = (int) $v;
			} elseif ( is_string( $v ) ) {
				$u     = get_user_by( is_email( $v ) ? 'email' : 'login', $v );
				$ids[] = $u ? $u->ID : 0;
			}
		}
		$ids = array_map( 'strval', array_values( array_filter( $ids ) ) );
		if ( ! empty( $field['multiple'] ) ) {
			return $ids ? $ids : '';
		}
		return $ids ? $ids[0] : '';
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) ) {
			return false;
		}
		$out = array();
		foreach ( pcf_get_array( $value ) as $id ) {
			$user = get_userdata( (int) $id );
			if ( ! $user ) {
				continue;
			}
			if ( 'id' === $field['return_format'] ) {
				$out[] = (int) $id;
			} elseif ( 'object' === $field['return_format'] ) {
				$out[] = $user;
			} else {
				$out[] = array(
					'ID'               => $user->ID,
					'user_firstname'   => $user->user_firstname,
					'user_lastname'    => $user->user_lastname,
					'nickname'         => $user->nickname,
					'user_nicename'    => $user->user_nicename,
					'display_name'     => $user->display_name,
					'user_email'       => $user->user_email,
					'user_url'         => $user->user_url,
					'user_registered'  => $user->user_registered,
					'user_description' => $user->user_description,
					'user_avatar'      => get_avatar( $user->ID ),
				);
			}
		}
		return ! empty( $field['multiple'] ) ? $out : ( $out ? $out[0] : false );
	}
}
