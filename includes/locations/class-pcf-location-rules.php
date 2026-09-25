<?php
/**
 * Reglas de ubicación.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Location_Post_Type extends PCF_Location {
	protected function initialize() {
		$this->name  = 'post_type';
		$this->label = 'Tipo de contenido';
	}
	public function match( $rule, $screen, $group ) {
		return ! empty( $screen['post_type'] ) && empty( $screen['block'] ) && $this->compare( $screen['post_type'], $rule );
	}
	public function get_values( $rule = array() ) {
		return pcf_get_post_type_choices();
	}
}

class PCF_Location_Post extends PCF_Location {
	protected function initialize() {
		$this->name  = 'post';
		$this->label = 'Entrada específica';
	}
	public function match( $rule, $screen, $group ) {
		return ! empty( $screen['post_id'] ) && $this->compare( $screen['post_id'], $rule );
	}
	public function get_values( $rule = array() ) {
		return array( '<post_id>' => 'ID de cualquier entrada (usa list-posts para buscar)' );
	}
}

class PCF_Location_Page extends PCF_Location_Post {
	protected function initialize() {
		$this->name  = 'page';
		$this->label = 'Página específica';
	}
	public function match( $rule, $screen, $group ) {
		return ! empty( $screen['post_id'] ) && 'page' === pcf_maybe_get( $screen, 'post_type' ) && $this->compare( $screen['post_id'], $rule );
	}
	public function get_values( $rule = array() ) {
		$out = array();
		foreach ( get_pages( array( 'number' => 200 ) ) as $p ) {
			$out[ $p->ID ] = $p->post_title;
		}
		return $out;
	}
}

class PCF_Location_Page_Template extends PCF_Location {
	protected function initialize() {
		$this->name  = 'page_template';
		$this->label = 'Plantilla de página';
	}
	public function match( $rule, $screen, $group ) {
		if ( 'page' !== pcf_maybe_get( $screen, 'post_type' ) ) {
			return false;
		}
		$template = pcf_maybe_get( $screen, 'page_template' );
		if ( null === $template && ! empty( $screen['post_id'] ) ) {
			$template = get_page_template_slug( $screen['post_id'] );
		}
		return $this->compare( $template ? $template : 'default', $rule );
	}
	public function get_values( $rule = array() ) {
		return array_merge( array( 'default' => 'Plantilla por defecto' ), array_flip( wp_get_theme()->get_page_templates( null, 'page' ) ) );
	}
}

class PCF_Location_Post_Template extends PCF_Location {
	protected function initialize() {
		$this->name  = 'post_template';
		$this->label = 'Plantilla de entrada';
	}
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['post_type'] ) ) {
			return false;
		}
		$template = pcf_maybe_get( $screen, 'page_template' );
		if ( null === $template && ! empty( $screen['post_id'] ) ) {
			$template = get_page_template_slug( $screen['post_id'] );
		}
		return $this->compare( $template ? $template : 'default', $rule );
	}
	public function get_values( $rule = array() ) {
		$out = array( 'default' => 'Plantilla por defecto' );
		foreach ( wp_get_theme()->get_post_templates() as $templates ) {
			$out = array_merge( $out, $templates );
		}
		return $out;
	}
}

class PCF_Location_Post_Status extends PCF_Location {
	protected function initialize() {
		$this->name  = 'post_status';
		$this->label = 'Estado de la entrada';
	}
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['post_id'] ) ) {
			return false;
		}
		return $this->compare( get_post_status( $screen['post_id'] ), $rule );
	}
	public function get_values( $rule = array() ) {
		return get_post_statuses();
	}
}

class PCF_Location_Post_Format extends PCF_Location {
	protected function initialize() {
		$this->name  = 'post_format';
		$this->label = 'Formato de entrada';
	}
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['post_id'] ) ) {
			return false;
		}
		$format = get_post_format( $screen['post_id'] );
		return $this->compare( $format ? $format : 'standard', $rule );
	}
	public function get_values( $rule = array() ) {
		return get_post_format_strings();
	}
}

class PCF_Location_Post_Taxonomy extends PCF_Location {
	protected function initialize() {
		$this->name  = 'post_taxonomy';
		$this->label = 'Término de la entrada';
	}
	/**
	 * Valor: "taxonomy:slug".
	 */
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['post_id'] ) || false === strpos( $rule['value'], ':' ) ) {
			return false;
		}
		list( $tax, $slug ) = explode( ':', $rule['value'], 2 );
		$terms              = wp_get_post_terms( $screen['post_id'], $tax, array( 'fields' => 'slugs' ) );
		$terms              = is_wp_error( $terms ) ? array() : $terms;
		$match              = in_array( $slug, $terms, true );
		return '!=' === $rule['operator'] ? ! $match : $match;
	}
	public function get_values( $rule = array() ) {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			foreach ( get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false, 'number' => 50 ) ) as $term ) {
				$out[ $tax->name . ':' . $term->slug ] = $tax->labels->singular_name . ': ' . $term->name;
			}
		}
		return $out;
	}
}

