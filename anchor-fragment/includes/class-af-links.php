<?php
/**
 * Links the citations of a post to their source paragraphs when the post is shown.
 *
 * Only the citation is linked ("EG, 30" in "(EG, 30)"), never the quotation, and only a citation the graph holds as
 * correct: the author typed it and the words agree (high), or the Catechism footnotes link it (cross_reference).
 * The post itself is not changed; the links are added to the page as it is displayed.
 *
 * @package AnchorFragment
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'AF_TEST' ) ) {
	exit;
}

class AF_Cite_Links {

	const OPTION = 'af_link_cites';

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter' ), 25 );
	}

	public static function enabled() {
		return '0' !== (string) get_option( self::OPTION, '1' );
	}

	/** The content filter: posts shown on their own page only. */
	public static function filter( $content ) {
		if ( ! self::enabled() || ! is_singular() || ! in_the_loop() || ! is_main_query() || '' === trim( (string) $content ) ) {
			return $content;
		}
		$edges = self::edges( (int) get_the_ID() );
		if ( ! $edges ) {
			return $content;
		}
		$an  = new AF_Analyzer( AF_Service::registry() );
		$res = self::decorate( $content, $edges, $an, array( __CLASS__, 'url_for' ) );
		return $res['html'];
	}

	/** "doc:locator" => true for the citations of a post that are typed and confirmed by the words. */
	public static function edges( $post_id ) {
		global $wpdb;
		$t    = AF_Graph::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT doc, loc_num FROM {$t} WHERE post_id = %d AND basis = 'typed' AND status IN ('high','cross_reference') AND ( confirmed IS NULL OR confirmed = 1 )", (int) $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['doc'] . ':' . (int) $r['loc_num'] ] = true;
		}
		return $out;
	}

	/** The address of a paragraph (with its text fragment), cached. */
	public static function url_for( $doc, $loc ) {
		$key = 'af_cl_' . md5( $doc . ':' . (int) $loc . ':' . AF_VERSION );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return (string) $hit;
		}
		$l   = AF_Service::link( $doc, (string) (int) $loc );
		$url = ( is_array( $l ) && ! empty( $l['link'] ) ) ? (string) $l['link'] : '';
		set_transient( $key, $url, '' === $url ? HOUR_IN_SECONDS : WEEK_IN_SECONDS );
		return $url;
	}

	/**
	 * Link the citations in an HTML fragment.
	 *
	 * @param string      $html   Post HTML.
	 * @param array       $edges  "doc:locator" => true, the citations that are right.
	 * @param AF_Analyzer $an     Reads a bracket as a citation.
	 * @param callable    $linker (doc, locator) => url or ''.
	 * @return array { html, linked, skipped[] } Brackets that read as citations but were not linked, with the reason.
	 */
	public static function decorate( $html, array $edges, AF_Analyzer $an, $linker ) {
		$res = array( 'html' => $html, 'linked' => 0, 'skipped' => array() );
		if ( '' === trim( $html ) || false === strpbrk( $html, '([' ) ) {
			return $res;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"><div id="af-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp   = new DOMXPath( $dom );
		$root = $dom->getElementById( 'af-root' );
		if ( ! $root ) {
			return $res;
		}
		$nodes = iterator_to_array( $xp->query( './/text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::code) and not(ancestor::pre) and not(ancestor::button) and not(ancestor::textarea)]', $root ) );
		foreach ( $nodes as $node ) {
			$text = $node->nodeValue;
			if ( false === strpbrk( $text, '([' ) || ! preg_match_all( '/[(\[]([^()\[\]]{3,140})[)\]]/u', $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$parts = array();
			$pos   = 0;
			foreach ( $ms as $m ) {
				$inner = $m[1][0];
				$refs  = $an->refs( $inner );
				if ( ! $refs ) {
					continue;
				}
				if ( 1 !== count( $refs ) ) {
					$res['skipped'][] = array( trim( $inner ), 'more than one citation in the brackets' );
					continue;
				}
				$doc = $refs[0]['doc'];
				$loc = (int) $refs[0]['loc'][0];
				if ( empty( $edges[ $doc . ':' . $loc ] ) ) {
					$res['skipped'][] = array( trim( $inner ), 'not a confirmed citation of this post' );
					continue;
				}
				$url = call_user_func( $linker, $doc, $loc );
				if ( '' === (string) $url ) {
					$res['skipped'][] = array( trim( $inner ), 'no link for that paragraph' );
					continue;
				}
				$start = $m[1][1];
				// "cf. LS, 119": the link is the citation, not the "cf." before it.
				if ( preg_match( '/^\s*(?:cf\.?|see(?:\s+also)?|compare)\s+/iu', $inner, $pm ) ) {
					$start += strlen( $pm[0] );
					$inner  = substr( $inner, strlen( $pm[0] ) );
				}
				$parts[] = array( $pos, $start, null );
				$parts[] = array( $start, $start + strlen( $inner ), $url );
				$pos     = $start + strlen( $inner );
				$res['linked']++;
			}
			if ( ! $parts ) {
				continue;
			}
			$parts[] = array( $pos, strlen( $text ), null );
			$frag    = $dom->createDocumentFragment();
			foreach ( $parts as $p ) {
				$piece = substr( $text, $p[0], $p[1] - $p[0] );
				if ( '' === $piece ) {
					continue;
				}
				if ( null === $p[2] ) {
					$frag->appendChild( $dom->createTextNode( $piece ) );
				} else {
					$a = $dom->createElement( 'a' );
					$a->setAttribute( 'href', $p[2] );
					$a->setAttribute( 'class', 'af-cite' );
					$a->setAttribute( 'rel', 'noopener' );
					$a->appendChild( $dom->createTextNode( $piece ) );
					$frag->appendChild( $a );
				}
			}
			$node->parentNode->replaceChild( $frag, $node );
		}
		if ( ! $res['linked'] ) {
			return $res;
		}
		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		$res['html'] = $out;
		return $res;
	}
}
