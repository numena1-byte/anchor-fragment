<?php
// The optional second opinion: the rule, the stored answers, the graph status it produces, and asking a few at a time.
// Usage: php semantic.php <path-to-plugin-dir>
$GLOBALS['af_plugin_dir'] = $argv[1];
require __DIR__ . '/stubs.php';
foreach ( array( 'parser', 'graph', 'semantic' ) as $f ) { require AF_DIR . "includes/class-af-$f.php"; }

define( 'ARRAY_A', 'ARRAY_A' );
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function get_post( $id ) { $r = $GLOBALS['wpdb']->get_results( 'SELECT * FROM wp_posts WHERE ID = ' . (int) $id ); return $r ? (object) $r[0] : null; }
function get_permalink( $p ) { return 'https://staging.example/?p=' . $p->ID; }
$GLOBALS['meta'] = array();
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); }

class FakeDb {
	public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $pdo;
	function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT, post_status TEXT, post_type TEXT, post_date TEXT, post_modified_gmt TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_postmeta (meta_key TEXT)' );
		$this->pdo->exec( "CREATE TABLE wp_af_citations (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INT, quote_ord INT, doc TEXT, locator TEXT, loc_num INT, basis TEXT, status TEXT, cover REAL, words INT, quote_hash TEXT, matched_at TEXT, plugin_version TEXT, confirmed INT, at_uri TEXT)" );
	}
	function prepare( $sql, ...$a ) {
		$a = is_array( $a[0] ?? null ) ? $a[0] : $a;
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ]; return '%d' === $m[0] ? (int) $v : $this->pdo->quote( (string) $v ); }, $sql );
	}
	function get_results( $sql, $o = null ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
	function get_var( $sql ) { $r = $this->pdo->query( $sql )->fetch( PDO::FETCH_NUM ); return $r ? $r[0] : null; }
	function query( $sql ) { return $this->pdo->exec( $sql ); }
	function insert( $t, $d ) { $this->pdo->prepare( "INSERT INTO $t (" . implode( ',', array_keys( $d ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $d ), '?' ) ) . ')' )->execute( array_values( $d ) ); }
	function update( $t, $d, $w ) { $this->pdo->prepare( "UPDATE $t SET " . implode( ',', array_map( function ( $k ) { return "$k=?"; }, array_keys( $d ) ) ) . ' WHERE ' . implode( ' AND ', array_map( function ( $k ) { return "$k=?"; }, array_keys( $w ) ) ) )->execute( array_merge( array_values( $d ), array_values( $w ) ) ); }
	function delete( $t, $w ) { $this->pdo->prepare( "DELETE FROM $t WHERE " . implode( ' AND ', array_map( function ( $k ) { return "$k=?"; }, array_keys( $w ) ) ) )->execute( array_values( $w ) ); }
}
$wpdb = new FakeDb();
foreach ( array( array( 1, 'Alpha', '2026-01-01' ), array( 2, 'Beta', '2026-02-01' ), array( 3, 'Gamma', '2026-03-01' ) ) as $p ) {
	$wpdb->pdo->exec( "INSERT INTO wp_posts VALUES ({$p[0]}, '{$p[1]}', 'publish', 'post', '{$p[2]}', '{$p[2]} 00:00:00')" );
}
$wpdb->pdo->exec( "INSERT INTO wp_posts VALUES (4, 'Draft', 'draft', 'post', '2026-04-01', '2026-04-01 00:00:00')" );

// A stand-in for the service class: one single-page document, one multi-page one, and a match that stores the quotation text.
class AF_Service {
	public static $analysed = array();
	public static function registry() {
		return array(
			'dei-verbum' => array( 'title' => 'Dei Verbum', 'short' => 'DV', 'url' => 'https://www.vatican.va/dv.html', 'language' => 'en' ),
			'catechism'  => array( 'title' => 'Catechism', 'short' => 'CCC', 'url' => 'https://www.vatican.va/ccc/index.html', 'multipage' => true ),
		);
	}
	public static function analyze_post( $id, $caps = true ) {
		self::$analysed[] = $id;
		$res = $GLOBALS['opts'][ 'af_match_' . $id ];
		foreach ( $res['quotes'] as &$qq ) {
			if ( empty( $qq['text'] ) && 'no_match' === $qq['status'] ) { $qq['text'] = 'Words that were not kept before.'; }
		}
		$GLOBALS['opts'][ 'af_match_' . $id ] = $res;
		AF_Graph::record_post( $id, $res );
		return $res;
	}
}

