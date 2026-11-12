<?php
/**
 * REST endpoint the editor will call, plus a small test page under Tools.
 *
 *   GET /wp-json/anchorfrag/v1/link?doc=laudato-si&locator=12
 *
 * Needs a logged-in user who can edit posts. It takes a document id from the
 * registry, never a URL, so it cannot be pointed at arbitrary addresses.
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
	}

	public static function handle_link( WP_REST_Request $req ) {
		$refresh = (bool) $req['refresh'] && current_user_can( 'manage_options' );
		$res     = AF_Service::link( $req['doc'], $req['locator'], $refresh );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function add_page() {
		add_management_page( 'Anchor Fragment Test', 'Anchor Fragment Test', 'edit_posts', 'af-test', array( __CLASS__, 'render_page' ) );
	}

	public static function render_page() {
		$cfg = array(
			'url'   => rest_url( 'anchorfrag/v1/link' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		);
		?>
		<div class="wrap">
			<h1>Anchor Fragment Test</h1>
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
		</div>
		<script>
		(function () {
			var cfg = <?php echo wp_json_encode( $cfg ); ?>;
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
				fetch(cfg.url + '?' + q.toString(), { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' })
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
		})();
		</script>
		<?php
	}
}
