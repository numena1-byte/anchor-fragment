<?php
/**
 * Builds the text for a #:~:text= fragment. Pure PHP, no WordPress functions.
 *
 * The browser highlights the FIRST place on the page where the text appears, and the
 * match must sit inside one block element. So we take the opening words of the
 * paragraph and lengthen them until they occur exactly once in the page text.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'AF_TEST' ) ) {
	exit;
}

class AF_Fragment {

	const MIN_WORDS = 4;
	const MAX_WORDS = 14;

	/**
	 * @param string $first_block Text of the paragraph's first block, number already removed.
	 * @param string $folded_page AF_Parser::fold()-ed page text, blocks separated by newlines.
	 * @param string $lead        The paragraph number as printed, e.g. "12. ". Used only when the
	 *                            opening is too short to be unique on its own (a footnote marker follows
	 *                            within the first few words); the number then becomes part of the match.
	 * @return array { frag: string|null, words: int, reason: string }
	 */
	public static function build( $first_block, $folded_page, $lead = '' ) {
		$text = trim( preg_replace( '/\s+/u', ' ', $first_block ) );

		// A footnote marker such as [12] sits in the page text between words, so stop before the first one.
		if ( preg_match( '/\s*\[\d+\]/u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			$text = substr( $text, 0, $m[0][1] );
		}

		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$have  = count( $words );

		if ( $have >= self::MIN_WORDS ) {
			$limit = min( self::MAX_WORDS, $have );
			for ( $n = self::MIN_WORDS; $n <= $limit; $n++ ) {
				$cand  = implode( ' ', array_slice( $words, 0, $n ) );
				$count = substr_count( $folded_page, AF_Parser::fold( $cand ) );
				if ( 1 === $count ) {
					return array( 'frag' => $cand, 'words' => $n, 'reason' => '' );
				}
				if ( 0 === $count ) {
					return array( 'frag' => null, 'words' => $n, 'reason' => 'opening words not found in the page text (normalisation mismatch)' );
				}
			}
			return array( 'frag' => null, 'words' => $limit, 'reason' => 'not unique within ' . self::MAX_WORDS . ' words' );
		}

		// Short opening: include the printed paragraph number in the match, if that makes it unique.
		$lead = preg_replace( '/\s+/u', ' ', (string) $lead );
		if ( $have >= 1 && '' !== trim( $lead ) ) {
			$cand  = $lead . implode( ' ', $words );
			$count = substr_count( $folded_page, AF_Parser::fold( $cand ) );
			if ( 1 === $count ) {
				return array( 'frag' => $cand, 'words' => $have, 'reason' => '' );
			}
			return array( 'frag' => null, 'words' => $have, 'reason' => 'short opening, and still ' . ( 0 === $count ? 'not found' : 'not unique' ) . ' with the paragraph number' );
		}
		return array( 'frag' => null, 'words' => $have, 'reason' => 'no words before a footnote marker or the end of the block' );
	}

	/** Percent-encode for a text directive: also encode "-" (rawurlencode already handles "&" and ","). */
	public static function encode( $s ) {
		return str_replace( '-', '%2D', rawurlencode( $s ) );
	}

	public static function link( $base_url, $frag ) {
		return $base_url . '#:~:text=' . self::encode( $frag );
	}
}