function nf( $i, $hash, $text, $doc, $loc, $status = 'no_match' ) {
	return array( 'i' => $i, 'status' => $status, 'words' => 40, 'hash' => $hash, 'text' => $text, 'excerpt' => substr( $text, 0, 20 ), 'best' => null, 'alt' => array(), 'typed' => array( array( 'doc' => $doc, 'loc' => array( $loc ), 'indexed' => true, 'attached' => true ) ) );
}
function ans( $rank, $score, $lead, $judge, $better = null ) {
	return array( 'version' => 1, 'ok' => true, 'signals' => array( 'rank' => $rank, 'score' => $score, 'lead' => $lead, 'judge' => $judge, 'reason' => 'because', 'better' => $better ), 'models' => array( 'embedding' => 'bge-m3', 'judge' => 'llama' ) );
}

echo "The rule\n";
check( 'first, close, ahead, faithful: agrees', 'agrees' === AF_Semantic::classify( ans( 1, 812, 120, 'faithful_translation' ) )['result'] );
check( 'a model that says loose paraphrase is not enough', 'inconclusive' === AF_Semantic::classify( ans( 1, 812, 120, 'loose_paraphrase' ) )['result'] );
check( 'too close to the next paragraph is not enough', 'inconclusive' === AF_Semantic::classify( ans( 1, 812, 10, 'faithful_translation' ) )['result'] );
check( 'a score under the line is not enough', 'inconclusive' === AF_Semantic::classify( ans( 1, 600, 120, 'faithful_translation' ) )['result'] );
check( 'no model reading never agrees', 'inconclusive' === AF_Semantic::classify( ans( 1, 900, 200, '' ) )['result'] );
check( 'another paragraph closer: disagrees', 'disagrees' === AF_Semantic::classify( ans( 2, 700, 50, 'faithful_translation', '18' ) )['result'] );
check( 'related but different: disagrees', 'disagrees' === AF_Semantic::classify( ans( 1, 800, 100, 'related_but_different' ) )['result'] );
check( 'far from the paragraph: disagrees', 'disagrees' === AF_Semantic::classify( ans( 1, 300, 100, '' ) )['result'] );
check( 'an error answer is an error', 'error' === AF_Semantic::classify( array( 'ok' => false, 'error' => 'boom' ) )['result'] );
check( 'an answer with no ranking is an error', 'error' === AF_Semantic::classify( array( 'ok' => true, 'signals' => array() ) )['result'] );

echo "Settings\n";
check( 'not set up by default', ! AF_Semantic::configured() );
check( 'an http address is refused', is_wp_error( AF_Semantic::save_settings( 'http://example.com/', 'k' ) ) );
check( 'an address that is not one is refused', is_wp_error( AF_Semantic::save_settings( 'not a url', 'k' ) ) );
check( 'https is saved', true === AF_Semantic::save_settings( 'https://svc.example/check', 'secret' ) && AF_Semantic::configured() );
check( 'the key is never part of the settings shown', array( 'url' => 'https://svc.example/check', 'key_set' => true ) === AF_Semantic::settings() );
AF_Semantic::save_settings( 'https://svc.example/other', '' );
check( 'an empty key keeps the saved one', AF_Semantic::configured() && 'https://svc.example/other' === AF_Semantic::settings()['url'] );
$calls = array();
AF_Semantic::$transport = function ( $url, $key, $payload ) use ( &$calls ) { $calls[] = array( $url, $key, $payload ); return array( 'ok' => true, 'ping' => true, 'models' => array( 'embedding' => 'bge-m3' ) ); };
$p = AF_Semantic::ping();
check( 'a connection test sends no quotation', ! is_wp_error( $p ) && isset( $calls[0][2]['ping'] ) && ! isset( $calls[0][2]['quote'] ) && 'secret' === $calls[0][1] );

