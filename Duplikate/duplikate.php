<?php
/**
 * Plugin Name:       Duplikate
 * Plugin URI:        https://github.com/zigmoon/duplikate
 * Description:       Duplicates pages, posts and any custom post type — hierarchy, taxonomies and metadata included — as a draft, with one click under the title and no database risk.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            zigmoon
 * Author URI:        https://github.com/zigmoon
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       duplikate
 * Domain Path:       /languages
 *
 * @package Duplikate
 */

namespace Duplikate;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DUPLIKATE_VERSION' ) ) {
	define( 'DUPLIKATE_VERSION', '1.0.0' );
}
if ( ! defined( 'DUPLIKATE_FILE' ) ) {
	define( 'DUPLIKATE_FILE', __FILE__ );
}
if ( ! defined( 'DUPLIKATE_DIR' ) ) {
	define( 'DUPLIKATE_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'DUPLIKATE_URL' ) ) {
	define( 'DUPLIKATE_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'DUPLIKATE_BASENAME' ) ) {
	define( 'DUPLIKATE_BASENAME', plugin_basename( __FILE__ ) );
}

require_once DUPLIKATE_DIR . 'includes/class-settings.php';
require_once DUPLIKATE_DIR . 'includes/class-duplicator.php';
require_once DUPLIKATE_DIR . 'includes/class-admin.php';
require_once DUPLIKATE_DIR . 'includes/class-cli.php';
require_once DUPLIKATE_DIR . 'includes/class-plugin.php';

/**
 * Loads the translations once WordPress is ready for them.
 *
 * Priority 1 on `init`: `__()` must not run earlier, and WordPress 6.7 reports
 * a notice for a text domain loaded after `init` has started.
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'duplikate', false, dirname( DUPLIKATE_BASENAME ) . '/languages' );
	},
	1
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	},
	5
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
