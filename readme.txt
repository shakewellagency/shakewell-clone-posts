=== Shakewell Clone Posts ===
Contributors: shakewell
Tags: clone, duplicate, copy, draft, editor
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copy any post, page or custom post type to a new draft from the list screen or the editor.

== Description ==

Shakewell Clone Posts does two things and nothing else.

* **Clone** makes a draft copy and keeps you on the list.
* **New Draft** makes a draft copy and opens it in the editor.

Both appear as row actions on list screens. The block editor shows "Copy to a new draft" in the Status panel, and the classic editor shows it in the Publish box. A bulk "Clone" action is on every list screen too.

It has no settings page, stores no options and does not talk to any outside service. It works with Advanced Custom Fields (fields are plain post meta and are copied exactly) and Co-Authors Plus (authors are a taxonomy and are copied with the other terms).

= What is copied =

* Title (with " (copy)" added), content, excerpt, parent, menu order, comment and ping status, page template, post format.
* Terms in every taxonomy the post type uses.
* The featured image (the copy points at the same image).
* All custom fields, byte for byte, including serialised arrays and ACF field reference keys.

= What is not copied =

* The slug. The draft has none, so WordPress creates a new one when you publish. The original keeps its URL.
* The password.
* Child posts and attachments.
* Edit locks, the last editor, old slugs and old dates, trash bookkeeping, and Duplicate Post's `_dp_` keys.
* Status and date: the copy is always a draft, owned by you, dated now.

Each copy records where it came from in the custom field `_shakewell_clone_of`, and the editor shows "Copy of: <original>".

= Who can copy what =

Public post types with an admin screen, except media, can be copied. You need permission to edit the original and to create new posts of that type. Every action is protected by a nonce.

= Filters and action =

* `shakewell_clone_posts_post_types` (array): post types that can be copied.
* `shakewell_clone_posts_can_clone` (bool, WP_Post): final say on whether the current user may copy a post.
* `shakewell_clone_posts_title` (string, WP_Post): the copy's title.
* `shakewell_clone_posts_password` (string, WP_Post): the copy's password, empty by default.
* `shakewell_clone_posts_exclude_meta` (array): custom field keys not to copy. Entries may use `*` as a wildcard.
* `do_action( 'shakewell_clone_posts_cloned', $new_id, $original_id )` runs after a copy is complete.

= External services =

None. The plugin makes no requests to any outside service.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer.
2. Activate it from the Plugins screen.

== Frequently Asked Questions ==

= Who can use it? =

Anyone who can edit the original and create posts of that type: editors and administrators by default. Use the `shakewell_clone_posts_can_clone` filter to change that.

= Can I limit it to some post types? =

Yes. Filter `shakewell_clone_posts_post_types` and return the list you want.

= Does it replace Duplicate Post? =

For Clone and New Draft, yes. Rewrite and Republish is not included. Old Duplicate Post copies keep their `_dp_original` field, which this plugin ignores.

= What happens when I delete the plugin? =

Nothing is removed, because the "copy of" markers sit on your content. To remove them, define `SHAKEWELL_CLONE_POSTS_DELETE_MARKERS` as true in wp-config.php before deleting the plugin.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.0 =
* First release: Clone, New Draft, bulk Clone, editor links.
