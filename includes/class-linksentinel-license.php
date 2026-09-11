<?php
/**
 * Knows whether Pro code is present and allowed to run.
 *
 * Freemius handles checkout, licences and premium updates. Until a Freemius
 * product id is configured here, the SDK is not loaded at all and the free
 * plugin behaves exactly as it always did.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_License {

	/** Freemius product id and public key; empty until the product exists. */
	const FS_ID         = '';
	const FS_PUBLIC_KEY = '';

	/** @var bool|null */
	private static $can = null;

	/** True when the premium files ship with this copy. */
	public static function is_premium_build() {
		return file_exists( LINKSENTINEL_PRO_DIR . 'class-linksentinel-pro.php' );
	}

	/**
	 * The Freemius instance, or null when the SDK is not configured.
	 *
	 * @return Freemius|null
	 */
	public static function fs() {
		static $fs    = null;
		static $tried = false;
		if ( $tried ) {
			return $fs;
		}
		$tried = true;
		if ( '' === self::FS_ID || ! file_exists( LINKSENTINEL_DIR . 'vendor/freemius/start.php' ) ) {
			return null;
		}
		if ( ! function_exists( 'fs_dynamic_init' ) ) {
			require_once LINKSENTINEL_DIR . 'vendor/freemius/start.php';
		}
		$fs = fs_dynamic_init(
			array(
				'id'                  => self::FS_ID,
				'slug'                => 'link-sentinel',
				'premium_slug'        => 'link-sentinel-pro',
				'type'                => 'plugin',
				'public_key'          => self::FS_PUBLIC_KEY,
				'is_premium'          => self::is_premium_build(),
				'premium_suffix'      => 'Pro',
				'has_premium_version' => true,
				'has_addons'          => false,
				'has_paid_plans'      => true,
				// Free users are never asked to opt in; nothing leaves the site unless they buy.
				'anonymous_mode'      => true,
				'is_live'             => true,
				'menu'                => array(
					'slug'       => 'link-sentinel',
					'first-path' => 'admin.php?page=link-sentinel',
					'support'    => false,
					'contact'    => false,
				),
			)
		);
		do_action( 'linksentinel_fs_loaded' );
		return $fs;
	}

	/** Whether premium code may run right now. */
	public static function can_use_pro() {
		if ( null !== self::$can ) {
			return self::$can;
		}
		if ( ! self::is_premium_build() ) {
			self::$can = false;
		} elseif ( defined( 'LINKSENTINEL_PRO_DEV' ) && LINKSENTINEL_PRO_DEV ) {
			self::$can = true; // local development and CI
		} else {
			$fs        = self::fs();
			self::$can = $fs ? (bool) $fs->can_use_premium_code() : false;
		}
		return self::$can;
	}

	/** Where "See Pro" points. */
	public static function upgrade_url() {
		$fs = self::fs();
		if ( $fs && method_exists( $fs, 'get_upgrade_url' ) ) {
			return $fs->get_upgrade_url();
		}
		return 'https://github.com/hxckya/link-sentinel#pro';
	}

	/** Short list of what Pro adds, for the settings page and readme. */
	public static function features() {
		return array(
			__( 'Redirects for dead URLs on your own site: visitors and search engines land on a page that works, not a 404', 'link-sentinel' ),
			__( 'Custom-field scanning: ACF fields, Elementor and other page-builder data, SEO plugin fields; fixable in place', 'link-sentinel' ),
			__( 'Slack, Discord and webhook alerts after scheduled scans', 'link-sentinel' ),
			__( 'CSV export of any view, for clients and audits', 'link-sentinel' ),
			__( 'Hourly and twice-daily scans', 'link-sentinel' ),
		);
	}
}
