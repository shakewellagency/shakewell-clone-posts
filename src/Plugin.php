<?php
/**
 * Plugin bootstrap, request handling and admin screens.
 *
 * @package Shakewell\ClonePosts
 */

namespace Shakewell\ClonePosts;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Clone and New Draft actions into WordPress.
 */
final class Plugin {

	private const ACTION = 'shakewell_clone_posts';
	private const BULK   = 'shakewell_clone_posts_bulk';
	private const NOTICE = 'shakewell_clone_posts_done';

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'register_bulk' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'post_submitbox_misc_actions', array( self::class, 'classic_editor' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'block_editor' ) );
	}

	/**
	 * URL of a nonce-protected copy link.
	 *
	 * @param int    $id   Original ID.
	 * @param string $mode "clone" (stay on the list) or "draft" (open the copy).
	 */
	public static function url( int $id, string $mode ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'mode'   => $mode,
					'post'   => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $id
		);
	}

	/**
	 * Handles a Clone or New Draft click.
	 */
	public static function handle(): void {
		// The ID is only used to build the nonce action, which is checked on the next line.
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( self::ACTION . '_' . $id );

		$new_id = Cloner::copy( $id );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ), '', array( 'response' => 403 ) );
		}

		if ( isset( $_GET['mode'] ) && 'draft' === $_GET['mode'] ) {
			$to = get_edit_post_link( $new_id, 'raw' );
		} else {
			$fallback = admin_url( 'edit.php?post_type=' . get_post_type( $new_id ) );
			$to       = add_query_arg( self::NOTICE, $new_id, remove_query_arg( self::NOTICE, wp_get_referer() ? wp_get_referer() : $fallback ) );
		}

		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Adds Clone and New Draft to list screen rows.
	 *
	 * @param string[] $actions Row actions.
	 * @param WP_Post  $post    The row's post.
	 * @return string[]
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post || ! Cloner::can( $post ) ) {
			return $actions;
		}

		$title = get_the_title( $post );
		/* translators: %s: post title. */
		$clone = sprintf( __( 'Clone &#8220;%s&#8221;', 'shakewell-clone-posts' ), $title );
		/* translators: %s: post title. */
		$draft = sprintf( __( 'Copy &#8220;%s&#8221; to a new draft and edit it', 'shakewell-clone-posts' ), $title );

		$actions['shakewell_clone'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( self::url( $post->ID, 'clone' ) ),
			esc_attr( $clone ),
			esc_html__( 'Clone', 'shakewell-clone-posts' )
		);
		$actions['shakewell_draft'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( self::url( $post->ID, 'draft' ) ),
			esc_attr( $draft ),
			esc_html__( 'New Draft', 'shakewell-clone-posts' )
		);

		return $actions;
	}

	/**
	 * Adds a Clone bulk action to every copyable type's list screen.
	 */
	public static function register_bulk(): void {
		foreach ( Cloner::types() as $type ) {
			add_filter(
				'bulk_actions-edit-' . $type,
				static function ( $actions ) {
					$actions[ self::BULK ] = __( 'Clone', 'shakewell-clone-posts' );
					return $actions;
				}
			);
			// Core has already checked the bulk-posts nonce when this filter runs.
			add_filter(
				'handle_bulk_actions-edit-' . $type,
				static function ( $redirect, $action, $ids ) {
					if ( self::BULK !== $action ) {
						return $redirect;
					}
					$new = array();
					foreach ( $ids as $id ) {
						$copy = Cloner::copy( (int) $id );
						if ( ! is_wp_error( $copy ) ) {
							$new[] = $copy;
						}
					}
					return add_query_arg( self::NOTICE, implode( ',', $new ), $redirect );
				},
				10,
				3
			);
		}
	}

	/**
	 * Confirms new copies on the list screen.
	 */
	public static function notice(): void {
		if ( empty( $_GET[ self::NOTICE ] ) || ! is_string( $_GET[ self::NOTICE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}

		$ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET[ self::NOTICE ] ) ) ) ), static fn( $id ) => current_user_can( 'edit_post', $id ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $ids ) {
			return;
		}

		if ( 1 === count( $ids ) ) {
			$id      = reset( $ids );
			$message = sprintf(
				/* translators: %s: link to the new draft. */
				esc_html__( 'Draft copy created: %s', 'shakewell-clone-posts' ),
				'<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>'
			);
		} else {
			$message = esc_html(
				sprintf(
					/* translators: %d: number of drafts. */
					_n( '%d draft copy created.', '%d draft copies created.', count( $ids ), 'shakewell-clone-posts' ),
					count( $ids )
				)
			);
		}

		wp_admin_notice(
			$message,
			array(
				'type'        => 'success',
				'dismissible' => true,
				'attributes'  => array( 'role' => 'status' ),
			)
		);
	}

	/**
	 * Data the editors show: link to copy this post, and a link back to its original.
	 *
	 * @param WP_Post $post The post being edited.
	 * @return array{copyUrl:string,copyLabel:string,ofLabel:string,ofTitle:string,ofUrl:string}
	 */
	private static function editor_data( WP_Post $post ): array {
		$original = (int) get_post_meta( $post->ID, Cloner::MARKER, true );
		$has_of   = $original && get_post( $original ) && current_user_can( 'edit_post', $original );

		return array(
			'copyUrl'   => Cloner::can( $post ) ? self::url( $post->ID, 'draft' ) : '',
			'copyLabel' => __( 'Copy to a new draft', 'shakewell-clone-posts' ),
			'ofLabel'   => __( 'Copy of:', 'shakewell-clone-posts' ),
			'ofTitle'   => $has_of ? get_the_title( $original ) : '',
			'ofUrl'     => $has_of ? (string) get_edit_post_link( $original, 'raw' ) : '',
		);
	}

	/**
	 * Classic editor: publish box links.
	 *
	 * @param WP_Post $post The post being edited.
	 */
	public static function classic_editor( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$data = self::editor_data( $post );
		if ( $data['copyUrl'] ) {
			printf( '<div class="misc-pub-section"><a href="%s">%s</a></div>', esc_url( $data['copyUrl'] ), esc_html( $data['copyLabel'] ) );
		}
		if ( $data['ofUrl'] ) {
			printf( '<div class="misc-pub-section">%s <a href="%s">%s</a></div>', esc_html( $data['ofLabel'] ), esc_url( $data['ofUrl'] ), esc_html( $data['ofTitle'] ) );
		}
	}

	/**
	 * Block editor: a row in the post status panel (script is plain JS, no build step).
	 */
	public static function block_editor(): void {
		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$data = self::editor_data( $post );
		if ( ! $data['copyUrl'] && ! $data['ofUrl'] ) {
			return;
		}

		wp_enqueue_script(
			'shakewell-clone-posts-editor',
			plugins_url( 'assets/editor.js', FILE ),
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element' ),
			VERSION,
			true
		);
		wp_add_inline_script( 'shakewell-clone-posts-editor', 'window.shakewellClonePosts = ' . wp_json_encode( $data ) . ';', 'before' );
	}
}
