<?php
/**
 * Posts the result of a scheduled scan to a webhook: Slack and Discord get a
 * readable message, anything else gets JSON. Same rules as the email report.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Webhook {

	const OPTION = 'linksentinel_last_webhook_scan';

	public static function init() {
		add_action( 'linksentinel_scan_done', array( __CLASS__, 'maybe_send' ) );
	}

	public static function maybe_send( $state ) {
		$url = (string) LinkSentinel_Settings::get( 'notify_webhook' );
		if ( '' === $url || 'schedule' !== ( isset( $state['trigger'] ) ? $state['trigger'] : '' ) ) {
			return false;
		}
		if ( (int) get_option( self::OPTION, 0 ) === (int) $state['id'] ) {
			return false;
		}
		update_option( self::OPTION, (int) $state['id'], false );
		$counts = LinkSentinel_DB::counts();
		if ( 0 === (int) $counts['broken'] ) {
			return false;
		}
		$r = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( self::payload( $url, $counts ) ),
			)
		);
		return ! is_wp_error( $r ) && (int) wp_remote_retrieve_response_code( $r ) < 300;
	}

	public static function payload( $url, array $counts ) {
		$rows  = LinkSentinel_DB::query( array( 'view' => 'broken', 'orderby' => 'occurrences', 'order' => 'desc', 'per_page' => 20, 'paged' => 1 ) );
		$links = array();
		$lines = array();
		foreach ( $rows['rows'] as $r ) {
			$links[] = array( 'url' => $r->url, 'http_code' => (int) $r->http_code, 'status' => $r->status, 'occurrences' => (int) $r->occurrences );
			$lines[] = sprintf( '%s %s', (int) $r->http_code ? (int) $r->http_code : 'broken', $r->url );
		}
		if ( (int) $rows['total'] > 20 ) {
			$lines[] = sprintf( '… and %d more', (int) $rows['total'] - 20 );
		}
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$admin = admin_url( 'admin.php?page=' . LinkSentinel_Admin::PAGE . '&view=broken' );
		$text  = sprintf( "%s: %d broken link(s), %d redirecting, %d blocked\n%s\n%s", $site, (int) $counts['broken'], (int) $counts['redirect'], (int) $counts['blocked'], implode( "\n", $lines ), $admin );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( false !== strpos( $host, 'hooks.slack.com' ) ) {
			return array( 'text' => $text );
		}
		if ( false !== strpos( $host, 'discord.com' ) || false !== strpos( $host, 'discordapp.com' ) ) {
			return array( 'content' => mb_substr( $text, 0, 1900 ) );
		}
		return array(
			'event'     => 'scan.finished',
			'site'      => $site,
			'site_url'  => home_url( '/' ),
			'text'      => $text,
			'counts'    => $counts,
			'links'     => $links,
			'admin_url' => $admin,
		);
	}
}
