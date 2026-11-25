<?php
/**
 * The "Citations to fix" screen: the quotations whose cite and words disagree (or whose cite could not be
 * found), one card each, grouped by post. It changes nothing in a post. A decision is stored beside the graph:
 * "my cite is right", "the cite needs fixing" or "ignore", and can be undone.
 */

defined( 'ABSPATH' ) || exit;

class AF_Fix {
	const STATE_OPTION = 'af_fix_state';
	const KINDS        = array( 'conflict', 'not_found', 'ambiguous' );
	const MAX_ROWS     = 600;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'anchorfrag/v1',
			'/fix/list',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_list' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		register_rest_route(
			'anchorfrag/v1',
			'/fix/state',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_state' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/* ------------------------------------------------------------------ pure parts */

	/** The key a decision is stored under: the post and the quotation (its fingerprint, or its position when it has none). */
	public static function key( $post_id, $hash, $ord ) {
		return (int) $post_id . '|' . ( '' !== (string) $hash ? (string) $hash : 'q' . (int) $ord );
	}

	/**
	 * Say in plain words what is wrong with one quotation.
	 *
	 * @param string $status The quotation's status (conflict, not_found, ambiguous).
	 * @param array  $edges  Its edges: doc, locator, loc_num, basis, status, cover.
	 * @param array  $reg    The registry, for titles.
	 * @return array { kind, headline, hint, cited[], found[] }
	 */
	public static function diagnose( $status, array $edges, array $reg ) {
		$title = function ( $doc ) use ( $reg ) {
			return isset( $reg[ $doc ]['title'] ) ? $reg[ $doc ]['title'] : $doc;
		};
		$cited = array();
		$found = array();
		foreach ( $edges as $e ) {
			$item = array( 'doc' => $e['doc'], 'title' => $title( $e['doc'] ), 'locator' => (string) $e['locator'], 'loc_num' => (int) $e['loc_num'], 'cover' => isset( $e['cover'] ) && null !== $e['cover'] ? (float) $e['cover'] : null, 'link' => isset( $e['link'] ) ? $e['link'] : '' );
			if ( 'typed' === $e['basis'] ) {
				$cited[] = $item;
			} else {
				$found[] = $item;
			}
		}
		$say = function ( array $list ) {
			$by = array();
			foreach ( $list as $i ) {
				$by[ $i['title'] ][] = $i['locator'];
			}
			$parts = array();
			foreach ( $by as $t => $locs ) {
				$parts[] = $t . ' ' . implode( ', ', $locs );
			}
			return implode( ' and ', $parts );
		};
		$hint = '';
		if ( 'conflict' === $status ) {
			$headline = $cited && $found
				? 'You cited ' . $say( $cited ) . ', but its words are in ' . $say( $found ) . '.'
				: 'The cite and the words disagree.';
			if ( 1 === count( $cited ) && $found ) {
				$c      = $cited[0];
				$same   = array_filter( $found, function ( $f ) use ( $c ) { return $f['doc'] === $c['doc']; } );
				if ( ! $same ) {
					$hint = 'other_document';
				} elseif ( min( array_map( function ( $f ) use ( $c ) { return abs( $f['loc_num'] - $c['loc_num'] ); }, $same ) ) <= 1 ) {
					$hint = 'next_to'; // an off-by-one, or a quotation that runs over into the next paragraph
				} else {
					$hint = 'same_document';
				}
			}
		} elseif ( 'not_found' === $status ) {
			$headline = $cited
				? 'You cited ' . $say( $cited ) . ', but this quotation was not found there, or anywhere else in the documents we hold.'
				: 'The quotation was not found.';
			$hint     = 'not_found';
		} elseif ( 'ambiguous' === $status ) {
			$headline = $found ? 'The words fit several paragraphs: ' . $say( $found ) . '. Say which one you mean.' : 'The words fit several paragraphs.';
			$hint     = 'ambiguous';
		} else {
			$headline = 'Needs a look (' . $status . ').';
		}
		return array( 'kind' => $status, 'headline' => $headline, 'hint' => $hint, 'cited' => $cited, 'found' => $found );
	}

