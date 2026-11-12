<?php
/**
 * Plugin Name: Anchor Fragment (prototype)
 * Description: Prototype. Fetches Vatican documents on the server, finds a numbered paragraph and builds a browser text-fragment link to it. No front-end output.
 * Version: 0.1.1
 * Requires PHP: 7.4
 * Author: WPI
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'AF_VERSION', '0.1.1' );
define( 'AF_DIR', plugin_dir_path( __FILE__ ) );

require_once AF_DIR . 'includes/class-af-parser.php';
require_once AF_DIR . 'includes/class-af-fragment.php';
require_once AF_DIR . 'includes/class-af-service.php';
require_once AF_DIR . 'includes/class-af-rest.php';

AF_Rest::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AF_DIR . 'includes/class-af-cli.php';
	WP_CLI::add_command( 'anchor', 'AF_CLI' );
}
