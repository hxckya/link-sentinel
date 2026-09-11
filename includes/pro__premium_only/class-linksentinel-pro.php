<?php
/**
 * Link Sentinel Pro: redirects for dead URLs, custom-field scanning,
 * webhooks, CSV export and tighter schedules. Loaded only when the licence
 * allows (see LinkSentinel_License); the free plugin never includes this
 * directory.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-linksentinel-redirects.php';
require_once __DIR__ . '/class-linksentinel-export.php';
require_once __DIR__ . '/class-linksentinel-meta.php';
require_once __DIR__ . '/class-linksentinel-webhook.php';

class LinkSentinel_Pro {

	const DB_VERSION = '1';

	public static function init() {
		add_filter( 'linksentinel_schedules', array( __CLASS__, 'schedules' ) );
		add_filter( 'linksentinel_settings_defaults', array( __CLASS__, 'defaults' ) );
		add_filter( 'linksentinel_settings_sanitize', array( __CLASS__, 'sanitize' ), 10, 2 );
		add_action( 'linksentinel_settings_rows', array( __CLASS__, 'settings_rows' ) );

		LinkSentinel_Redirects::init();
		LinkSentinel_Export::init();
		LinkSentinel_Meta::init();
		LinkSentinel_Webhook::init();

		if ( get_option( 'linksentinel_pro_db_version' ) !== self::DB_VERSION ) {
			LinkSentinel_Redirects::install();
			update_option( 'linksentinel_pro_db_version', self::DB_VERSION, false );
		}
	}

	/** Adds the shorter WP-Cron intervals in front of "Never". */
	public static function schedules( $schedules ) {
		$never = isset( $schedules['never'] ) ? $schedules['never'] : null;
		unset( $schedules['never'] );
		$schedules['twicedaily'] = __( 'Twice a day', 'link-sentinel' );
		$schedules['hourly']     = __( 'Every hour', 'link-sentinel' );
		if ( null !== $never ) {
			$schedules['never'] = $never;
		}
		return $schedules;
	}

	public static function defaults( $d ) {
		$d['scan_meta']      = false;
		$d['notify_webhook'] = '';
		return $d;
	}

	public static function sanitize( $out, $in ) {
		$out['scan_meta']      = ! empty( $in['scan_meta'] );
		$url                   = isset( $in['notify_webhook'] ) ? esc_url_raw( trim( (string) $in['notify_webhook'] ), array( 'http', 'https' ) ) : '';
		$out['notify_webhook'] = $url;
		return $out;
	}

	public static function settings_rows( $s ) {
		$opt = LinkSentinel_Settings::OPTION;
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Custom fields', 'link-sentinel' ); ?> <span class="lsn-badge"><?php esc_html_e( 'Pro', 'link-sentinel' ); ?></span></th>
			<td>
				<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[scan_meta]" value="1" <?php checked( ! empty( $s['scan_meta'] ) ); ?>> <?php esc_html_e( 'Also scan post meta: ACF fields, Elementor and other page-builder data, SEO plugin fields', 'link-sentinel' ); ?></label>
				<p class="description"><?php esc_html_e( 'Links found there can be edited in place too. Slower on very large sites; the scan still runs in small steps.', 'link-sentinel' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="lsn-webhook"><?php esc_html_e( 'Webhook', 'link-sentinel' ); ?></label> <span class="lsn-badge"><?php esc_html_e( 'Pro', 'link-sentinel' ); ?></span></th>
			<td>
				<input type="url" id="lsn-webhook" name="<?php echo esc_attr( $opt ); ?>[notify_webhook]" value="<?php echo esc_attr( isset( $s['notify_webhook'] ) ? $s['notify_webhook'] : '' ); ?>" class="large-text code" placeholder="https://hooks.slack.com/services/…">
				<p class="description"><?php esc_html_e( 'Slack and Discord incoming webhooks get a readable message; any other URL gets JSON. Same rules as email: scheduled scans only, once per scan, only when something is broken.', 'link-sentinel' ); ?></p>
			</td>
		</tr>
		<?php
	}
}
