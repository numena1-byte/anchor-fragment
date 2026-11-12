<?php
/**
 * Turns a fetched Vatican HTML page into a map of locator => paragraph.
 * Pure PHP (no WordPress functions) so it can be tested on its own.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'AF_TEST' ) ) {
	exit;
}

class AF_Parser {

	/** Bump when parsing rules change, so cached results are rebuilt. */
	const VERSION = 5; // 5: headings printed as paragraphs leave the previous one; 4: optional max locator; 3: documents also build the hashed word-run index used for matching.

	/** Largest forward jump in numbering still accepted (it is reported as a gap). */
	const MAX_SKIP = 5;

	/**
	 * Convert a fetched body to UTF-8, using the HTTP header or the page's own declaration.
	 *
	 * @return array [ string $utf8, string $charset_label ]
	 */
	public static function to_utf8( $body, $content_type = '' ) {
		$charset = '';
		if ( preg_match( '/charset\s*=\s*["\']?([A-Za-z0-9_:.\-]+)/i', (string) $content_type, $m ) ) {
			$charset = $m[1];
		} elseif ( preg_match( '/<meta[^>]+charset\s*=\s*["\']?([A-Za-z0-9_:.\-]+)/i', substr( $body, 0, 8192 ), $m ) ) {
			$charset = $m[1];
		}
		$charset = strtoupper( $charset );

		if ( '' === $charset ) {
			$charset = mb_check_encoding( $body, 'UTF-8' ) ? 'UTF-8' : 'WINDOWS-1252';
		} elseif ( in_array( $charset, array( 'ISO-8859-1', 'LATIN1', 'US-ASCII' ), true ) ) {
			$charset = 'WINDOWS-1252'; // Browsers treat these as Windows-1252.
		}

		if ( 'UTF-8' === $charset || 'UTF8' === $charset ) {
			return array( $body, 'UTF-8' );
		}

		try {
			$out = mb_convert_encoding( $body, 'UTF-8', $charset );
		} catch ( \Throwable $e ) {
			$charset = 'WINDOWS-1252';
			$out     = mb_convert_encoding( $body, 'UTF-8', $charset );
		}
		return array( $out, $charset );
	}

	/** Collapse whitespace the way a browser's text matching would see it. */
	public static function clean( $s ) {
		$s = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B" ), array( ' ', '' ), $s );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Case- and accent-insensitive form used only for counting matches. */
	public static function fold( $s ) {
		$s = preg_replace( '/\s+/u', ' ', $s );
		if ( class_exists( 'Normalizer' ) ) {
			$d = Normalizer::normalize( $s, Normalizer::FORM_D );
			if ( false !== $d && null !== $d ) {
				$s = preg_replace( '/\p{Mn}+/u', '', $d );
			}
		}
		return mb_strtolower( $s, 'UTF-8' );
	}

	/** A short line with no sentence-ending punctuation, e.g. "D) Norms for adapting the Liturgy to the culture of peoples". */
	private static function looks_like_heading( $text ) {
		if ( preg_match( '/^\(?(?:[a-z]|\d{1,2}|[ivx]+)[).]\s/u', $text ) ) {
			return false; // A list item such as "b) ..." or "(2) ...".
		}
		return str_word_count( $text ) <= 15
			&& ! preg_match( '/[.;:,?!)\]"\x{201D}\x{2019}]\s*$/u', $text );
	}

	/**
	 * @param string $html UTF-8 HTML.
	 * @param int    $max_locator If set, numbers above this are never taken as paragraphs (some pages print footnotes as numbered paragraphs that continue the sequence).
	 * @return array {
	 *   paragraphs: locator => [ n, lead, first, full, inferred ],
	 *   page:       folded text of every block, one per line (for uniqueness checks),
	 *   stats:      count, min, max, gaps[], rejected[], inferred[], blocks
	 * }
	 */
	public static function parse( $html, $max_locator = 0 ) {
		// The text is already UTF-8. A leftover <meta charset=...> (e.g. windows-1252) would make
		// libxml decode it a second time and garble or truncate the page, so remove such tags.
		$html = preg_replace( '/<meta\b[^>]*\bcharset\s*=[^>]*>/i', '', $html );

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xp = new DOMXPath( $dom );
		foreach ( iterator_to_array( $xp->query( '//script|//style|//noscript' ) ) as $n ) {
			$n->parentNode->removeChild( $n );
		}

		// Collect text blocks in document order.
		$blocks = array();
		foreach ( $xp->query( '//p|//h1|//h2|//h3|//h4|//h5|//h6|//li|//td|//th|//dd|//dt' ) as $node ) {
			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, array( 'li', 'td', 'th', 'dd', 'dt' ), true )
				&& $xp->query( './/p|.//li|.//table', $node )->length > 0 ) {
				continue; // A container of other blocks; its children are counted instead.
			}
			$text = self::clean( $node->textContent );
			if ( '' === $text ) {
				continue;
			}
			// Footnote bodies carry an anchor named _ftn<N>; in-text references use _ftnref<N>.
			$is_note  = $xp->query( './/a[starts-with(@name,"_ftn") and not(starts-with(@name,"_ftnref"))]', $node )->length > 0;
			$blocks[] = array(
				'tag'  => $tag,
				'text' => $text,
				'note' => $is_note,
			);
		}

		// Walk the blocks, picking out paragraphs that start with the next number.
		// Each paragraph keeps the indexes of its blocks; text is assembled afterwards.
		$paras      = array();
		$rej_blocks = array();
		$last       = 0;
		$cur        = null;
		foreach ( $blocks as $i => $b ) {
			if ( preg_match( '/^h[1-6]$/', $b['tag'] ) || $b['note'] ) {
				$cur = null; // Headings and footnotes end the current paragraph.
				continue;
			}
			if ( 'p' === $b['tag'] && preg_match( '/^(\d{1,4})\.(?:\s+|(?=[^\d\s]))/u', $b['text'], $m ) ) {
				$n = (int) $m[1];
				if ( $n > $last && $n <= $last + self::MAX_SKIP && ( ! $max_locator || $n <= $max_locator ) ) {
					// Some pages mark section headings as plain paragraphs. Short lines with no closing
					// punctuation that sit directly before the next numbered paragraph belong to it, not the last one.
					if ( null !== $cur ) {
						while ( count( $paras[ $cur ]['blocks'] ) > 1 && self::looks_like_heading( $blocks[ end( $paras[ $cur ]['blocks'] ) ]['text'] ) ) {
							array_pop( $paras[ $cur ]['blocks'] );
						}
					}
					$key           = (string) $n;
					$paras[ $key ] = array(
						'n'        => $n,
						'lead'     => $m[0],
						'blocks'   => array( $i ),
						'inferred' => '',
					);
					$last = $n;
					$cur  = $key;
					continue;
				}
				// Looks like a number but is out of sequence: treat as ordinary text, but remember it.
				$rej_blocks[] = array( 'idx' => $i, 'n' => $n, 'lead' => $m[0], 'used' => false );
			}
			if ( null !== $cur && 'p' === $b['tag'] ) {
				$paras[ $cur ]['blocks'][] = $i;
			}
		}

		// Repair single-number gaps. If exactly one out-of-sequence numbered block sits between
		// paragraph g-1 and g+1, the source most likely mis-numbered it (e.g. "81." for 87).
		$inferred = array();
		for ( $g = 2; $g < $last; $g++ ) {
			$before = isset( $paras[ (string) ( $g - 1 ) ] ) ? $paras[ (string) ( $g - 1 ) ] : null;
			$after  = isset( $paras[ (string) ( $g + 1 ) ] ) ? $paras[ (string) ( $g + 1 ) ] : null;
			if ( isset( $paras[ (string) $g ] ) || ! $before || ! $after ) {
				continue;
			}
			$lo    = $before['blocks'][0];
			$hi    = $after['blocks'][0];
			$cands = array();
			foreach ( $rej_blocks as $ri => $r ) {
				if ( $r['idx'] > $lo && $r['idx'] < $hi ) {
					$cands[] = $ri;
				}
			}
			if ( 1 !== count( $cands ) ) {
				continue; // None, or ambiguous: leave the gap visible rather than guess.
			}
			$r    = $rej_blocks[ $cands[0] ];
			$move = array();
			$keep = array();
			foreach ( $before['blocks'] as $bi ) {
				if ( $bi >= $r['idx'] ) {
					$move[] = $bi;
				} else {
					$keep[] = $bi;
				}
			}
			if ( ! $move || $move[0] !== $r['idx'] ) {
				continue;
			}
			$paras[ (string) ( $g - 1 ) ]['blocks'] = $keep;
			$paras[ (string) $g ]                    = array(
				'n'        => $g,
				'lead'     => $r['lead'],
				'blocks'   => $move,
				'inferred' => 'source numbered it ' . $r['n'],
			);
			$rej_blocks[ $cands[0] ]['used'] = true;
			$inferred[ $g ]                  = $paras[ (string) $g ]['inferred'];
		}
		ksort( $paras, SORT_NUMERIC );

		// Assemble the text of each paragraph from its blocks.
		foreach ( $paras as $k => $p ) {
			$first_text = $blocks[ $p['blocks'][0] ]['text'];
			$rest       = ltrim( substr( $first_text, strlen( $p['lead'] ) ) );
			$first      = $rest;
			$full       = $rest;
			foreach ( array_slice( $p['blocks'], 1 ) as $bi ) {
				if ( '' === $first ) {
					$first = $blocks[ $bi ]['text']; // The number stood alone in its block.
				}
				$full .= ' ' . $blocks[ $bi ]['text'];
			}
			$paras[ $k ] = array(
				'n'        => $p['n'],
				'lead'     => $p['lead'],
				'first'    => $first,
				'full'     => trim( $full ),
				'inferred' => $p['inferred'],
			);
		}

		// Gaps in the sequence 1..max.
		$gaps = array();
		$prev = 0;
		foreach ( $paras as $p ) {
			for ( $i = $prev + 1; $i < $p['n']; $i++ ) {
				$gaps[] = $i;
			}
			$prev = $p['n'];
		}

		$rejected = array();
		foreach ( $rej_blocks as $r ) {
			if ( ! $r['used'] ) {
				$rejected[] = $r['n'];
			}
		}

		$page = array();
		foreach ( $blocks as $b ) {
			$page[] = self::fold( $b['text'] );
		}

		return array(
			'paragraphs' => $paras,
			'page'       => implode( "\n", $page ),
			'stats'      => array(
				'count'    => count( $paras ),
				'min'      => $paras ? reset( $paras )['n'] : 0,
				'max'      => $paras ? end( $paras )['n'] : 0,
				'gaps'     => $gaps,
				'rejected' => $rejected,
				'inferred' => $inferred,
				'blocks'   => count( $blocks ),
			),
		);
	}
}
