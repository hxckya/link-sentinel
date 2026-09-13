<?php
/**
 * Import from Broken Link Checker. Included from run.php; shares its scope: ok(), eq(), $home.
 * The mapping checks call the pure functions with hand-built data shaped like what
 * Broken Link Checker stores; the round trip at the end uses a throwaway copy of its
 * option and table, and is skipped on a site that has real Broken Link Checker data.
 */
$I = 'LinkSentinel_Import_BLC';

// ---- Decoding and merging its options -----------------------------------------
eq( $I::decode( '{"exclusion_list":["a.example"],"run_via_cron":true}' ), array( 'exclusion_list' => array( 'a.example' ), 'run_via_cron' => true ), 'blc decode: JSON string, as its config manager saves it' );
eq( $I::decode( array( 'x' => 1 ) ), array( 'x' => 1 ), 'blc decode: an array passes through' );
eq( $I::decode( 'not json' ), null, 'blc decode: garbage is null' );
eq( $I::decode( false ), null, 'blc decode: missing option is null' );
eq( $I::merge_local( null, null, null ), null, 'blc merge: nothing stored is null' );
$merged = $I::merge_local(
	array( 'exclusion_list' => array( 'old.example' ), 'check_threshold' => 48, 'active_modules' => array( 'post' => array( 'Name' => 'Posts' ) ) ),
	array( 'exclusion_list' => array( 'new.example' ) ),
	array( 'active_modules' => array( 'page' ) )
);
ok( array( 'new.example' ) === $merged['exclusion_list'] && 48 === $merged['check_threshold'] && array( 'page' ) === $merged['active_modules'], 'blc merge: 2.4.9+ split options win over wsblc_options, keys only in the old one survive' );

// ---- Modules ("Look for links in") ----------------------------------------------
eq( $I::active_modules( array( 'active_modules' => array( 'post' => array( 'ModuleCategory' => 'container' ), 'http' => array(), 'comment' => array() ) ) ), array( 'post', 'http', 'comment' ), 'blc modules: id => header map (up to 2.4.8)' );
eq( $I::active_modules( array( 'active_modules' => array( 'page', 'link' ) ) ), array( 'page', 'link' ), 'blc modules: list of ids (2.4.9+)' );
eq( $I::active_modules( array() ), array( 'post', 'page', 'comment' ), 'blc modules: none stored falls back to its defaults' );
$content = $I::map_content( array( 'post', 'product', 'comment', 'custom_field', 'http', 'wp_block' ), array( 'post' => 'Posts', 'page' => 'Pages', 'product' => 'Products' ), array( 'post', 'page', 'product', 'wp_block' ) );
eq( $content['post_types'], array( 'post', 'product' ), 'blc content: active post-type modules that are public become post types' );
eq( $content['comments'], true, 'blc content: comment module turns on comments' );
eq( count( $content['skipped'] ), 2, 'blc content: non-public type and custom fields reported, parsers/checkers ignored' );

// ---- Statuses ---------------------------------------------------------------------
eq( $I::map_statuses( array( 'publish' ) ), array( 'include_drafts' => false, 'partial' => false ), 'blc statuses: publish only' );
eq( $I::map_statuses( array( 'publish', 'draft' ) ), array( 'include_drafts' => true, 'partial' => true ), 'blc statuses: some draft statuses include drafts and are flagged' );
eq( $I::map_statuses( array( 'publish', 'draft', 'pending', 'future', 'private' ) ), array( 'include_drafts' => true, 'partial' => false ), 'blc statuses: all four is an exact match' );

