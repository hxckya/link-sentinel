<?php
/**
 * WP-CLI: `wp link-sentinel scan|status|list`, for cron jobs, CI and
 * developers who would rather not click.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

class LinkSentinel_CLI {

	/**
	 * Run a scan to completion and print a summary.
	 *
	 * ## OPTIONS
	 *
	 * [--full]
	 * : Re-fetch every link, ignoring the re-check interval.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp link-sentinel scan --full
	 *
	 * @when after_wp_load
	 */
	public function scan( $args, $assoc ) {
		$state = LinkSentinel_Scanner::start( ! empty( $assoc['full'] ) );
		$last  = -1;
		while ( LinkSentinel_Scanner::is_running() ) {
			$state = LinkSentinel_Scanner::step( 15 );
			$now   = (int) $state['sources'] + (int) $state['checked'];
			if ( $now === $last ) {
				WP_CLI::error( 'The scan is not making progress: ' . ( $state['last_error'] ? $state['last_error'] : 'another process may hold the lock' ) );
			}
			$last = $now;
			if ( 'collect' === $state['phase'] ) {
				WP_CLI::log( sprintf( 'Reading content… %d items, %d links', (int) $state['sources'], (int) $state['found'] ) );
			} else {
				WP_CLI::log( sprintf( 'Checking links… %d of %d', (int) $state['checked'], (int) $state['to_check'] ) );
			}
		}
		$this->status( array(), $assoc );
		$counts = LinkSentinel_DB::counts();
		if ( (int) $counts['broken'] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Print the counts from the last scan.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc ) {
		$counts = LinkSentinel_DB::counts();
		$state  = LinkSentinel_Scanner::state();
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( array( 'state' => $state, 'counts' => $counts ) ) );
			return;
		}
		$rows = array();
		foreach ( array( 'broken', 'redirect', 'blocked', 'error', 'ok', 'dismissed' ) as $k ) {
			$rows[] = array( 'status' => $k, 'links' => (int) $counts[ $k ] );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'links' ) );
		WP_CLI::log( sprintf( 'Last scan: %s, %d items read, %d links.', $state['finished'] ? gmdate( 'Y-m-d H:i', (int) $state['finished'] ) . ' UTC' : 'never', (int) $state['sources'], (int) $state['found'] ) );
	}

	/**
	 * List links by status.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : broken (default), redirect, blocked, error, ok, dismissed, all.
	 *
	 * [--limit=<n>]
	 * : Rows to print. Default 50.
	 *
	 * [--format=<format>]
	 * : table (default), json, csv.
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_links( $args, $assoc ) {
		$view  = isset( $assoc['status'] ) ? $assoc['status'] : 'broken';
		$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 50;
		$res   = LinkSentinel_DB::query( array( 'view' => $view, 'orderby' => 'occurrences', 'order' => 'desc', 'per_page' => $limit, 'paged' => 1 ) );
		$rows  = array();
		foreach ( $res['rows'] as $r ) {
			$rows[] = array(
				'status'      => $r->status,
				'code'        => (int) $r->http_code,
				'url'         => $r->url,
				'occurrences' => (int) $r->occurrences,
				'error'       => $r->error,
			);
		}
		WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'status', 'code', 'url', 'occurrences', 'error' ) );
	}
}

WP_CLI::add_command( 'link-sentinel', 'LinkSentinel_CLI' );
