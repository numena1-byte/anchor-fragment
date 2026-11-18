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

	/**
	 * Layout only: no border, quote mark or colour, so the theme's own blockquote style is the one that shows.
	 * The source line is set a little smaller than the quotation.
	 */
	public static function css() {
		return 'blockquote.af-quote .af-quote__source{display:block;font-size:.875em;opacity:.85}'
			. 'blockquote.af-quote .af-quote__source cite{font-style:normal}';
	}

	/** Stand-in for a theme's blockquote style, used only on the admin test page where no theme CSS is loaded. */
	public static function admin_preview_css() {
		return 'blockquote.af-quote{margin:1.5em 0;padding:.25em 0 .25em 1.25em;border-left:4px solid #ccd0d4}blockquote.af-quote p{margin:0 0 .75em}';
	}

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'media_buttons', array( __CLASS__, 'editor_button' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_editor' ) );
	}

	/** Documents the button offers: those whose text can be read from a web page (not PDF-only ones). */
	public static function picker_docs() {
		$docs = array();
		foreach ( AF_Service::registry() as $id => $d ) {
			if ( false === stripos( (string) $d['url'], '.pdf' ) ) {
				$docs[] = array( 'id' => $id, 'title' => $d['title'] );
			}
		}
		return $docs;
	}

	/** The button above the classic editor. */
	public static function editor_button() {
		if ( current_user_can( 'edit_posts' ) ) {
			echo '<button type="button" class="button af-insert-quote">Insert quotation</button>';
		}
	}

	public static function enqueue_editor( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_enqueue_script( 'af-insert-quote', plugins_url( 'assets/insert-quote.js', AF_DIR . 'anchor-fragment.php' ), array(), AF_VERSION, true );
		wp_add_inline_script(
			'af-insert-quote',
			'window.afQuote = ' . wp_json_encode(
				array(
					'rest'  => rest_url( 'anchorfrag/v1/passage' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'docs'  => self::picker_docs(),
				)
			) . ';',
			'before'
		);
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
