<?php
/**
 * Plugin Name:       Site Shots Auto
 * Description:       Full-page screenshots of every page and URL on your site. Automatic. Free. No paid APIs.
 * Version:           1.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPLv2 or later
 * Text Domain:       site-shots-auto
 */

defined( 'ABSPATH' ) || exit;

define( 'SSA_VER', '1.1.1' );
define( 'SSA_FILE', __FILE__ );
define( 'SSA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSA_URL', plugin_dir_url( __FILE__ ) );

require_once SSA_DIR . 'includes/class-ssa-settings.php';
require_once SSA_DIR . 'includes/class-ssa-urls.php';
require_once SSA_DIR . 'includes/class-ssa-github.php';
require_once SSA_DIR . 'includes/class-ssa-rest.php';
require_once SSA_DIR . 'includes/class-ssa-capture.php';
require_once SSA_DIR . 'includes/class-ssa-queue.php';
require_once SSA_DIR . 'includes/class-ssa-admin.php';

register_activation_hook( __FILE__, array( 'SSA_Queue', 'reschedule' ) );
register_deactivation_hook( __FILE__, array( 'SSA_Queue', 'unschedule' ) );

add_action(
	'plugins_loaded',
	function () {
		SSA_Queue::init();
		SSA_REST::init();
		if ( is_admin() ) {
			SSA_Admin::init();
		}
	}
);
