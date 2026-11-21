<?php
/**
 * Plugin Name: Anchor Fragment (prototype)
 * Description: Prototype. Fetches Vatican documents on the server, builds browser text-fragment links to numbered paragraphs, and matches quotations in posts to their source paragraph. No front-end output.
 * Version: 0.10.0-dev
 * Requires PHP: 7.4
 * Author: WPI
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'AF_VERSION', '0.10.0-dev' );
define( 'AF_DIR', plugin_dir_path( __FILE__ ) );

require_once AF_DIR . 'includes/class-af-parser.php';
require_once AF_DIR . 'includes/class-af-fragment.php';
require_once AF_DIR . 'includes/class-af-matcher.php';
require_once AF_DIR . 'includes/class-af-analyzer.php';
require_once AF_DIR . 'includes/class-af-quote.php';
require_once AF_DIR . 'includes/class-af-graph.php';
require_once AF_DIR . 'includes/class-af-service.php';
require_once AF_DIR . 'includes/class-af-rest.php';

AF_Rest::init();
AF_Quote::init();
AF_Graph::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AF_DIR . 'includes/class-af-cli.php';
	WP_CLI::add_command( 'anchor', 'AF_CLI' );
	// Developer aid for hosts that only allow a few wp commands: set option af_dev_peek to "url=text=width", run the
	// af_dev_peek cron event, read option af_dev_peek_out.
	add_action(
		'af_dev_peek',
		function () {
			$in = explode( '=', (string) get_option( 'af_dev_peek', '' ) );
			update_option( 'af_dev_peek_out', AF_CLI::peek_text( $in[0], isset( $in[1] ) ? $in[1] : '', isset( $in[2] ) ? (int) $in[2] : 300 ), false );
		}
	);
}