echo "The graph\n";
$hash = 'hnf1';
$resA = array( 'post_id' => 1, 'quotes' => array( nf( 0, $hash, 'Words of the quotation that nothing matched.', 'dei-verbum', 17 ) ) );
$rowsA = AF_Graph::rows_from_result( $resA );
check( 'without an answer the citation is not_found', 'not_found' === array_values( $rowsA )[0]['status'] );
AF_Semantic::save( 1, $hash, 'dei-verbum', 17, array( 'result' => 'agrees', 'score' => 812 ) );
check( 'an agreeing answer makes it semantic', 'semantic' === array_values( AF_Graph::rows_from_result( $resA ) )[0]['status'] );
check( 'only for that paragraph', 'not_found' === array_values( AF_Graph::rows_from_result( array( 'post_id' => 1, 'quotes' => array( nf( 0, $hash, 'x', 'dei-verbum', 18 ) ) ) ) )[0]['status'] );
check( 'only for that post', 'not_found' === array_values( AF_Graph::rows_from_result( array( 'post_id' => 2 ) + $resA ) )[0]['status'] );
AF_Semantic::save( 1, $hash, 'dei-verbum', 17, array( 'result' => 'disagrees', 'score' => 400 ) );
check( 'a disagreeing answer leaves it not_found', 'not_found' === array_values( AF_Graph::rows_from_result( $resA ) )[0]['status'] );
$GLOBALS['opts']['af_sem_1']['hnf1|dei-verbum|17']['rule'] = 0;
AF_Semantic::save( 1, $hash, 'dei-verbum', 17, array( 'result' => 'agrees' ) );
$GLOBALS['opts']['af_sem_1']['hnf1|dei-verbum|17']['rule'] = 0;
check( 'an answer made under an older rule counts as none', null === AF_Semantic::lookup( 1, $hash, 'dei-verbum', 17 ) && 'not_found' === array_values( AF_Graph::rows_from_result( $resA ) )[0]['status'] );
AF_Semantic::save( 1, $hash, 'dei-verbum', 17, array( 'result' => 'agrees' ) );
$resT = array( 'post_id' => 1, 'translation' => true, 'quotes' => $resA['quotes'] );
check( 'a translation post is confirmed the same way', 'semantic' === array_values( AF_Graph::rows_from_result( $resT ) )[0]['status'] );
$resM = array( 'post_id' => 1, 'quotes' => array( array_merge( nf( 0, $hash, 'x', 'dei-verbum', 17 ), array( 'typed' => array( array( 'doc' => 'dei-verbum', 'loc' => array( 17 ), 'indexed' => true, 'attached' => false ) ) ) ) ) );
check( 'a mention is never confirmed by an answer', 'mention' === array_values( AF_Graph::rows_from_result( $resM ) )[0]['status'] );
check( 'semantic is a status of its own, in weaker, not in the default view', array( 'semantic' ) === AF_Graph::status_set( 'semantic' ) && in_array( 'semantic', AF_Graph::status_set( 'weaker' ), true ) && ! in_array( 'semantic', AF_Graph::status_set( '' ), true ) );
unset( $GLOBALS['opts']['af_sem_1'] );