// ---- Exclusions ---------------------------------------------------------------------
$ex = $I::map_exclusions( array( 'example.com', '*.cdn.example.net', 'HTTPS://Example.org/Private/', 'example.com/go/', 'youtube', '/wp-content/', '//old.example.com', '10.0.0.5', 'localhost', 'example.com:8080', '', '  ' ) );
eq( $ex['rules'], array( 'example.com', 'cdn.example.net', 'https://example.org/private/', 'http://example.com/go/', 'https://example.com/go/', 'old.example.com', '10.0.0.5' ), 'blc exclusions: domains, wildcards, URLs, host/path and IPs map; duplicates dropped' );
eq( $ex['skipped'], array( 'youtube', '/wp-content/', 'localhost' ), 'blc exclusions: plain words and paths are reported, not guessed' );
$rules = $ex['rules'];
ok( LinkSentinel_Extractor::is_excluded( 'https://www.example.com/page', $rules ) && LinkSentinel_Extractor::is_excluded( 'https://example.org/private/report', $rules ) && LinkSentinel_Extractor::is_excluded( 'http://example.com/go/offer', $rules ), 'blc exclusions: mapped rules exclude what Broken Link Checker excluded' );
ok( ! LinkSentinel_Extractor::is_excluded( 'https://example.org/public/', $rules ), 'blc exclusions: a URL prefix stays a prefix' );
ok( $I::is_host( 'bücher.de' ) && $I::is_host( 'xn--bcher-kva.de' ) && ! $I::is_host( 'amazon' ) && ! $I::is_host( 'exa mple.com' ), 'blc exclusions: host test (IDN yes, bare word or spaces no)' );

// ---- Schedule ---------------------------------------------------------------------------
eq( $I::map_local_schedule( array() ), array( 'schedule' => 'daily', 'recheck_hours' => 72 ), 'blc schedule: defaults (hourly worker, 72 h) become daily, 72 h' );
eq( $I::map_local_schedule( array( 'check_threshold' => 168, 'run_via_cron' => true ) ), array( 'schedule' => 'weekly', 'recheck_hours' => 168 ), 'blc schedule: a week or more becomes weekly' );
eq( $I::map_local_schedule( array( 'check_threshold' => 2000 ) ), array( 'schedule' => 'weekly', 'recheck_hours' => 720 ), 'blc schedule: threshold clamped to 720 h' );
eq( $I::map_local_schedule( array( 'check_threshold' => 24, 'run_via_cron' => false ) ), array( 'schedule' => 'never', 'recheck_hours' => 24 ), 'blc schedule: no cron worker becomes manual' );
eq( $I::map_local_schedule( array( 'check_threshold' => 0 ) ), array( 'schedule' => 'daily', 'recheck_hours' => 72 ), 'blc schedule: invalid threshold falls back to 72 h' );
eq( $I::map_cloud_schedule( null ), array( 'schedule' => 'never', 'approximate' => false ), 'blc cloud: no schedule is manual' );
eq( $I::map_cloud_schedule( array( 'active' => false, 'frequency' => 'daily' ) ), array( 'schedule' => 'never', 'approximate' => false ), 'blc cloud: inactive schedule is manual' );
eq( $I::map_cloud_schedule( array( 'active' => true, 'frequency' => 'weekly' ) ), array( 'schedule' => 'weekly', 'approximate' => false ), 'blc cloud: weekly' );
eq( $I::map_cloud_schedule( array( 'active' => true, 'frequency' => 'monthly' ) ), array( 'schedule' => 'weekly', 'approximate' => true ), 'blc cloud: monthly becomes weekly, flagged' );
ok( $I::uses_cloud( array( 'use_legacy_blc_version' => false ) ) && ! $I::uses_cloud( array( 'use_legacy_blc_version' => true ) ) && ! $I::uses_cloud( array() ) && ! $I::uses_cloud( null ), 'blc cloud: mode read from use_legacy_blc_version' );

