<?php
/**
 * Finds which paragraph a quotation came from, using an index of hashed word runs.
 *
 * Every paragraph is cut into overlapping runs of K words. Each run is hashed and the
 * hash is stored against the paragraph number, so the index can find a quotation but
 * cannot be turned back into the document. A quotation is cut into runs the same way,
 * and the share of its runs found in a paragraph is its "coverage". Pure PHP, no
 * WordPress functions.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'AF_TEST' ) ) {
	exit;
}

class AF_Matcher {

	/** Words per run. */
	const K = 5;

	/** A quotation with fewer words than this is too short to place reliably. */
	const MIN_TOKENS = 8;

	/**
	 * Lower-cased words of a text: accents removed, punctuation, quote styles and
	 * footnote markers such as [12] ignored.
	 *
	 * @return string[]
	 */
	public static function tokens( $text ) {
		$text = preg_replace( '/\[\d+\]/u', ' ', $text );
		$text = AF_Parser::fold( $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return preg_split( '/ /', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * A quotation as a list of token lists. Ellipses and [bracketed edits] mark places
	 * where words were left out or added, so runs must not span them.
	 *
	 * @return array[]
	 */
	public static function segments( $text ) {
		$t   = preg_replace( '/\[\d+\]/u', ' ', $text );
		$t   = preg_replace( '/\[[^\]]*\]|\x{2026}|\.\s?\.\s?\./u', '|', $t );
		$out = array();
		foreach ( explode( '|', $t ) as $part ) {
			$tok = self::tokens( $part );
			if ( $tok ) {
				$out[] = $tok;
			}
		}
		return $out;
	}

	/**
	 * 48-bit hashes of every K-word run in a token list.
	 *
	 * @return int[]
	 */
	public static function hashes( array $tokens ) {
		$out = array();
		$n   = count( $tokens );
		for ( $i = 0; $i + self::K <= $n; $i++ ) {
			$out[] = hexdec( substr( md5( implode( ' ', array_slice( $tokens, $i, self::K ) ) ), 0, 12 ) );
		}
		return $out;
	}

	/**
	 * Index of one document: run hash => paragraph number, or a list of numbers when
	 * the same run occurs in several paragraphs.
	 *
	 * @param array $paras locator => [ 'full' => text, ... ]
	 * @return array
	 */
	public static function build_index( array $paras ) {
		$idx = array();
		foreach ( $paras as $loc => $p ) {
			$n = (int) $loc;
			foreach ( array_unique( self::hashes( self::tokens( $p['full'] ) ) ) as $h ) {
				if ( ! isset( $idx[ $h ] ) ) {
					$idx[ $h ] = $n;
				} elseif ( is_int( $idx[ $h ] ) ) {
					if ( $idx[ $h ] !== $n ) {
						$idx[ $h ] = array( $idx[ $h ], $n );
					}
				} elseif ( ! in_array( $n, $idx[ $h ], true ) ) {
					$idx[ $h ][] = $n;
				}
			}
		}
		return $idx;
	}

	/**
	 * Score a quotation against one document's index.
	 *
	 * @param array[] $segments Token lists from segments().
	 * @param array   $idx      Index from build_index().
	 * @return array { total, covered, coverage, locators[], top[] }
	 */
	public static function score( array $segments, array $idx ) {
		$total   = 0;
		$covered = 0;
		$counts  = array();
		foreach ( $segments as $tokens ) {
			foreach ( self::hashes( $tokens ) as $h ) {
				$total++;
				if ( ! isset( $idx[ $h ] ) ) {
					continue;
				}
				$covered++;
				foreach ( (array) $idx[ $h ] as $n ) {
					$counts[ $n ] = ( isset( $counts[ $n ] ) ? $counts[ $n ] : 0 ) + 1;
				}
			}
		}

		// Paragraphs that account for a real share of the hits. A quotation spanning
		// several paragraphs lists them all; stray hits on common phrases are left out.
		$locs = array();
		if ( $counts ) {
			arsort( $counts );
			$cut = max( 2, (int) ceil( 0.2 * $covered ) );
			foreach ( $counts as $n => $c ) {
				if ( $c >= $cut ) {
					$locs[] = (int) $n;
				}
			}
			if ( ! $locs ) {
				$locs[] = (int) array_key_first( $counts );
			}
			sort( $locs );
		}

		return array(
			'total'    => $total,
			'covered'  => $covered,
			'coverage' => $total ? round( $covered / $total, 3 ) : 0.0,
			'locators' => $locs,
			'top'      => array_slice( $counts, 0, 5, true ),
		);
	}
}
