<?php
/**
 * Removes everything the plugin stored when it is deleted from the Plugins screen.
 *
 * The plugin stores no options. Its "copy of" markers sit on editors' content, so they are kept
 * unless wp-config.php defines SHAKEWELL_CLONE_POSTS_DELETE_MARKERS as true.
 *
 * @package Shakewell\ClonePosts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'SHAKEWELL_CLONE_POSTS_DELETE_MARKERS' ) && SHAKEWELL_CLONE_POSTS_DELETE_MARKERS ) {
	delete_post_meta_by_key( '_shakewell_clone_of' );
}
