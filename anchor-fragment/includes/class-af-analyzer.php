<?php
/**
 * Reads a post, picks out quotations, gathers hints about their source, matches them
 * against the document indexes and labels each result. Pure PHP, no WordPress functions.
 *
 * Statuses
 *   high         strong text match that agrees with a citation the author typed
 *   medium       strong text match in a document the surrounding text or a link names
 *   low          strong text match, nothing else points at that document
 *   ambiguous    strong match in more than one document and nothing breaks the tie
 *   conflict     the author typed one paragraph but the text matches another
 *   partial      some of the quotation matches (edited, or only part is from the document)
 *   out_of_scope no match, and the context points at a document we do not have
 *   no_match     nothing found
 *   too_short    too few words to place reliably
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'AF_TEST' ) ) {
	exit;
}

class AF_Analyzer {

	/** Coverage at or above this counts as a match. */
	const ACCEPT = 0.8;

	/** Coverage at or above this (but below ACCEPT) is reported as partial. */
	const PARTIAL = 0.4;

	/** Other documents sharing at least this much of a quotation are listed as alternatives. */
	const ALT = 0.2;

	/** Coverages this close are treated as a tie between documents. */
	const TIE = 0.05;

	/** Documents we do not index, named in text. */
	private $out_of_scope = array( 'catechism', 'ccc', 'code of canon law', 'canon law', 'cic' );

	/** id => [ title, short, aliases[], slug ] */
	private $docs = array();

	/** @param array $registry id => entry from registry.json */
	public function __construct( array $registry ) {
		foreach ( $registry as $id => $d ) {
			$aliases = array();
			$names   = array_merge( array( $d['title'] ), isset( $d['aliases'] ) ? $d['aliases'] : array() );
			foreach ( $names as $a ) {
				$a = trim( rtrim( self::lite( $a ), "' " ) );
				if ( '' !== $a ) {
					$aliases[] = $a;
				}
			}
			$path          = (string) parse_url( $d['url'], PHP_URL_PATH );
			$this->docs[ $id ] = array(
				'title'   => $d['title'],
				'short'   => isset( $d['short'] ) ? $d['short'] : '',
				'aliases' => array_values( array_unique( $aliases ) ),
				'slug'    => pathinfo( $path, PATHINFO_FILENAME ),
			);
		}
	}

	/**
	 * @param string   $html      Post body as HTML (paragraphs already wrapped in <p>).
	 * @param string[] $tags      Names of the post's tags.
	 * @param callable $get_index Returns the index for a document id, or null.
	 * @return array { quotes[], summary[] }
	 */
	public function analyze( $html, array $tags, $get_index ) {
		$indexes = array();
		foreach ( array_keys( $this->docs ) as $id ) {
			$i = call_user_func( $get_index, $id );
			if ( is_array( $i ) ) {
				$indexes[ $id ] = $i;
			}
		}
		$tag_docs = $this->docs_in( implode( ' ; ', $tags ) );

		$results = array();
		$carry   = null; // The document most recently cited by number, for bare "(74)" style cites.
		$n       = 0;
		foreach ( $this->extract( $html ) as $q ) {
			$n++;

			// Words of the quotation, with pasted paragraph numbers removed from line starts.
			$segments = array();
			$joined   = array();
			foreach ( $q['paras'] as $p ) {
				$p = preg_replace( '/^\s*\d{1,4}\.\s+/u', '', $p );
				if ( '' === trim( $p ) ) {
					continue;
				}
				$joined[] = $p;
				foreach ( AF_Matcher::segments( $p ) as $s ) {
					$segments[] = $s;
				}
			}
			$tokens = 0;
			foreach ( $segments as $s ) {
				$tokens += count( $s );
			}

			// Hints about the source.
			$ctx       = trim( $q['cite'] . ' ' . $q['after'] . ' ' . $q['lead'] );
			$foreign   = array();
			$hint_text = $this->docs_in( $ctx );
			$hint_link = $this->docs_from_links( $q['hrefs'], $foreign );
			$hints     = array_values( array_unique( array_merge( $hint_text, $hint_link ) ) );
			$lite_ctx  = self::lite( $ctx );
			$oos       = array();
			foreach ( $this->out_of_scope as $name ) {
				if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\b/u', $lite_ctx ) ) {
					$oos[] = $name;
				}
			}
			$oos = array_merge( $oos, $foreign );

			// Citations the author typed.
			$typed = $this->cites( $ctx );
			foreach ( $this->bare_cites( $q['cite'] . ' ' . $q['after'] ) as $loc ) {
				$doc = $carry;
				if ( null === $doc && 1 === count( $hints ) ) {
					$doc = $hints[0];
				}
				$typed[] = array( 'doc' => $doc, 'loc' => $loc );
			}
			foreach ( $typed as $t ) {
				if ( null !== $t['doc'] ) {
					$carry = $t['doc'];
				}
			}

			// Score against every document.
			$scores = array();
			foreach ( $indexes as $id => $idx ) {
				$s = AF_Matcher::score( $segments, $idx );
				if ( $s['covered'] > 0 ) {
					$s['doc'] = $id;
					$scores[] = $s;
				}
			}
			usort(
				$scores,
				function ( $a, $b ) {
					return $b['coverage'] <=> $a['coverage'];
				}
			);

			$best      = null;
			$ambiguous = false;
			$alts      = array();
			if ( $scores ) {
				// Among documents scoring within TIE of the best, prefer one that is named.
				$cands = array();
				foreach ( $scores as $s ) {
					if ( $s['coverage'] >= $scores[0]['coverage'] - self::TIE ) {
						$cands[] = $s;
					}
				}
				$rank = function ( $doc ) use ( $hints, $tag_docs ) {
					return in_array( $doc, $hints, true ) ? 2 : ( in_array( $doc, $tag_docs, true ) ? 1 : 0 );
				};
				$top_rank = -1;
				foreach ( $cands as $c ) {
					if ( $rank( $c['doc'] ) > $top_rank ) {
						$top_rank = $rank( $c['doc'] );
						$best     = $c;
					}
				}
				$tied      = 0;
				foreach ( $cands as $c ) {
					if ( $rank( $c['doc'] ) === $top_rank ) {
						$tied++;
					}
				}
				$ambiguous = $tied > 1 && $best['coverage'] >= self::ACCEPT;
				foreach ( $scores as $s ) {
					if ( $s['doc'] !== $best['doc'] && $s['coverage'] >= self::ALT ) {
						$alts[] = array( 'doc' => $s['doc'], 'locators' => $s['locators'], 'coverage' => $s['coverage'] );
					}
				}
			}

			// Decide.
			$note = '';
			if ( $tokens < AF_Matcher::MIN_TOKENS ) {
				$status = 'too_short';
				$note   = $tokens . ' words';
			} elseif ( $best && $best['coverage'] >= self::ACCEPT ) {
				$typed_here = array_filter( $typed, function ( $t ) {
					return ! empty( $t['loc'] );
				} );
				$agree = false;
				foreach ( $typed_here as $t ) {
					if ( ( null === $t['doc'] || $t['doc'] === $best['doc'] ) && array_intersect( $t['loc'], $best['locators'] ) ) {
						$agree = true;
					}
				}
				if ( $typed_here ) {
					$status = $agree ? 'high' : 'conflict';
					if ( ! $agree ) {
						$bits = array();
						foreach ( $typed_here as $t ) {
							$bits[] = ( $t['doc'] ? $t['doc'] : '?' ) . ' ' . implode( ',', $t['loc'] );
						}
						$note = 'typed ' . implode( '; ', $bits ) . ', text matches ' . $best['doc'] . ' ' . implode( ',', $best['locators'] );
					}
				} elseif ( $ambiguous ) {
					$status = 'ambiguous';
				} elseif ( in_array( $best['doc'], $hints, true ) || in_array( $best['doc'], $tag_docs, true ) ) {
					$status = 'medium';
				} else {
					$status = 'low';
				}
			} elseif ( $best && $best['coverage'] >= self::PARTIAL ) {
				$status = 'partial';
			} elseif ( $oos ) {
				$status = 'out_of_scope';
				$note   = implode( ', ', array_unique( $oos ) );
			} else {
				$status = 'no_match';
			}

			$text = implode( ' ', $joined );
			$results[] = array(
				'i'         => $n,
				'kind'      => $q['kind'],
				'excerpt'   => mb_substr( $text, 0, 110 ),
				'words'     => $tokens,
				'status'    => $status,
				'best'      => $best ? array(
					'doc'      => $best['doc'],
					'locators' => $best['locators'],
					'coverage' => $best['coverage'],
				) : null,
				'alt'       => $alts,
				'typed'     => array_values( $typed ),
				'hints'     => $hints,
				'tag_hints' => $tag_docs,
				'note'      => $note,
			);
		}

		$summary = array();
		foreach ( $results as $r ) {
			$summary[ $r['status'] ] = ( isset( $summary[ $r['status'] ] ) ? $summary[ $r['status'] ] : 0 ) + 1;
		}
		return array( 'quotes' => $results, 'summary' => $summary );
	}

	/* ---------------------------------------------------------------- text helpers */

	private static function straight( $s ) {
		return str_replace(
			array( "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xC2\xA0" ),
			array( "'", "'", '"', '"', ' ' ),
			$s
		);
	}

	/** Lower case, accents removed, apostrophes and quotes straightened. */
	private static function lite( $s ) {
		return AF_Parser::fold( self::straight( $s ) );
	}

	private static function clean( $s ) {
		return AF_Parser::clean( $s );
	}

	/** Document ids whose title, alias or short code appears in the text. */
	private function docs_in( $text ) {
		$lite  = self::lite( $text );
		$plain = self::straight( $text );
		$found = array();
		foreach ( $this->docs as $id => $d ) {
			foreach ( $d['aliases'] as $a ) {
				if ( preg_match( '/\b' . preg_quote( $a, '/' ) . '\b/u', $lite ) ) {
					$found[ $id ] = true;
					break;
				}
			}
			// Short codes such as LS are matched only in capitals.
			if ( ! isset( $found[ $id ] ) && '' !== $d['short'] && preg_match( '/\b' . preg_quote( $d['short'], '/' ) . '\b/u', $plain ) ) {
				$found[ $id ] = true;
			}
		}
		return array_keys( $found );
	}

	/** Document ids whose address appears in a link; other vatican.va links are returned in $foreign. */
	private function docs_from_links( array $hrefs, array &$foreign ) {
		$found = array();
		foreach ( $hrefs as $h ) {
			$hit = false;
			foreach ( $this->docs as $id => $d ) {
				if ( '' !== $d['slug'] && false !== stripos( $h, $d['slug'] ) ) {
					$found[ $id ] = true;
					$hit          = true;
				}
			}
			if ( ! $hit && false !== stripos( $h, 'vatican.va' ) ) {
				$foreign[] = $h;
			}
		}
		return array_keys( $found );
	}

	/**
	 * Citations naming a document and a number: "LS 12", "Laudato si', no. 12", "FT 74-76".
	 *
	 * @return array[] each { doc, loc[] }
	 */
	private function cites( $text ) {
		$lite  = self::lite( $text );
		$plain = self::straight( $text );
		$num   = '(?:\s*[,.:]?\s*(?:nn?\.|nos?\.|no|n|\x{00A7}+|paras?\.?|paragraphs?)?\s*)(\d{1,3})(?!\d)(?:\s*[-\x{2013}\x{2014}]\s*(\d{1,3})(?!\d))?';
		$out   = array();
		$add   = function ( $doc, $m ) use ( &$out ) {
			$a   = (int) $m[1];
			$b   = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
			$loc = ( $b > $a && $b - $a <= 12 ) ? range( $a, $b ) : array( $a );
			$out[ $doc . ':' . implode( ',', $loc ) ] = array( 'doc' => $doc, 'loc' => $loc );
		};
		foreach ( $this->docs as $id => $d ) {
			if ( '' !== $d['short'] && preg_match_all( '/\b' . preg_quote( $d['short'], '/' ) . '\b' . $num . '/u', $plain, $ms, PREG_SET_ORDER ) ) {
				foreach ( $ms as $m ) {
					$add( $id, $m );
				}
			}
			foreach ( $d['aliases'] as $a ) {
				if ( preg_match_all( '/\b' . preg_quote( $a, '/' ) . '\b[\'"]?' . $num . '/u', $lite, $ms, PREG_SET_ORDER ) ) {
					foreach ( $ms as $m ) {
						$add( $id, $m );
					}
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * Bare numbers in parentheses, "(74)" or "(nn. 12-14)", which carry on from an earlier cite.
	 *
	 * @return array[] each a list of numbers
	 */
	private function bare_cites( $text ) {
		$out = array();
		if ( preg_match_all( '/\(\s*(?:nn?\.|nos?\.|\x{00A7})?\s*(\d{1,3})(?!\d)(?:\s*[-\x{2013}\x{2014}]\s*(\d{1,3})(?!\d))?\s*\)/u', $text, $ms, PREG_SET_ORDER ) ) {
			foreach ( $ms as $m ) {
				$a     = (int) $m[1];
				$b     = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
				$out[] = ( $b > $a && $b - $a <= 12 ) ? range( $a, $b ) : array( $a );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- extraction */

	/**
	 * Quotations in the post: block quotes, and quoted passages of about eight words or more.
	 *
	 * @return array[] each { kind, paras[], cite, lead, after, hrefs[] }
	 */
	private function extract( $html ) {
		$html = preg_replace( '/<meta\b[^>]*\bcharset\s*=[^>]*>/i', '', $html );
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp = new DOMXPath( $dom );

		$adjacent = function ( $node, $dir ) {
			$x = $node->$dir;
			while ( $x && XML_ELEMENT_NODE !== $x->nodeType ) {
				$x = $x->$dir;
			}
			return $x;
		};
		$hrefs = function ( $node ) use ( $xp ) {
			$out = array();
			if ( $node ) {
				foreach ( $xp->query( './/a[@href]', $node ) as $a ) {
					$out[] = $a->getAttribute( 'href' );
				}
			}
			return $out;
		};

		$out = array();

		foreach ( $xp->query( '//blockquote' ) as $bq ) {
			if ( $xp->query( 'ancestor::blockquote', $bq )->length > 0 ) {
				continue;
			}
			$prev_el = $adjacent( $bq, 'previousSibling' );
			$next_el = $adjacent( $bq, 'nextSibling' );
			$lead    = $prev_el ? mb_substr( self::clean( $prev_el->textContent ), -300 ) : '';
			$after   = $next_el ? mb_substr( self::clean( $next_el->textContent ), 0, 200 ) : '';
			$links   = array_merge( $hrefs( $bq ), $hrefs( $prev_el ), $hrefs( $next_el ) );

			$cite = '';
			foreach ( iterator_to_array( $xp->query( './/cite|.//sup', $bq ) ) as $c ) {
				if ( 'cite' === strtolower( $c->nodeName ) ) {
					$cite .= ' ' . self::clean( $c->textContent );
				}
				$c->parentNode->removeChild( $c );
			}

			$paras = array();
			$ps    = $xp->query( './/p', $bq );
			if ( $ps->length ) {
				foreach ( $ps as $p ) {
					$paras[] = self::clean( $p->textContent );
				}
			} else {
				$paras[] = self::clean( $bq->textContent );
			}
			// A citation in parentheses at the very end belongs to the cite, not the quotation.
			$last = count( $paras ) - 1;
			if ( preg_match( '/\(([^()]{1,100})\)\s*$/u', $paras[ $last ], $m ) && preg_match( '/\d/', $m[1] ) ) {
				$cite            .= ' (' . $m[1] . ')';
				$paras[ $last ]   = trim( substr( $paras[ $last ], 0, -strlen( $m[0] ) ) );
			}
			$paras = array_values( array_filter( $paras, function ( $p ) {
				return '' !== trim( $p );
			} ) );
			if ( $paras ) {
				$out[] = array( 'kind' => 'blockquote', 'paras' => $paras, 'cite' => trim( $cite ), 'lead' => $lead, 'after' => $after, 'hrefs' => $links );
			}
		}

		foreach ( $xp->query( '//p[not(ancestor::blockquote)]' ) as $p ) {
			$text = self::clean( $p->textContent );
			if ( ! preg_match_all( '/[\x{201C}"\x{00AB}]([^\x{201D}"\x{00BB}]{40,700})[\x{201D}"\x{00BB}]/u', $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $ms as $m ) {
				$quote = $m[1][0];
				if ( count( AF_Matcher::tokens( $quote ) ) < AF_Matcher::MIN_TOKENS ) {
					continue;
				}
				$start = $m[0][1];
				$end   = $start + strlen( $m[0][0] );
				$out[] = array(
					'kind'  => 'inline',
					'paras' => array( $quote ),
					'cite'  => '',
					'lead'  => mb_substr( substr( $text, 0, $start ), -300 ),
					'after' => mb_substr( substr( $text, $end ), 0, 200 ),
					'hrefs' => $hrefs( $p ),
				);
			}
		}
		return $out;
	}
}
