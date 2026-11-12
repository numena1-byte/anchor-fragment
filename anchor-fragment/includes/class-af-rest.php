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
				),
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
		$res = AF_Service::ingest_step( $req['doc'], 25.0, (bool) $req['restart'], (bool) $req['sample'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function handle_link( WP_REST_Request $req ) {
		$refresh = (bool) $req['refresh'] && current_user_can( 'manage_options' );
		$res     = AF_Service::link( $req['doc'], $req['locator'], $refresh );
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

	public static function add_page() {
		add_management_page( 'Anchor Fragment Test', 'Anchor Fragment Test', 'edit_posts', 'af-test', array( __CLASS__, 'render_page' ) );
	}

	public static function render_page() {
		$cfg = array(
			'link'  => rest_url( 'anchorfrag/v1/link' ),
			'match'  => rest_url( 'anchorfrag/v1/match' ),
			'ingest' => rest_url( 'anchorfrag/v1/ingest' ),
			'status' => rest_url( 'anchorfrag/v1/status' ),
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
						<td><?php echo esc_html( $row['title'] ); ?><?php echo $row['multipage'] ? ' <em>(multi-page)</em>' : ''; ?></td>
						<td class="af-count"><?php echo null === $row['count'] ? '–' : esc_html( $row['count'] . ( $row['gaps'] ? ', ' . $row['gaps'] . ' gap(s)' : '' ) ); ?></td>
						<td><?php echo esc_html( null === $row['expected'] ? '' : $row['expected'] ); ?></td>
						<td class="af-idx"><?php echo $row['indexed'] ? ( $row['partial'] ? 'sample only' : 'yes' ) : ( $row['progress'] ? esc_html( $row['progress']['done'] . ' of ' . $row['progress']['total'] . ' pages' ) : 'no' ); ?></td>
						<td>
							<?php if ( $row['multipage'] && current_user_can( 'manage_options' ) ) : ?>
								<button class="button af-fetch" type="button"><?php echo $row['indexed'] && ! $row['partial'] ? 'Fetch again' : ( $row['progress'] ? 'Continue' : 'Fetch all' ); ?></button>
								<button class="button af-fetch af-sample" type="button">Fetch sample (about 36 pages)</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p id="af-docs-msg" style="max-width:60em"></p>

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
					var restart = sample || /again/.test(btn.textContent);
					var idx = row.querySelector('.af-idx');
					Array.prototype.forEach.call(row.querySelectorAll('.af-fetch'), function (x) { x.disabled = true; });
					function step(first) {
						var q = new URLSearchParams({ doc: doc });
						if (first && restart) { q.set('restart', '1'); }
						if (sample) { q.set('sample', '1'); }
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
