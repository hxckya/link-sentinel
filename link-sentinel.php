<?php
/**
 * Plugin Name:       Link Sentinel – Broken Link Checker
 * Plugin URI:        https://github.com/hxckya/link-sentinel
 * Description:       Finds broken links and images across your site and helps you fix them. Runs on your own server — no cloud account — and tells bot-blocked sites apart from dead ones.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            hxckya
 * Author URI:        https://github.com/hxckya
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       link-sentinel
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

define( 'LINKSENTINEL_VERSION', '0.1.0' );
define( 'LINKSENTINEL_FILE', __FILE__ );
define( 'LINKSENTINEL_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINKSENTINEL_URL', plugin_dir_url( __FILE__ ) );

require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-settings.php';
require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-db.php';
require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-extractor.php';
require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-checker.php';
require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-scanner.php';
require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-fixer.php';
require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-rest.php';
require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-admin.php';
require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-plugin.php';

register_activation_hook( __FILE__, array( 'LinkSentinel_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LinkSentinel_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'LinkSentinel_Plugin', 'instance' ) );
