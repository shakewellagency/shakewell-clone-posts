<?php
/**
 * Permission checks and the copy itself.
 *
 * @package Shakewell\ClonePosts
 */

namespace Shakewell\ClonePosts;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a draft copy of a post. Nothing here reads request data.
 */
final class Cloner {

	public const MARKER = '_shakewell_clone_of';

	/**
	 * Post types that can be copied: public types with an admin screen, except attachments.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		$types = array_diff(
			get_post_types(
				array(
					'public'  => true,
					'show_ui' => true,
				)
			),
			array( 'attachment' )
		);

		return array_values( (array) apply_filters( 'shakewell_clone_posts_post_types', $types ) );
	}

	/**
	 * Whether the current user may copy this post.
	 *
	 * @param WP_Post $post The original.
	 */
	public static function can( WP_Post $post ): bool {
		$type = get_post_type_object( $post->post_type );
		$can  = $type
			&& in_array( $post->post_type, self::types(), true )
			&& ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true )
			&& current_user_can( 'edit_post', $post->ID )
			&& current_user_can( $type->cap->create_posts );

		return (bool) apply_filters( 'shakewell_clone_posts_can_clone', $can, $post );
	}

	/**
	 * Copies a post to a new draft owned by the current user.
	 *
	 * @param int $original_id The post to copy.
	 * @return int|WP_Error The new post ID.
	 */
	public static function copy( int $original_id ) {
		$post = get_post( $original_id );
		if ( ! $post || ! self::can( $post ) ) {
			return new WP_Error( 'shakewell_clone_posts_forbidden', __( 'You are not allowed to copy this item.', 'shakewell-clone-posts' ) );
		}

		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'             => $post->post_type,
					'post_status'           => 'draft',
					'post_author'           => get_current_user_id(),
					'post_title'            => apply_filters(
						'shakewell_clone_posts_title',
						/* translators: %s: title of the original post. */
						sprintf( __( '%s (copy)', 'shakewell-clone-posts' ), $post->post_title ),
						$post
					),
					'post_name'             => '',
					'post_content'          => $post->post_content,
					'post_content_filtered' => $post->post_content_filtered,
					'post_excerpt'          => $post->post_excerpt,
					'post_parent'           => $post->post_parent,
					'menu_order'            => $post->menu_order,
					'comment_status'        => $post->comment_status,
					'ping_status'           => $post->ping_status,
					'post_password'         => apply_filters( 'shakewell_clone_posts_password', '', $post ),
				)
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		self::copy_meta( $post->ID, $new_id );
		add_post_meta( $new_id, self::MARKER, $post->ID );

		do_action( 'shakewell_clone_posts_cloned', $new_id, $post->ID );

		return $new_id;
	}

	/**
	 * Copies post meta. Raw values are read so serialised data (ACF, Co-Authors Plus) survives unchanged.
	 *
	 * @param int $from Original ID.
	 * @param int $to   Copy ID.
	 */
	private static function copy_meta( int $from, int $to ): void {
		$exclude = (array) apply_filters(
			'shakewell_clone_posts_exclude_meta',
			array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_*', '_wp_desired_post_slug', '_dp_*', self::MARKER )
		);

		foreach ( get_post_meta( $from ) as $key => $values ) {
			foreach ( $exclude as $pattern ) {
				if ( fnmatch( $pattern, $key ) ) {
					continue 2;
				}
			}
			foreach ( $values as $value ) {
				// The raw value is serialised text; unserialise so add_post_meta stores the same bytes, not a second layer.
				add_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}
}
