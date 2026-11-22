<?php
/**
 * Probe: the footnotes of the Catechism name the documents each paragraph quotes ("LG 1; cf. Mk 16:15"). A quotation of
 * Lumen Gentium 1 can therefore match Catechism 748 as well as the original, and an author who cites one while the
 * words match the other is not wrong. This reads the footnotes into a map and checks the recorded conflicts against it.
 *
 * The map is paragraph number => list of "document:locator" the footnotes of that paragraph cite. It holds citations,
 * never any of the Catechism's text.
 */

defined( 'ABSPATH' ) || exit;

class AF_Xref {

	const STATE = 'af_xref_state';
	const MAP   = 'af_xref_map';

	/**
	 * One page of the Catechism: footnote references per paragraph, as footnote texts.
	 * Two markups are understood: the Holy See's archive pages (a reference is a link to "#$code" and its note a link to
	 * "#-code", the same number in both) and Word-style pages (_ftnref5 / _ftn5 anchors).
	 *
	 * @return array { paras: n => string[] (footnote texts), refs: int, notes: int, style: string }
	 */
	public static function parse_page( $html ) {
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp = new DOMXPath( $dom );
		foreach ( iterator_to_array( $xp->query( '//script|//style' ) ) as $n ) {
			$n->parentNode->removeChild( $n );
		}

		$notes   = self::archive_notes( $dom ); // code => text
		$style   = $notes ? 'archive' : '';
		$cur     = 0;
		$lead    = array();
		$perpara = array(); // paragraph => codes
		foreach ( $xp->query( '//p|//li|//td|//dd|//div' ) as $node ) {
			if ( $xp->query( './/p|.//li|.//td|.//div', $node )->length > 0 ) {
				continue; // A container of other blocks.
			}
			$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $node->textContent, ENT_QUOTES, 'UTF-8' ) ) );
			$text = str_replace( "\xC2\xA0", ' ', $text );
			if ( '' === $text ) {
				continue;
			}
			$note_codes = array();
			$ref_codes  = array();
			foreach ( $xp->query( './/a', $node ) as $a ) {
				$href = $a->getAttribute( 'href' );
				$name = $a->getAttribute( 'name' ) ?: $a->getAttribute( 'id' );
				$num  = trim( $a->textContent );
				if ( 0 === strpos( $href, '#-' ) && preg_match( '/^\[?\d{1,4}\]?$/', $num ) ) {
					$style = $style ?: 'archive'; // The note itself: read by archive_notes().
				} elseif ( 0 === strpos( $href, '#$' ) && preg_match( '/^\[?\d{1,4}\]?$/', $num ) ) {
					$ref_codes[] = substr( $href, 2 );
					$style       = $style ?: 'archive';
				} elseif ( 0 === strpos( $name, '_ftnref' ) || ( 0 === strpos( $href, '#_ftn' ) && false === strpos( $href, '#_ftnref' ) ) ) {
					$ref_codes[] = preg_replace( '/\D/', '', '' !== $name ? $name : $href );
					$style       = $style ?: 'word';
				} elseif ( 0 === strpos( $name, '_ftn' ) || 0 === strpos( $href, '#_ftnref' ) ) {
					$note_codes[] = preg_replace( '/\D/', '', '' !== $name ? $name : $href );
					$style        = $style ?: 'word';
				}
			}
			if ( $note_codes ) {
				$body = preg_replace( '/^\[?\d{1,4}\]?\s*/u', '', $text );
				foreach ( $note_codes as $c ) {
					$notes[ $c ] = isset( $notes[ $c ] ) ? $notes[ $c ] . ' ' . $body : $body;
				}
				continue; // A footnote block does not start a paragraph.
			}
			if ( preg_match( '/^(\d{1,4})\s+\S/u', $text, $m ) ) {
				$cur = (int) $m[1];
				// A lead-in line just before a numbered paragraph carries the note for that paragraph's quotation too.
				foreach ( $lead as $c ) {
					$perpara[ $cur ][] = $c;
				}
				$lead = array();
			} else {
				$lead = false === stripos( (string) $node->getAttribute( 'style' ), 'margin-left' ) ? $ref_codes : array(); // An indented block continues the paragraph above.
			}
			if ( $cur ) {
				foreach ( $ref_codes as $c ) {
					$perpara[ $cur ][] = $c;
				}
			}
		}
		$paras = array();
		$refs  = 0;
		foreach ( $perpara as $n => $codes ) {
			foreach ( $codes as $c ) {
				$refs++;
				if ( isset( $notes[ $c ] ) ) {
					$paras[ $n ][] = $notes[ $c ];
				}
			}
		}
		return array( 'paras' => $paras, 'refs' => $refs, 'notes' => count( $notes ), 'style' => $style );
	}

	/**
	 * The notes of an archive page. They are not blocks of their own: after the text come the numbers as links
	 * ("#-code") each followed by its note, up to the next line break.
	 */
	private static function archive_notes( DOMDocument $dom ) {
		$notes = array();
		$cur   = null;
		$begun = false;
		$walk  = function ( DOMNode $n ) use ( &$walk, &$notes, &$cur, &$begun ) {
			foreach ( $n->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType ) {
					$tag = strtolower( $c->nodeName );
					if ( 'a' === $tag && 0 === strpos( $c->getAttribute( 'href' ), '#-' ) && preg_match( '/^\[?\d{1,4}\]?$/', trim( $c->textContent ) ) ) {
						$cur           = substr( $c->getAttribute( 'href' ), 2 );
						$notes[ $cur ] = '';
						$begun         = false;
						continue; // Its own number is not part of the note.
					}
					if ( 'br' === $tag || 'hr' === $tag ) {
						if ( $begun ) {
							$cur = null;
						}
						continue;
					}
					if ( 'a' === $tag && null !== $cur && ! $begun ) {
						continue; // Page navigation links are not notes.
					}
					$walk( $c );
				} elseif ( XML_TEXT_NODE === $c->nodeType && null !== $cur ) {
					$t = trim( preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $c->nodeValue ) ) );
					if ( '' !== $t ) {
						$notes[ $cur ] = trim( $notes[ $cur ] . ' ' . $t );
						$begun         = true;
					}
				}
			}
		};
		$walk( $dom );
		return array_filter( $notes, 'strlen' );
	}

	/** Footnote texts to "document:locator" strings, for the documents in the registry. */
	public static function refs_of( AF_Analyzer $an, array $texts ) {
		$out = array();
		foreach ( $texts as $t ) {
			foreach ( $an->refs( $t ) as $r ) {
				foreach ( $r['loc'] as $l ) {
					$out[ $r['doc'] . ':' . $l ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * The footnote map: the one shipped with the plugin (data/catechism-xref.json) plus anything this site read itself.
	 * Catechism paragraph => [ "document:locator", ... ]
	 */
	public static function map() {
		static $shipped = null;
		if ( null === $shipped ) {
			$shipped = array();
			$file    = AF_DIR . 'data/catechism-xref.json';
			if ( is_readable( $file ) ) {
				$j = json_decode( (string) file_get_contents( $file ), true );
				if ( is_array( $j ) && isset( $j['map'] ) && is_array( $j['map'] ) ) {
					$shipped = $j['map'];
				}
			}
		}
		$map = $shipped;
		if ( function_exists( 'get_option' ) ) {
			foreach ( (array) get_option( self::MAP, array() ) as $n => $refs ) {
				$map[ $n ] = array_values( array_unique( array_merge( isset( $map[ $n ] ) ? $map[ $n ] : array(), (array) $refs ) ) );
			}
		}
		return $map;
	}

	/**
	 * Is the typed citation explained by the footnotes of the Catechism paragraph the words matched (or the reverse)?
	 *
	 * @return string 'exact', 'document' or ''
	 */
	public static function explains( array $map, $cc_para, $doc, $loc ) {
		$have = isset( $map[ (int) $cc_para ] ) ? $map[ (int) $cc_para ] : array();
		if ( in_array( $doc . ':' . (int) $loc, $have, true ) ) {
			return 'exact';
		}
		foreach ( $have as $h ) {
			if ( 0 === strpos( $h, $doc . ':' ) ) {
				return 'document';
			}
		}
		return '';
	}

	/** Read the next pages of the Catechism. @return array|WP_Error */
	public static function step( $budget = 25.0, $restart = false ) {
		$reg = AF_Service::registry();
		if ( empty( $reg['catechism']['multipage'] ) ) {
			return new WP_Error( 'af_xref', 'The Catechism is not set up as a multi-page document.', array( 'status' => 400 ) );
		}
		$mp = $reg['catechism']['multipage'];
		$st = get_option( self::STATE );
		if ( $restart || ! is_array( $st ) ) {
			$res = AF_Service::fetch_page( $mp['index'] );
			if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
				return new WP_Error( 'af_xref', 'Could not read the Catechism index.', array( 'status' => 502 ) );
			}
			list( $html ) = AF_Parser::to_utf8( $res['body'], $res['content_type'] );
			$pages        = array();
			if ( preg_match_all( '#href=["\']?(' . $mp['page_pattern'] . ')#i', $html, $m ) ) {
				foreach ( $m[1] as $href ) {
					$pages[ strtoupper( $href ) ] = $href;
				}
			}
			$st = array( 'pages' => array_values( $pages ), 'next' => 0, 'refs' => 0, 'notes' => 0, 'with_refs' => 0, 'styles' => array(), 'errors' => array(), 'sample' => '' );
			update_option( self::MAP, array(), false );
		}
		$map  = get_option( self::MAP, array() );
		$an   = new AF_Analyzer( $reg );
		$base = preg_replace( '#/[^/]*$#', '/', $mp['index'] );
		$t0   = microtime( true );
		$tot  = count( $st['pages'] );
		while ( $st['next'] < $tot && ( microtime( true ) - $t0 ) < $budget ) {
			$page = $st['pages'][ $st['next'] ];
			$res  = AF_Service::fetch_page( $base . $page );
			if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
				$st['errors'][] = $page . ': ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $res['code'] );
				if ( count( $st['errors'] ) > 20 ) {
					break;
				}
				$st['next']++;
				continue;
			}
			list( $html ) = AF_Parser::to_utf8( $res['body'], $res['content_type'] );
			$p            = self::parse_page( $html );
			$st['refs']  += $p['refs'];
			$st['notes'] += $p['notes'];
			if ( $p['style'] ) {
				$st['styles'][ $p['style'] ] = ( $st['styles'][ $p['style'] ] ?? 0 ) + 1;
			}
			if ( '' === $st['sample'] && 0 === $p['refs'] && $st['next'] > 3 ) {
				// Nothing recognised on a text page: keep a little markup so it can be looked at.
				$st['sample'] = substr( $html, (int) ( strlen( $html ) / 3 ), 600 );
			}
			foreach ( $p['paras'] as $n => $texts ) {
				$r = self::refs_of( $an, $texts );
				if ( $r ) {
					$map[ $n ]      = array_values( array_unique( array_merge( $map[ $n ] ?? array(), $r ) ) );
					$st['with_refs']++;
				}
			}
			$st['next']++;
		}
		update_option( self::MAP, $map, false );
		update_option( self::STATE, $st, false );
		return array(
			'done'      => (int) $st['next'],
			'total'     => $tot,
			'finished'  => $st['next'] >= $tot,
			'paragraphs' => count( $map ),
			'refs'      => (int) $st['refs'],
			'notes'     => (int) $st['notes'],
			'styles'    => $st['styles'],
			'errors'    => array_slice( $st['errors'], -5 ),
			'sample'    => $st['sample'],
		);
	}

	/**
	 * The recorded conflicts that involve the Catechism, with whether its footnotes explain them.
	 *
	 * @return array { map_size, groups: [...], counts: { exact, document, none, other } }
	 */
	public static function explain( $status = 'conflict' ) {
		global $wpdb;
		$map = self::map();
		$t   = AF_Graph::table();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT CONCAT(post_id,'|',quote_ord,'|',quote_hash) FROM {$t} WHERE status = %s AND ( confirmed IS NULL OR confirmed = 1 )", $status ) ); // phpcs:ignore WordPress.DB
		$counts = array( 'exact' => 0, 'document' => 0, 'none' => 0, 'other' => 0 );
		$groups = array();
		foreach ( (array) $ids as $key ) {
			list( $pid, $ord, $hash ) = explode( '|', $key );
			$edges = $wpdb->get_results( $wpdb->prepare( "SELECT id, doc, loc_num, basis FROM {$t} WHERE post_id = %d AND quote_ord = %d AND quote_hash = %s AND status = %s", $pid, $ord, $hash, $status ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$cc    = array();
			$oth   = array();
			foreach ( $edges as $e ) {
				if ( 'catechism' === $e['doc'] ) {
					$cc[] = $e;
				} else {
					$oth[] = $e;
				}
			}
			if ( ! $cc || ! $oth ) {
				$counts['other']++;
				continue;
			}
			$verdict = '';
			$why     = '';
			foreach ( $cc as $c ) {
				foreach ( $oth as $o ) {
					$x = self::explains( $map, $c['loc_num'], $o['doc'], $o['loc_num'] );
					if ( 'exact' === $x || ( 'document' === $x && 'exact' !== $verdict ) ) {
						$verdict = $x;
						$why     = 'CCC ' . $c['loc_num'] . ' cites ' . $o['doc'] . ( 'exact' === $x ? ' ' . $o['loc_num'] : '' );
					}
				}
			}
			$counts[ '' === $verdict ? 'none' : $verdict ]++;
			$groups[] = array(
				'post'    => (int) $pid,
				'title'   => get_the_title( (int) $pid ),
				'link'    => get_permalink( (int) $pid ),
				'verdict' => '' === $verdict ? 'none' : $verdict,
				'why'     => $why,
				'edges'   => array_map( function ( $e ) { return $e['doc'] . ' ' . $e['loc_num'] . ' (' . $e['basis'] . ')'; }, $edges ),
			);
		}
		usort( $groups, function ( $a, $b ) { return strcmp( $a['verdict'], $b['verdict'] ) ?: ( $a['post'] <=> $b['post'] ); } );
		return array( 'map_size' => count( $map ), 'counts' => $counts, 'groups' => $groups );
	}
}
