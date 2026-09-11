<?php
/**
 * Wires everything up.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Plugin {

	/** @var LinkSentinel_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( LinkSentinel_License::can_use_pro() ) {
			require_once LINKSENTINEL_PRO_DIR . 'class-linksentinel-pro.php';
			LinkSentinel_Pro::init();
			LinkSentinel_Settings::flush();
		}
		add_action( 'linksentinel_tick', array( $this, 'tick' ) );
		add_action( 'linksentinel_scheduled_scan', array( $this, 'scheduled_scan' ) );
		add_action( 'rest_api_init', array( 'LinkSentinel_REST', 'register' ) );
		add_action( 'save_post', array( 'LinkSentinel_Scanner', 'on_post_saved' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'on_post_deleted' ) );
		add_action( 'update_option_' . LinkSentinel_Settings::OPTION, array( $this, 'ensure_schedule' ) );

		if ( is_admin() ) {
			LinkSentinel_Admin::init();
		}
		$this->maybe_upgrade();
	}

	public static function activate() {
		LinkSentinel_DB::install();
		self::schedule_for( LinkSentinel_Settings::get( 'schedule' ) );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'linksentinel_tick' );
		wp_clear_scheduled_hook( 'linksentinel_scheduled_scan' );
		delete_transient( LinkSentinel_Scanner::LOCK );
	}

	private function maybe_upgrade() {
		if ( get_option( 'linksentinel_db_version' ) !== LinkSentinel_DB::DB_VERSION ) {
			LinkSentinel_DB::install();
		}
	}

	/** WP-Cron continuation of a running scan. */
	public function tick() {
		LinkSentinel_Scanner::step( 12 );
	}

	/** The recurring full scan. */
	public function scheduled_scan() {
		if ( ! LinkSentinel_Scanner::is_running() ) {
			LinkSentinel_Scanner::start( false, 'schedule' );
		}
		LinkSentinel_Scanner::step( 12 );
	}

	public function on_post_deleted( $post_id ) {
		LinkSentinel_DB::delete_occurrences_for_source( 'post', (int) $post_id );
	}

	public function ensure_schedule() {
		LinkSentinel_Settings::all(); // refresh cache after save
		self::schedule_for( get_option( LinkSentinel_Settings::OPTION )['schedule'] ?? 'weekly' );
	}

	private static function schedule_for( $schedule ) {
		$next    = wp_next_scheduled( 'linksentinel_scheduled_scan' );
		$current = $next ? wp_get_schedule( 'linksentinel_scheduled_scan' ) : null;
		if ( 'never' === $schedule ) {
			wp_clear_scheduled_hook( 'linksentinel_scheduled_scan' );
			return;
		}
		// An interval that is no longer offered (a Pro one after the licence lapsed) falls back to daily.
		if ( ! array_key_exists( $schedule, LinkSentinel_Settings::schedules() ) || ! array_key_exists( $schedule, wp_get_schedules() ) ) {
			$schedule = 'daily';
		}
		if ( $current === $schedule ) {
			return;
		}
		wp_clear_scheduled_hook( 'linksentinel_scheduled_scan' );
		// First run overnight-ish rather than immediately after saving settings.
		wp_schedule_event( time() + 6 * HOUR_IN_SECONDS, $schedule, 'linksentinel_scheduled_scan' );
	}
}
