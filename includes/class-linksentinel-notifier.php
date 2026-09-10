<?php
/**
 * Tells the site owner when a scheduled scan finds something, by email.
 * Sent once per scan, only when there is something to act on, so the
 * message is a signal rather than a heartbeat.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Notifier {

	const OPTION = 'linksentinel_last_notified_scan';

	/** Called when a scan reaches "done". */
	public static function maybe_send( array $state ) {
		if ( empty( LinkSentinel_Settings::get( 'notify_email' ) ) ) {
			return false;
		}
		if ( 'schedule' !== ( isset( $state['trigger'] ) ? $state['trigger'] : '' ) ) {
			return false; // manual scans are watched on screen
		}
		if ( (int) get_option( self::OPTION, 0 ) === (int) $state['id'] ) {
			return false;
		}
		$counts = LinkSentinel_DB::counts();
		if ( 0 === (int) $counts['broken'] ) {
			update_option( self::OPTION, (int) $state['id'], false );
			return false;
		}
		$to = LinkSentinel_Settings::get( 'notify_to' );
		if ( ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}
		$sent = wp_mail( $to, self::subject( $counts ), self::body( $counts ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
		update_option( self::OPTION, (int) $state['id'], false );
		return $sent;
	}

	public static function subject( array $counts ) {
		/* translators: 1: site name, 2: number of broken links */
		return sprintf( _n( '[%1$s] %2$d broken link found', '[%1$s] %2$d broken links found', (int) $counts['broken'], 'link-sentinel' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), (int) $counts['broken'] );
	}

	public static function body( array $counts ) {
		$lines   = array();
		$lines[] = sprintf(
			/* translators: 1: broken, 2: redirects, 3: blocked, 4: unreachable */
			__( 'Link Sentinel finished its scheduled scan: %1$d broken, %2$d redirecting, %3$d blocked, %4$d unreachable.', 'link-sentinel' ),
			(int) $counts['broken'],
			(int) $counts['redirect'],
			(int) $counts['blocked'],
			(int) $counts['error']
		);
		$lines[] = '';
		$rows    = LinkSentinel_DB::query( array( 'view' => 'broken', 'orderby' => 'occurrences', 'order' => 'desc', 'per_page' => 20, 'paged' => 1 ) );
		foreach ( $rows['rows'] as $r ) {
			$where = array();
			foreach ( LinkSentinel_DB::occurrences( (int) $r->id, 3 ) as $o ) {
				$where[] = self::describe( $o );
			}
			$lines[] = sprintf( '%s %s', (int) $r->http_code ? (int) $r->http_code : __( 'broken', 'link-sentinel' ), $r->url );
			if ( $where ) {
				$lines[] = '   ' . implode( ' · ', array_filter( $where ) );
			}
		}
		if ( (int) $rows['total'] > 20 ) {
			/* translators: %d: remaining count */
			$lines[] = sprintf( __( '…and %d more.', 'link-sentinel' ), (int) $rows['total'] - 20 );
		}
		$lines[] = '';
		$lines[] = __( 'Review and fix them here:', 'link-sentinel' );
		$lines[] = admin_url( 'admin.php?page=' . LinkSentinel_Admin::PAGE . '&view=broken' );
		$lines[] = '';
		$lines[] = __( 'You receive this because email notifications are enabled under Link Sentinel → Settings.', 'link-sentinel' );
		return implode( "\n", $lines );
	}

	private static function describe( $o ) {
		if ( 'post' === $o->source_type ) {
			$t = get_the_title( (int) $o->source_id );
			return '' !== $t ? $t : __( '(no title)', 'link-sentinel' );
		}
		if ( 'menu' === $o->source_type ) {
			return __( 'Menu', 'link-sentinel' );
		}
		if ( 'comment' === $o->source_type ) {
			return __( 'Comment', 'link-sentinel' ) . ' #' . (int) $o->source_id;
		}
		if ( 'widget' === $o->source_type ) {
			return __( 'Widget', 'link-sentinel' );
		}
		if ( 'term' === $o->source_type ) {
			$term = get_term( (int) $o->source_id );
			return $term && ! is_wp_error( $term ) ? $term->name : __( 'Term', 'link-sentinel' );
		}
		return '';
	}
}
