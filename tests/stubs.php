<?php
// Shared fakes for the tests that run the plugin without WordPress. Set $GLOBALS['af_plugin_dir'] first.
define( 'ABSPATH', '/' );
$dir = rtrim( realpath( $GLOBALS['af_plugin_dir'] ), '/' );
define( 'AF_DIR', $dir . '/' );
define( 'AF_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['opts'] = array();
$GLOBALS['hits'] = array();
function get_option( $k, $d = false ) { return 'af_last_fetch' === $k ? 0 : ( isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d ); }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function apply_filters( $t, $v ) { return $v; }
function home_url( $p = '' ) { return 'https://staging.example/' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ strtolower( $h ) ] ) ? $r['headers'][ strtolower( $h ) ] : ''; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {
	public $c; public $m;
	function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; }
	function get_error_code() { return $this->c; }
	function get_error_message() { return $this->m; }
}

function wp_json_encode( $v ) { return json_encode( $v ); }

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	echo ( $cond ? "  ok   " : "  FAIL " ) . $label . ( $cond ? '' : "  -> $detail" ) . "\n";
	if ( ! $cond ) { $fail++; }
}
