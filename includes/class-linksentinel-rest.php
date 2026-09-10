<?php
/**
 * REST routes the admin page drives. Everything requires manage_options.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_REST {

	const NS = 'link-sentinel/v1';

	public static function register() {
		$perm = function () {
			return current_user_can( 'manage_options' );
		};
		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		register_rest_route( self::NS, '/scan/status', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'status' ), 'permission_callback' => $perm ) );
		register_rest_route(
			self::NS,
			'/scan/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => $perm,
				'args'                => array( 'force_all' => array( 'type' => 'boolean', 'default' => false ) ),
			)
		);
		register_rest_route( self::NS, '/scan/step', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'step' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/scan/stop', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'stop' ), 'permission_callback' => $perm ) );

		register_rest_route( self::NS, '/links/(?P<id>\d+)/recheck', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'recheck' ), 'permission_callback' => $perm, 'args' => $id_arg ) );
		register_rest_route(
			self::NS,
			'/links/(?P<id>\d+)/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'dismiss' ),
				'permission_callback' => $perm,
				'args'                => $id_arg + array( 'dismissed' => array( 'type' => 'boolean', 'default' => true ) ),
			)
		);
		register_rest_route( self::NS, '/links/(?P<id>\d+)/unlink', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'unlink' ), 'permission_callback' => $perm, 'args' => $id_arg ) );
		register_rest_route(
			self::NS,
			'/links/(?P<id>\d+)/url',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_url' ),
				'permission_callback' => $perm,
				'args'                => $id_arg + array( 'url' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
			)
		);
	}

	private static function payload( $state ) {
		$counts = LinkSentinel_DB::counts();
		$done   = 'check' === $state['phase'] && $state['to_check'] > 0 ? min( 100, (int) round( 100 * $state['checked'] / $state['to_check'] ) ) : ( 'done' === $state['phase'] ? 100 : 0 );
		return array(
			'state'    => $state,
			'counts'   => $counts,
			'running'  => in_array( $state['phase'], array( 'collect', 'check' ), true ),
			'percent'  => $done,
			'message'  => self::describe( $state ),
		);
	}

	private static function describe( $state ) {
		switch ( $state['phase'] ) {
			case 'collect':
				/* translators: 1: number of posts read, 2: number of links found */
				return sprintf( __( 'Reading content… %1$d items, %2$d links found', 'link-sentinel' ), (int) $state['sources'], (int) $state['found'] );
			case 'check':
				/* translators: 1: links checked, 2: links to check */
				return sprintf( __( 'Checking links… %1$d of %2$d', 'link-sentinel' ), (int) $state['checked'], (int) $state['to_check'] );
			case 'done':
				/* translators: %s: human time diff */
				return $state['finished'] ? sprintf( __( 'Last scan finished %s ago', 'link-sentinel' ), human_time_diff( (int) $state['finished'] ) ) : __( 'Scan finished', 'link-sentinel' );
			default:
				return __( 'No scan yet', 'link-sentinel' );
		}
	}

	public static function status() {
		return rest_ensure_response( self::payload( LinkSentinel_Scanner::state() ) );
	}

	public static function start( WP_REST_Request $r ) {
		$state = LinkSentinel_Scanner::start( (bool) $r->get_param( 'force_all' ) );
		// Do a first slice right away so the page shows movement.
		$state = LinkSentinel_Scanner::step( 4 );
		return rest_ensure_response( self::payload( $state ) );
	}

	public static function step() {
		return rest_ensure_response( self::payload( LinkSentinel_Scanner::step( 8 ) ) );
	}

	public static function stop() {
		return rest_ensure_response( self::payload( LinkSentinel_Scanner::stop() ) );
	}

	public static function recheck( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		LinkSentinel_DB::mark_unchecked( array( $id ) );
		$results = LinkSentinel_Scanner::recheck( array( $id ) );
		$link    = LinkSentinel_DB::get_link( $id );
		return rest_ensure_response( array( 'ok' => true, 'link' => $link, 'result' => isset( $results[ $id ] ) ? $results[ $id ] : null ) );
	}

	public static function dismiss( WP_REST_Request $r ) {
		LinkSentinel_DB::set_dismissed( (int) $r['id'], (bool) $r->get_param( 'dismissed' ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function unlink( WP_REST_Request $r ) {
		$n = LinkSentinel_Fixer::unlink( (int) $r['id'] );
		if ( is_wp_error( $n ) ) {
			return $n;
		}
		return rest_ensure_response( array( 'ok' => true, 'updated' => $n ) );
	}

	public static function set_url( WP_REST_Request $r ) {
		$n = LinkSentinel_Fixer::replace_url( (int) $r['id'], $r->get_param( 'url' ) );
		if ( is_wp_error( $n ) ) {
			return $n;
		}
		return rest_ensure_response( array( 'ok' => true, 'updated' => $n ) );
	}
}
