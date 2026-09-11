<?php
/**
 * CSV export of any view of the links table.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Export {

	const VIEWS = array( 'broken', 'redirect', 'blocked', 'error', 'ok', 'dismissed', 'all' );

	public static function init() {
		add_action( 'admin_post_linksentinel_export', array( __CLASS__, 'download' ) );
		add_action( 'linksentinel_links_toolbar', array( __CLASS__, 'button' ) );
	}

	public static function button( $view ) {
		$view = in_array( $view, self::VIEWS, true ) ? $view : 'broken';
		$url  = wp_nonce_url( admin_url( 'admin-post.php?action=linksentinel_export&view=' . rawurlencode( $view ) ), 'linksentinel_export' );
		echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export CSV', 'link-sentinel' ) . '</a>';
	}

	public static function header() {
		return array( 'status', 'http_code', 'url', 'final_url', 'error', 'occurrences', 'found_in', 'first_seen', 'last_checked' );
	}

	/** Rows for a view as plain arrays, in pages, capped so a huge table cannot exhaust memory. */
	public static function rows( $view, $cap = 20000 ) {
		$out  = array();
		$page = 1;
		while ( count( $out ) < $cap ) {
			$q = LinkSentinel_DB::query( array( 'view' => $view, 'orderby' => 'url', 'order' => 'asc', 'per_page' => 500, 'paged' => $page ) );
			if ( ! $q['rows'] ) {
				break;
			}
			foreach ( $q['rows'] as $r ) {
				$out[] = self::line( $r );
			}
			if ( count( $q['rows'] ) < 500 ) {
				break;
			}
			$page++;
		}
		return $out;
	}

	public static function line( $r ) {
		$where = array();
		foreach ( LinkSentinel_DB::occurrences( (int) $r->id, 3 ) as $o ) {
			$where[] = self::place( $o );
		}
		return array(
			$r->status,
			(int) $r->http_code,
			$r->url,
			(string) $r->final_url,
			(string) $r->error,
			(int) $r->occurrences,
			implode( ' | ', array_filter( $where ) ),
			(string) $r->first_seen,
			(string) $r->last_checked,
		);
	}

	private static function place( $o ) {
		if ( 'post' === $o->source_type ) {
			$t     = get_the_title( (int) $o->source_id );
			$field = 0 === strpos( (string) $o->field, 'meta:' ) ? ' [' . substr( $o->field, 5 ) . ']' : '';
			return ( '' !== $t ? $t : '#' . (int) $o->source_id ) . $field . ' <' . get_permalink( (int) $o->source_id ) . '>';
		}
		if ( 'term' === $o->source_type ) {
			$term = get_term( (int) $o->source_id );
			return 'term: ' . ( $term && ! is_wp_error( $term ) ? $term->name : '#' . (int) $o->source_id );
		}
		return $o->source_type . ' #' . (int) $o->source_id;
	}

	/** The whole CSV as a string (tests, WP-CLI). */
	public static function csv( $view ) {
		$h = fopen( 'php://temp', 'w+' );
		self::write( $h, $view );
		rewind( $h );
		$s = stream_get_contents( $h );
		fclose( $h );
		return $s;
	}

	private static function write( $h, $view ) {
		fwrite( $h, "\xEF\xBB\xBF" ); // BOM so Excel reads UTF-8
		fputcsv( $h, self::header() );
		foreach ( self::rows( $view ) as $line ) {
			fputcsv( $h, $line );
		}
	}

	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'link-sentinel' ) );
		}
		check_admin_referer( 'linksentinel_export' );
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'broken';
		$view = in_array( $view, self::VIEWS, true ) ? $view : 'broken';
		$name = sanitize_file_name( 'link-sentinel-' . $view . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$h = fopen( 'php://output', 'w' );
		self::write( $h, $view );
		fclose( $h );
		exit;
	}
}