	public static function states() {
		$s = get_option( self::STATE_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/** The edges of one quotation, as rows of the graph table. */
	private static function edges_of( $post_id, $hash, $ord ) {
		global $wpdb;
		$t = AF_Graph::table();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, doc, locator, loc_num, basis, status, cover, confirmed FROM {$t} WHERE post_id = %d AND quote_hash = %s AND quote_ord = %d", (int) $post_id, (string) $hash, (int) $ord ),
			ARRAY_A
		);
	}

	/**
	 * Record a decision about one quotation.
	 *
	 * ok:   the author's cite is right (the typed paragraph is confirmed, the others rejected).
	 * fix:  the cite needs fixing (the typed paragraph is rejected, the paragraphs where the words are found are confirmed).
	 * skip: leave it alone; nothing changes in the graph.
	 * '':   undo the decision.
	 *
	 * @return array|WP_Error { state, key }
	 */
	public static function set_state( $post_id, $hash, $ord, $state ) {
		if ( ! in_array( $state, array( '', 'ok', 'fix', 'skip' ), true ) ) {
			return new WP_Error( 'af_fix_state', 'Unknown decision.' );
		}
		$edges = self::edges_of( $post_id, $hash, $ord );
		if ( ! $edges ) {
			return new WP_Error( 'af_fix_gone', 'That quotation is no longer in the citation graph (the post was matched again). Reload the page.' );
		}
		foreach ( $edges as $e ) {
			if ( 'ok' === $state ) {
				AF_Graph::set_confirmed( $e['id'], 'typed' === $e['basis'] ? 1 : 0 );
			} elseif ( 'fix' === $state ) {
				AF_Graph::set_confirmed( $e['id'], 'typed' === $e['basis'] ? 0 : 1 );
			} else {
				AF_Graph::set_confirmed( $e['id'], null );
			}
		}
		$all = self::states();
		$k   = self::key( $post_id, $hash, $ord );
		if ( '' === $state ) {
			unset( $all[ $k ] );
		} else {
			$all[ $k ] = $state;
		}
		update_option( self::STATE_OPTION, $all, false );
		return array( 'state' => $state, 'key' => $k );
	}

	/**
	 * The quotations to show.
	 *
	 * @param array $a kind (conflict|not_found|ambiguous|all), show (todo|done|all), doc, q (words of the post title).
	 * @return array { items[], counts{}, total, shown }
	 */
	public static function items( array $a ) {
		$kind  = isset( $a['kind'] ) && in_array( $a['kind'], self::KINDS, true ) ? $a['kind'] : ( ! empty( $a['kind'] ) && 'all' === $a['kind'] ? 'all' : 'conflict' );
		$show  = isset( $a['show'] ) && in_array( $a['show'], array( 'done', 'all' ), true ) ? $a['show'] : 'todo';
		$kinds = 'all' === $kind ? self::KINDS : array( $kind );
		$reg   = AF_Service::registry();
		$st    = self::states();
		$q     = isset( $a['q'] ) ? strtolower( trim( (string) $a['q'] ) ) : '';
		$counts = array();
		$items  = array();
		foreach ( self::KINDS as $k ) {
			$counts[ $k ] = (int) AF_Graph::review( array( 'status' => array( $k ), 'confirmed' => 'any' ), 0, 0 )['total'];
		}
		foreach ( $kinds as $k ) {
			$f   = array( 'status' => array( $k ), 'confirmed' => 'any' );
			if ( ! empty( $a['doc'] ) ) {
				$f['doc'] = (string) $a['doc'];
			}
			$res = AF_Graph::review( $f, 0, self::MAX_ROWS );
			foreach ( $res['groups'] as $g ) {
				$state = isset( $st[ self::key( $g['post_id'], $g['hash'], $g['ord'] ) ] ) ? $st[ self::key( $g['post_id'], $g['hash'], $g['ord'] ) ] : '';
				if ( 'todo' === $show && '' !== $state ) {
					continue;
				}
				if ( 'done' === $show && '' === $state ) {
					continue;
				}
				if ( '' !== $q && false === strpos( strtolower( $g['title'] ), $q ) ) {
					continue;
				}
				foreach ( $g['edges'] as &$e ) {
					if ( empty( $e['link'] ) && class_exists( 'AF_Service' ) ) {
						$lk        = AF_Service::link( $e['doc'], $e['locator'] );
						$e['link'] = is_wp_error( $lk ) ? '' : $lk['link'];
					}
				}
				unset( $e );
				$d = self::diagnose( $k, $g['edges'], $reg );
				$items[] = array(
					'key'      => self::key( $g['post_id'], $g['hash'], $g['ord'] ),
					'post_id'  => $g['post_id'],
					'title'    => $g['title'],
					'url'      => $g['url'],
					'edit_url' => function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $g['post_id'], 'raw' ) : '',
					'hash'     => $g['hash'],
					'ord'      => $g['ord'],
					'excerpt'  => $g['excerpt'],
					'note'     => $g['note'],
					'state'    => $state,
					'second'   => array_values( array_filter( array_map( function ( $e ) { return isset( $e['second'] ) ? $e['second'] : ''; }, $g['edges'] ) ) ),
				) + $d;
			}
		}
		usort(
			$items,
			function ( $x, $y ) {
				return strcmp( $x['title'], $y['title'] ) ?: ( $x['post_id'] <=> $y['post_id'] ) ?: ( $x['ord'] <=> $y['ord'] );
			}
		);
		return array( 'items' => $items, 'counts' => $counts, 'shown' => count( $items ) );
	}

