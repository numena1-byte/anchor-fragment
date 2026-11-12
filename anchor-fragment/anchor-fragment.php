<?php
/**
 * Plugin Name: Anchor Fragment (prototype)
 * Description: Prototype. Fetches Vatican documents on the server, builds browser text-fragment links to numbered paragraphs, and matches quotations in posts to their source paragraph. No front-end output.
 * Version: 0.3.0
 * Requires PHP: 7.4
 * Author: WPI
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'AF_VERSION', '0.3.0' );
define( 'AF_DIR', plugin_dir_path( __FILE__ ) );

require_once AF_DIR . 'includes/class-af-parser.php';
require_once AF_DIR . 'includes/class-af-fragment.php';
require_once AF_DIR . 'includes/class-af-matcher.php';
require_once AF_DIR . 'includes/class-af-analyzer.php';
require_once AF_DIR . 'includes/class-af-service.php';
require_once AF_DIR . 'includes/class-af-rest.php';

AF_Rest::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AF_DIR . 'includes/class-af-cli.php';
	WP_CLI::add_command( 'anchor', 'AF_CLI' );
}