// ---- Plan and apply ------------------------------------------------------------------------
$env     = array( 'post_types' => array( 'post' => 'Posts', 'page' => 'Pages' ), 'all_types' => array( 'post', 'page' ), 'schedules' => array( 'weekly' => 'Weekly', 'daily' => 'Daily', 'never' => 'Never' ) );
$current = array_merge( LinkSentinel_Settings::defaults(), array( 'post_types' => array( 'post', 'page' ), 'post_statuses' => array( 'publish' ), 'scan_comments' => false, 'exclusions' => 'example.com', 'schedule' => 'weekly', 'recheck_hours' => 72, 'timeout' => 10, 'notify_email' => true, 'notify_to' => '' ) );
$local   = array(
	'active_modules'             => array( 'post' => array(), 'comment' => array(), 'custom_field' => array() ),
	'enabled_post_statuses'      => array( 'publish', 'draft' ),
	'exclusion_list'             => array( 'Example.com', 'twitter.com', 'affiliate' ),
	'check_threshold'            => 72,
	'run_via_cron'               => true,
	'timeout'                    => 20,
	'send_email_notifications'   => true,
	'notification_email_address' => 'ops@example.com',
	'mark_broken_links'          => true,
);
$plan = $I::plan( array( 'local' => $local, 'cloud' => null, 'dismissed' => 3, 'not_broken' => 1 ), $current, $env );
eq( $plan['mode'], 'local', 'blc plan: local mode' );
eq( array_keys( $plan['items'] ), array( 'content', 'statuses', 'exclusions', 'timeout', 'email', 'schedule', 'dismissed' ), 'blc plan: every mappable difference is an item' );
eq( $plan['items']['exclusions']['set']['exclusions'], "example.com\ntwitter.com", 'blc plan: exclusions appended, an existing rule not repeated' );
ok( false !== strpos( $plan['items']['dismissed']['to'], '3 dismissed links' ) && false !== strpos( $plan['items']['dismissed']['to'], '1 link marked' ), 'blc plan: dismissed and "Not broken" counted separately' );
eq( count( $plan['skipped'] ), 4, 'blc plan: custom fields, partial statuses, word exclusion and front-end styling reported' );

$all_keys = array_keys( $plan['items'] );
$saved    = LinkSentinel_Settings::sanitize( $I::settings_input( $current, $plan['items'], $all_keys ) );
ok( array( 'post' ) === $saved['post_types'] && true === $saved['scan_comments'] && 5 === count( $saved['post_statuses'] ), 'blc apply: content and statuses survive sanitize' );
ok( 'daily' === $saved['schedule'] && 72 === $saved['recheck_hours'] && 20 === $saved['timeout'] && 'ops@example.com' === $saved['notify_to'] && "example.com\ntwitter.com" === $saved['exclusions'], 'blc apply: schedule, timeout, email and exclusions survive sanitize' );
eq( $saved['user_agent'], $current['user_agent'], 'blc apply: settings the import does not touch are kept' );
$only = LinkSentinel_Settings::sanitize( $I::settings_input( $current, $plan['items'], array( 'timeout' ) ) );
ok( 20 === $only['timeout'] && 'example.com' === $only['exclusions'] && array( 'publish' ) === $only['post_statuses'] && 'weekly' === $only['schedule'], 'blc apply: unticked items are left alone' );
$again = $I::plan( array( 'local' => $local, 'cloud' => null, 'dismissed' => 0 ), $saved, $env );
eq( array_keys( $again['items'] ), array(), 'blc plan: after importing, nothing is left to change' );

$cloud_plan = $I::plan( array( 'local' => null, 'cloud' => array( 'use_legacy_blc_version' => false, 'schedule' => array( 'active' => true, 'frequency' => 'monthly' ) ) ), array_merge( $current, array( 'schedule' => 'never' ) ), $env );
ok( 'cloud' === $cloud_plan['mode'] && array( 'schedule' ) === array_keys( $cloud_plan['items'] ) && 'weekly' === $cloud_plan['items']['schedule']['set']['schedule'], 'blc plan: cloud schedule is the only thing to take from a Cloud-only install' );
eq( count( $cloud_plan['skipped'] ), 3, 'blc plan: cloud monthly, day/time and cloud-side ignores reported' );
$manual = $I::plan( array( 'local' => array( 'run_via_cron' => false ) ), $current, $env );
ok( isset( $manual['items']['schedule'] ) && 'never' === $manual['items']['schedule']['set']['schedule'], 'blc plan: dashboard-only checking becomes manual scans' );
$strings = $I::plan( array( 'local' => array( 'run_via_cron' => '1', 'check_threshold' => '168', 'timeout' => '30', 'send_email_notifications' => '', 'enabled_post_statuses' => array( 'publish' ), 'exclusion_list' => array() ) ), $current, $env );
ok( 'weekly' === $strings['items']['schedule']['set']['schedule'] && 168 === $strings['items']['schedule']['set']['recheck_hours'] && ! isset( $strings['items']['timeout'] ) && false === $strings['items']['email']['set']['notify_email'], 'blc plan: string values as Broken Link Checker saves them ("1", "", "168"); its default timeout is not imported' );

