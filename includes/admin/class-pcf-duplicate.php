<?php
/**
 * Duplicar entradas con sus campos.
 *
 * WordPress no trae "duplicar". Para un sitio armado con PCF eso duele más
 * de lo normal: una página puede tener decenas de campos —repetidores
 * incluidos— y rehacerla a mano es media tarde de copiar y pegar.
 *
 * Los valores de PCF viven en el meta del post (`nombre` con el valor y
 * `_nombre` con la key del campo, ver pcf-meta-functions.php), así que copiar
 * TODO el meta copia los campos sin que haya que saber nada de ellos: vale
 * igual para un texto suelto que para la fila 7 de un repetidor anidado.
 *
 * La copia sale como borrador y con el autor de quien la hizo, para que
 * publicar sea siempre una decisión explícita.
 *
 * Puntos de extensión:
 *   · `pcf/duplicate/post_types`  (array)  qué tipos muestran el enlace.
 *   · `pcf/duplicate/skip_meta`   (array)  claves de meta que no se copian.
 *   · `pcf/duplicate/postarr`     (array)  el post antes de insertarse.
 *   · `pcf/duplicate/after`       (acción) id nuevo, id original.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Duplicate {

	/** Acción de admin-post.php y nombre de la acción masiva. */
	const ACTION = 'pcf_duplicate';

	/**
	 * Tipos que nunca se duplican desde acá, aunque tengan UI: o los maneja
	 * el núcleo con su propia pantalla, o duplicarlos no significa nada.
	 */
	const EXCLUIDOS = array(
		'attachment',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
	);

	/**
	 * Meta que pertenece a la entrada vieja, no a su contenido: candados de
	 * edición, rastros de la papelera y slugs anteriores.
	 */
	const SKIP_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_pingme',
		'_encloseme',
	);

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'submitbox_button' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_bulk' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Tipos de contenido donde aparece el enlace.
	 *
	 * Se resuelve una vez por petición: row_action() corre por cada fila del
	 * listado y no tiene sentido recalcular el filtro veinte veces.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		static $tipos = null;

		if ( null === $tipos ) {
			$lista = array_values( array_diff( get_post_types( array( 'show_ui' => true ) ), self::EXCLUIDOS ) );

			/** @param string[] $lista */
			$tipos = (array) apply_filters( 'pcf/duplicate/post_types', $lista );
		}

		return $tipos;
	}

	/**
	 * ¿Este usuario puede duplicar esta entrada? Hace falta poder editar la
	 * original —para no filtrar borradores ajenos— y poder crear entradas de
	 * ese tipo.
	 *
	 * @param WP_Post|int $post
	 */
	public static function user_can( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}
		$obj = get_post_type_object( $post->post_type );
		if ( ! $obj ) {
			return false;
		}

		return current_user_can( 'edit_post', $post->ID ) && current_user_can( $obj->cap->create_posts );
	}

	/**
	 * URL con nonce para duplicar una entrada.
	 *
	 * @param WP_Post|int $post
	 */
	public static function url( $post ) {
		$post = get_post( $post );

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $post->ID
		);
	}

	/**
	 * Enlace "Duplicar" en la fila del listado, junto a Editar y Papelera.
	 *
	 * @param array   $actions
	 * @param WP_Post $post
	 */
	public static function row_action( $actions, $post ) {
		if ( ! self::user_can( $post ) ) {
			return $actions;
		}

		$actions['pcf_duplicate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url( $post ) ),
			esc_html__( 'Duplicar', 'pcf' )
		);

		return $actions;
	}

	/**
	 * Botón dentro de la caja "Publicar" del editor clásico, para no tener
	 * que volver al listado.
	 *
	 * @param WP_Post $post
	 */
	public static function submitbox_button( $post ) {
		if ( 'auto-draft' === $post->post_status || ! self::user_can( $post ) ) {
			return;
		}

		printf(
			'<div class="misc-pub-section pcf-duplicate-section"><a class="button" href="%s">%s</a></div>',
			esc_url( self::url( $post ) ),
			esc_html__( 'Duplicar con sus campos', 'pcf' )
		);
	}

	/**
	 * Acción masiva "Duplicar" en cada listado.
	 */
	public static function register_bulk() {
		foreach ( self::post_types() as $tipo ) {
			add_filter( "bulk_actions-edit-{$tipo}", array( __CLASS__, 'bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$tipo}", array( __CLASS__, 'handle_bulk' ), 10, 3 );
		}
	}

	public static function bulk_action( $acciones ) {
		$acciones[ self::ACTION ] = __( 'Duplicar', 'pcf' );

		return $acciones;
	}

	/**
	 * @param string $redirect
	 * @param string $accion
	 * @param int[]  $ids
	 */
	public static function handle_bulk( $redirect, $accion, $ids ) {
		if ( self::ACTION !== $accion ) {
			return $redirect;
		}

		$hechas = 0;
		foreach ( (array) $ids as $id ) {
			if ( ! self::user_can( $id ) ) {
				continue;
			}
			if ( ! is_wp_error( self::duplicate( (int) $id ) ) ) {
				$hechas++;
			}
		}

		return add_query_arg( 'pcf_duplicated', $hechas, remove_query_arg( 'pcf_duplicate_error', $redirect ) );
	}

	/**
	 * Duplica una entrada y abre la copia en el editor.
	 */
	public static function handle() {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		if ( $id <= 0 || ! self::user_can( $id ) ) {
			wp_die( esc_html__( 'No tienes permiso para duplicar esta entrada.', 'pcf' ), 403 );
		}

		check_admin_referer( self::ACTION . '_' . $id );

		$nuevo = self::duplicate( $id );

		if ( is_wp_error( $nuevo ) ) {
			$volver = get_edit_post_link( $id, 'raw' );
			wp_safe_redirect( add_query_arg( 'pcf_duplicate_error', 1, $volver ? $volver : admin_url() ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'pcf_duplicated', 1, get_edit_post_link( $nuevo, 'raw' ) ) );
		exit;
	}

	/**
	 * El duplicado en sí: entrada, meta (o sea, los campos) y taxonomías.
	 *
	 * @param int $id Entrada original.
	 * @return int|WP_Error ID de la copia.
	 */
	public static function duplicate( $id ) {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'pcf_duplicate_missing', __( 'La entrada original no existe.', 'pcf' ) );
		}

		/* translators: %s: título de la entrada original. */
		$titulo = sprintf( __( '%s (copia)', 'pcf' ), $post->post_title );

		$postarr = array(
			'post_type'      => $post->post_type,
			// Siempre borrador: publicar la copia es una decisión aparte.
			'post_status'    => 'draft',
			'post_title'     => $titulo,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_parent'    => $post->post_parent,
			'menu_order'     => $post->menu_order,
			'post_password'  => $post->post_password,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_author'    => get_current_user_id(),
			// Sin post_name: WordPress genera un slug libre a partir del título.
		);

		/**
		 * @param array   $postarr Datos de la copia.
		 * @param WP_Post $post    Entrada original.
		 */
		$postarr = (array) apply_filters( 'pcf/duplicate/postarr', $postarr, $post );

		// wp_insert_post espera los datos escapados: sin esto, una barra
		// invertida del contenido desaparece en cada copia.
		$nuevo = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $nuevo ) ) {
			return $nuevo;
		}

		self::copy_meta( $post->ID, $nuevo );
		self::copy_terms( $post, $nuevo );

		/**
		 * @param int $nuevo    ID de la copia.
		 * @param int $original ID de la entrada original.
		 */
		do_action( 'pcf/duplicate/after', $nuevo, $post->ID );

		return $nuevo;
	}

	/**
	 * Copia el meta, que es donde viven los valores de los campos.
	 *
	 * Se leen los valores crudos y se reconstruyen con la API de meta, para
	 * que los arrays se vuelvan a serializar bien y se disparen los hooks de
	 * siempre. Las claves repetidas conservan sus varias filas.
	 */
	protected static function copy_meta( $origen, $destino ) {
		$saltar = (array) apply_filters( 'pcf/duplicate/skip_meta', self::SKIP_META, $origen, $destino );

		foreach ( get_post_meta( $origen ) as $clave => $valores ) {
			if ( in_array( $clave, $saltar, true ) ) {
				continue;
			}

			foreach ( (array) $valores as $valor ) {
				// add_post_meta deshace el escapado y vuelve a serializar.
				add_post_meta( $destino, $clave, wp_slash( maybe_unserialize( $valor ) ) );
			}
		}
	}

	/**
	 * Copia categorías, etiquetas y cualquier taxonomía del tipo.
	 */
	protected static function copy_terms( WP_Post $post, $destino ) {
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$terminos = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terminos ) || empty( $terminos ) ) {
				continue;
			}
			wp_set_object_terms( $destino, $terminos, $tax );
		}
	}

	/**
	 * Aviso tras duplicar, tanto en el editor de la copia como en el listado
	 * después de una acción masiva.
	 */
	public static function notice() {
		if ( isset( $_GET['pcf_duplicate_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'No se pudo duplicar la entrada.', 'pcf' )
			);
			return;
		}

		if ( ! isset( $_GET['pcf_duplicated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$n = (int) $_GET['pcf_duplicated']; // phpcs:ignore WordPress.Security.NonceVerification

		$mensaje = 1 === $n
			? __( 'Copia creada como borrador, con sus campos.', 'pcf' )
			: sprintf(
				/* translators: %s: número de copias. */
				_n( '%s copia creada como borrador, con sus campos.', '%s copias creadas como borrador, con sus campos.', $n, 'pcf' ),
				number_format_i18n( $n )
			);

		if ( 0 === $n ) {
			$mensaje = __( 'No se duplicó ninguna entrada.', 'pcf' );
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			0 === $n ? 'warning' : 'success',
			esc_html( $mensaje )
		);
	}
}

PCF_Duplicate::init();
