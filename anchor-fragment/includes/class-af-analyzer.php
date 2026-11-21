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
				'max'     => isset( $d['expected_count'] ) ? (int) $d['expected_count'] : 999,
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

		// Names that mean "a text we do not index". A document that is in the registry but has no index
		// yet (for example a multi-page one not fetched) counts too, and one that has an index does not.
		$oos_names = array();
		foreach ( $this->out_of_scope as $name ) {
			$oos_names[ $name ] = true;
		}
		foreach ( $this->docs as $id => $d ) {
			$names = array_merge( $d['aliases'], '' !== $d['short'] ? array( strtolower( $d['short'] ) ) : array() );
			foreach ( $names as $name ) {
				if ( isset( $indexes[ $id ] ) ) {
					unset( $oos_names[ $name ] );
				} else {
					$oos_names[ $name ] = true;
				}
			}
		}
		$oos_names = array_keys( $oos_names );

		$results = array();
		$carry   = null; // The document most recently cited by number, for bare "(74)" style cites.
		$near    = null; // The one document named around the previous quotation, for "#892" style cites.
		$n       = 0;
		foreach ( $this->extract( $html ) as $q ) {
			$n++;

			// A cite at the end of the quotation's own paragraph, as in "... the weakest" (DT 16).", belongs to the quotation and
			// is not part of its words. When there is one, it is the author's citation of this quotation: a cite in the text before
			// or after, which is usually about the neighbouring quotation, is not read for it.
			$qparas  = $q['paras'];
			$has_own = false;
			if ( $qparas ) {
				$k  = count( $qparas ) - 1;
				$sp = $this->split_trailing_cite( $qparas[ $k ] );
				if ( '' !== $sp[1] ) {
					$qparas[ $k ] = $sp[0];
					$q['cite']    = trim( $q['cite'] . ' ' . $sp[1] );
					$has_own      = true;
				}
			}

			// The same when the cite follows the closing quotation mark: "...evolutionary one” (GS 5). The Catechism says ...".
			if ( ! $has_own && preg_match( '/^[\s\x{201D}\x{2019}"\']*[(\[]((?:[^()\[\]]|\([^()]*\)){1,160})[)\]]/us', $q['after'], $am ) && ( $this->cites( $am[1] ) || $this->marked_cites( $am[1] ) || $this->bare_cites( '(' . $am[1] . ')' ) ) ) {
				$q['cite'] = trim( $q['cite'] . ' (' . $am[1] . ')' );
				$has_own   = true;
			}

			// A blockquote's cite (its footer, or a bracket at the end that was already split off) is the author's cite of it too.
			if ( ! $has_own && '' !== $q['cite'] && ( $this->cites( $q['cite'] ) || $this->marked_cites( $q['cite'] ) || $this->bare_cites( $q['cite'] ) ) ) {
				$has_own = true;
			}
			// The one document named just before the quotation ("In Amoris Laetitia, Pope Francis states:") is where a cite
			// with only a number belongs, ahead of the document cited last.
			$lead_named = $this->docs_in( mb_substr( $q['lead'], -90 ) );
			$lead_doc   = 1 === count( $lead_named ) ? $lead_named[0] : null;

			// Words of the quotation, with pasted paragraph numbers removed from line starts.
			$segments = array();
			$joined   = array();
			foreach ( $qparas as $p ) {
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
			foreach ( $oos_names as $name ) {
				if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\b/u', $lite_ctx ) ) {
					$oos[] = $name;
				}
			}
			$oos = array_merge( $oos, $foreign );

			// Citations the author typed.
			$typed = $this->cites( $has_own ? $q['cite'] : $ctx );
			// A quotation inserted by the plugin says which paragraph it is meant to be.
			if ( ! empty( $q['declared'] ) && isset( $this->docs[ $q['declared']['doc'] ] ) ) {
				$typed[] = array( 'doc' => $q['declared']['doc'], 'loc' => $q['declared']['loc'] );
			}
			foreach ( $this->bare_cites( $has_own ? $q['cite'] : $q['cite'] . ' ' . $q['after'] ) as $loc ) {
				$doc = null !== $lead_doc ? $lead_doc : $carry;
				if ( null === $doc && 1 === count( $hints ) ) {
					$doc = $hints[0];
				}
				$typed[] = array( 'doc' => $doc, 'loc' => $loc, 'soft' => true );
			}
			// "#892" or "no. 892" beside the quotation, with no document named there: the document comes from an
			// earlier cite, or the one named here, or the one named around the previous quotation. "article 892"
			// and "para. 892" count only with a document named in the same sentence. The number still has to
			// agree with the text before it counts.
			$tail = mb_substr( $q['lead'], -100 );
			$head = mb_substr( $q['after'], 0, 100 );
			// Each stretch of text is read on its own, so that a sentence cannot run from the text before the
			// quotation into the text after it.
			$marked = $has_own ? $this->marked_cites( $q['cite'] ) : array_merge( $this->marked_cites( $q['cite'] ), $this->marked_cites( $tail ), $this->marked_cites( $head ) );
			foreach ( $marked as $mc ) {
				$doc = $mc['doc']; // Set when the cite names its document in the same sentence.
				if ( null === $doc ) {
					// "Familiaris Consortio, no. 84": a title and a comma just before the number means it belongs to that
					// work, which is not in the registry, so it is not a number of the document carried on from earlier.
					if ( preg_match( '/\p{Lu}[\p{L}\'\x{2019}-]+(?:\s+(?:\p{Lu}[\p{L}\'\x{2019}-]+|of|et|the|de|in|on|and))*\s+\p{Lu}[\p{L}\'\x{2019}-]+,\s*$/u', $mc['pre'] ) || preg_match( '/\bvol(?:ume)?\.?\s+[\p{L}\d]+,\s*$/iu', $mc['pre'] ) ) {
						continue;
					}
					// The same number already read as a named cite ("Amoris Laetitia, no. 301").
					$dup = false;
					foreach ( $typed as $t ) {
						if ( $t['loc'] === $mc['loc'] ) {
							$dup = true;
						}
					}
					if ( $dup ) {
						continue;
					}
				}
				if ( null === $doc ) {
					$doc = null !== $lead_doc ? $lead_doc : $carry;
				}
				if ( null === $doc && 1 === count( $hints ) ) {
					$doc = $hints[0];
				}
				if ( null === $doc ) {
					$doc = $near;
				}
				if ( null !== $doc && $mc['loc'][0] <= $this->docs[ $doc ]['max'] ) {
					$typed[] = array( 'doc' => $doc, 'loc' => $mc['loc'] );
				}
			}
			foreach ( $typed as $t ) {
				if ( null !== $t['doc'] && empty( $t['soft'] ) ) {
					$carry = $t['doc'];
				}
			}
			$near = 1 === count( $hints ) ? $hints[0] : ( 1 === count( $hint_text ) ? $hint_text[0] : null );

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
			$resolved  = '';
			$alts      = array();
			if ( $scores ) {
				// Among documents scoring within TIE of the best, prefer one that is named.
				$cands = array();
				foreach ( $scores as $s ) {
					if ( $s['coverage'] >= $scores[0]['coverage'] - self::TIE ) {
						$cands[] = $s;
					}
				}
				// A document named just before the quotation ("again Francis, from Evangelii Gaudium:") or in its own
				// footer is the one the author means. A name later in the text, as in "Dilexi te confirms this,
				// adding that ...", introduces the next sentence and is only a general hint.
				$named_before = array_values( array_unique( array_merge( $this->docs_in( mb_substr( $q['lead'], -90 ) ), $this->docs_in( $q['cite'] ) ) ) );
				$rank = function ( $doc ) use ( $hints, $tag_docs, $named_before ) {
					if ( in_array( $doc, $named_before, true ) ) {
						return 3;
					}
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
				if ( ! $ambiguous && 3 === $top_rank && count( $cands ) > 1 ) {
					$resolved = 'several documents contain these words; the one named just before the quotation was chosen';
				}
				foreach ( $scores as $s ) {
					if ( $s['doc'] !== $best['doc'] && $s['coverage'] >= self::ALT ) {
						$alts[] = array( 'doc' => $s['doc'], 'locators' => $s['locators'], 'coverage' => $s['coverage'] );
					}
				}
			}

			// A bare number in brackets, "(84)", takes its document from an earlier cite, which is only a guess. It counts when
			// the text agrees with it. When the text points elsewhere it is dropped, not reported as a conflict.
			$typed = array_values(
				array_filter(
					$typed,
					function ( $t ) use ( $best ) {
						if ( empty( $t['soft'] ) ) {
							return true;
						}
						return $best && $best['coverage'] >= self::ACCEPT && ( null === $t['doc'] || $t['doc'] === $best['doc'] ) && array_intersect( $t['loc'], $best['locators'] );
					}
				)
			);

			// Decide.
			$note = $resolved;
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
					if ( $agree ) {
						// "(EG, 30; 33)": one number agrees with the words, another does not.
						$extra = array();
						foreach ( $typed_here as $t ) {
							if ( ! ( ( null === $t['doc'] || $t['doc'] === $best['doc'] ) && array_intersect( $t['loc'], $best['locators'] ) ) ) {
								$extra[] = ( $t['doc'] ? $t['doc'] : '?' ) . ' ' . implode( ',', $t['loc'] );
							}
						}
						if ( $extra ) {
							$note = 'cited ' . implode( '; ', $extra ) . ' too, which the words do not match; the words match ' . $best['doc'] . ' ' . implode( ',', $best['locators'] );
						}
					} else {
						$bits = array();
						foreach ( $typed_here as $t ) {
							$bits[] = ( $t['doc'] ? $t['doc'] : '?' ) . ' ' . implode( ',', $t['loc'] );
						}
						$note = 'typed ' . implode( '; ', $bits ) . ', text matches ' . $best['doc'] . ' ' . implode( ',', $best['locators'] );
						foreach ( $typed_here as $t ) {
							if ( $t['doc'] === $best['doc'] && 1 === count( $t['loc'] ) && 1 === count( $best['locators'] ) && 1 === levenshtein( (string) $t['loc'][0], (string) $best['locators'][0] ) ) {
								$note .= ' (a likely typing slip)';
								break;
							}
						}
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
				'hash'      => substr( sha1( AF_Parser::fold( $text ) ), 0, 16 ),
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

	/**
	 * Remove dates, so that "Evangelii Gaudium (24 November 2013), 27" is read as paragraph 27 and not 24. Handles
	 * "24 November 2013" and "November 24, 2013", with the brackets and comma that usually go with them.
	 */
	private static function strip_dates( $text ) {
		$mo = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sept?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
		$text = preg_replace( '/\(?\s*\b\d{1,2}(?:st|nd|rd|th)?\s+' . $mo . '\.?,?\s+\d{4}\b\s*\)?\s*,?/iu', ' ', $text );
		$text = preg_replace( '/\(?\s*\b' . $mo . '\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}\b\s*\)?\s*,?/iu', ' ', $text );
		return $text;
	}

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
		$text  = self::strip_dates( $text );
		$lite  = self::lite( $text );
		$plain = self::straight( $text );
		$num   = '(?:\s*[,.:]?\s*(?:nn?\.|nos?\.|no|n|\x{00A7}+|#|paras?\.?|paragraphs?)?\s*)(\d{1,4})(?!\d)(?:\s*[-\x{2013}\x{2014}]\s*(\d{1,4})(?!\d))?((?:\s*[;,]\s*(?:nn?\.|nos?\.|\x{00A7}+)?\s*\d{1,4}(?!\d)(?:\s*[-\x{2013}\x{2014}]\s*\d{1,4}(?!\d))?)*)';
		$out   = array();
		$docs  = $this->docs;
		$add1  = function ( $doc, $a, $b ) use ( &$out, $docs ) {
			$a = (int) $a;
			if ( $a < 1 || $a > $docs[ $doc ]['max'] ) {
				return; // Not a paragraph number of this document, e.g. a year.
			}
			$b   = (int) $b;
			$loc = ( $b > $a && $b - $a <= 12 ) ? range( $a, $b ) : array( $a );
			$out[ $doc . ':' . implode( ',', $loc ) ] = array( 'doc' => $doc, 'loc' => $loc );
		};
		$add   = function ( $doc, $m ) use ( $add1 ) {
			$add1( $doc, $m[1], isset( $m[2] ) ? $m[2] : 0 );
			// "(EG, 30; 33)": the numbers after the first belong to the same document.
			if ( ! empty( $m[3] ) && preg_match_all( '/(\d{1,4})(?!\d)(?:\s*[-\x{2013}\x{2014}]\s*(\d{1,4})(?!\d))?/u', $m[3], $more, PREG_SET_ORDER ) ) {
				foreach ( $more as $x ) {
					$add1( $doc, $x[1], isset( $x[2] ) ? $x[2] : 0 );
				}
			}
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
	 * Split a cite off the end of a quotation's paragraph: "...in favor of the weakest" (DT 16)." gives the words and
	 * "(DT 16)". Nothing is split unless the bracketed text reads as a citation.
	 *
	 * @return array [ words, cite ]
	 */
	private function split_trailing_cite( $p ) {
		if ( ! preg_match( '/^(.*?)[\s\x{201D}\x{2019}"\']*[(\[]((?:[^()\[\]]|\([^()]*\)){1,160})[)\]]\s*[.,;:]?\s*$/us', $p, $m ) || '' === trim( $m[1] ) ) {
			return array( $p, '' );
		}
		$cap = $m[2];
		if ( ! $this->cites( $cap ) && ! $this->marked_cites( $cap ) && ! $this->bare_cites( '(' . $cap . ')' ) ) {
			return array( $p, '' );
		}
		return array( rtrim( $m[1] ), '(' . $cap . ')' );
	}

	/**
	 * Numbers marked with "#" or "no." ("#892", "no. 892"), which take their document from nearby text, and
	 * with "article" or "para." ("article 892 of the Catechism"), which count only when exactly one document
	 * is named in the same sentence.
	 *
	 * @return array[] each { loc[], doc|null }
	 */
	private function marked_cites( $text ) {
		$out = array();
		$re  = '/(?<![\w&])(?:(#|nn?\.?|nos?\.?)|(paras?\.?|paragraphs?|articles?))\s*(\d{1,4})(?!\d)(?:\s*[-\x{2013}\x{2014}]\s*(\d{1,4})(?!\d))?/ui';
		if ( ! preg_match_all( $re, $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		$sentences = preg_split( '/(?<=[.!?])\s+(?=[A-Z"\x{201C}])/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE );
		foreach ( $ms as $m ) {
			$marker = $m[1][0];
			$word   = $m[2][0];
			// "n" and "no" need their full stop or a "#": a bare "no 5" is ordinary text.
			if ( '' !== $marker && '#' !== $marker && false === strpos( $marker, '.' ) && ! preg_match( '/^nos?$/i', $marker ) ) {
				continue;
			}
			$a   = (int) $m[3][0];
			$b   = isset( $m[4] ) && '' !== $m[4][0] ? (int) $m[4][0] : 0;
			$loc = ( $b > $a && $b - $a <= 12 ) ? range( $a, $b ) : array( $a );
			$doc = null;
			if ( '' !== $word ) {
				$off  = $m[0][1];
				$sent = $text;
				foreach ( $sentences as $s ) {
					if ( $off >= $s[1] && $off < $s[1] + strlen( $s[0] ) + 1 ) {
						$sent = $s[0];
					}
				}
				$named = $this->docs_in( $sent );
				if ( 1 !== count( $named ) ) {
					continue; // No document in the sentence: "Article 11 states" may be about anything.
				}
				$doc = $named[0];
			}
			$out[] = array( 'loc' => $loc, 'doc' => $doc, 'pre' => substr( $text, max( 0, $m[0][1] - 60 ), min( 60, $m[0][1] ) ) );
		}
		return $out;
	}

	/**
	 * Bare numbers in parentheses, "(74)", "(nn. 12-14)" or "(ibid. 116)", which carry on from an earlier cite.
	 *
	 * @return array[] each a list of numbers
	 */
	private function bare_cites( $text ) {
		$out = array();
		if ( preg_match_all( '/\(\s*(?:(?i:ibid|idem|id)\.?\s*,?\s*)?(?:nn?\.|nos?\.|\x{00A7})?\s*(\d{1,3})(?!\d)(?:\s*[-\x{2013}\x{2014}]\s*(\d{1,3})(?!\d))?\s*\)/u', $text, $ms, PREG_SET_ORDER ) ) {
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

			$declared = null;
			if ( preg_match( '/\baf-quote\b/', $bq->getAttribute( 'class' ) ) && $bq->hasAttribute( 'data-af-doc' ) && preg_match( '/^\d{1,4}$/', $bq->getAttribute( 'data-af-locator' ) ) ) {
				$declared = array( 'doc' => $bq->getAttribute( 'data-af-doc' ), 'loc' => array( (int) $bq->getAttribute( 'data-af-locator' ) ) );
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
				$out[] = array( 'kind' => 'blockquote', 'paras' => $paras, 'cite' => trim( $cite ), 'lead' => $lead, 'after' => $after, 'hrefs' => $links, 'declared' => $declared );
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
