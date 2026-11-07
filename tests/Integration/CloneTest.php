<?php
/**
 * Behaviour tests for copying and for the request handler.
 *
 * @package Shakewell\ClonePosts
 */

namespace Shakewell\ClonePosts\Tests;

use Shakewell\ClonePosts\Cloner;
use Shakewell\ClonePosts\Plugin;
use WP_UnitTestCase;
use WPDieException;

final class CloneTest extends WP_UnitTestCase {

	private int $editor;

	public function set_up(): void {
		parent::set_up();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
	}

	private function original( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => 'Hello',
					'post_content' => '<p>Body</p>',
					'post_excerpt' => 'Short',
					'post_status'  => 'publish',
					'post_name'    => 'hello',
					'post_author'  => self::factory()->user->create( array( 'role' => 'author' ) ),
				),
				$args
			)
		);
	}

	private function copy( int $id ): int {
		$new = Cloner::copy( $id );
		$this->assertIsInt( $new );
		return $new;
	}

	/** Runs the admin-post handler and returns the redirect target. */
	private function request( int $id, string $mode, ?string $nonce = 'valid' ): string {
		$_GET = array( 'post' => (string) $id, 'mode' => $mode );
		if ( null !== $nonce ) {
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'shakewell_clone_posts_' . $id );
		} else {
			unset( $_REQUEST['_wpnonce'] );
		}
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		);
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) {
			throw new WPDieException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		} );
		try {
			Plugin::handle();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'No redirect.' );
	}

	public function test_copy_is_a_draft_by_current_user_with_copy_title_and_empty_slug(): void {
		$new  = get_post( $this->copy( $this->original() ) );
		$this->assertSame( 'draft', $new->post_status );
		$this->assertSame( $this->editor, (int) $new->post_author );
		$this->assertSame( 'Hello (copy)', $new->post_title );
		$this->assertSame( '', $new->post_name );
	}

	public function test_title_is_filterable(): void {
		add_filter( 'shakewell_clone_posts_title', static fn() => 'Custom' );
		$this->assertSame( 'Custom', get_post( $this->copy( $this->original() ) )->post_title );
	}

	public function test_content_excerpt_parent_menu_order_and_statuses_are_copied(): void {
		$parent = $this->original();
		$id     = $this->original( array( 'post_type' => 'page', 'post_parent' => $parent, 'menu_order' => 7, 'comment_status' => 'closed', 'ping_status' => 'closed' ) );
		$new    = get_post( $this->copy( $id ) );
		$this->assertSame( '<p>Body</p>', $new->post_content );
		$this->assertSame( 'Short', $new->post_excerpt );
		$this->assertSame( $parent, (int) $new->post_parent );
		$this->assertSame( 7, (int) $new->menu_order );
		$this->assertSame( 'closed', $new->comment_status );
		$this->assertSame( 'closed', $new->ping_status );
	}

	public function test_template_and_featured_image_are_copied(): void {
		$id    = $this->original( array( 'post_type' => 'page' ) );
		$image = self::factory()->attachment->create_object( array( 'file' => 'a.jpg', 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $id, '_wp_page_template', 'wide.php' );
		set_post_thumbnail( $id, $image );
		$new = $this->copy( $id );
		$this->assertSame( 'wide.php', get_post_meta( $new, '_wp_page_template', true ) );
		$this->assertSame( $image, (int) get_post_thumbnail_id( $new ) );
	}

	public function test_terms_and_post_format_are_copied(): void {
		$id  = $this->original();
		$cat = self::factory()->category->create();
		wp_set_post_categories( $id, array( $cat ) );
		wp_set_post_tags( $id, array( 'one', 'two' ) );
		set_post_format( $id, 'aside' );
		$new = $this->copy( $id );
		$this->assertSame( array( $cat ), wp_get_post_categories( $new ) );
		$this->assertEqualSets( array( 'one', 'two' ), wp_get_post_tags( $new, array( 'fields' => 'names' ) ) );
		$this->assertSame( 'aside', get_post_format( $new ) );
	}

	public function test_meta_is_copied_byte_for_byte_including_serialised_and_acf_keys(): void {
		global $wpdb;
		$id = $this->original();
		update_post_meta( $id, 'plain', "O'Reilly \\ \"quoted\"" );
		update_post_meta( $id, 'rows', array( 'a' => array( 1, "x\\y", 'z' => array( 'k' => "it's" ) ) ) );
		update_post_meta( $id, '_rows', 'field_abc123' );
		add_post_meta( $id, 'multi', 'one' );
		add_post_meta( $id, 'multi', 'two' );
		$new = $this->copy( $id );

		foreach ( array( 'plain', 'rows', '_rows' ) as $key ) {
			$raw = fn( $post ) => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post, $key ) );
			$this->assertSame( $raw( $id ), $raw( $new ), $key );
		}
		$this->assertSame( array( 'one', 'two' ), get_post_meta( $new, 'multi' ) );
		$this->assertSame( 'field_abc123', get_post_meta( $new, '_rows', true ) );
	}

	public function test_excluded_meta_is_not_copied(): void {
		$id = $this->original();
		foreach ( array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_dp_original', '_dp_is_rewrite_republish_copy', 'keep' ) as $key ) {
			update_post_meta( $id, $key, 'x' );
		}
		update_post_meta( $id, Cloner::MARKER, 999 );
		$new  = $this->copy( $id );
		$keys = array_keys( get_post_meta( $new ) );
		foreach ( array( '_edit_lock', '_wp_old_slug', '_wp_old_date', '_dp_original', '_dp_is_rewrite_republish_copy' ) as $key ) {
			$this->assertNotContains( $key, $keys, $key );
		}
		$this->assertNotSame( 'x', get_post_meta( $new, '_edit_last', true ) );
		$this->assertContains( 'keep', $keys );
		$this->assertSame( $id, (int) get_post_meta( $new, Cloner::MARKER, true ) );
	}

	public function test_exclude_list_is_filterable(): void {
		$id = $this->original();
		update_post_meta( $id, 'secret', 'x' );
		add_filter( 'shakewell_clone_posts_exclude_meta', static fn( $list ) => array_merge( $list, array( 'secret' ) ) );
		$this->assertSame( '', get_post_meta( $this->copy( $id ), 'secret', true ) );
	}

	public function test_password_is_not_copied_by_default(): void {
		$id = $this->original( array( 'post_password' => 'pw' ) );
		$this->assertSame( '', get_post( $this->copy( $id ) )->post_password );
	}

	public function test_children_and_attachments_are_not_copied(): void {
		$id = $this->original( array( 'post_type' => 'page' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_parent' => $id ) );
		self::factory()->attachment->create_object( array( 'file' => 'b.jpg', 'post_mime_type' => 'image/jpeg', 'post_parent' => $id ) );
		$new = $this->copy( $id );
		$this->assertSame( array(), get_children( array( 'post_parent' => $new, 'post_type' => 'any' ) ) );
	}

	public function test_marker_is_set_and_action_fires(): void {
		$id    = $this->original();
		$fired = array();
		add_action( 'shakewell_clone_posts_cloned', static function ( $new, $old ) use ( &$fired ) {
			$fired = array( $new, $old );
		}, 10, 2 );
		$new = $this->copy( $id );
		$this->assertSame( $id, (int) get_post_meta( $new, '_shakewell_clone_of', true ) );
		$this->assertSame( array( $new, $id ), $fired );
	}

	public function test_refused_without_nonce(): void {
		$this->expectException( WPDieException::class );
		$this->request( $this->original(), 'clone', null );
	}

	public function test_refused_without_edit_post_on_original(): void {
		$id = $this->original( array( 'post_status' => 'private' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertWPError( Cloner::copy( $id ) );
		$this->expectException( WPDieException::class );
		$this->request( $id, 'clone' );
	}

	public function test_refused_for_a_disallowed_post_type(): void {
		$id = $this->original();
		add_filter( 'shakewell_clone_posts_post_types', static fn() => array( 'page' ) );
		$this->assertWPError( Cloner::copy( $id ) );
	}

	public function test_refused_for_users_who_cannot_create_that_type(): void {
		register_post_type( 'locked', array( 'public' => true, 'show_ui' => true, 'capability_type' => array( 'locked', 'lockeds' ), 'capabilities' => array( 'create_posts' => 'create_lockeds' ), 'map_meta_cap' => true ) );
		$id = self::factory()->post->create( array( 'post_type' => 'locked', 'post_status' => 'draft', 'post_author' => $this->editor ) );
		wp_get_current_user()->add_cap( 'edit_lockeds' );
		$this->assertTrue( current_user_can( 'edit_post', $id ) );
		$this->assertFalse( current_user_can( get_post_type_object( 'locked' )->cap->create_posts ) );
		$this->assertWPError( Cloner::copy( $id ) );
		unregister_post_type( 'locked' );
	}

	public function test_new_draft_redirects_to_the_edit_screen(): void {
		$id       = $this->original();
		$location = $this->request( $id, 'draft' );
		$new      = (int) get_posts( array( 'post_status' => 'draft', 'fields' => 'ids', 'numberposts' => 1 ) )[0];
		$this->assertSame( get_edit_post_link( $new, 'raw' ), $location );
	}

	public function test_clone_redirects_to_the_list_with_a_notice_id(): void {
		$id       = $this->original();
		$location = $this->request( $id, 'clone' );
		$this->assertStringContainsString( 'edit.php?post_type=post', $location );
		$this->assertStringContainsString( 'shakewell_clone_posts_done=', $location );
	}

	public function test_works_for_a_page_and_a_custom_type_with_both_taxonomy_kinds(): void {
		register_taxonomy( 'genre', 'book', array( 'hierarchical' => true ) );
		register_taxonomy( 'label', 'book', array( 'hierarchical' => false ) );
		register_post_type( 'book', array( 'public' => true, 'show_ui' => true, 'taxonomies' => array( 'genre', 'label' ) ) );

		$id     = self::factory()->post->create( array( 'post_type' => 'book', 'post_title' => 'Widget' ) );
		$region = self::factory()->term->create( array( 'taxonomy' => 'genre' ) );
		wp_set_object_terms( $id, array( $region ), 'genre' );
		wp_set_object_terms( $id, array( 'crime' ), 'label' );
		$new = $this->copy( $id );
		$this->assertSame( 'book', get_post_type( $new ) );
		$this->assertSame( array( $region ), wp_get_object_terms( $new, 'genre', array( 'fields' => 'ids' ) ) );
		$this->assertSame( array( 'crime' ), wp_get_object_terms( $new, 'label', array( 'fields' => 'names' ) ) );

		$page = $this->original( array( 'post_type' => 'page' ) );
		$this->assertSame( 'page', get_post_type( $this->copy( $page ) ) );

		unregister_post_type( 'book' );
		unregister_taxonomy( 'genre' );
		unregister_taxonomy( 'label' );
	}
}
