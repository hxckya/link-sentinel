<?php
/**
 * Settings with defaults. One option, read once per request.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Settings {

	const OPTION = 'linksentinel_settings';

	/** @var array|null */
	private static $cache = null;

	public static function defaults() {
		return array(
			'post_types'       => array( 'post', 'page' ),
			'post_statuses'    => array( 'publish' ),
			'scan_comments'    => false,
			'scan_menus'       => true,
			'schedule'         => 'weekly', // never | daily | weekly
			'recheck_hours'    => 72,       // a link checked more recently than this is not re-fetched
			'timeout'          => 10,
			'concurrency'      => 8,
			'exclude'          => '',       // one domain or URL prefix per line
			'blocked_is_broken' => false,
			'user_agent'       => 'Mozilla/5.0 (compatible; LinkSentinel/' . LINKSENTINEL_VERSION . '; +https://github.com/hxckya/link-sentinel)',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Sanitise everything that can come from the settings form. */
	public static function sanitize( $input ) {
		$d   = self::defaults();
		$out = array();
		$in  = is_array( $input ) ? $input : array();

		$public_types      = get_post_types( array( 'public' => true ), 'names' );
		$out['post_types'] = array_values( array_intersect( isset( $in['post_types'] ) ? (array) $in['post_types'] : array(), $public_types ) );
		if ( empty( $out['post_types'] ) ) {
			$out['post_types'] = $d['post_types'];
		}

		$out['post_statuses'] = array( 'publish' );
		if ( ! empty( $in['include_drafts'] ) ) {
			$out['post_statuses'] = array( 'publish', 'draft', 'pending', 'future', 'private' );
		}

		$out['scan_comments']     = ! empty( $in['scan_comments'] );
		$out['scan_menus']        = ! empty( $in['scan_menus'] );
		$out['blocked_is_broken'] = ! empty( $in['blocked_is_broken'] );

		$schedule        = isset( $in['schedule'] ) ? $in['schedule'] : $d['schedule'];
		$out['schedule'] = in_array( $schedule, array( 'never', 'daily', 'weekly' ), true ) ? $schedule : $d['schedule'];

		$out['recheck_hours'] = isset( $in['recheck_hours'] ) ? max( 1, min( 720, (int) $in['recheck_hours'] ) ) : $d['recheck_hours'];
		$out['timeout']       = isset( $in['timeout'] ) ? max( 3, min( 60, (int) $in['timeout'] ) ) : $d['timeout'];
		$out['concurrency']   = isset( $in['concurrency'] ) ? max( 1, min( 20, (int) $in['concurrency'] ) ) : $d['concurrency'];

		$lines          = isset( $in['exclude'] ) ? explode( "\n", (string) $in['exclude'] ) : array();
		$lines          = array_filter( array_map( 'trim', array_map( 'sanitize_text_field', $lines ) ) );
		$out['exclude'] = implode( "\n", $lines );

		$ua                = isset( $in['user_agent'] ) ? sanitize_text_field( $in['user_agent'] ) : '';
		$out['user_agent'] = '' !== $ua ? $ua : $d['user_agent'];

		self::$cache = null;
		return $out;
	}

	/** Exclusion rules as a list of lowercase domains or URL prefixes. */
	public static function exclusions() {
		$raw = (string) self::get( 'exclude' );
		return array_filter( array_map( 'strtolower', array_map( 'trim', explode( "\n", $raw ) ) ) );
	}
}