class PCF_Location_Post_Category extends PCF_Location_Post_Taxonomy {
	protected function initialize() {
		$this->name  = 'post_category';
		$this->label = 'Categoría de la entrada';
	}
	public function match( $rule, $screen, $group ) {
		if ( false === strpos( $rule['value'], ':' ) ) {
			$rule['value'] = 'category:' . $rule['value'];
		}
		return parent::match( $rule, $screen, $group );
	}
}

class PCF_Location_Page_Type extends PCF_Location {
	protected function initialize() {
		$this->name  = 'page_type';
		$this->label = 'Tipo de página';
	}
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['post_id'] ) || 'page' !== pcf_maybe_get( $screen, 'post_type' ) ) {
			return false;
		}
		$post = get_post( $screen['post_id'] );
		switch ( $rule['value'] ) {
			case 'front_page':
				$m = (int) get_option( 'page_on_front' ) === $post->ID;
				break;
			case 'posts_page':
				$m = (int) get_option( 'page_for_posts' ) === $post->ID;
				break;
			case 'top_level':
				$m = 0 === (int) $post->post_parent;
				break;
			case 'parent':
				$m = (bool) get_children( array( 'post_parent' => $post->ID, 'post_type' => 'page', 'numberposts' => 1 ) );
				break;
			case 'child':
				$m = 0 !== (int) $post->post_parent;
				break;
			default:
				$m = false;
		}
		return '!=' === $rule['operator'] ? ! $m : $m;
	}
	public function get_values( $rule = array() ) {
		return array(
			'front_page' => 'Portada',
			'posts_page' => 'Página de entradas',
			'top_level'  => 'Primer nivel',
			'parent'     => 'Página padre',
			'child'      => 'Página hija',
		);
	}
}

class PCF_Location_Page_Parent extends PCF_Location_Page {
	protected function initialize() {
		$this->name  = 'page_parent';
		$this->label = 'Página superior';
	}
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['post_id'] ) ) {
			return false;
		}
		return $this->compare( wp_get_post_parent_id( $screen['post_id'] ), $rule );
	}
}

class PCF_Location_Current_User extends PCF_Location {
	protected function initialize() {
		$this->name     = 'current_user';
		$this->label    = 'Usuario actual';
		$this->category = 'user';
	}
	public function match( $rule, $screen, $group ) {
		switch ( $rule['value'] ) {
			case 'logged_in':
				$m = is_user_logged_in();
				break;
			case 'viewing_front':
				$m = ! is_admin();
				break;
			case 'viewing_back':
				$m = is_admin();
				break;
			default:
				$m = false;
		}
		return '!=' === $rule['operator'] ? ! $m : $m;
	}
	public function get_values( $rule = array() ) {
		return array( 'logged_in' => 'Conectado', 'viewing_front' => 'Viendo el front', 'viewing_back' => 'Viendo el admin' );
	}
}

class PCF_Location_Current_User_Role extends PCF_Location {
	protected function initialize() {
		$this->name     = 'current_user_role';
		$this->label    = 'Rol del usuario actual';
		$this->category = 'user';
	}
	public function match( $rule, $screen, $group ) {
		$user = wp_get_current_user();
		if ( 'super_admin' === $rule['value'] ) {
			$m = is_super_admin( $user->ID );
			return '!=' === $rule['operator'] ? ! $m : $m;
		}
		return $this->compare( $user->roles, $rule );
	}
	public function get_values( $rule = array() ) {
		return wp_roles()->get_names();
	}
}

class PCF_Location_User_Form extends PCF_Location {
	protected function initialize() {
		$this->name     = 'user_form';
		$this->label    = 'Formulario de usuario';
		$this->category = 'user';
	}
	public function match( $rule, $screen, $group ) {
		$form = pcf_maybe_get( $screen, 'user_form' );
		if ( ! $form ) {
			return false;
		}
		if ( 'all' === $rule['value'] ) {
			return '==' === $rule['operator'];
		}
		// "edit" incluye "add" como en ACF.
		$m = $form === $rule['value'] || ( 'edit' === $rule['value'] && 'add' === $form );
		return '!=' === $rule['operator'] ? ! $m : $m;
	}
	public function get_values( $rule = array() ) {
		return array( 'all' => 'Todos', 'add' => 'Añadir', 'edit' => 'Añadir / Editar', 'register' => 'Registro' );
	}
}

