<?php
/**
 * Removes everything Link Sentinel stored: two tables, options, cron events.
 *
 * @package LinkSentinel
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}linksentinel_occurrences" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}linksentinel_links" );
// phpcs:enable
delete_option( 'linksentinel_settings' );
delete_option( 'linksentinel_scan' );
delete_option( 'linksentinel_db_version' );
delete_transient( 'linksentinel_lock' );
wp_clear_scheduled_hook( 'linksentinel_tick' );
wp_clear_scheduled_hook( 'linksentinel_scheduled_scan' );