echo "Asking\n";
// Post 1: three quotations, all cited and not found. The first has its words kept; the second was matched before words were kept.
$GLOBALS['opts']['af_match_1'] = array( 'post_id' => 1, 'quotes' => array(
	nf( 0, 'ha', 'First quotation words that nothing matched.', 'dei-verbum', 17 ),
	nf( 1, 'hb', '', 'dei-verbum', 18 ),
	nf( 2, 'hc', 'Third quotation words that nothing matched.', 'catechism', 2349 ),
) );
$GLOBALS['opts']['af_match_1']['quotes'][1]['text'] = '';
AF_Graph::record_post( 1, $GLOBALS['opts']['af_match_1'] );
check( 'three not_found edges to start with', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_af_citations WHERE post_id = 1 AND status = 'not_found'" ) );

$sent = array();
$answers = array( 'First' => ans( 1, 812, 120, 'faithful_translation' ), 'Words' => ans( 2, 700, 40, 'faithful_translation', '19' ) );
AF_Semantic::$transport = function ( $url, $key, $payload ) use ( &$sent, $answers ) {
	$sent[] = $payload;
	return strpos( $payload['quote'], 'First' ) === 0 ? $answers['First'] : $answers['Words'];
};
$o = AF_Semantic::step( 0, false, 20.0 );
check( 'two answered, the multi-page document not asked', 2 === $o['processed'] && 1 === $o['skipped'] && $o['done'], json_encode( $o ) );
check( 'one agreed and one disagreed', 1 === $o['agrees'] && 1 === $o['disagrees'], json_encode( $o ) );
check( 'the post whose words were not kept was matched again', array( 1 ) === AF_Service::$analysed );
check( 'the request holds only the quotation, document and paragraph', 'First quotation words that nothing matched.' === $sent[0]['quote'] && '17' === $sent[0]['locator'] && 'dei-verbum' === $sent[0]['document']['id'] && 'https://www.vatican.va/dv.html' === $sent[0]['document']['url'] && 1 === $sent[0]['version'] && array( 'version', 'quote', 'document', 'locator', 'language' ) === array_keys( $sent[0] ), json_encode( $sent[0] ) );
check( 'the agreeing citation is now semantic in the graph', 'semantic' === $wpdb->get_var( "SELECT status FROM wp_af_citations WHERE post_id = 1 AND loc_num = 17" ) );
check( 'the disagreeing one stays not_found', 'not_found' === $wpdb->get_var( "SELECT status FROM wp_af_citations WHERE post_id = 1 AND loc_num = 18" ) );
check( 'the method entry for the record', array( 'name' => 'semantic', 'result' => 'agrees', 'score' => 812, 'model' => 'bge-m3' ) === AF_Semantic::method_entry( AF_Semantic::lookup( 1, 'ha', 'dei-verbum', 17 ) ) );

$sent = array();
$o = AF_Semantic::step( 0, false, 20.0 );
check( 'asking again sends nothing', 0 === count( $sent ) && 0 === $o['processed'], json_encode( $o ) );
$o = AF_Semantic::step( 0, true, 20.0 );
check( 'force asks again, including the confirmed one', 2 === count( $sent ), (string) count( $sent ) );

// The review list shows what was said.
$rv = AF_Graph::review( array( 'status' => AF_Graph::status_set( 'not_found' ), 'confirmed' => 'unreviewed' ), 0, 20 );
$text = json_encode( $rv );
check( 'the review list carries the second opinion', false !== strpos( $text, 'disagrees' ) && false !== strpos( $text, 'closer: 19' ), $text );
$rv = AF_Graph::review( array( 'status' => AF_Graph::status_set( 'semantic' ), 'confirmed' => 'unreviewed' ), 0, 20 );
check( 'the confirmed ones can be listed', 1 === $rv['total'] && false !== strpos( json_encode( $rv ), 'agrees' ), json_encode( $rv ) );

// A service that is down stops the run and keeps the rest for later.
$GLOBALS['opts']['af_match_2'] = array( 'post_id' => 2, 'quotes' => array( nf( 0, 'hz', 'Second post words nothing matched.', 'dei-verbum', 5 ) ) );
AF_Graph::record_post( 2, $GLOBALS['opts']['af_match_2'] );
AF_Semantic::$transport = function () { return new WP_Error( 'af_semantic_net', 'Could not reach the service.' ); };
$o = AF_Semantic::step( 0, false, 20.0 );
check( 'an unreachable service stops the run, not done', 1 === count( $o['errors'] ) && ! $o['done'] && 0 === $o['processed'], json_encode( $o ) );
AF_Semantic::$transport = function () { return array( 'ok' => false, 'error' => 'quota' ); };
$o = AF_Semantic::step( 0, false, 20.0 );
check( 'an error answer is reported and the run carries on', 1 === count( $o['errors'] ) && $o['done'], json_encode( $o ) );
check( 'and is not stored as an answer', null === AF_Semantic::lookup( 2, 'hz', 'dei-verbum', 5 ) );
unset( $GLOBALS['opts']['af_semantic_url'], $GLOBALS['opts']['af_semantic_key'] );
check( 'with no service set up, asking is refused', is_wp_error( AF_Semantic::step( 0 ) ) );
AF_Semantic::save_settings( 'https://svc.example/x', 'k' );
AF_Semantic::save_settings( '' );
check( 'an empty address turns it off and forgets the key', ! AF_Semantic::configured() && array( 'url' => '', 'key_set' => false ) === AF_Semantic::settings() );

echo ( $fail ? "\n$fail FAILED\n" : "\nall ok\n" );
exit( $fail ? 1 : 0 );
