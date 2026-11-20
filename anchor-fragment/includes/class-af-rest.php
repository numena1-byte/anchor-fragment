<?php
/**
 * REST endpoints the editor will call, plus a small test page under Tools.
 *
 *   GET /wp-json/anchorfrag/v1/link?doc=laudato-si&locator=12
 *   GET /wp-json/anchorfrag/v1/match?post_id=123
 *   GET /wp-json/anchorfrag/v1/status
 *   POST /wp-json/anchorfrag/v1/ingest   (doc=catechism; administrators only)
 *
 * Both need a logged-in user who can edit posts. They take document ids from the
 * registry and post ids, never URLs, so they cannot be pointed at arbitrary addresses.
 */

defined( 'ABSPATH' ) || exit;

class AF_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_page' ) );
		add_action( 'admin_post_af_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_af_import_file', array( __CLASS__, 'handle_import_file' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'anchorfrag/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'anchorfrag/v1',
			'/ingest',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_ingest' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'doc'     => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'restart' => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'sample'  => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'crawl'   => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			'anchorfrag/v1',
			'/check',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					if ( function_exists( 'set_time_limit' ) ) {
						@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					}
					return rest_ensure_response( AF_Service::check_server() );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'anchorfrag/v1',
			'/link',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_link' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'doc'     => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'locator' => array(
						'required'          => true,
						'validate_callback' => function ( $v ) {
							return (bool) preg_match( '/^[0-9A-Za-z.\-]{1,12}$/', (string) $v );
						},
					),
					'refresh' => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		register_rest_route(
			'anchorfrag/v1',
			'/passage',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_passage' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'doc'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'locator' => array(
						'required'          => true,
						'validate_callback' => function ( $v ) {
							return (bool) preg_match( '/^[0-9]{1,4}$/', (string) $v );
						},
					),
				),
			)
		);

		register_rest_route(
			'anchorfrag/v1',
			'/doctext',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_text_info' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => array( 'doc' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ) ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_text_add' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => array(
						'doc'        => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
						'paragraphs' => array( 'required' => true ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'handle_text_clear' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => array( 'doc' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ) ),
				),
			)
		);

		register_rest_route(
			'anchorfrag/v1',
			'/match',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_match' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'anchorfrag/v1',
			'/graph/rebuild',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_graph_rebuild' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'anchorfrag/v1',
			'/graph/review',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_graph_review' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_rest_route(
			'anchorfrag/v1',
			'/graph/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_graph_confirm' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'anchorfrag/v1',
			'/graph',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_graph' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	public static function handle_graph_rebuild( WP_REST_Request $req ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		AF_Graph::maybe_install();
		return rest_ensure_response( AF_Graph::rebuild_step( (int) $req['after'], (bool) $req['force'], 20, 25 ) );
	}

	public static function handle_graph( WP_REST_Request $req ) {
		AF_Graph::maybe_install();
		$f = array();
		foreach ( array( 'doc', 'locator', 'basis', 'confirmed' ) as $k ) {
			if ( null !== $req[ $k ] && '' !== $req[ $k ] ) {
				$f[ $k ] = sanitize_text_field( $req[ $k ] );
			}
		}
		if ( $req['status'] ) {
			$f['status'] = AF_Graph::status_set( sanitize_text_field( $req['status'] ) );
		}
		$out = array( 'summary' => AF_Graph::summary( $f ), 'top' => AF_Graph::top_paragraphs( $f, 15 ) );
		if ( isset( $f['doc'] ) || isset( $f['status'] ) ) {
			$out['posts'] = AF_Graph::citing_posts( $f, 100 );
			foreach ( $out['posts'] as &$p ) {
				$p['url'] = get_permalink( (int) $p['post_id'] );
			}
		}
		return rest_ensure_response( $out );
	}

	public static function handle_graph_review( WP_REST_Request $req ) {
		AF_Graph::maybe_install();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$f = array( 'status' => AF_Graph::status_set( sanitize_text_field( (string) $req['status'] ?: 'review' ) ) );
		if ( $req['doc'] ) {
			$f['doc'] = sanitize_text_field( $req['doc'] );
		}
		$f['confirmed'] = $req['reviewed'] ? 'any' : 'unreviewed';
		$res            = AF_Graph::review( $f, (int) $req['offset'], 20 );
		$reg            = AF_Service::registry();
		foreach ( $res['groups'] as &$g ) {
			foreach ( $g['edges'] as &$e ) {
				$e['title'] = isset( $reg[ $e['doc'] ] ) ? $reg[ $e['doc'] ]['title'] : $e['doc'];
				$lk         = AF_Service::link( $e['doc'], $e['locator'] );
				$e['link']  = is_wp_error( $lk ) ? '' : $lk['link'];
			}
		}
		return rest_ensure_response( $res );
	}

	public static function handle_graph_confirm( WP_REST_Request $req ) {
		$v = $req['value'];
		return rest_ensure_response( array( 'id' => (int) $req['id'], 'confirmed' => AF_Graph::set_confirmed( (int) $req['id'], ( null === $v || '' === $v || 'null' === $v ) ? null : (int) $v ) ) );
	}

	public static function handle_status() {
		return rest_ensure_response( AF_Service::status() );
	}

	public static function handle_ingest( WP_REST_Request $req ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$res = AF_Service::ingest_step( $req['doc'], 25.0, (bool) $req['restart'], (bool) $req['sample'], (bool) $req['crawl'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function handle_link( WP_REST_Request $req ) {
		$refresh = (bool) $req['refresh'] && current_user_can( 'manage_options' );
		$res     = AF_Service::link( $req['doc'], $req['locator'], $refresh );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function handle_passage( WP_REST_Request $req ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$res = AF_Service::passage( $req['doc'], $req['locator'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function handle_text_info( WP_REST_Request $req ) {
		$res = AF_Service::text_info( $req['doc'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function handle_text_add( WP_REST_Request $req ) {
		$p = $req['paragraphs'];
		if ( ! is_array( $p ) ) {
			return new WP_Error( 'af_bad_request', 'paragraphs must be an object of paragraph number => text.', array( 'status' => 400 ) );
		}
		$res = AF_Service::accept_text( $req['doc'], $p );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function handle_text_clear( WP_REST_Request $req ) {
		AF_Service::clear_text( $req['doc'] );
		return rest_ensure_response( array( 'cleared' => true ) );
	}

	public static function handle_match( WP_REST_Request $req ) {
		// The first run may fetch up to six documents, 2 seconds apart.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$res = AF_Service::analyze_post( (int) $req['post_id'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** Download a crawled document as a hosted data file (administrators only). */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$id = isset( $_GET['doc'] ) ? sanitize_key( wp_unslash( $_GET['doc'] ) ) : '';
		check_admin_referer( 'af_export_' . $id );
		$data = AF_Service::export_hosted( $id );
		if ( is_wp_error( $data ) ) {
			wp_die( esc_html( $data->get_error_message() ), 409 );
		}
		$body = wp_json_encode( $data );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $id . '@parser' . (int) $data['parser_version'] . '.json"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Import a hosted data file that was uploaded by hand. It must match the registry's checksum. */
	public static function handle_import_file() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'af_import_file' );
		$id  = isset( $_POST['doc'] ) ? sanitize_key( wp_unslash( $_POST['doc'] ) ) : '';
		$msg = 'No file was uploaded.';
		$ok  = false;
		if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security
			if ( (int) $_FILES['file']['size'] > AF_Service::HOSTED_MAX ) { // phpcs:ignore WordPress.Security
				$msg = 'The file is too large.';
			} else {
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				$res = AF_Service::import_hosted_body( $id, (string) file_get_contents( $_FILES['file']['tmp_name'] ) ); // phpcs:ignore WordPress.Security
				$ok  = ! is_wp_error( $res );
				$msg = $ok ? $res['message'] : $res->get_error_message();
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'af-test', 'af_notice' => rawurlencode( $msg ), 'af_ok' => $ok ? 1 : 0 ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/** Script for the "Add text from a PDF" section; PDF.js itself is loaded by that script when a file is chosen. */
	public static function enqueue_page( $hook ) {
		if ( 'tools_page_af-test' !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$base = plugins_url( 'assets/', AF_DIR . 'anchor-fragment.php' );
		wp_enqueue_script( 'af-pdf-extract', $base . 'pdf-extract.js', array(), AF_VERSION, true );
		wp_enqueue_script( 'af-add-pdf-text', $base . 'add-pdf-text.js', array( 'af-pdf-extract' ), AF_VERSION, true );
		wp_add_inline_script(
			'af-add-pdf-text',
			'window.afPdf = ' . wp_json_encode(
				array(
					'rest'   => rest_url( 'anchorfrag/v1/doctext' ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'pdfjs'  => $base . 'pdfjs/pdf.min.js',
					'worker' => $base . 'pdfjs/pdf.worker.min.js',
				)
			) . ';',
			'before'
		);
	}

	public static function add_page() {
		add_management_page( 'Anchor Fragment Test', 'Anchor Fragment Test', 'edit_posts', 'af-test', array( __CLASS__, 'render_page' ) );
	}

	public static function render_page() {
		$cfg = array(
			'link'  => rest_url( 'anchorfrag/v1/link' ),
			'match'  => rest_url( 'anchorfrag/v1/match' ),
			'ingest' => rest_url( 'anchorfrag/v1/ingest' ),
			'status' => rest_url( 'anchorfrag/v1/status' ),
			'check'  => rest_url( 'anchorfrag/v1/check' ),
			'passage' => rest_url( 'anchorfrag/v1/passage' ),
			'graph'   => rest_url( 'anchorfrag/v1/graph' ),
			'rebuild' => rest_url( 'anchorfrag/v1/graph/rebuild' ),
			'review'  => rest_url( 'anchorfrag/v1/graph/review' ),
			'confirm' => rest_url( 'anchorfrag/v1/graph/confirm' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		);
		?>
		<div class="wrap">
			<h1>Anchor Fragment Test</h1>

			<h2>Documents</h2>
			<p>Single-page documents are fetched automatically the first time they are needed. A multi-page document such as the Catechism (about 370 pages) is fetched here, a few pages at a time, 2 seconds apart; the whole job takes roughly 15 to 20 minutes. Keep this page open until it says it has finished. If you leave, press the button again and it carries on where it stopped.</p>
			<table class="widefat striped" style="max-width:60em" id="af-docs">
				<thead><tr><th>Document</th><th>Paragraphs</th><th>Expected</th><th>Index</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( AF_Service::status() as $row ) : ?>
					<tr data-doc="<?php echo esc_attr( $row['id'] ); ?>">
						<td><?php echo esc_html( $row['title'] ); ?><?php echo $row['multipage'] ? ' <em>(multi-page)</em>' : ( $row['hosted'] ? ' <em>(PDF, hosted data)</em>' : '' ); ?></td>
						<td class="af-count"><?php echo null === $row['count'] ? '–' : esc_html( $row['count'] . ( $row['gaps'] ? ', ' . $row['gaps'] . ' gap(s)' : '' ) ); ?></td>
						<td><?php echo esc_html( null === $row['expected'] ? '' : $row['expected'] ); ?></td>
						<td class="af-idx"><?php echo $row['indexed'] ? ( $row['partial'] ? 'sample only' : 'yes' ) : ( $row['progress'] ? esc_html( $row['progress']['done'] . ' of ' . $row['progress']['total'] . ' pages' ) : 'no' ); ?></td>
						<td>
							<?php if ( current_user_can( 'manage_options' ) ) : ?>
								<?php if ( $row['hosted'] ) : ?>
									<button class="button af-fetch" type="button"><?php echo $row['indexed'] ? 'Import again' : 'Import'; ?></button>
								<?php endif; ?>
								<?php if ( $row['multipage'] ) : ?>
									<button class="button af-fetch" data-crawl="1" type="button"><?php echo $row['indexed'] && ! $row['partial'] ? 'Crawl again' : ( $row['progress'] ? 'Continue crawl' : 'Crawl vatican.va' ); ?></button>
									<button class="button af-fetch af-sample" data-crawl="1" type="button">Crawl a sample (about 36 pages)</button>
								<?php endif; ?>
								<?php if ( $row['indexed'] && ! $row['partial'] && ! $row['pdf'] ) : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=af_export&doc=' . $row['id'] ), 'af_export_' . $row['id'] ) ); ?>">Export file</a>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p id="af-docs-msg" style="max-width:60em"></p>
			<?php if ( isset( $_GET['af_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-<?php echo ! empty( $_GET['af_ok'] ) ? 'success' : 'error'; // phpcs:ignore WordPress.Security ?>" style="max-width:60em"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['af_notice'] ) ) ); // phpcs:ignore WordPress.Security ?></p></div>
			<?php endif; ?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<h3>Import from a file</h3>
				<p style="max-width:60em">If this server cannot download the data, download the file for a document from the plugin's repository on GitHub and upload it here. It is accepted only if it is exactly the file the registry names.</p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="af_import_file">
					<?php wp_nonce_field( 'af_import_file' ); ?>
					<select name="doc">
						<?php foreach ( AF_Service::registry() as $id => $d ) : ?>
							<?php if ( ! empty( $d['hosted'] ) ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $d['title'] ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<input type="file" name="file" accept=".json,application/json">
					<button class="button" type="submit">Import file</button>
				</form>

				<h3 style="margin-top:2em">Check this server</h3>
				<p style="max-width:60em">Tests whether this server can reach vatican.va and GitHub, and shows the limits that decide whether a crawl can finish.</p>
				<button class="button" type="button" id="af-check">Check this server</button>
				<div id="af-check-out" style="margin-top:1em;max-width:60em"></div>
			<?php endif; ?>

			<hr>
			<h2>Build a link</h2>
			<p>Enter a document and a paragraph number. The server fetches the Vatican page (once, then cached), finds the paragraph and returns a link. Click the link to check that your browser scrolls to and highlights the paragraph.</p>
			<form id="af-form">
				<select name="doc">
					<?php foreach ( AF_Service::registry() as $id => $d ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $d['title'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="locator" value="12" size="6" aria-label="Paragraph number">
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<label><input type="checkbox" name="refresh"> re-fetch</label>
				<?php endif; ?>
				<button class="button button-primary" type="submit">Build link</button>
			</form>
			<p id="af-link" style="margin-top:1.5em;font-size:1.1em"></p>
			<pre id="af-out" style="background:#fff;border:1px solid #ccd0d4;padding:1em;max-width:60em;white-space:pre-wrap"></pre>

			<hr>
			<h2>Insert a quotation (proof of concept)</h2>
			<p>Choose a document and a paragraph number. The server fetches that one page from vatican.va, cuts out the paragraph and shows it as a block quote with its citation. Nothing is saved. "Checked" means the text found is the same paragraph the index was built from.</p>
			<form id="af-quote-form">
				<select name="doc">
					<?php foreach ( AF_Service::registry() as $id => $d ) : ?>
						<?php if ( false === stripos( (string) $d['url'], '.pdf' ) ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $d['title'] ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
				<input type="text" name="locator" value="1670" size="6" aria-label="Paragraph number">
				<button class="button button-primary" type="submit">Show passage</button>
			</form>
			<style><?php echo AF_Quote::admin_preview_css() . AF_Quote::css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
			<div id="af-quote-out" style="margin-top:1em;max-width:50em"></div>

			<hr>
			<h2>Add text from a PDF</h2>
			<p>For documents that exist only as a PDF. Download the PDF from the link, choose the file here, and give the paragraph numbers you want. The PDF is read in your browser and never uploaded. Each paragraph is checked word for word against the index and kept on this site only if it agrees.</p>
			<form id="af-pdf-form">
				<select name="doc">
					<?php foreach ( AF_Service::registry() as $id => $d ) : ?>
						<?php if ( false !== stripos( (string) $d['url'], '.pdf' ) ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $d['title'] ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
				<a id="af-pdf-link" href="#" target="_blank" rel="noopener">Open the PDF source</a><br>
				<input type="file" name="file" accept="application/pdf,.pdf">
				Paragraphs <input type="text" name="from" value="291" size="4" aria-label="First paragraph"> to <input type="text" name="to" value="312" size="4" aria-label="Last paragraph">
				<button class="button button-primary" type="submit">Read PDF and add text</button>
				<button class="button" type="button" id="af-pdf-clear">Remove added text</button>
			</form>
			<pre id="af-pdf-out" style="white-space:pre-wrap;max-width:60em"></pre>

			<hr>
			<h2>Citation graph</h2>
			<p>Every post's matches are recorded as edges: post, document, paragraph, how it was found. "Match all posts" fills the graph from every published post (posts changed later are re-matched automatically a few seconds after saving). Filter below to see what cites what.</p>
			<p><button class="button button-primary" id="af-rebuild">Match all posts</button>
			<label><input type="checkbox" id="af-rebuild-force"> redo posts already matched</label>
			<span id="af-rebuild-msg" style="margin-left:1em"></span></p>
			<form id="af-graph-form">
				Document <select name="doc"><option value="">all</option>
				<?php foreach ( AF_Service::registry() as $id => $d ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $d['title'] ); ?></option>
				<?php endforeach; ?>
				</select>
				Paragraph <input type="text" name="locator" size="6">
				Confidence <select name="status"><option value="">high + medium</option><option value="high">high only</option><option value="weaker">+ low, partial</option><option value="review">conflict + ambiguous</option><option value="conflict">conflict only</option><option value="ambiguous">ambiguous only</option><option value="unchecked">typed, not checked</option><option value="all">everything</option></select>
				<button class="button" type="submit">Show</button>
			</form>
			<div id="af-graph-out" style="margin-top:1em;max-width:70em"></div>

			<h3>Review citations</h3>
			<p>Each entry is one quotation. For a conflict, the author typed one paragraph and the words matched another: mark which is right. Decisions are kept when posts are matched again, and wrong edges leave every report.</p>
			<form id="af-review-form">
				<select name="status"><option value="review">conflict + ambiguous</option><option value="conflict">conflict only</option><option value="ambiguous">ambiguous only</option><option value="unchecked">typed, not checked</option></select>
				<label><input type="checkbox" name="reviewed"> include ones already reviewed</label>
				<button class="button" type="submit">Load</button>
			</form>
			<div id="af-review-out" style="margin-top:1em;max-width:70em"></div>

			<hr>
			<h2>Match posts</h2>
			<p>Enter post IDs separated by commas. For each post, the plugin finds the quotations and works out which paragraph of which document each came from. Results are shown below and saved on the server. The first run fetches any documents not yet indexed, which can take about 15 seconds.</p>
			<form id="af-match-form">
				<input type="text" name="ids" size="50" placeholder="e.g. 27610, 29439" aria-label="Post IDs">
				<button class="button button-primary" type="submit">Match</button>
			</form>
			<div id="af-match-out" style="margin-top:1em;max-width:70em"></div>
		</div>
		<script>
		(function () {
			var cfg = <?php echo wp_json_encode( $cfg ); ?>;


			/* ---- fetch multi-page documents ---- */
			var dmsg = document.getElementById('af-docs-msg');
			Array.prototype.forEach.call(document.querySelectorAll('.af-fetch'), function (btn) {
				btn.addEventListener('click', function () {
					var row = btn.closest('tr');
					var doc = row.getAttribute('data-doc');
					var sample = btn.classList.contains('af-sample');
					var crawl = btn.getAttribute('data-crawl') === '1';
					var restart = sample || /again/.test(btn.textContent);
					var idx = row.querySelector('.af-idx');
					Array.prototype.forEach.call(row.querySelectorAll('.af-fetch'), function (x) { x.disabled = true; });
					function step(first) {
						var q = new URLSearchParams({ doc: doc });
						if (first && restart) { q.set('restart', '1'); }
						if (sample) { q.set('sample', '1'); }
						if (crawl) { q.set('crawl', '1'); }
						return fetch(cfg.ingest + '?' + q.toString(), { method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
							.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
							.then(function (res) {
								if (!res.ok) { dmsg.textContent = 'Stopped: ' + (res.body.message || 'request failed') + ' Press the button to try again.'; Array.prototype.forEach.call(row.querySelectorAll('.af-fetch'), function (x) { x.disabled = false; }); return; }
								var b = res.body;
								idx.textContent = b.finished ? (sample ? 'sample only' : 'yes') : b.done + ' of ' + b.total + ' pages';
								dmsg.textContent = b.message + (b.errors && b.errors.length ? ' Problems: ' + b.errors.join('; ') : '');
								if (b.finished) { Array.prototype.forEach.call(row.querySelectorAll('.af-fetch'), function (x) { x.disabled = false; }); return; }
								return step(false);
							})
							.catch(function (err) { dmsg.textContent = 'Request failed (' + err + '). Press the button to carry on.'; Array.prototype.forEach.call(row.querySelectorAll('.af-fetch'), function (x) { x.disabled = false; }); });
					}
					dmsg.textContent = 'Starting…';
					step(true);
				});
			});

			/* ---- check this server ---- */
			var chk = document.getElementById('af-check');
			if (chk) {
				chk.addEventListener('click', function () {
					var box = document.getElementById('af-check-out');
					chk.disabled = true;
					box.textContent = 'Checking… (up to about 20 seconds)';
					fetch(cfg.check, { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (rows) {
							box.textContent = '';
							var tb = document.createElement('table');
							tb.className = 'widefat striped';
							(rows.length ? rows : []).forEach(function (r) {
								var tr = document.createElement('tr');
								var mark = { ok: 'OK', warn: 'Warning', fail: 'Problem' }[r.status] || r.status;
								[mark, r.name, r.detail].forEach(function (t) { var td = document.createElement('td'); td.textContent = t; tr.appendChild(td); });
								tb.appendChild(tr);
							});
							box.appendChild(tb);
							chk.disabled = false;
						})
						.catch(function (err) { box.textContent = 'The check failed (' + err + ').'; chk.disabled = false; });
				});
			}

			/* ---- insert a quotation ---- */
			var qf = document.getElementById('af-quote-form');
			qf.addEventListener('submit', function (e) {
				e.preventDefault();
				var box = document.getElementById('af-quote-out');
				box.textContent = 'Fetching…';
				var q = new URLSearchParams({ doc: qf.doc.value, locator: qf.locator.value });
				fetch(cfg.passage + '?' + q.toString(), { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
					.then(function (res) {
						box.textContent = '';
						if (!res.ok) { box.textContent = res.body.message || 'Request failed.'; return; }
						var b = res.body;
						var wrap = document.createElement('div'); wrap.innerHTML = b.html; box.appendChild(wrap);
						var info = document.createElement('p');
						info.textContent = (b.verified ? 'Checked against the index: same paragraph.' : 'NOT checked: the text found does not match the indexed paragraph.') + ' ' + b.text.split(/\s+/).length + ' words, fetched in ' + b.fetch_ms + ' ms.';
						box.appendChild(info);
						var ta = document.createElement('textarea'); ta.readOnly = true; ta.rows = 6; ta.style.width = '100%'; ta.value = b.html; box.appendChild(ta);
					})
					.catch(function (err) { box.textContent = 'Request failed (' + err + ').'; });
			});

			/* ---- build a link ---- */
			var form = document.getElementById('af-form');
			var out = document.getElementById('af-out');
			var linkBox = document.getElementById('af-link');
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				linkBox.textContent = '';
				out.textContent = 'Working…';
				var q = new URLSearchParams({ doc: form.doc.value, locator: form.locator.value });
				if (form.refresh && form.refresh.checked) { q.set('refresh', '1'); }
				var t0 = performance.now();
				fetch(cfg.link + '?' + q.toString(), { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
					.then(function (res) {
						var ms = Math.round(performance.now() - t0);
						out.textContent = JSON.stringify(res.body, null, 2) + '\n\nround trip: ' + ms + ' ms';
						if (res.ok && res.body.link) {
							var a = document.createElement('a');
							a.href = res.body.link;
							a.target = '_blank';
							a.rel = 'noopener';
							a.textContent = res.body.title + ' ' + res.body.locator + ' (opens vatican.va)';
							linkBox.appendChild(a);
						}
					})
					.catch(function (err) { out.textContent = 'Request failed: ' + err; });
			});

			/* ---- citation graph ---- */
			var rbtn = document.getElementById('af-rebuild');
			var rmsg = document.getElementById('af-rebuild-msg');
			rbtn.addEventListener('click', function () {
				var tot = { processed: 0, skipped: 0, errors: [] };
				var force = document.getElementById('af-rebuild-force').checked;
				rbtn.disabled = true;
				function step(after) {
					fetch(cfg.rebuild + '?after=' + after + (force ? '&force=1' : ''), { method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
						.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
						.then(function (res) {
							var b = res.body;
							if (!res.ok) { rmsg.textContent = 'Stopped: ' + (b.message || 'request failed') + ' Press the button to carry on.'; rbtn.disabled = false; return; }
							tot.processed += b.processed; tot.skipped += b.skipped; tot.errors = tot.errors.concat(b.errors);
							rmsg.textContent = 'Matched ' + tot.processed + ', already done ' + tot.skipped + ', ' + b.remaining + ' left' + (tot.errors.length ? ', ' + tot.errors.length + ' errors (' + tot.errors.slice(0, 3).join('; ') + ')' : '') + '.';
							if (b.done) { rmsg.textContent += ' Finished.'; rbtn.disabled = false; showGraph(); return; }
							step(b.last);
						})
						.catch(function (err) { rmsg.textContent = 'Request failed (' + err + '). Press the button to carry on.'; rbtn.disabled = false; });
				}
				step(0);
			});
			var gform = document.getElementById('af-graph-form');
			var gout = document.getElementById('af-graph-out');
			function table(head, rows) {
				var t = el('table', null, 'border-collapse:collapse;margin:.5em 0 1em');
				var tr = el('tr');
				head.forEach(function (h) { tr.appendChild(el('th', h, 'text-align:left;padding:2px 12px 2px 0;border-bottom:1px solid #ccc')); });
				t.appendChild(tr);
				rows.forEach(function (r) {
					var tr2 = el('tr');
					r.forEach(function (c) {
						var td = el('td', null, 'padding:2px 12px 2px 0');
						if (c && c.href) { var a = el('a', c.text); a.href = c.href; a.target = '_blank'; a.rel = 'noopener'; td.appendChild(a); } else { td.textContent = c; }
						tr2.appendChild(td);
					});
					t.appendChild(tr2);
				});
				return t;
			}
			function showGraph() {
				var q = new URLSearchParams();
				['doc', 'locator', 'status'].forEach(function (k) { if (gform[k].value) { q.set(k, gform[k].value); } });
				gout.textContent = 'Loading…';
				fetch(cfg.graph + '?' + q.toString(), { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						gout.textContent = '';
						var s = j.summary;
						gout.appendChild(el('p', s.edges + ' citations in ' + s.posts + ' posts, touching ' + s.paragraphs + ' paragraphs. Posts matched so far: ' + s.analyzed + '.'));
						gout.appendChild(el('h3', 'By document'));
						gout.appendChild(table(['Document', 'Posts', 'Paragraphs', 'Citations'], s.by_doc.map(function (r) { return [r.doc, r.posts, r.paragraphs, r.edges]; })));
						gout.appendChild(el('h3', 'By confidence (all documents)'));
						gout.appendChild(table(['Status', 'Citations'], s.by_status.map(function (r) { return [r.status, r.edges]; })));
						gout.appendChild(el('h3', 'Most cited paragraphs'));
						gout.appendChild(table(['Document', 'Paragraph', 'Posts'], j.top.map(function (r) { return [r.doc, r.locator, r.posts]; })));
						if (j.posts) {
							gout.appendChild(el('h3', 'Posts citing this selection'));
							gout.appendChild(table(['Post', 'Document', 'Paragraph', 'How', 'Status'], j.posts.map(function (r) { return [{ text: r.title || ('#' + r.post_id), href: r.url }, r.doc, r.locator, r.basis, r.status]; })));
						}
					})
					.catch(function (err) { gout.textContent = 'Request failed (' + err + ').'; });
			}
			gform.addEventListener('submit', function (e) { e.preventDefault(); showGraph(); });
			showGraph();

			/* ---- review ---- */
			var rform = document.getElementById('af-review-form');
			var rout = document.getElementById('af-review-out');
			var roffset = 0;
			function decide(btnRow, edge, value, siblings) {
				var all = [{ edge: edge, value: value }];
				if (value === 1 && edge.status === 'conflict') { // the other conflicting edges of this quotation cannot also be right
					siblings.forEach(function (s) { if (s.edge.id !== edge.id && s.edge.status === 'conflict' && s.edge.confirmed !== '1' && s.edge.confirmed !== 1) { all.push({ edge: s.edge, value: 0 }); } });
				}
				all.forEach(function (d) {
					var body = new URLSearchParams({ id: d.edge.id, value: d.value === null ? '' : d.value });
					fetch(cfg.confirm, { method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function (j) { d.edge.confirmed = j.confirmed === null ? null : String(j.confirmed); d.edge.paint(); });
				});
			}
			function renderGroup(g) {
				var box = el('div', null, 'border:1px solid #ccd0d4;background:#fff;padding:.6em .9em;margin:0 0 .8em');
				var head = el('div', null, 'margin-bottom:.3em');
				var a = el('a', g.title || ('#' + g.post_id)); a.href = g.url; a.target = '_blank'; a.rel = 'noopener';
				head.appendChild(a);
				box.appendChild(head);
				if (g.excerpt) { box.appendChild(el('div', '“' + g.excerpt + '…”', 'font-style:italic;color:#50575e;margin-bottom:.4em')); }
				var sibs = g.edges.map(function (e) { return { edge: e }; });
				g.edges.forEach(function (e) {
					var row = el('div', null, 'padding:2px 0');
					var label = el('span', null, 'display:inline-block;min-width:7em');
					var link = el('a', e.title + ' ' + e.locator); if (e.link) { link.href = e.link; link.target = '_blank'; link.rel = 'noopener'; }
					var how = e.basis === 'typed' ? 'author typed' : 'words match';
					var cov = e.cover !== null && e.cover !== undefined ? ', ' + Math.round(e.cover * 100) + '% of the words' : '';
					label.textContent = how;
					var state = el('span', null, 'margin-left:.6em;font-weight:600');
					var yes = el('button', 'Correct'); yes.className = 'button button-small'; yes.type = 'button';
					var no = el('button', 'Wrong'); no.className = 'button button-small'; no.type = 'button'; no.style.marginLeft = '4px';
					var clr = el('button', 'Clear'); clr.className = 'button button-small'; clr.type = 'button'; clr.style.marginLeft = '4px';
					e.paint = function () {
						var c = e.confirmed === null || e.confirmed === undefined ? null : String(e.confirmed);
						state.textContent = c === '1' ? '✔ correct' : c === '0' ? '✘ wrong' : '';
						row.style.opacity = c === '0' ? '.55' : '1';
					};
					yes.addEventListener('click', function () { decide(row, e, 1, sibs); });
					no.addEventListener('click', function () { decide(row, e, 0, sibs); });
					clr.addEventListener('click', function () { decide(row, e, null, sibs); });
					row.appendChild(label); row.appendChild(link); row.appendChild(el('span', ' (' + e.status + cov + ') ', 'color:#50575e'));
					row.appendChild(yes); row.appendChild(no); row.appendChild(clr); row.appendChild(state);
					e.paint();
					box.appendChild(row);
				});
				return box;
			}
			function loadReview(append) {
				if (!append) { roffset = 0; rout.textContent = 'Loading…'; }
				var q = new URLSearchParams({ status: rform.status.value, offset: roffset });
				if (rform.reviewed.checked) { q.set('reviewed', '1'); }
				fetch(cfg.review + '?' + q.toString(), { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
					.then(function (res) {
						if (!res.ok) { rout.textContent = 'Failed: ' + (res.body.message || 'request failed'); return; }
						var b = res.body;
						if (!append) { rout.textContent = ''; rout.appendChild(el('p', b.total + ' quotations to review.', 'font-weight:600')); }
						var old = document.getElementById('af-review-more'); if (old) { old.remove(); }
						b.groups.forEach(function (g) { rout.appendChild(renderGroup(g)); });
						roffset += b.groups.length;
						if (roffset < b.total && b.groups.length) {
							var more = el('button', 'Show the next ' + Math.min(20, b.total - roffset)); more.className = 'button'; more.id = 'af-review-more'; more.type = 'button';
							more.addEventListener('click', function () { more.disabled = true; loadReview(true); });
							rout.appendChild(more);
						}
					})
					.catch(function (err) { rout.textContent = 'Request failed (' + err + ').'; });
			}
			rform.addEventListener('submit', function (e) { e.preventDefault(); loadReview(false); });

			/* ---- match posts ---- */
			var mform = document.getElementById('af-match-form');
			var mout = document.getElementById('af-match-out');

			function el(tag, text, css) {
				var n = document.createElement(tag);
				if (text !== undefined && text !== null) { n.textContent = text; }
				if (css) { n.style.cssText = css; }
				return n;
			}

			function renderPost(res) {
				var box = el('details', null, 'background:#fff;border:1px solid #ccd0d4;padding:.6em 1em;margin:0 0 .6em');
				box.open = true;
				var counts = Object.keys(res.summary || {}).map(function (k) { return k + ' ' + res.summary[k]; }).join(', ');
				box.appendChild(el('summary', '#' + res.post_id + ' ' + res.title + ' — ' + res.quotes.length + ' quotation(s)' + (counts ? ': ' + counts : ''), 'cursor:pointer;font-weight:600'));
				if (!res.quotes.length) { box.appendChild(el('p', 'No block quotes or long quoted passages found.')); return box; }
				var t = el('table', null, 'border-collapse:collapse;width:100%;margin-top:.6em;font-size:13px');
				var head = el('tr');
				['#', 'status', 'matched', 'cover', 'typed', 'quotation'].forEach(function (h) { head.appendChild(el('th', h, 'text-align:left;border-bottom:1px solid #ccd0d4;padding:3px 6px')); });
				t.appendChild(head);
				res.quotes.forEach(function (q) {
					var tr = el('tr');
					var matched = q.best ? q.best.doc + ' ' + q.best.locators.join(',') : '—';
					var typed = (q.typed || []).map(function (c) { return (c.doc || '?') + ' ' + c.loc.join(','); }).join('; ');
					var extra = q.note ? ' [' + q.note + ']' : '';
					if (q.alt && q.alt.length) { extra += ' also: ' + q.alt.map(function (a) { return a.doc + ' ' + a.locators.join(',') + ' (' + a.coverage + ')'; }).join('; '); }
					[q.i, q.status, matched, q.best ? q.best.coverage : '', typed, q.excerpt + extra].forEach(function (v) {
						tr.appendChild(el('td', v, 'vertical-align:top;border-bottom:1px solid #eee;padding:3px 6px'));
					});
					t.appendChild(tr);
				});
				box.appendChild(t);
				return box;
			}

			mform.addEventListener('submit', function (e) {
				e.preventDefault();
				var ids = mform.ids.value.split(/[\s,]+/).filter(function (s) { return /^\d+$/.test(s); });
				mout.textContent = '';
				if (!ids.length) { mout.textContent = 'Enter at least one post ID.'; return; }
				var chain = Promise.resolve();
				ids.forEach(function (id) {
					chain = chain.then(function () {
						var status = el('p', 'Matching #' + id + '…');
						mout.appendChild(status);
						return fetch(cfg.match + '?post_id=' + id, { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
							.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
							.then(function (res) {
								mout.removeChild(status);
								if (res.ok) { mout.appendChild(renderPost(res.body)); }
								else { mout.appendChild(el('p', '#' + id + ': ' + (res.body.message || 'failed'), 'color:#b32d2e')); }
							})
							.catch(function (err) { status.textContent = '#' + id + ': request failed (' + err + ')'; });
					});
				});
				chain.then(function () { mout.appendChild(el('p', 'Done. Results are saved on the server.', 'color:#555')); });
			});
		})();
		</script>
		<?php
	}
}
