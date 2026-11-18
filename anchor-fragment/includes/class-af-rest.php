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