	/* ------------------------------------------------------------------ REST */

	public static function handle_list( WP_REST_Request $req ) {
		AF_Graph::maybe_install();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return rest_ensure_response(
			self::items(
				array(
					'kind' => sanitize_key( (string) $req['kind'] ),
					'show' => sanitize_key( (string) $req['show'] ),
					'doc'  => sanitize_text_field( (string) $req['doc'] ),
					'q'    => sanitize_text_field( (string) $req['q'] ),
				)
			)
		);
	}

	public static function handle_state( WP_REST_Request $req ) {
		$res = self::set_state( (int) $req['post_id'], sanitize_text_field( (string) $req['hash'] ), (int) $req['ord'], sanitize_key( (string) $req['state'] ) );
		return is_wp_error( $res ) ? new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 400 ) ) : rest_ensure_response( $res );
	}

	/* ------------------------------------------------------------------ the screen */

	public static function add_page() {
		add_management_page( 'Citations to fix', 'Citations to fix', 'edit_posts', 'af-fix', array( __CLASS__, 'render_page' ) );
	}

	public static function render_page() {
		$cfg = array(
			'list'    => rest_url( 'anchorfrag/v1/fix/list' ),
			'state'   => rest_url( 'anchorfrag/v1/fix/state' ),
			'passage' => rest_url( 'anchorfrag/v1/passage' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		);
		$docs = array();
		foreach ( AF_Service::registry() as $id => $e ) {
			$docs[ $id ] = $e['title'];
		}
		asort( $docs );
		?>
		<div class="wrap" id="af-fix">
			<h1>Citations to fix</h1>
			<p style="max-width:55em">Each card is one quotation in one post where the cite you typed and the words you quoted do not agree. Nothing here changes a post. Open the post to correct it, or record what you decided so the card leaves the to-do list. A decision can always be undone. The numbers come from the last time the posts were matched; run "Match all posts" on the test page to refresh them.</p>
			<style>
				#af-fix .af-bar { display:flex; flex-wrap:wrap; gap:.6em 1.2em; align-items:center; margin:1em 0 .4em }
				#af-fix .af-chips a { display:inline-block; margin-right:.4em; padding:3px 10px; border:1px solid #c3c4c7; border-radius:14px; background:#fff; text-decoration:none; color:#1d2327 }
				#af-fix .af-chips a.on { background:#2271b1; border-color:#2271b1; color:#fff }
				#af-fix details.af-post { background:#fff; border:1px solid #ccd0d4; margin:0 0 .8em; max-width:64em }
				#af-fix details.af-post > summary { cursor:pointer; padding:.6em .9em; font-weight:600; list-style:none }
				#af-fix details.af-post > summary::-webkit-details-marker { display:none }
				#af-fix .af-sub { font-weight:400; color:#50575e; margin-left:.6em }
				#af-fix .af-card { border-top:1px solid #e2e4e7; padding:.7em .9em }
				#af-fix .af-card.done { opacity:.6 }
				#af-fix .af-head { font-weight:600; margin:0 0 .25em }
				#af-fix .af-quote { font-style:italic; color:#50575e; margin:0 0 .4em }
				#af-fix .af-cites { display:flex; flex-wrap:wrap; gap:.3em 1.4em; margin:.2em 0 .4em }
				#af-fix .af-cites span.lab { color:#50575e; margin-right:.3em }
				#af-fix .af-badge { display:inline-block; font-size:11px; padding:1px 7px; border-radius:9px; background:#f0f0f1; color:#50575e; margin-left:.4em; font-weight:400 }
				#af-fix .af-badge.ok { background:#edfaef; color:#1a6e2e }
				#af-fix .af-badge.fix { background:#fcf0f1; color:#8a1f2b }
				#af-fix .af-actions { margin-top:.4em }
				#af-fix .af-actions .button { margin-right:4px }
				#af-fix .af-cmp { display:grid; grid-template-columns:1fr 1fr; gap:.8em; margin:.5em 0; font-size:13px }
				#af-fix .af-cmp > div { background:#f6f7f7; padding:.5em .7em; border-left:3px solid #c3c4c7 }
				#af-fix .af-cmp > div.found { border-left-color:#2271b1 }
				#af-fix .af-cmp b { display:block; margin-bottom:.2em }
				#af-fix mark { background:#fff2a8 }
				@media (max-width:782px) { #af-fix .af-cmp { grid-template-columns:1fr } }
			</style>
			<div class="af-chips" id="af-fix-kinds"></div>
			<div class="af-bar">
				<label>Show <select id="af-fix-show"><option value="todo">to do</option><option value="done">decided</option><option value="all">everything</option></select></label>
				<label>Document <select id="af-fix-doc"><option value="">all</option>
					<?php foreach ( $docs as $id => $t ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select></label>
				<label>Post title <input type="search" id="af-fix-q" placeholder="words in the title"></label>
				<button type="button" class="button" id="af-fix-csv">Download these as CSV</button>
			</div>
			<p id="af-fix-sum" style="font-weight:600"></p>
			<div id="af-fix-out"></div>
		</div>
		<script>
		(function () {
			var cfg = <?php echo wp_json_encode( $cfg ); ?>;
			var out = document.getElementById('af-fix-out'), sum = document.getElementById('af-fix-sum'), kindsBox = document.getElementById('af-fix-kinds');
			var showSel = document.getElementById('af-fix-show'), docSel = document.getElementById('af-fix-doc'), qIn = document.getElementById('af-fix-q');
			var KIND_NAMES = { conflict: 'Cite and words disagree', not_found: 'Not found', ambiguous: 'Several paragraphs fit', all: 'All three' };
			var HINTS = {
				next_to: 'Next to the paragraph you cited: an off-by-one, or the quotation runs on into the next paragraph.',
				same_document: 'Same document, a different paragraph.',
				other_document: 'A different document from the one you named.',
				not_found: 'Check the wording against the source, or the paragraph number.',
				ambiguous: 'Add a paragraph number or the document name next to the quotation.'
			};
			var kind = 'conflict', last = null;
			function el(tag, text, style) { var e = document.createElement(tag); if (text !== undefined && text !== null) { e.textContent = text; } if (style) { e.style.cssText = style; } return e; }
			function api(url, opts) { opts = opts || {}; opts.headers = { 'X-WP-Nonce': cfg.nonce }; opts.credentials = 'same-origin'; return fetch(url, opts).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.message || 'request failed'); } return j; }); }); }

			function drawKinds(counts) {
				kindsBox.textContent = '';
				['conflict', 'not_found', 'ambiguous', 'all'].forEach(function (k) {
					var n = k === 'all' ? counts.conflict + counts.not_found + counts.ambiguous : counts[k];
					var a = el('a', KIND_NAMES[k] + ' (' + n + ')'); a.href = '#'; if (k === kind) { a.className = 'on'; }
					a.addEventListener('click', function (e) { e.preventDefault(); kind = k; load(); });
					kindsBox.appendChild(a);
				});
			}

			/* A window of the passage around the first run of the quotation's words we can find in it. */
			function highlight(text, excerpt) {
				var words = (excerpt || '').replace(/[\u201c\u201d"\u2026]/g, ' ').split(/\s+/).filter(Boolean);
				for (var n = Math.min(7, words.length); n >= 3; n--) {
					for (var s = 0; s + n <= words.length; s++) {
						var re;
						try { re = new RegExp(words.slice(s, s + n).map(function (w) { return w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('[^A-Za-z0-9]+'), 'i'); } catch (e) { continue; }
						var m = re.exec(text);
						if (m) {
							var from = Math.max(0, m.index - 220), to = Math.min(text.length, m.index + m[0].length + 380);
							var frag = document.createDocumentFragment();
							frag.appendChild(document.createTextNode((from ? '\u2026 ' : '') + text.slice(from, m.index)));
							var mk = document.createElement('mark'); mk.textContent = m[0]; frag.appendChild(mk);
							frag.appendChild(document.createTextNode(text.slice(m.index + m[0].length, to) + (to < text.length ? ' \u2026' : '')));
							return frag;
						}
					}
				}
				return document.createTextNode(text.length > 600 ? text.slice(0, 600) + ' \u2026' : text);
			}

			function citeLine(label, list) {
				var box = el('div');
				box.appendChild(el('span', label, null)).className = 'lab';
				if (!list.length) { box.appendChild(el('span', 'nothing')); return box; }
				list.forEach(function (c, i) {
					if (i) { box.appendChild(document.createTextNode('; ')); }
					var t = c.title + ' ' + c.locator;
					if (c.link) { var a = el('a', t); a.href = c.link; a.target = '_blank'; a.rel = 'noopener'; box.appendChild(a); } else { box.appendChild(document.createTextNode(t)); }
					if (c.cover !== null && c.cover !== undefined && label.indexOf('found') > -1) { box.appendChild(el('span', ' (' + Math.round(c.cover * 100) + '% of the words)', 'color:#50575e')); }
				});
				return box;
			}

			function card(it) {
				var c = el('div'); c.className = 'af-card' + (it.state ? ' done' : '');
				var h = el('div', it.headline); h.className = 'af-head';
				var badge = el('span'); badge.className = 'af-badge';
				function paintBadge() {
					badge.className = 'af-badge ' + (it.state || '');
					badge.textContent = it.state === 'ok' ? 'cite is right' : it.state === 'fix' ? 'cite needs fixing' : it.state === 'skip' ? 'ignored' : '';
					badge.style.display = it.state ? '' : 'none';
					c.className = 'af-card' + (it.state ? ' done' : '');
				}
				h.appendChild(badge);
				c.appendChild(h);
				if (it.excerpt) { c.appendChild(el('p', '\u201c' + it.excerpt + (it.excerpt.length > 90 ? '\u2026' : '') + '\u201d')).className = 'af-quote'; }
				var cites = el('div'); cites.className = 'af-cites';
				if (it.kind !== 'ambiguous') { cites.appendChild(citeLine('You cited: ', it.cited)); }
				if (it.kind !== 'not_found') { cites.appendChild(citeLine(it.kind === 'ambiguous' ? 'Fits: ' : 'Words found in: ', it.found)); }
				c.appendChild(cites);
				if (HINTS[it.hint]) { c.appendChild(el('div', HINTS[it.hint], 'color:#50575e;font-size:12px')); }
				(it.second || []).forEach(function (s) { c.appendChild(el('div', 'Second opinion: ' + s, 'color:#50575e;font-size:12px')); });
				var cmp = el('div'); cmp.className = 'af-cmp'; cmp.style.display = 'none';
				var acts = el('div'); acts.className = 'af-actions';
				var bCmp = el('button', 'Compare the paragraphs'); bCmp.type = 'button'; bCmp.className = 'button button-small';
				var loaded = false;
				bCmp.addEventListener('click', function () {
					if (cmp.style.display !== 'none') { cmp.style.display = 'none'; bCmp.textContent = 'Compare the paragraphs'; return; }
					cmp.style.display = ''; bCmp.textContent = 'Hide the paragraphs';
					if (loaded) { return; }
					loaded = true;
					var jobs = it.cited.slice(0, 1).map(function (p) { return { p: p, cls: '', lab: 'The paragraph you cited: ' }; }).concat(it.found.slice(0, 2).map(function (p) { return { p: p, cls: 'found', lab: 'Where the words are: ' }; }));
					jobs.forEach(function (j) {
						var box = el('div'); box.className = j.cls;
						box.appendChild(el('b', j.lab + j.p.title + ' ' + j.p.locator));
						var body = el('span', 'Loading\u2026'); box.appendChild(body); cmp.appendChild(box);
						api(cfg.passage + '?doc=' + encodeURIComponent(j.p.doc) + '&locator=' + encodeURIComponent(j.p.locator))
							.then(function (r) { body.textContent = ''; body.appendChild(highlight(r.text || r.passage || '', it.excerpt)); })
							.catch(function (e) { body.textContent = 'Could not load it (' + e.message + ').'; });
					});
				});
				acts.appendChild(bCmp);
				if (it.edit_url) { var ed = el('a', 'Edit the post'); ed.href = it.edit_url; ed.target = '_blank'; ed.rel = 'noopener'; ed.className = 'button button-small'; acts.appendChild(ed); }
				function decide(state, label, extra) {
					var b = el('button', label); b.type = 'button'; b.className = 'button button-small' + (extra || '');
					b.addEventListener('click', function () {
						b.disabled = true;
						api(cfg.state, { method: 'POST', body: new URLSearchParams({ post_id: it.post_id, hash: it.hash, ord: it.ord, state: state }) })
							.then(function (r) { it.state = r.state; paintBadge(); bumpCounts(); drawButtons(); })
							.catch(function (e) { b.disabled = false; alert('Not saved: ' + e.message); });
					});
					return b;
				}
				var dyn = el('span'); acts.appendChild(dyn);
				function drawButtons() {
					dyn.textContent = '';
					if (!it.state) {
						if (it.kind !== 'ambiguous' && it.cited.length) { dyn.appendChild(decide('ok', 'My cite is right')); }
						if (it.kind === 'conflict') { dyn.appendChild(decide('fix', 'The cite needs fixing')); }
						dyn.appendChild(decide('skip', 'Ignore'));
					} else {
						dyn.appendChild(decide('', 'Undo'));
					}
				}
				paintBadge(); drawButtons();
				c.appendChild(acts); c.appendChild(cmp);
				return c;
			}

			function bumpCounts() {
				var todo = 0, done = 0;
				last.items.forEach(function (i) { if (i.state) { done++; } else { todo++; } });
				sum.textContent = last.items.length + ' quotation' + (last.items.length === 1 ? '' : 's') + ' shown (' + todo + ' to do, ' + done + ' decided) in ' + Object.keys(byPost(last.items)).length + ' post(s).';
			}
			function byPost(items) { var m = {}; items.forEach(function (i) { (m[i.post_id] = m[i.post_id] || []).push(i); }); return m; }

			function draw() {
				out.textContent = '';
				drawKinds(last.counts);
				if (!last.items.length) { sum.textContent = 'Nothing to show with these settings.'; return; }
				bumpCounts();
				var groups = byPost(last.items);
				Object.keys(groups).forEach(function (pid) {
					var list = groups[pid];
					var d = el('details'); d.className = 'af-post'; d.open = true;
					var s = el('summary', list[0].title || ('#' + pid));
					s.appendChild(el('span', list.length + (list.length === 1 ? ' quotation' : ' quotations'))).className = 'af-sub';
					if (list[0].url) { var v = el('a', 'view'); v.href = list[0].url; v.target = '_blank'; v.rel = 'noopener'; v.style.cssText = 'margin-left:.8em;font-weight:400'; v.addEventListener('click', function (e) { e.stopPropagation(); }); s.appendChild(v); }
					d.appendChild(s);
					list.forEach(function (it) { d.appendChild(card(it)); });
					out.appendChild(d);
				});
			}

			function load() {
				out.textContent = ''; sum.textContent = 'Loading\u2026';
				var q = new URLSearchParams({ kind: kind, show: showSel.value, doc: docSel.value, q: qIn.value });
				api(cfg.list + '?' + q.toString()).then(function (j) { last = j; draw(); }).catch(function (e) { sum.textContent = 'Failed: ' + e.message; });
			}
			[showSel, docSel].forEach(function (s) { s.addEventListener('change', load); });
			var tm; qIn.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(load, 400); });

			document.getElementById('af-fix-csv').addEventListener('click', function () {
				if (!last) { return; }
				function q(v) { return '"' + String(v === null || v === undefined ? '' : v).replace(/"/g, '""') + '"'; }
				var rows = [['Post', 'Edit the post', 'View the post', 'Problem', 'Quotation starts', 'You cited', 'Words found in', 'What to do', 'Decision']];
				function names(l) { return l.map(function (c) { return c.title + ' ' + c.locator; }).join('; '); }
				last.items.forEach(function (i) { rows.push([i.title, i.edit_url, i.url, KIND_NAMES[i.kind], i.excerpt, names(i.cited), names(i.found), i.headline, i.state]); });
				var blob = new Blob(['﻿' + rows.map(function (r) { return r.map(q).join(','); }).join('\r\n')], { type: 'text/csv' });
				var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'citations-to-fix.csv'; document.body.appendChild(a); a.click(); a.remove();
			});
			load();
		})();
		</script>
		<?php
	}
}
