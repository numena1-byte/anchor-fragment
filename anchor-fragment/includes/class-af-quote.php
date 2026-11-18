<?php
/**
 * The standard display of a quotation from a source document.
 *
 *   <blockquote class="af-quote" data-af-doc="catechism" data-af-locator="1670">
 *     <p>The paragraph text.</p>
 *     <footer class="af-quote__source">&mdash; <cite><a href="…#:~:text=…">Catechism of the Catholic Church, no. 1670</a></cite></footer>
 *   </blockquote>
 *
 * The document title and paragraph number in the footer link to the source with the paragraph highlighted.
 * The data attributes say which paragraph the quotation is meant to be, so the plugin need not guess from the words.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AF_Quote {

	const CLASS_NAME = 'af-quote';

	/** Escape for HTML text and attributes (same result inside and outside WordPress). */
	private static function e( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/** "Catechism of the Catholic Church, no. 1670" */
	public static function label( $title, $locator ) {
		return $title . ', no. ' . $locator;
	}

	/**
	 * @param array $p A result of AF_Service::passage(): doc, title, locator, text, link.
	 * @return string The blockquote markup.
	 */
	public static function render( array $p ) {
		$paras = preg_split( '/\n{2,}/', trim( (string) $p['text'] ) );
		$body  = '';
		foreach ( $paras as $para ) {
			if ( '' !== trim( $para ) ) {
				$body .= '<p>' . self::e( trim( $para ) ) . '</p>';
			}
		}
		$label = self::label( $p['title'], $p['locator'] );
		return '<blockquote class="' . self::CLASS_NAME . '" data-af-doc="' . self::e( $p['doc'] ) . '" data-af-locator="' . self::e( $p['locator'] ) . '">'
			. $body
			. '<footer class="af-quote__source">&mdash; <cite><a href="' . self::e( $p['link'] ) . '">' . self::e( $label ) . '</a></cite></footer>'
			. '</blockquote>';
	}

	/** Small stylesheet; themes can override every rule. */
	public static function css() {
		return 'blockquote.af-quote{margin:1.5em 0;padding:.25em 0 .25em 1.25em;border-left:4px solid currentColor;border-left-color:rgba(128,128,128,.45)}'
			. 'blockquote.af-quote p{margin:0 0 .75em}'
			. 'blockquote.af-quote .af-quote__source{font-size:.875em;opacity:.85}'
			. 'blockquote.af-quote .af-quote__source cite{font-style:normal}';
	}

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && false !== strpos( (string) $post->post_content, self::CLASS_NAME ) ) {
			wp_register_style( 'af-quote', false, array(), AF_VERSION );
			wp_enqueue_style( 'af-quote' );
			wp_add_inline_style( 'af-quote', self::css() );
		}
	}
}