class PCF_Location_User_Role extends PCF_Location {
	protected function initialize() {
		$this->name     = 'user_role';
		$this->label    = 'Rol del usuario editado';
		$this->category = 'user';
	}
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['user_form'] ) ) {
			return false;
		}
		if ( 'all' === $rule['value'] ) {
			return '==' === $rule['operator'];
		}
		$roles = array();
		if ( ! empty( $screen['user_role'] ) ) {
			$roles = (array) $screen['user_role'];
		} elseif ( ! empty( $screen['user_id'] ) && ( $u = get_userdata( $screen['user_id'] ) ) ) {
			$roles = $u->roles;
		} else {
			$roles = array( get_option( 'default_role' ) );
		}
		return $this->compare( $roles, $rule );
	}
	public function get_values( $rule = array() ) {
		return array_merge( array( 'all' => 'Todos' ), wp_roles()->get_names() );
	}
}

class PCF_Location_Taxonomy extends PCF_Location {
	protected function initialize() {
		$this->name     = 'taxonomy';
		$this->label    = 'Taxonomía (formulario de término)';
		$this->category = 'forms';
	}
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['taxonomy'] ) ) {
			return false;
		}
		if ( 'all' === $rule['value'] ) {
			return '==' === $rule['operator'];
		}
		return $this->compare( $screen['taxonomy'], $rule );
	}
	public function get_values( $rule = array() ) {
		return array_merge( array( 'all' => 'Todas' ), pcf_get_taxonomy_choices() );
	}
}

class PCF_Location_Attachment extends PCF_Location {
	protected function initialize() {
		$this->name     = 'attachment';
		$this->label    = 'Adjunto';
		$this->category = 'forms';
	}
	public function match( $rule, $screen, $group ) {
		if ( 'attachment' !== pcf_maybe_get( $screen, 'post_type' ) ) {
			return false;
		}
		if ( 'all' === $rule['value'] ) {
			return '==' === $rule['operator'];
		}
		$mime = get_post_mime_type( $screen['post_id'] );
		$m    = $mime === $rule['value'] || 0 === strpos( (string) $mime, rtrim( $rule['value'], '/' ) . '/' );
		return '!=' === $rule['operator'] ? ! $m : $m;
	}
	public function get_values( $rule = array() ) {
		return array( 'all' => 'Todos', 'image' => 'Imágenes', 'video' => 'Vídeos', 'audio' => 'Audio', 'application/pdf' => 'PDF' );
	}
}

class PCF_Location_Comment extends PCF_Location {
	protected function initialize() {
		$this->name     = 'comment';
		$this->label    = 'Comentario';
		$this->category = 'forms';
	}
	public function match( $rule, $screen, $group ) {
		if ( ! isset( $screen['comment'] ) ) {
			return false;
		}
		return 'all' === $rule['value'] ? '==' === $rule['operator'] : $this->compare( $screen['comment'], $rule );
	}
	public function get_values( $rule = array() ) {
		return array( 'all' => 'Todos', 'comment' => 'Comentarios' );
	}
}

class PCF_Location_Options_Page extends PCF_Location {
	protected function initialize() {
		$this->name     = 'options_page';
		$this->label    = 'Página de opciones';
		$this->category = 'forms';
	}
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['options_page'] ) ) {
			return false;
		}
		return $this->compare( $screen['options_page'], $rule );
	}
	public function get_values( $rule = array() ) {
		$out = array();
		foreach ( pcf_get_options_pages() as $page ) {
			$out[ $page['menu_slug'] ] = $page['page_title'];
		}
		return $out;
	}
}

class PCF_Location_Block extends PCF_Location {
	protected function initialize() {
		$this->name     = 'block';
		$this->label    = 'Bloque';
		$this->category = 'forms';
	}
	public function match( $rule, $screen, $group ) {
		if ( empty( $screen['block'] ) ) {
			return false;
		}
		if ( 'all' === $rule['value'] ) {
			return '==' === $rule['operator'];
		}
		// Compatibilidad: acf/hero == pcf/hero si viene de ACF.
		$names = array( $screen['block'] );
		if ( 0 === strpos( $screen['block'], 'acf/' ) ) {
			$names[] = 'pcf/' . substr( $screen['block'], 4 );
		}
		return $this->compare( $names, $rule );
	}
	public function get_values( $rule = array() ) {
		$out = array( 'all' => 'Todos los bloques PCF' );
		foreach ( PCF_Blocks::get_blocks() as $name => $b ) {
			$out[ $name ] = $b['title'];
		}
		return $out;
	}
}