eq(
	$I::dismissable_urls( array( 'https://example.org/a#frag', 'https://example.org/a', 'mailto:x@example.org', 'https://skip.example.com/x', '/relative/path', array() ), $home, array( 'skip.example.com' ) ),
	array( 'https://example.org/a', untrailingslashit( $home ) . '/relative/path' ),
	'blc dismissed: normalised like scanned links, deduplicated, non-http and excluded dropped'
);

// ---- Round trip against a stand-in of its option and table ------------------------------------------
global $wpdb;
$blc_table = $wpdb->prefix . 'blc_links';
if ( $I::detected() || $blc_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $blc_table ) ) ) ) {
	echo "SKIP  blc round trip (this site has real Broken Link Checker data)\n";
} else {
	$prev_ls  = get_option( LinkSentinel_Settings::OPTION, array() );
	$had_san  = has_filter( 'sanitize_option_' . LinkSentinel_Settings::OPTION );
	$blc_json = wp_json_encode( array_merge( $local, array( 'exclusion_list' => array( 'twitter.com', 'affiliate' ) ) ) );
	add_option( 'wsblc_options', $blc_json, '', false );
	$wpdb->query( "CREATE TABLE {$blc_table} ( link_id int unsigned NOT NULL AUTO_INCREMENT, url text NOT NULL, dismissed tinyint(1) NOT NULL DEFAULT 0, false_positive tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (link_id) )" );
	$wpdb->insert( $blc_table, array( 'url' => 'https://gone.example.com/page#section', 'dismissed' => 1 ) );
	$wpdb->insert( $blc_table, array( 'url' => 'https://flaky.example.com/', 'false_positive' => 1 ) );
	$wpdb->insert( $blc_table, array( 'url' => 'https://fine.example.com/' ) );
	$blc_rows = $wpdb->get_results( "SELECT * FROM {$blc_table} ORDER BY link_id", ARRAY_A );
	delete_option( $I::OPTION );
	LinkSentinel_Settings::flush();

	ok( $I::detected(), 'blc import: wsblc_options is detected' );
	ob_start();
	$I::render();
	$offer = ob_get_clean();
	ok( false !== strpos( $offer, 'lsn_blc[]' ) && false !== strpos( $offer, $I::ACTION ) && false !== strpos( $offer, '_wpnonce' ), 'blc import: settings screen offers a nonce-protected preview form' );

	$rec = $I::run( array( 'exclusions', 'schedule', 'dismissed', 'no-such-item' ) );
	$ls  = LinkSentinel_Settings::all();
	ok( in_array( 'twitter.com', LinkSentinel_Settings::exclusions(), true ) && 'daily' === $ls['schedule'], 'blc import: selected settings saved' );
	ok( ! in_array( 'no-such-item', $rec['imported'], true ) && ! in_array( 'timeout', $rec['imported'], true ), 'blc import: only real, selected items applied' );
	eq( $rec['dismissed'], 2, 'blc import: dismissed and "Not broken" links imported' );
	$gone  = LinkSentinel_DB::link_by_url( 'https://gone.example.com/page' );
	$flaky = LinkSentinel_DB::link_by_url( 'https://flaky.example.com/' );
	ok( $gone && 1 === (int) $gone->dismissed && $flaky && 1 === (int) $flaky->dismissed && null === LinkSentinel_DB::link_by_url( 'https://fine.example.com/' ), 'blc import: they are dismissed in Link Sentinel, other links are not touched' );
	ok( get_option( 'wsblc_options' ) === $blc_json && $wpdb->get_results( "SELECT * FROM {$blc_table} ORDER BY link_id", ARRAY_A ) === $blc_rows, 'blc import: its option and table are unchanged' );
	ob_start();
	$I::render();
	eq( ob_get_clean(), '', 'blc import: the offer is not shown again' );

	// cleanup
	if ( ! $had_san ) {
		unregister_setting( 'linksentinel', LinkSentinel_Settings::OPTION );
	}
	update_option( LinkSentinel_Settings::OPTION, $prev_ls );
	LinkSentinel_Settings::flush();
	delete_option( $I::OPTION );
	delete_option( 'wsblc_options' );
	foreach ( array( $gone, $flaky ) as $row ) {
		if ( $row ) {
			LinkSentinel_DB::delete_link( (int) $row->id );
		}
	}
	$wpdb->query( "DROP TABLE IF EXISTS {$blc_table}" );
}
