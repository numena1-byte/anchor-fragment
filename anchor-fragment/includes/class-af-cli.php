<?php
/**
 * WP-CLI commands for testing the fetch and parse steps.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build text-fragment links to numbered paragraphs of Vatican documents.
 */
class AF_CLI {

	/**
	 * Print a text-fragment link for one paragraph.
	 *
	 * ## OPTIONS
	 *
	 * <doc>
	 * : Document id from registry.json, e.g. laudato-si
	 *
	 * <locator>
	 * : Paragraph number, e.g. 12
	 *
	 * [--refresh]
	 * : Fetch the page again instead of using the cache.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function link( $args, $assoc_args ) {
		$res = AF_Service::link( $args[0], $args[1], isset( $assoc_args['refresh'] ) );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::log( 'Document : ' . $res['title'] );
		WP_CLI::log( 'Locator  : ' . $res['locator'] );
		WP_CLI::log( 'Excerpt  : ' . $res['excerpt'] );
		WP_CLI::log( 'Fragment : ' . $res['fragment'] . ' (' . $res['words'] . ' words)' );
		WP_CLI::log( 'Source   : ' . $res['source'] . ( 'fetched' === $res['source'] ? ', ' . $res['fetch_ms'] . ' ms' : '' ) );
		WP_CLI::log( '' );
		WP_CLI::log( $res['link'] );
	}

	/**
	 * Fetch and parse documents, then report whether the paragraph numbering is complete.
	 *
	 * Requests are spaced at least 2 seconds apart. Documents already cached are not fetched again.
	 *
	 * ## OPTIONS
	 *
	 * [<doc>]
	 * : Document id. Omit to check every document in the registry.
	 *
	 * [--refresh]
	 * : Fetch again instead of using the cache.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function check( $args, $assoc_args ) {
		$ids     = $args ? array( $args[0] ) : array_keys( AF_Service::registry() );
		$refresh = isset( $assoc_args['refresh'] );
		$rows    = array();
		$notes   = array();

		foreach ( $ids as $id ) {
			$doc = AF_Service::get_doc( $id, $refresh );
			if ( is_wp_error( $doc ) ) {
				$rows[] = array(
					'doc' => $id, 'http' => 'ERR', 'bytes' => '', 'charset' => '', 'found' => '', 'expected' => '',
					'range' => '', 'gaps' => '', 'rejected' => '', 'no_frag' => '', 'source' => '', 'ms' => '', 'ok' => 'NO',
				);
				$notes[] = $id . ': ' . $doc->get_error_message();
				continue;
			}
			$m  = $doc['meta'];
			$st = $m['stats'];
			$ok = ( null === $m['expected_count'] || (int) $m['expected_count'] === (int) $st['count'] )
				&& empty( $st['gaps'] ) && empty( $st['no_fragment'] );

			$rows[] = array(
				'doc'      => $id,
				'http'     => $m['http'],
				'bytes'    => $m['bytes'],
				'charset'  => $m['charset'],
				'found'    => $st['count'],
				'expected' => $m['expected_count'],
				'range'    => $st['min'] . '-' . $st['max'],
				'gaps'     => count( $st['gaps'] ),
				'rejected' => count( $st['rejected'] ),
				'no_frag'  => count( $st['no_fragment'] ),
				'source'   => $m['source'],
				'ms'       => 'fetched' === $m['source'] ? $m['fetch_ms'] : '',
				'ok'       => $ok ? 'yes' : 'NO',
			);
			if ( $st['gaps'] ) {
				$notes[] = $id . ' missing: ' . implode( ', ', array_slice( $st['gaps'], 0, 25 ) ) . ( count( $st['gaps'] ) > 25 ? ' …' : '' );
			}
			foreach ( $st['inferred'] as $loc => $why ) {
				$notes[] = $id . ' ' . $loc . ': repaired, ' . $why . ' on the page';
			}
			if ( $st['rejected'] ) {
				$notes[] = $id . ' out-of-sequence numbers ignored: ' . implode( ', ', array_slice( $st['rejected'], 0, 25 ) );
			}
			foreach ( array_slice( $st['no_fragment'], 0, 10, true ) as $loc => $why ) {
				$notes[] = $id . ' ' . $loc . ': no fragment (' . $why . ')';
			}
			if ( $m['document_policy'] ) {
				$notes[] = $id . ' Document-Policy header: ' . $m['document_policy'];
			}
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'doc', 'http', 'bytes', 'charset', 'found', 'expected', 'range', 'gaps', 'rejected', 'no_frag', 'source', 'ms', 'ok' ) );
		foreach ( $notes as $n ) {
			WP_CLI::log( $n );
		}
	}

	/**
	 * Fetch a multi-page document (the Catechism) all the way through.
	 *
	 * ## OPTIONS
	 *
	 * <doc>
	 * : Document id from registry.json, e.g. catechism
	 *
	 * [--restart]
	 * : Throw away partial progress and start again.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function ingest( $args, $assoc_args ) {
		$restart = isset( $assoc_args['restart'] );
		do {
			$res = AF_Service::ingest_step( $args[0], 50.0, $restart );
			$restart = false;
			if ( is_wp_error( $res ) ) {
				WP_CLI::error( $res->get_error_message() );
			}
			WP_CLI::log( $res['message'] );
		} while ( ! $res['finished'] );
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Find which paragraph each quotation in a post came from.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post to examine.
	 *
	 * @param array $args Positional arguments.
	 */
	public function match( $args ) {
		$res = AF_Service::analyze_post( (int) $args[0] );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::log( '#' . $res['post_id'] . ' ' . $res['title'] );
		$rows = array();
		foreach ( $res['quotes'] as $q ) {
			$rows[] = array(
				'i'       => $q['i'],
				'status'  => $q['status'],
				'matched' => $q['best'] ? $q['best']['doc'] . ' ' . implode( ',', $q['best']['locators'] ) : '-',
				'cover'   => $q['best'] ? $q['best']['coverage'] : '',
				'quote'   => $q['excerpt'],
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'i', 'status', 'matched', 'cover', 'quote' ) );
		} else {
			WP_CLI::log( 'No quotations found.' );
		}
	}

	/**
	 * Delete cached documents.
	 *
	 * ## OPTIONS
	 *
	 * [<doc>]
	 * : Document id. Omit to clear all.
	 *
	 * @param array $args Positional arguments.
	 */
	public function clear( $args ) {
		$ids = $args ? array( $args[0] ) : array_keys( AF_Service::registry() );
		foreach ( $ids as $id ) {
			AF_Service::clear_doc( $id );
		}
		WP_CLI::success( 'Cleared ' . count( $ids ) . ' cached document(s).' );
	}
}
