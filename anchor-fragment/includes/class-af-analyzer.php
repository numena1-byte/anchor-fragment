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
 *   cross_reference the author typed one paragraph and the text matches another that the Catechism footnotes link
 *                (the Catechism quotes the very source the post cites): counts as correct
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

	/** Catechism paragraph => [ "doc:locator", ... ] its footnotes cite. */
	private $xref = array();

	/** Give the analyzer the Catechism footnote map, so a Catechism paragraph and the source it quotes count as one. */
	public function set_xref( array $map ) {
		$this->xref = $map;
	}

	/** Does the Catechism paragraph's footnotes cite that very paragraph of the document, or the reverse? */
	private function cross_ref( $a_doc, $a_loc, $b_doc, $b_loc ) {
		if ( 'catechism' === $a_doc && 'catechism' !== $b_doc ) {
			return isset( $this->xref[ (int) $a_loc ] ) && in_array( $b_doc . ':' . (int) $b_loc, $this->xref[ (int) $a_loc ], true );
		}
		if ( 'catechism' === $b_doc && 'catechism' !== $a_doc ) {
			return isset( $this->xref[ (int) $b_loc ] ) && in_array( $a_doc . ':' . (int) $a_loc, $this->xref[ (int) $b_loc ], true );
		}
		return false;
	}

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
				// "... (Humanae Vitae, 11) ... (Catechism of the Catholic Church, 2370).”": a quotation that already holds a cite of
				// that kind is quoting its source's own cites, so the last bracket is part of it, not the author's.
				$embedded = '' !== $sp[1] && preg_match( '/[(\[](?:cf\.\s*)?\p{Lu}[\p{L}\s.\'\x{2019}]+,\s*(?:no\.\s*|n\.\s*|#)?\d{1,4}[)\]]/u', $sp[0] );
				if ( '' !== $sp[1] && ! $embedded ) {
					$qparas[ $k ] = $sp[0];
					$q['cite']    = trim( $q['cite'] . ' ' . $sp[1] );
					$has_own      = true;
				}
			}

			// The same when the cite follows the closing quotation mark: "...evolutionary one” (GS 5). The Catechism says ...".
			if ( ! $has_own && preg_match( '/^[\s\x{201D}\x{2019}"\']*[(\[]((?:[^()\[\]]|\([^()]*\)|\[[A-Za-z]{1,8}\]){1,160})[)\]]/us', $q['after'], $am ) && ( $this->cites( $am[1] ) || $this->marked_cites( $am[1] ) || $this->bare_cites( '(' . $am[1] . ')' ) ) ) {
				$q['cite'] = trim( $q['cite'] . ' (' . $am[1] . ')' );
				$has_own   = true;
			}

			// A blockquote's cite (its footer, or a bracket at the end that was already split off) is the author's cite of it too.
			if ( ! $has_own && '' !== $q['cite'] && ( $this->cites( $q['cite'] ) || $this->marked_cites( $q['cite'] ) || $this->bare_cites( $q['cite'] ) ) ) {
				$has_own = true;
			}
			// The one document named just before the quotation ("In Amoris Laetitia, Pope Francis states:") is where a cite
			// with only a number belongs, ahead of the document cited last.
			// A cite right after the closing mark of an earlier long quotation in the text before this one, "...in his depths”
			// (CCC 1776) — that can help us", is that quotation's own, not this one's.
			$q['lead'] = preg_replace_callback(
				'/([\x{201D}"])(\s*[(\[](?:[^()\[\]]|\([^()]*\)|\[[A-Za-z]{1,8}\]){1,160}[)\]])/us',
				function ( $m ) use ( $q ) {
					$pos    = mb_strpos( $q['lead'], $m[0] );
					$before = false === $pos ? '' : mb_substr( $q['lead'], max( 0, $pos - 40 ), min( 40, $pos ) );
					$inner  = trim( $m[2], " \t\n()[]" );
					if ( mb_strlen( $before ) >= 40 && ! preg_match( '/[\x{201C}"]/u', $before ) && ( $this->cites( $inner ) || $this->marked_cites( $inner ) || $this->bare_cites( '(' . $inner . ')' ) ) ) {
						return $m[1];
					}
					return $m[0];
				},
				$q['lead']
			);
			$lead_named = $this->docs_in( mb_substr( $q['lead'], -90 ) );
			if ( 1 !== count( $lead_named ) && ! $lead_named ) {
				$lead_named = $this->docs_in( $q['lead'] ); // Named earlier in the same stretch of text, with none nearer.
			}
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
			// A cite is attached to the quotation when it is its own (a bracket, footer or footnote cite), stands right after
			// it, or ends the sentence that introduces it ("As Francis says in EG 30, “…”"). A document and number found
			// elsewhere in the text near it, as when the author discusses a paragraph and then quotes somebody else, is a
			// mention: the post refers to that paragraph, but not as the source of this quotation.
			$attached = array();
			if ( ! $has_own ) {
				$lead_sent = preg_split( '/(?<=[.!?])\s+(?=[\p{Lu}\x{201C}"(])/u', trim( $q['lead'] ) );
				$nearby    = trim( ( $lead_sent ? end( $lead_sent ) : '' ) . ' | ' . mb_substr( ltrim( $q['after'], " \t\n\x{201D}\x{2019}\"'" ), 0, 60 ) );
				foreach ( $this->cites( $nearby ) as $c ) {
					$attached[ $c['doc'] . '|' . implode( ',', (array) $c['loc'] ) ] = true;
				}
			}
			foreach ( $typed as $k => $t ) {
				$typed[ $k ]['attached'] = $has_own || isset( $attached[ $t['doc'] . '|' . implode( ',', (array) $t['loc'] ) ] );
			}
			// The cite after the next quotation in the same sentence may be meant for this one too: it counts only when the
			// words agree.
			if ( ! $has_own && ! empty( $q['shared'] ) ) {
				foreach ( $this->cites( $q['shared'] ) as $sc ) {
					$sc['soft'] = true;
					$typed[]    = $sc;
				}
			}
			// A quotation inserted by the plugin says which paragraph it is meant to be.
			if ( ! empty( $q['declared'] ) && isset( $this->docs[ $q['declared']['doc'] ] ) ) {
				$typed[] = array( 'doc' => $q['declared']['doc'], 'loc' => $q['declared']['loc'] );
			}
			// A "(cf. CCC 1955)" in the author's own sentence next to the quotation is a side reference, not the source of it.
			if ( ! $has_own ) {
				$plain = preg_replace( '/[(\[]\s*cf\.?\s[^()\[\]]*[)\]]/iu', ' ', $ctx );
				if ( $plain !== $ctx ) {
					$keep = array();
					foreach ( $this->cites( $plain ) as $t ) {
						$keep[ $t['doc'] . '|' . implode( ',', (array) $t['loc'] ) ] = true;
					}
					foreach ( $typed as $k => $t ) {
						if ( ! isset( $keep[ $t['doc'] . '|' . implode( ',', (array) $t['loc'] ) ] ) ) {
							$typed[ $k ]['soft'] = true;
						}
					}
				}
			}
			// "(cf. CCC 1955)" is a cross-reference, usually the source's own: it counts only when the words agree.
			if ( $has_own && preg_match( '/^[\s(\[]*cf\.?\s/iu', $q['cite'] ) ) {
				foreach ( $typed as $k => $t ) {
					$typed[ $k ]['soft'] = true;
				}
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
				$guess = false;
				if ( null === $doc ) {
					// A link beside the quotation that goes to one document says which: "(<a href=...gaudium-et-spes...>Paragraph 69</a>)".
					// It comes before the document named in the lead, which may belong to an earlier quotation.
					$doc = 1 === count( $hint_link ) ? $hint_link[0] : $lead_doc;
					if ( null === $doc && null !== $carry ) {
						$doc   = $carry;
						$guess = true; // Only the document cited last: counts when the words agree.
					}
				}
				if ( null === $doc && 1 === count( $hints ) ) {
					$doc = $hints[0];
				}
				if ( null === $doc ) {
					$doc   = $near;
					$guess = null !== $doc;
				}
				if ( null !== $doc && $mc['loc'][0] <= $this->docs[ $doc ]['max'] ) {
					$typed[] = $guess ? array( 'doc' => $doc, 'loc' => $mc['loc'], 'soft' => true ) : array( 'doc' => $doc, 'loc' => $mc['loc'] );
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
				$rank = function ( $doc, $locs = array() ) use ( $hints, $tag_docs, $named_before, $typed ) {
					foreach ( $typed as $t ) {
						if ( empty( $t['soft'] ) && $t['doc'] === $doc && ! empty( $t['loc'] ) && array_intersect( $t['loc'], $locs ) ) {
							return 4; // The author typed exactly this paragraph, and the words fit it as well as any.
						}
					}
					if ( in_array( $doc, $named_before, true ) ) {
						return 3;
					}
					return in_array( $doc, $hints, true ) ? 2 : ( in_array( $doc, $tag_docs, true ) ? 1 : 0 );
				};
				$top_rank = -1;
				foreach ( $cands as $c ) {
					if ( $rank( $c['doc'], $c['locators'] ) > $top_rank ) {
						$top_rank = $rank( $c['doc'], $c['locators'] );
						$best     = $c;
					}
				}
				$tied      = 0;
				foreach ( $cands as $c ) {
					if ( $rank( $c['doc'], $c['locators'] ) === $top_rank ) {
						$tied++;
					}
				}
				$ambiguous = $tied > 1 && $best['coverage'] >= self::ACCEPT;
				if ( ! $ambiguous && 4 === $top_rank && count( $cands ) > 1 ) {
					$resolved = 'several documents contain these words; the paragraph the author cited was chosen';
				} elseif ( ! $ambiguous && 3 === $top_rank && count( $cands ) > 1 ) {
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
						// The Catechism quotes the very paragraph the post cites (or the reverse): both are right.
						if ( $this->xref ) {
							$all = true;
							foreach ( $typed_here as $t ) {
								$ok = ( null === $t['doc'] || $t['doc'] === $best['doc'] ) && array_intersect( $t['loc'], $best['locators'] );
								if ( ! $ok && null !== $t['doc'] ) {
									foreach ( $t['loc'] as $tl ) {
										foreach ( $best['locators'] as $bl ) {
											if ( $this->cross_ref( $t['doc'], $tl, $best['doc'], $bl ) ) {
												$ok = true;
											}
										}
									}
								}
								$all = $all && $ok;
							}
							if ( $all ) {
								$status = 'cross_reference';
								$note   = 'cross-reference: ' . implode( '; ', $bits ) . ' and ' . $best['doc'] . ' ' . implode( ',', $best['locators'] ) . ' are linked by the Catechism footnotes (the Catechism quotes the same source)';
							}
						}
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
				// The words of a quotation nothing matched, kept on this site only so that the optional second opinion can be asked later.
				'text'      => 'no_match' === $status ? mb_substr( $text, 0, 3000 ) : '',
				'words'     => $tokens,
				'status'    => $status,
				'best'      => $best ? array(
					'doc'      => $best['doc'],
					'locators' => $best['locators'],
					'coverage' => $best['coverage'],
				) : null,
				'alt'       => $alts,
				// Whether the cited document has an index here: without one the cite cannot be checked at all.
				'typed'     => array_values( array_map( function ( $t ) use ( $indexes ) {
					$t['indexed'] = isset( $indexes[ $t['doc'] ] );
					return $t;
				}, $typed ) ),
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
		// An edition year in a bracket, "(CCC, 1997)", is not a paragraph number.
		$text = preg_replace( '/,\s*(?:19[5-9]\d|20[0-3]\d)(?=\s*\))/u', '', $text );
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
	/** Citations in a short text, as { doc, loc[] }, for documents in the registry. */
	public function refs( $text ) {
		return $this->cites( $text );
	}

	private function cites( $text ) {
		$text  = self::strip_dates( $text );
		// "Dei Verbum [DV] 7": a bracketed abbreviation between the name and the number is part of the cite.
		$text  = preg_replace( '/\s*\[([A-Za-z]{1,8})\]\s*(?=\d)/u', ' $1 ', $text );
		$lite  = self::lite( $text );
		$plain = self::straight( $text );
		$num   = '(?:\s*[,.:]?\s*(?:nn?\.|nos?\.|no|n|\x{00A7}+|#|paras?\.?|paragraphs?)?\s*)(\d{1,4})(?!\d)(?!\s*(?:%|(?:times?|percent|per\s?cent|years?|pages?|words?|days?|months?|weeks?|paragraphs?|articles?|footnotes?|references?|occurrences?|instances?|mentions?|uses|people|million|billion|thousand|hundred)\b))(?:\s*[-\x{2013}\x{2014}]\s*(\d{1,4})(?!\d))?((?:\s*[;,]\s*(?:nn?\.|nos?\.|\x{00A7}+)?\s*\d{1,4}(?!\d)(?:\s*[-\x{2013}\x{2014}]\s*\d{1,4}(?!\d))?)*)';
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
		if ( ! preg_match( '/^(.*?)[\s\x{201D}\x{2019}"\']*[(\[]((?:[^()\[\]]|\([^()]*\)|\[[A-Za-z]{1,8}\]){1,160})[)\]]\s*[.,;:]?\s*$/us', $p, $m ) || '' === trim( $m[1] ) ) {
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
				if ( 1 === count( $named ) ) {
					$doc = $named[0];
				} elseif ( ! preg_match( '/^[\s(\[]*$/', substr( $text, 0, $m[0][1] ) ) ) {
					continue; // No document in the sentence: "Article 11 states" may be about anything.
				}
				// else the whole cite is "(para. 1776)": a bracket that is only a marked number is the author's cite of the quotation, and its document comes from the text around it.
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

		// The links of a paragraph that stand after a quotation and before the next one belong to that quotation; when there
		// are none, the paragraph's links all count.
		// Where the cite that follows a quotation ends: "(MH 192)" right after the closing mark belongs to that quotation.
		$cite_end = function ( $text, $pos ) {
			if ( preg_match( '/\G[\s\x{201D}\x{2019}"\']*[(\[]((?:[^()\[\]]|\([^()]*\)|\[[A-Za-z]{1,8}\]){1,160})[)\]]/u', $text, $m, 0, $pos ) && preg_match( '/\d/', $m[1] ) ) {
				return $pos + strlen( $m[0] );
			}
			return $pos;
		};
		// The links of a paragraph, each with where it stands in the text, so a quotation gets the links between the
		// previous quotation's cite and the next quotation, not those of its neighbours.
		$links_at = function ( $p, $text ) use ( $xp ) {
			$out    = array();
			$cursor = 0;
			foreach ( $xp->query( './/a[@href]', $p ) as $a ) {
				$t = self::clean( $a->textContent );
				if ( '' === $t ) {
					continue;
				}
				$pos = strpos( $text, $t, $cursor );
				if ( false === $pos ) {
					continue;
				}
				$cursor = $pos + strlen( $t );
				$out[]  = array( $pos, $a->getAttribute( 'href' ) );
			}
			return $out;
		};

		// "(Contrast these with CCC 1934: “Created in the image…”)" next to a block quote: the cite stands before a quotation
		// that ends the paragraph, so it is that quotation's, not the neighbouring block quote's.
		$owned = function ( $text ) {
			if ( ! preg_match( '/^(.*?)[\x{201C}"]([^\x{201D}"]{40,700})[\x{201D}"][\s)\].]{0,4}$/us', $text, $m ) ) {
				return false;
			}
			return (bool) $this->cites( $m[1] ) && ! $this->cites( $m[2] );
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
			if ( $prev_el && $owned( self::clean( $prev_el->textContent ) ) ) {
				$lead    = '';
				$prev_el = null;
			}
			if ( $next_el && $owned( self::clean( $next_el->textContent ) ) ) {
				$after   = '';
				$next_el = null;
			}
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
			// Not when the quotation already holds such cites of its own ("(Gaudium et Spes, 16)"): the last one is the author's
			// quoted source too, as in Amoris Laetitia 222.
			$holds = preg_match( '/[(\[](?:cf\.\s*)?\p{Lu}[\p{L}\s.\'\x{2019}]+,\s*(?:no\.\s*|n\.\s*|#)?\d{1,4}[)\]].*?[(\[]/us', $paras[ $last ] );
			if ( ! $holds && preg_match( '/\(([^()]{1,100})\)\s*$/u', $paras[ $last ], $m ) && preg_match( '/\d/', $m[1] ) ) {
				$cite            .= ' (' . $m[1] . ')';
				$paras[ $last ]   = trim( substr( $paras[ $last ], 0, -strlen( $m[0] ) ) );
			}
			$paras = array_values( array_filter( $paras, function ( $p ) {
				return '' !== trim( $p );
			} ) );
			// A last line of its own that starts with a dash, "--- Amoris Laetitia, 72-73", is the attribution.
			$last = count( $paras ) - 1;
			if ( $last >= 1 && preg_match( '/^\s*(?:-{1,3}|[\x{2012}-\x{2015}\x{2212}])\s*(\S.{0,159})$/us', $paras[ $last ], $m ) && ( $this->cites( $m[1] ) || $this->marked_cites( $m[1] ) || $this->bare_cites( '(' . $m[1] . ')' ) || $this->docs_in( $m[1] ) ) ) {
				$cite .= ' (' . trim( $m[1] ) . ')';
				array_pop( $paras );
			}
			if ( $paras ) {
				$out[] = array( 'kind' => 'blockquote', 'paras' => $paras, 'cite' => trim( $cite ), 'lead' => $lead, 'after' => $after, 'hrefs' => $links, 'declared' => $declared );
			}
		}

		foreach ( $xp->query( '//p[not(ancestor::blockquote)]' ) as $p ) {
			$text = self::clean( $p->textContent );
			if ( ! preg_match_all( '/[\x{201C}"\x{00AB}]([^\x{201D}"\x{00BB}]{8,700})[\x{201D}"\x{00BB}]/u', $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$at = $links_at( $p, $text );
			foreach ( $ms as $i => $m ) {
				$quote = $m[1][0];
				// A short quotation ("in the name of the Church.") is not matched, but it still marks where the one before it
				// stops, so its cite is not read as that one's.
				if ( mb_strlen( $quote ) < 40 || count( AF_Matcher::tokens( $quote ) ) < AF_Matcher::MIN_TOKENS ) {
					continue;
				}
				$start = $m[0][1];
				$end   = $start + strlen( $m[0][0] );
				// This quotation's own stretch of the paragraph: from the cite of the quotation before it to the start of the next.
				$from = 0;
				if ( $i > 0 ) {
					$pe   = $ms[ $i - 1 ][0][1] + strlen( $ms[ $i - 1 ][0][0] );
					$from = $cite_end( $text, $pe );
				}
				$to    = isset( $ms[ $i + 1 ] ) ? $ms[ $i + 1 ][0][1] : strlen( $text );
				$own   = array();
				foreach ( $at as $l ) {
					if ( $l[0] >= $from && $l[0] < $to ) {
						$own[] = $l[1];
					}
				}
				// One cite for a pair: "...“A,” and be characterized by “B” (EG 170)". When this quotation has no cite right
				// after it and the next one is in the same sentence and has one, that cite may be meant for both.
				$shared = '';
				if ( isset( $ms[ $i + 1 ] ) && $cite_end( $text, $end ) === $end ) {
					$gap   = substr( $text, $end, max( 0, $to - $end ) );
					$ne    = $ms[ $i + 1 ][0][1] + strlen( $ms[ $i + 1 ][0][0] );
					$nce   = $cite_end( $text, $ne );
					if ( $nce > $ne && ! preg_match( '/[.!?;:]\s/u', $gap . ' ' ) ) {
						$shared = trim( substr( $text, $ne, $nce - $ne ) );
					}
				}
				$out[] = array(
					'kind'   => 'inline',
					'paras'  => array( $quote ),
					'cite'   => '',
					'lead'   => mb_substr( substr( $text, $from, max( 0, $start - $from ) ), -300 ),
					'after'  => mb_substr( substr( $text, $end, max( 0, $to - $end ) ), 0, 200 ),
					'hrefs'  => $own,
					'shared' => $shared,
				);
			}
		}
		return $out;
	}
}
