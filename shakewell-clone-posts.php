<?php
/**
 * Plugin Name:       Shakewell Clone Posts
 * Plugin URI:        https://github.com/shakewellagency/shakewell-clone-posts
 * Description:       Clone any post, page or custom post type to a new draft from the list screen or the editor.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-clone-posts
 *
 * @package Shakewell\ClonePosts
 */

namespace Shakewell\ClonePosts;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Cloner.php';
require_once __DIR__ . '/src/Plugin.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
