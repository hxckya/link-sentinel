<?php
/**
 * One-time import from Broken Link Checker (wordpress.org slug broken-link-checker).
 *
 * Where that plugin keeps its data, as read from its source:
 *
 * - Up to 2.4.8 (and every 1.x release) the local checker keeps all settings in one
 *   option, wsblc_options, saved as a JSON string (1.x: includes/config-manager.php and
 *   core/init.php; 2.x: the same files under legacy/). Values come back as strings:
 *   "1" / "" for booleans, "72" for numbers.
 * - From 2.4.9 the same keys are split across wsblc_general_options (exclusion_list,
 *   check_threshold, run_via_cron, timeout, e-mail), wsblc_frontend_options
 *   (enabled_post_statuses, active_modules as a list of ids) and
 *   wsblc_modules_options (legacy/config.php). They are copied from wsblc_options
 *   once and the old option is left in place, so the split options win.
 * - The 2.x Cloud scanner keeps blc_settings, a JSON string (app/options/settings/
 *   class-model.php). Its schedule is there; its ignored links and exclusions live
 *   with the cloud service, not on this site.
 * - Deactivating it keeps all of this. Only its uninstall (uninstall.php) removes its
 *   options and tables, as of 2.4.14.1; the deactivation hook that would delete
 *   blc_settings is commented out in app/options/settings/class-controller.php.
 * - Dismissed links ("Dismiss") and links marked "Not broken" are flags on
 *   {prefix}blc_links (dismissed, false_positive; legacy/includes/admin/db-schema.php).
 *   Both undo themselves: blcLink::status_changed() (legacy/includes/links.php) clears
 *   dismissed when a link's result changes, and false_positive when a new result is
 *   still broken or the link works again. Link Sentinel's dismissed flag is permanent
 *   (a dismissed link is not checked until someone restores it), so only dismissed
 *   links are offered, unticked, and "Not broken" links are left to be checked here.
 *
 * Everything here only reads those options and tables. The mapping is done by pure
 * functions (decode, merge_local, map_*, plan, settings_input) so it can be tested
 * without a Broken Link Checker install; site_plan() adds the linksentinel_blc_import_plan
 * filter on top.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Import_BLC {

	/** Records that the import ran (or was declined), so the offer is shown once. */
	const OPTION = 'linksentinel_blc_import';

	/** Nonce action and admin-post action. */
	const ACTION = 'linksentinel_import_blc';

	/** Upper bound on dismissed links read in one request. */
	const MAX_DISMISSED = 5000;

	/** Broken Link Checker's own default timeout (seconds); a value left at it is not imported. */
	const BLC_DEFAULT_TIMEOUT = 30;

	/** Broken Link Checker options this importer reads. Never written. */
	const BLC_OPTIONS = array( 'wsblc_options', 'wsblc_general_options', 'wsblc_frontend_options', 'blc_settings' );

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	// ----- Pure mapping ---------------------------------------------------------

	/**
	 * Decode one Broken Link Checker option: a JSON string (how it saves them) or an array.
	 *
	 * @return array|null
	 */
	public static function decode( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$data = json_decode( $raw, true );
			return is_array( $data ) ? $data : null;
		}
		return null;
	}

	/**
	 * One view of the local checker's configuration: the pre-2.4.9 option, overlaid
	 * by the split options that replaced it.
	 *
	 * @return array|null Null when none of the options exists.
	 */
	public static function merge_local( $legacy, $general, $frontend ) {
		$parts = array_filter( array( $legacy, $general, $frontend ), 'is_array' );
		if ( ! $parts ) {
			return null;
		}
		$out = array();
		foreach ( $parts as $part ) {
			foreach ( $part as $key => $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Active module ids. Old releases store id => module header, 2.4.9+ a list of ids;
	 * with neither, Broken Link Checker falls back to posts, pages and comments.
	 *
	 * @return string[]
	 */
	public static function active_modules( array $local ) {
		$raw = isset( $local['active_modules'] ) && is_array( $local['active_modules'] ) ? $local['active_modules'] : array();
		$ids = array();
		foreach ( $raw as $key => $value ) {
			$ids[] = is_string( $key ) ? $key : ( is_string( $value ) ? $value : '' );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $ids ) ) ) );
		return $ids ? $ids : array( 'post', 'page', 'comment' );
	}

	/**
	 * Exclusion list → Link Sentinel rules.
	 *
	 * Broken Link Checker skips a URL that contains any listed entry anywhere, as
	 * literal text (preg_quote()d, case-insensitive; legacy/core/core.php
	 * build_exclusion_regex()). Link Sentinel matches a domain (with its subdomains)
	 * or a URL prefix, so only entries that name a host or a URL carry over:
	 * "example.com" becomes a domain rule, "https://example.com/x" stays a prefix,
	 * "example.com/x" becomes the http:// and https:// prefixes. Plain words
	 * ("youtube", "/go/", "?ref=") have no equivalent. Entries with "*" are reported
	 * apart: "*" was not a wildcard there, so "*.example.com" excluded nothing, and
	 * turning it into a rule would start excluding links it used to check.
	 *
	 * @return array{rules: string[], skipped: string[], wildcards: string[]}
	 */
	public static function map_exclusions( $list ) {
		$rules     = array();
		$skipped   = array();
		$wildcards = array();
		foreach ( (array) $list as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}
			$entry = trim( sanitize_text_field( (string) $entry ) );
			if ( '' === $entry ) {
				continue;
			}
			if ( false !== strpos( $entry, '*' ) ) {
				$wildcards[] = $entry;
				continue;
			}
			$value = strtolower( $entry );
			if ( 0 === strpos( $value, '//' ) ) {
				$value = substr( $value, 2 );
			}
			if ( preg_match( '#^https?://#', $value ) ) {
				$host = wp_parse_url( $value, PHP_URL_HOST );
				if ( is_string( $host ) && self::is_host( $host ) ) {
					$rules[] = $value;
					continue;
				}
			} elseif ( preg_match( '#^\.?([^/:?\#\s]+)(?::\d+)?/?$#', $value, $m ) && self::is_host( $m[1] ) ) {
				$rules[] = $m[1];
				continue;
			} elseif ( preg_match( '#^([^/:?\#\s]+)(?::\d+)?(/.*)$#', $value, $m ) && self::is_host( $m[1] ) ) {
				$rules[] = 'http://' . $value;
				$rules[] = 'https://' . $value;
				continue;
			}
			$skipped[] = $entry;
		}
		return array(
			'rules'     => array_values( array_unique( $rules ) ),
			'skipped'   => array_values( array_unique( $skipped ) ),
			'wildcards' => array_values( array_unique( $wildcards ) ),
		);
	}

	/** A domain name or IPv4 address (not a bare word like "localhost" or "amazon"). */
	public static function is_host( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^\d{1,3}(?:\.\d{1,3}){3}$/', $value ) ) {
			return true;
		}
		return (bool) preg_match( '/^(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+(?:\p{L}{2,63}|xn--[a-z0-9-]{1,59})$/u', $value );
	}

	/**
	 * "Look for links in" → post types and comments.
	 *
	 * @param string[] $module_ids   Active module ids.
	 * @param array    $public_types name => label, the types the Settings screen offers.
	 * @param string[] $all_types    Every registered post type name.
	 * @return array{post_types: string[], comments: bool, skipped: string[]} Skipped lines for
	 *         container modules are keyed by module id, so an extension that does scan them
	 *         (Pro: custom fields) can drop them through the linksentinel_blc_import_plan filter.
	 */
	public static function map_content( array $module_ids, array $public_types, array $all_types ) {
		$types   = array();
		$skipped = array();
		foreach ( $module_ids as $id ) {
			if ( isset( $public_types[ $id ] ) ) {
				$types[] = $id;
			} elseif ( in_array( $id, $all_types, true ) ) {
				/* translators: %s: post type name */
				$skipped[] = sprintf( __( 'Post type “%s”: not a public post type, so Link Sentinel does not scan it.', 'link-sentinel' ), $id );
			}
		}
		$containers = array(
			'custom_field' => __( 'Custom fields: not scanned by the free plugin.', 'link-sentinel' ),
			'acf_field'    => __( 'ACF fields: not scanned by the free plugin.', 'link-sentinel' ),
			'blogroll'     => __( 'Blogroll (Links Manager) items: Link Sentinel does not scan them.', 'link-sentinel' ),
		);
		foreach ( $containers as $id => $reason ) {
			if ( in_array( $id, $module_ids, true ) ) {
				$skipped[ $id ] = $reason;
			}
		}
		return array(
			'post_types' => $types,
			'comments'   => in_array( 'comment', $module_ids, true ),
			'skipped'    => $skipped,
		);
	}

	/**
	 * Post statuses → Link Sentinel's single "include drafts, pending, scheduled and private" switch.
	 *
	 * @return array{include_drafts: bool, partial: bool}
	 */
	public static function map_statuses( $statuses ) {
		$statuses = array_map( 'sanitize_key', array_filter( (array) $statuses, 'is_scalar' ) );
		$picked   = array_intersect( array( 'draft', 'pending', 'future', 'private' ), $statuses );
		return array(
			'include_drafts' => (bool) $picked,
			'partial'        => $picked && count( $picked ) < 4,
		);
	}

	/**
	 * The local checker fetches each link again after check_threshold hours, which maps
	 * to Link Sentinel's re-check interval. Its background worker (run_via_cron) is on
	 * out of the box, so leaving it on is not a choice about scan frequency: the
	 * schedule stays null (keep Link Sentinel's own). Only when it was turned off, and
	 * Broken Link Checker checked just while an admin page was open, does that become
	 * manual scans here.
	 *
	 * @return array{schedule: string|null, recheck_hours: int} schedule null: no choice to import.
	 */
	public static function map_local_schedule( array $local ) {
		$hours    = isset( $local['check_threshold'] ) ? (int) $local['check_threshold'] : 72;
		$hours    = $hours > 0 ? $hours : 72;
		$via_cron = ! array_key_exists( 'run_via_cron', $local ) || ! empty( $local['run_via_cron'] );
		return array(
			'schedule'      => $via_cron ? null : 'never',
			'recheck_hours' => max( 1, min( 720, $hours ) ),
		);
	}

	/**
	 * Scans per week for a schedule name, to tell whether a change scans more often.
	 * WP-Cron's own names; anything unknown counts as manual.
	 */
	public static function scans_per_week( $schedule ) {
		$per_week = array( 'hourly' => 168, 'twicedaily' => 14, 'daily' => 7, 'weekly' => 1 );
		return isset( $per_week[ $schedule ] ) ? $per_week[ $schedule ] : 0;
	}

	/**
	 * Cloud scan schedule (blc_settings.schedule: active, frequency daily|weekly|monthly).
	 *
	 * @return array{schedule: string, approximate: bool}
	 */
	public static function map_cloud_schedule( $schedule ) {
		if ( ! is_array( $schedule ) || empty( $schedule['active'] ) ) {
			return array( 'schedule' => 'never', 'approximate' => false );
		}
		$frequency = isset( $schedule['frequency'] ) ? (string) $schedule['frequency'] : 'daily';
		if ( 'daily' === $frequency ) {
			return array( 'schedule' => 'daily', 'approximate' => false );
		}
		// Weekly stays weekly; monthly has no counterpart and becomes weekly.
		return array( 'schedule' => 'weekly', 'approximate' => 'weekly' !== $frequency );
	}

	/** Did Broken Link Checker use its Cloud scanner (rather than the local checker)? */
	public static function uses_cloud( $cloud ) {
		return is_array( $cloud ) && array_key_exists( 'use_legacy_blc_version', $cloud ) && empty( $cloud['use_legacy_blc_version'] );
	}

	/**
	 * What an import would change.
	 *
	 * @param array $source  local (array|null), cloud (array|null), dismissed (int), not_broken (int).
	 * @param array $current Link Sentinel settings as stored now (LinkSentinel_Settings::all()).
	 * @param array $env     post_types (name => label), all_types (names), schedules (value => label).
	 * @return array{mode: string, has_local: bool, items: array, skipped: string[]} Each item has
	 *         label, from, to, set (settings-form values) and optionally note (shown under "to")
	 *         and checked (false: offered but not pre-selected).
	 */
	public static function plan( array $source, array $current, array $env ) {
		$local     = isset( $source['local'] ) && is_array( $source['local'] ) ? $source['local'] : null;
		$cloud     = isset( $source['cloud'] ) && is_array( $source['cloud'] ) ? $source['cloud'] : null;
		$schedules = isset( $env['schedules'] ) ? (array) $env['schedules'] : array();
		$types     = isset( $env['post_types'] ) ? (array) $env['post_types'] : array();
		$items     = array();
		$skipped   = array();
		$cloud_on  = self::uses_cloud( $cloud );

		if ( $local ) {
			// Content to scan.
			$content = self::map_content( self::active_modules( $local ), $types, isset( $env['all_types'] ) ? (array) $env['all_types'] : array() );
			$skipped = array_merge( $skipped, $content['skipped'] );
			if ( $content['post_types'] ) {
				$now_types = array_values( (array) $current['post_types'] );
				$new_types = $content['post_types'];
				sort( $now_types );
				sort( $new_types );
				if ( $now_types !== $new_types || (bool) $current['scan_comments'] !== $content['comments'] ) {
					$items['content'] = array(
						'label' => __( 'Content to scan', 'link-sentinel' ),
						'from'  => self::describe_content( (array) $current['post_types'], ! empty( $current['scan_comments'] ), $types ),
						'to'    => self::describe_content( $content['post_types'], $content['comments'], $types ),
						'set'   => array( 'post_types' => $content['post_types'], 'scan_comments' => $content['comments'] ),
					);
				}
			}

			// Post statuses.
			$statuses = self::map_statuses( isset( $local['enabled_post_statuses'] ) ? $local['enabled_post_statuses'] : array( 'publish' ) );
			$now_drafts = count( (array) $current['post_statuses'] ) > 1;
			if ( $statuses['include_drafts'] !== $now_drafts ) {
				$items['statuses'] = array(
					'label' => __( 'Post statuses', 'link-sentinel' ),
					'from'  => self::describe_drafts( $now_drafts ),
					'to'    => self::describe_drafts( $statuses['include_drafts'] ),
					'set'   => array( 'include_drafts' => $statuses['include_drafts'] ),
				);
			}
			if ( $statuses['partial'] ) {
				$skipped[] = __( 'Post statuses: only some of draft, pending, scheduled and private were selected. Link Sentinel includes those four together or not at all.', 'link-sentinel' );
			}

			// Exclusions.
			$ex       = self::map_exclusions( isset( $local['exclusion_list'] ) ? $local['exclusion_list'] : array() );
			$existing = array_filter( array_map( 'trim', explode( "\n", (string) $current['exclusions'] ) ) );
			$lower    = array_map( 'strtolower', $existing );
			$added    = array_values( array_diff( $ex['rules'], $lower ) );
			if ( $added ) {
				$items['exclusions'] = array(
					'label' => __( 'Never check', 'link-sentinel' ),
					/* translators: %d: number of exclusion rules */
					'from'  => $existing ? sprintf( _n( '%d rule', '%d rules', count( $existing ), 'link-sentinel' ), count( $existing ) ) : __( 'None', 'link-sentinel' ),
					/* translators: %s: comma-separated list of domains and URL prefixes */
					'to'    => sprintf( __( 'Adds: %s', 'link-sentinel' ), implode( ', ', $added ) ),
					'set'   => array( 'exclusions' => implode( "\n", array_merge( $existing, $added ) ) ),
				);
			}
			foreach ( $ex['skipped'] as $word ) {
				/* translators: %s: an exclusion-list entry */
				$skipped[] = sprintf( __( 'Exclusion “%s”: Link Sentinel matches domains and URL prefixes, not words inside a URL.', 'link-sentinel' ), $word );
			}
			foreach ( $ex['wildcards'] as $word ) {
				/* translators: %s: an exclusion-list entry containing "*" */
				$skipped[] = sprintf( __( 'Exclusion “%s”: Broken Link Checker matched entries as literal text, so the * was not a wildcard and this entry excluded nothing there.', 'link-sentinel' ), $word );
			}

			// Timeout, only when it was changed from Broken Link Checker's default.
			if ( isset( $local['timeout'] ) && (int) $local['timeout'] > 0 && self::BLC_DEFAULT_TIMEOUT !== (int) $local['timeout'] ) {
				$timeout = max( 3, min( 60, (int) $local['timeout'] ) );
				if ( (int) $current['timeout'] !== $timeout ) {
					$items['timeout'] = array(
						'label' => __( 'Timeout', 'link-sentinel' ),
						/* translators: %d: seconds */
						'from'  => sprintf( __( '%d seconds', 'link-sentinel' ), (int) $current['timeout'] ),
						/* translators: %d: seconds */
						'to'    => sprintf( __( '%d seconds', 'link-sentinel' ), $timeout ),
						'set'   => array( 'timeout' => $timeout ),
					);
				}
			}

			// E-mail report.
			$notify    = ! array_key_exists( 'send_email_notifications', $local ) || ! empty( $local['send_email_notifications'] );
			$notify_to = isset( $local['notification_email_address'] ) && is_scalar( $local['notification_email_address'] ) ? sanitize_email( (string) $local['notification_email_address'] ) : '';
			$notify_to = is_email( $notify_to ) ? $notify_to : '';
			if ( $notify !== (bool) $current['notify_email'] || ( '' !== $notify_to && $notify_to !== $current['notify_to'] ) ) {
				$to = '' !== $notify_to ? $notify_to : (string) $current['notify_to'];
				$items['email'] = array(
					'label' => __( 'Email report', 'link-sentinel' ),
					'from'  => self::describe_email( ! empty( $current['notify_email'] ), (string) $current['notify_to'] ),
					'to'    => self::describe_email( $notify, $to ),
					'set'   => array( 'notify_email' => $notify, 'notify_to' => $to ),
				);
			}
			if ( ! empty( $local['send_authors_email_notifications'] ) ) {
				$skipped[] = __( 'Emails to post authors: Link Sentinel sends its report to one address.', 'link-sentinel' );
			}
			if ( ! empty( $local['mark_broken_links'] ) || ! empty( $local['nofollow_broken_links'] ) ) {
				$skipped[] = __( 'Styling broken links or adding nofollow on your pages: Link Sentinel does not change how posts are displayed.', 'link-sentinel' );
			}
		}

		// Automatic scan: from the scanner Broken Link Checker was actually using.
		$sched = null;
		if ( $cloud_on ) {
			$mapped = self::map_cloud_schedule( isset( $cloud['schedule'] ) ? $cloud['schedule'] : null );
			$sched  = array( 'schedule' => $mapped['schedule'], 'recheck_hours' => (int) $current['recheck_hours'] );
			if ( $mapped['approximate'] ) {
				$skipped[] = __( 'Monthly cloud scan: Link Sentinel has no monthly schedule, so it becomes weekly.', 'link-sentinel' );
			}
			if ( ! empty( $cloud['schedule']['active'] ) ) {
				$skipped[] = __( 'Scan day and time: Link Sentinel scans through WP-Cron and does not fix a day or hour.', 'link-sentinel' );
			}
			$skipped[] = __( 'Ignored links and exclusions set in the Cloud scanner: they are stored with the cloud service, not on this site.', 'link-sentinel' );
		} elseif ( $local ) {
			$sched = self::map_local_schedule( $local );
			if ( 'never' === $sched['schedule'] ) {
				$skipped[] = __( 'Checking only while the dashboard is open: Link Sentinel has no such mode, so automatic scans are set to manual.', 'link-sentinel' );
			} else {
				$sched['schedule'] = (string) $current['schedule'];
				/* translators: %s: Link Sentinel's current automatic scan schedule, such as Weekly */
				$skipped[] = sprintf( __( 'Checking in the background around the clock: Link Sentinel keeps its own automatic scan schedule (%s), which you can change below.', 'link-sentinel' ), isset( $schedules[ $current['schedule'] ] ) ? $schedules[ $current['schedule'] ] : (string) $current['schedule'] );
			}
		}
		if ( $sched && isset( $schedules[ $sched['schedule'] ] ) && ( $sched['schedule'] !== $current['schedule'] || (int) $sched['recheck_hours'] !== (int) $current['recheck_hours'] ) ) {
			// The email report goes out after every scheduled scan that finds broken links,
			// so more frequent scans mean more email: never pre-select that while it is on.
			$email_on   = ! empty( $current['notify_email'] ) || ! empty( $items['email']['set']['notify_email'] );
			$more_often = self::scans_per_week( $sched['schedule'] ) > self::scans_per_week( (string) $current['schedule'] );
			$item       = array(
				'label'   => __( 'Automatic scan', 'link-sentinel' ),
				'from'    => self::describe_schedule( (string) $current['schedule'], (int) $current['recheck_hours'], $schedules ),
				'to'      => self::describe_schedule( $sched['schedule'], (int) $sched['recheck_hours'], $schedules ),
				'set'     => array( 'schedule' => $sched['schedule'], 'recheck_hours' => (int) $sched['recheck_hours'] ),
				'checked' => ! ( $more_often && $email_on ),
			);
			if ( $email_on && 'never' !== $sched['schedule'] && $sched['schedule'] !== $current['schedule'] ) {
				$item['note'] = __( 'The email report follows scans: it is sent after every scheduled scan that finds broken links.', 'link-sentinel' );
			}
			$items['schedule'] = $item;
		}

		// Dismissed links. Link Sentinel's flag does not undo itself like Broken Link
		// Checker's does, so the item starts unticked.
		$dismissed  = isset( $source['dismissed'] ) ? (int) $source['dismissed'] : 0;
		$not_broken = isset( $source['not_broken'] ) ? (int) $source['not_broken'] : 0;
		if ( $dismissed > 0 ) {
			/* translators: %d: number of links */
			$count              = sprintf( _n( '%d dismissed link', '%d dismissed links', $dismissed, 'link-sentinel' ), $dismissed );
			$items['dismissed'] = array(
				'label'   => __( 'Dismissed links', 'link-sentinel' ),
				'from'    => '',
				/* translators: %s: e.g. "12 dismissed links" */
				'to'      => sprintf( __( '%s, listed under Dismissed', 'link-sentinel' ), $count ),
				'note'    => __( 'Broken Link Checker showed these links again when their status changed; Link Sentinel will not check them again until you restore them.', 'link-sentinel' ),
				'set'     => array(),
				'checked' => false,
			);
			if ( $dismissed > self::MAX_DISMISSED ) {
				/* translators: %s: maximum number of links */
				$skipped[] = sprintf( __( 'Dismissed links beyond the first %s.', 'link-sentinel' ), number_format_i18n( self::MAX_DISMISSED ) );
			}
		}
		if ( $not_broken > 0 ) {
			/* translators: %d: number of links */
			$skipped[] = sprintf( _n( '%d link marked “Not broken”: Broken Link Checker kept checking it and flagged it again when its result changed. Link Sentinel checks it like any other link.', '%d links marked “Not broken”: Broken Link Checker kept checking them and flagged them again when their result changed. Link Sentinel checks them like any other link.', $not_broken, 'link-sentinel' ), $not_broken );
		}

		return array(
			'mode'      => $cloud_on ? 'cloud' : 'local',
			'has_local' => null !== $local,
			'items'     => $items,
			'skipped'   => array_unique( $skipped ),
		);
	}

	/**
	 * The plan for this site, as the Settings screen shows it and run() applies it.
	 *
	 * Filter linksentinel_blc_import_plan( $plan, $source, $current, $env ) lets an
	 * extension add items or drop "Not imported" lines for things it handles.
	 *
	 * @param array $current Link Sentinel settings as stored now.
	 */
	public static function site_plan( array $current ) {
		$source = self::source();
		$env    = self::env();
		$plan   = apply_filters( 'linksentinel_blc_import_plan', self::plan( $source, $current, $env ), $source, $current, $env );
		return is_array( $plan ) && isset( $plan['items'], $plan['skipped'] ) ? $plan : self::plan( $source, $current, $env );
	}

	/**
	 * Settings-form input that applies the selected plan items on top of the current
	 * settings. Shaped like the Settings form (include_drafts, not post_statuses), so it
	 * goes through LinkSentinel_Settings::sanitize() like any other save; keys that
	 * belong to extensions pass through untouched.
	 */
	public static function settings_input( array $current, array $items, array $selected ) {
		$in                   = $current;
		$in['include_drafts'] = count( (array) ( isset( $current['post_statuses'] ) ? $current['post_statuses'] : array() ) ) > 1;
		unset( $in['post_statuses'] );
		foreach ( $items as $key => $item ) {
			if ( ! in_array( $key, $selected, true ) || empty( $item['set'] ) ) {
				continue;
			}
			foreach ( $item['set'] as $name => $value ) {
				$in[ $name ] = $value;
			}
		}
		return $in;
	}

	/**
	 * Broken Link Checker URLs as Link Sentinel stores them: absolute, fragment-free,
	 * http(s) only, deduplicated, and not already covered by an exclusion.
	 *
	 * @return string[]
	 */
	public static function dismissable_urls( array $urls, $home, array $rules ) {
		$out = array();
		foreach ( $urls as $url ) {
			if ( ! is_scalar( $url ) ) {
				continue;
			}
			$normal = LinkSentinel_Extractor::normalize( (string) $url, $home );
			if ( null === $normal || LinkSentinel_Extractor::is_excluded( $normal, $rules ) ) {
				continue;
			}
			$out[ $normal ] = true;
		}
		return array_keys( $out );
	}

	private static function describe_content( array $post_types, $comments, array $labels ) {
		$names = array();
		foreach ( $post_types as $t ) {
			$names[] = isset( $labels[ $t ] ) ? $labels[ $t ] : $t;
		}
		if ( $comments ) {
			$names[] = __( 'approved comments', 'link-sentinel' );
		}
		return implode( ', ', $names );
	}

	private static function describe_drafts( $include ) {
		return $include ? __( 'Published, drafts, pending, scheduled and private', 'link-sentinel' ) : __( 'Published only', 'link-sentinel' );
	}

	private static function describe_email( $on, $to ) {
		if ( ! $on ) {
			return __( 'Off', 'link-sentinel' );
		}
		return '' !== $to ? $to : __( 'Site admin email', 'link-sentinel' );
	}

	private static function describe_schedule( $schedule, $hours, array $schedules ) {
		$label = isset( $schedules[ $schedule ] ) ? $schedules[ $schedule ] : $schedule;
		/* translators: 1: schedule name such as Daily, 2: hours */
		return sprintf( __( '%1$s; re-check links after %2$d hours', 'link-sentinel' ), $label, $hours );
	}

	// ----- Reading Broken Link Checker's data (read-only) ---------------------------

	/** Is there anything of Broken Link Checker's on this site to import? */
	public static function detected() {
		foreach ( self::BLC_OPTIONS as $name ) {
			if ( null !== self::decode( get_option( $name, null ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Everything plan() needs, read from the options and tables. */
	public static function source() {
		$counts = self::dismissed_counts();
		return array(
			'local'      => self::merge_local(
				self::decode( get_option( 'wsblc_options', null ) ),
				self::decode( get_option( 'wsblc_general_options', null ) ),
				self::decode( get_option( 'wsblc_frontend_options', null ) )
			),
			'cloud'      => self::decode( get_option( 'blc_settings', null ) ),
			'dismissed'  => $counts['dismissed'],
			'not_broken' => $counts['not_broken'],
		);
	}

	/** Environment plan() maps against. */
	public static function env() {
		$labels = array();
		$types  = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		foreach ( $types as $t ) {
			$labels[ $t->name ] = $t->labels->name;
		}
		return array(
			'post_types' => $labels,
			'all_types'  => array_values( get_post_types() ),
			'schedules'  => LinkSentinel_Settings::schedules(),
		);
	}

	/** Does {prefix}blc_links exist with the columns this importer reads? */
	private static function has_links_table() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only look at another plugin's table; the name is $wpdb->prefix plus a constant
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'blc_links' ) ) ) !== $wpdb->prefix . 'blc_links' ) {
			return false;
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}blc_links" );
		// phpcs:enable
		return is_array( $columns ) && ! array_diff( array( 'url', 'dismissed', 'false_positive' ), $columns );
	}

	/** @return array{dismissed: int, not_broken: int} */
	private static function dismissed_counts() {
		global $wpdb;
		$out = array( 'dismissed' => 0, 'not_broken' => 0 );
		if ( ! self::has_links_table() ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see has_links_table()
		$row = $wpdb->get_row( "SELECT SUM(dismissed = 1) AS dismissed, SUM(dismissed = 0 AND false_positive = 1) AS not_broken FROM {$wpdb->prefix}blc_links WHERE dismissed = 1 OR false_positive = 1" );
		if ( $row ) {
			$out['dismissed']  = (int) $row->dismissed;
			$out['not_broken'] = (int) $row->not_broken;
		}
		return $out;
	}

	/** @return string[] Raw URLs of dismissed links ("Not broken" ones are not imported; see the class comment). */
	private static function dismissed_raw_urls() {
		global $wpdb;
		if ( ! self::has_links_table() ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see has_links_table()
		$urls = $wpdb->get_col( $wpdb->prepare( "SELECT url FROM {$wpdb->prefix}blc_links WHERE dismissed = 1 ORDER BY link_id ASC LIMIT %d", self::MAX_DISMISSED ) );
		return is_array( $urls ) ? $urls : array();
	}

	// ----- Import ------------------------------------------------------------------

	/**
	 * Apply the selected plan items. The plan is rebuilt here from the stored data, so
	 * nothing but item keys is taken from the request.
	 *
	 * @param string[] $selected Plan item keys.
	 * @return array The record saved in self::OPTION.
	 */
	public static function run( array $selected ) {
		$current  = LinkSentinel_Settings::all();
		$plan     = self::site_plan( $current );
		$selected = array_values( array_intersect( array_keys( $plan['items'] ), $selected ) );
		$settings = array_values( array_diff( $selected, array( 'dismissed' ) ) );

		// Recorded before anything changes, so a second submission that arrives while this
		// one is still writing finds it and stops (see handle()).
		$record = array(
			'time'      => time(),
			'imported'  => $selected,
			'dismissed' => 0,
		);
		update_option( self::OPTION, $record, false );

		if ( $settings ) {
			// Save through the sanitize callback the Settings form uses (registering it is idempotent).
			LinkSentinel_Admin::register_settings();
			update_option( LinkSentinel_Settings::OPTION, self::settings_input( $current, $plan['items'], $settings ) );
			LinkSentinel_Settings::flush();
		}

		if ( in_array( 'dismissed', $selected, true ) ) {
			$links = array();
			foreach ( self::dismissable_urls( self::dismissed_raw_urls(), home_url( '/' ), LinkSentinel_Settings::exclusions() ) as $url ) {
				$links[ $url ] = LinkSentinel_Extractor::is_internal( $url );
			}
			$record['dismissed'] = LinkSentinel_DB::dismiss_urls( $links );
			update_option( self::OPTION, $record, false );
		}

		return $record;
	}

	/** admin-post.php?action=linksentinel_import_blc */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'link-sentinel' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$done = add_query_arg( array( 'page' => LinkSentinel_Admin::PAGE . '-settings', 'lsn-blc' => 'done' ), admin_url( 'admin.php' ) );
		// Already imported or declined: a resubmitted form (Back button, a tab left open, a
		// double click) must not apply Broken Link Checker's values over later changes.
		if ( false !== get_option( self::OPTION, false ) ) {
			wp_safe_redirect( $done );
			exit;
		}
		$selected = array();
		if ( empty( $_POST['lsn_blc_skip'] ) && isset( $_POST['lsn_blc'] ) ) {
			$selected = array_map( 'sanitize_key', (array) wp_unslash( $_POST['lsn_blc'] ) );
		}
		self::run( $selected );
		wp_safe_redirect( $done );
		exit;
	}

	// ----- Settings screen ------------------------------------------------------------

	/** The offer (with its preview) or, right after an import, what was done. */
	public static function render() {
		$record = get_option( self::OPTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects which message to show
		if ( is_array( $record ) && isset( $_GET['lsn-blc'] ) ) {
			self::render_done( $record );
			return;
		}
		if ( $record || ! self::detected() ) {
			return;
		}
		$plan  = self::site_plan( LinkSentinel_Settings::all() );
		$items = $plan['items'];
		?>
		<div class="lsn-import">
			<h2><?php esc_html_e( 'Switching from Broken Link Checker?', 'link-sentinel' ); ?></h2>
			<p>
				<?php
				if ( 'cloud' === $plan['mode'] && $plan['has_local'] ) {
					esc_html_e( 'Broken Link Checker’s settings were found on this site. It was using its Cloud scanner, so the schedule comes from there; content, statuses and exclusions come from its local checker settings, which it keeps alongside. Link Sentinel can bring over what has an equivalent here.', 'link-sentinel' );
				} elseif ( 'cloud' === $plan['mode'] ) {
					esc_html_e( 'Broken Link Checker’s settings were found on this site; it was using its Cloud scanner. Link Sentinel can bring over what has an equivalent here.', 'link-sentinel' );
				} else {
					esc_html_e( 'Broken Link Checker’s settings were found on this site. Link Sentinel can bring over what has an equivalent here.', 'link-sentinel' );
				}
				echo ' ';
				esc_html_e( 'Broken Link Checker’s own settings and data are only read, never changed.', 'link-sentinel' );
				?>
			</p>
			<details>
				<summary><?php esc_html_e( 'Review what would be imported', 'link-sentinel' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<?php if ( $items ) : ?>
						<table class="widefat striped lsn-import-table">
							<thead>
								<tr>
									<td class="check-column"></td>
									<th scope="col"><?php esc_html_e( 'Setting', 'link-sentinel' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Now', 'link-sentinel' ); ?></th>
									<th scope="col"><?php esc_html_e( 'After import', 'link-sentinel' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $items as $key => $item ) : ?>
									<tr>
										<th scope="row" class="check-column"><input type="checkbox" id="lsn-blc-<?php echo esc_attr( $key ); ?>" name="lsn_blc[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! isset( $item['checked'] ) || $item['checked'] ); ?>></th>
										<td><label for="lsn-blc-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $item['label'] ); ?></strong></label></td>
										<td><?php echo esc_html( $item['from'] ); ?></td>
										<td class="lsn-import-to">
											<?php echo esc_html( $item['to'] ); ?>
											<?php if ( ! empty( $item['note'] ) ) : ?>
												<br><span class="description"><?php echo esc_html( $item['note'] ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ( isset( $items['dismissed'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'Dismissed links that no longer appear in the content Link Sentinel scans drop off after its next scan.', 'link-sentinel' ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'Nothing to change: Link Sentinel’s settings already match what can be imported.', 'link-sentinel' ); ?></p>
					<?php endif; ?>
					<?php if ( $plan['skipped'] ) : ?>
						<h3><?php esc_html_e( 'Not imported', 'link-sentinel' ); ?></h3>
						<ul class="lsn-import-skipped">
							<?php foreach ( $plan['skipped'] as $line ) : ?>
								<li><?php echo esc_html( $line ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<p>
						<?php if ( $items ) : ?>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Import selected', 'link-sentinel' ); ?></button>
						<?php endif; ?>
						<button type="submit" class="button" name="lsn_blc_skip" value="1"><?php echo $items ? esc_html__( 'Don’t import', 'link-sentinel' ) : esc_html__( 'Hide this', 'link-sentinel' ); ?></button>
					</p>
				</form>
			</details>
		</div>
		<?php
	}

	private static function render_done( array $record ) {
		$imported = isset( $record['imported'] ) ? (array) $record['imported'] : array();
		if ( ! $imported ) {
			$text = __( 'Nothing was imported from Broken Link Checker. This offer will not be shown again.', 'link-sentinel' );
		} else {
			$n = count( array_diff( $imported, array( 'dismissed' ) ) );
			$d = isset( $record['dismissed'] ) ? (int) $record['dismissed'] : 0;
			/* translators: %d: number of settings */
			$settings_text = sprintf( _n( '%d setting', '%d settings', $n, 'link-sentinel' ), $n );
			/* translators: %d: number of links */
			$links_text = sprintf( _n( '%d dismissed link', '%d dismissed links', $d, 'link-sentinel' ), $d );
			/* translators: 1: for example "3 settings", 2: for example "12 dismissed links" */
			$text = sprintf( __( 'Imported from Broken Link Checker: %1$s and %2$s. Its own settings and data were left as they were.', 'link-sentinel' ), $settings_text, $links_text );
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
