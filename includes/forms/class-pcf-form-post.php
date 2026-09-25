<?php
/**
 * Formulario de entradas (meta boxes) y adjuntos.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Form_Post {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );
		add_action( 'edit_attachment', array( __CLASS__, 'save_attachment' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title' ) );
	}

	public static function enqueue( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			PCF_Renderer::enqueue();
		}
	}

	public static function add_meta_boxes( $post_type, $post ) {
		if ( ! $post instanceof WP_Post || 0 === strpos( $post_type, 'pcf-' ) ) {
			return;
		}
		$groups = pcf_get_field_groups(
			array(
				'post_id'       => $post->ID,
				'post_type'     => $post_type,
				'page_template' => get_page_template_slug( $post ),
			)
		);
		$hide = array();
		foreach ( $groups as $group ) {
			$context  = 'side' === $group['position'] ? 'side' : ( 'acf_after_title' === $group['position'] ? 'pcf_after_title' : 'normal' );
			$priority = 'high';
			add_meta_box(
				'pcf-' . $group['key'],
				esc_html( $group['title'] ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				$context,
				$priority,
				array( 'group' => $group )
			);
			if ( 'seamless' === $group['style'] ) {
				add_filter(
					"postbox_classes_{$post_type}_pcf-{$group['key']}",
					function ( $classes ) {
						$classes[] = 'pcf-seamless';
						return $classes;
					}
				);
			}
			$hide = array_merge( $hide, (array) $group['hide_on_screen'] );
		}
		if ( $hide ) {
			self::hide_on_screen( array_unique( $hide ) );
		}
	}

	public static function after_title() {
		global $post, $wp_meta_boxes;
		do_meta_boxes( get_current_screen(), 'pcf_after_title', $post );
	}

	public static function render_meta_box( $post, $box ) {
		PCF_Renderer::render_group( $box['args']['group'], $post->ID );
	}

	/**
	 * Oculta elementos del editor clásico (mismo vocabulario que ACF).
	 */
	protected static function hide_on_screen( $items ) {
		$map = array(
			'permalink'       => '#edit-slug-box',
			'the_content'     => '#postdivrich',
			'excerpt'         => '#postexcerpt',
			'discussion'      => '#commentstatusdiv',
			'comments'        => '#commentsdiv',
			'revisions'       => '#revisionsdiv',
			'slug'            => '#slugdiv',
			'author'          => '#authordiv',
			'format'          => '#formatdiv',
			'page_attributes' => '#pageparentdiv',
			'featured_image'  => '#postimagediv',
			'categories'      => '#categorydiv',
			'tags'            => '#tagsdiv-post_tag',
			'send-trackbacks' => '#trackbacksdiv',
		);
		$css = array();
		foreach ( $items as $item ) {
			if ( isset( $map[ $item ] ) ) {
				$css[] = $map[ $item ];
			}
		}
		if ( $css ) {
			add_action(
				'admin_head',
				function () use ( $css ) {
					echo '<style>' . esc_html( implode( ',', $css ) ) . '{display:none!important}</style>';
				}
			);
		}
	}

	public static function save_post( $post_id, $post ) {
		static $running = false;
		if ( $running || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! PCF_Renderer::verify_nonce() || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$running = true;
		PCF_Renderer::save_submission( $post_id, PCF_Renderer::submitted_groups() );
		$running = false;
	}

	public static function save_attachment( $post_id ) {
		self::save_post( $post_id, get_post( $post_id ) );
	}
}

PCF_Form_Post::init();
