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
ok( 2 === count( $content['skipped'] ) && isset( $content['skipped']['custom_field'] ), 'blc content: non-public type and custom fields reported (custom fields keyed by module id), parsers/checkers ignored' );

// ---- Statuses ---------------------------------------------------------------------
eq( $I::map_statuses( array( 'publish' ) ), array( 'include_drafts' => false, 'partial' => false ), 'blc statuses: publish only' );
eq( $I::map_statuses( array( 'publish', 'draft' ) ), array( 'include_drafts' => true, 'partial' => true ), 'blc statuses: some draft statuses include drafts and are flagged' );
eq( $I::map_statuses( array( 'publish', 'draft', 'pending', 'future', 'private' ) ), array( 'include_drafts' => true, 'partial' => false ), 'blc statuses: all four is an exact match' );

// ---- Exclusions ---------------------------------------------------------------------
$ex = $I::map_exclusions( array( 'example.com', '*.cdn.example.net', 'example.org/*', 'HTTPS://Example.org/Private/', 'example.com/go/', 'youtube', '/wp-content/', '//old.example.com', '10.0.0.5', 'localhost', 'example.com:8080', '', '  ' ) );
eq( $ex['rules'], array( 'example.com', 'https://example.org/private/', 'http://example.com/go/', 'https://example.com/go/', 'old.example.com', '10.0.0.5' ), 'blc exclusions: domains, URLs, host/path and IPs map; duplicates dropped' );
eq( $ex['skipped'], array( 'youtube', '/wp-content/', 'localhost' ), 'blc exclusions: plain words and paths are reported, not guessed' );
eq( $ex['wildcards'], array( '*.cdn.example.net', 'example.org/*' ), 'blc exclusions: entries with * (literal text there, so they excluded nothing) are reported, not made into rules' );
$rules = $ex['rules'];
ok( LinkSentinel_Extractor::is_excluded( 'https://www.example.com/page', $rules ) && LinkSentinel_Extractor::is_excluded( 'https://example.org/private/report', $rules ) && LinkSentinel_Extractor::is_excluded( 'http://example.com/go/offer', $rules ), 'blc exclusions: mapped rules exclude what Broken Link Checker excluded' );
ok( ! LinkSentinel_Extractor::is_excluded( 'https://example.org/public/', $rules ) && ! LinkSentinel_Extractor::is_excluded( 'https://img.cdn.example.net/a.png', $rules ), 'blc exclusions: a URL prefix stays a prefix; a * entry excludes nothing, as before' );
ok( $I::is_host( 'bücher.de' ) && $I::is_host( 'xn--bcher-kva.de' ) && ! $I::is_host( 'amazon' ) && ! $I::is_host( 'exa mple.com' ), 'blc exclusions: host test (IDN yes, bare word or spaces no)' );

// ---- Schedule ---------------------------------------------------------------------------
eq( $I::map_local_schedule( array() ), array( 'schedule' => null, 'recheck_hours' => 72 ), 'blc schedule: defaults (background worker on, 72 h) are no schedule choice, only the 72 h re-check' );
eq( $I::map_local_schedule( array( 'check_threshold' => 168, 'run_via_cron' => true ) ), array( 'schedule' => null, 'recheck_hours' => 168 ), 'blc schedule: worker on keeps the schedule whatever the threshold' );
eq( $I::map_local_schedule( array( 'check_threshold' => 2000 ) ), array( 'schedule' => null, 'recheck_hours' => 720 ), 'blc schedule: threshold clamped to 720 h' );
eq( $I::map_local_schedule( array( 'check_threshold' => 24, 'run_via_cron' => false ) ), array( 'schedule' => 'never', 'recheck_hours' => 24 ), 'blc schedule: worker turned off becomes manual' );
eq( $I::map_local_schedule( array( 'check_threshold' => '24', 'run_via_cron' => '' ) ), array( 'schedule' => 'never', 'recheck_hours' => 24 ), 'blc schedule: worker off as saved in wsblc_options ("")' );
eq( $I::map_local_schedule( array( 'check_threshold' => 0 ) ), array( 'schedule' => null, 'recheck_hours' => 72 ), 'blc schedule: invalid threshold falls back to 72 h' );
ok( $I::scans_per_week( 'hourly' ) > $I::scans_per_week( 'twicedaily' ) && $I::scans_per_week( 'twicedaily' ) > $I::scans_per_week( 'daily' ) && $I::scans_per_week( 'daily' ) > $I::scans_per_week( 'weekly' ) && $I::scans_per_week( 'weekly' ) > $I::scans_per_week( 'never' ) && 0 === $I::scans_per_week( 'bogus' ), 'blc schedule: frequency order hourly > twicedaily > daily > weekly > never' );
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
	'check_threshold'            => 48,
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
eq( $plan['items']['schedule']['set'], array( 'schedule' => 'weekly', 'recheck_hours' => 48 ), 'blc plan: with its background worker on, only the re-check interval is imported; the schedule stays weekly' );
ok( ! isset( $plan['items']['schedule']['checked'] ) || true === $plan['items']['schedule']['checked'], 'blc plan: a re-check change that does not scan more often is pre-selected' );
$dis = $plan['items']['dismissed'];
ok( false === $dis['checked'] && false !== strpos( $dis['to'], '3 dismissed links' ) && false === strpos( $dis['to'], 'Not broken' ) && false !== strpos( $dis['note'], 'will not check them again' ), 'blc plan: dismissed links offered unticked, saying they will not be checked again' );
$not_broken_lines = preg_grep( '/1 link marked “Not broken”/', $plan['skipped'] );
ok( 1 === count( $not_broken_lines ), 'blc plan: "Not broken" links are listed under Not imported' );
ok( 6 === count( $plan['skipped'] ) && isset( $plan['skipped']['custom_field'] ) && 1 === count( preg_grep( '/keeps its own automatic scan schedule \(Weekly\)/', $plan['skipped'] ) ), 'blc plan: custom fields, partial statuses, word exclusion, front-end styling, background worker and "Not broken" reported' );

$all_keys = array_keys( $plan['items'] );
$saved    = LinkSentinel_Settings::sanitize( $I::settings_input( $current, $plan['items'], $all_keys ) );
ok( array( 'post' ) === $saved['post_types'] && true === $saved['scan_comments'] && 5 === count( $saved['post_statuses'] ), 'blc apply: content and statuses survive sanitize' );
ok( 'weekly' === $saved['schedule'] && 48 === $saved['recheck_hours'] && 20 === $saved['timeout'] && 'ops@example.com' === $saved['notify_to'] && "example.com\ntwitter.com" === $saved['exclusions'], 'blc apply: schedule, timeout, email and exclusions survive sanitize' );
eq( $saved['user_agent'], $current['user_agent'], 'blc apply: settings the import does not touch are kept' );
$only = LinkSentinel_Settings::sanitize( $I::settings_input( $current, $plan['items'], array( 'timeout' ) ) );
ok( 20 === $only['timeout'] && 'example.com' === $only['exclusions'] && array( 'publish' ) === $only['post_statuses'] && 72 === $only['recheck_hours'], 'blc apply: unticked items are left alone' );
$again = $I::plan( array( 'local' => $local, 'cloud' => null, 'dismissed' => 0 ), $saved, $env );
eq( array_keys( $again['items'] ), array(), 'blc plan: after importing, nothing is left to change' );

$defaults_plan = $I::plan( array( 'local' => array() ), $current, $env );
ok( ! isset( $defaults_plan['items']['schedule'] ), 'blc plan: Broken Link Checker on its defaults does not change the automatic scan (no switch from weekly to daily)' );

$cloud_plan = $I::plan( array( 'local' => null, 'cloud' => array( 'use_legacy_blc_version' => false, 'schedule' => array( 'active' => true, 'frequency' => 'monthly' ) ) ), array_merge( $current, array( 'schedule' => 'never' ) ), $env );
ok( 'cloud' === $cloud_plan['mode'] && array( 'schedule' ) === array_keys( $cloud_plan['items'] ) && 'weekly' === $cloud_plan['items']['schedule']['set']['schedule'], 'blc plan: cloud schedule is the only thing to take from a Cloud-only install' );
eq( count( $cloud_plan['skipped'] ), 3, 'blc plan: cloud monthly, day/time and cloud-side ignores reported' );
ok( false === $cloud_plan['items']['schedule']['checked'] && false !== strpos( $cloud_plan['items']['schedule']['note'], 'every scheduled scan' ), 'blc plan: scanning more often while the email report is on is offered unticked, and says reports follow scans' );
$cloud_daily = array( 'use_legacy_blc_version' => false, 'schedule' => array( 'active' => true, 'frequency' => 'daily' ) );
$quiet       = $I::plan( array( 'cloud' => $cloud_daily ), array_merge( $current, array( 'notify_email' => false ) ), $env );
ok( true === $quiet['items']['schedule']['checked'] && empty( $quiet['items']['schedule']['note'] ), 'blc plan: with the email report off, weekly to daily is pre-selected' );
$both = $I::plan( array( 'local' => array( 'send_email_notifications' => '1' ), 'cloud' => $cloud_daily ), array_merge( $current, array( 'notify_email' => false ) ), $env );
ok( ! isset( $both['items']['email'] ) && true === $both['items']['schedule']['checked'] && in_array( 'Email report settings: they belong to the local checker, which the Cloud scanner does not use.', $both['skipped'], true ), 'blc plan: in Cloud mode the local checker\'s email settings are not imported' );
$local_mail = $I::plan( array( 'local' => array( 'send_email_notifications' => '1', 'run_via_cron' => false ) ), array_merge( $current, array( 'notify_email' => false, 'schedule' => 'daily' ) ), $env );
ok( true === $local_mail['items']['email']['set']['notify_email'] && true === $local_mail['items']['schedule']['checked'], 'blc plan: a report the import turns on counts as on (fewer scans stay pre-selected)' );
$cloud_idle = $I::plan( array( 'cloud' => array( 'use_legacy_blc_version' => false, 'schedule' => array( 'active' => false, 'frequency' => 'daily' ) ) ), $current, $env );
ok( ! isset( $cloud_idle['items']['schedule'] ) && (bool) array_filter( $cloud_idle['skipped'], function ( $l ) { return 0 === strpos( $l, 'Cloud scanner without a scan schedule' ); } ), 'blc plan: an inactive cloud schedule keeps Link Sentinel\'s own schedule' );
$manual = $I::plan( array( 'local' => array( 'run_via_cron' => false ) ), $current, $env );
ok( isset( $manual['items']['schedule'] ) && 'never' === $manual['items']['schedule']['set']['schedule'] && true === $manual['items']['schedule']['checked'] && empty( $manual['items']['schedule']['note'] ), 'blc plan: dashboard-only checking becomes manual scans, pre-selected (fewer scans)' );
$strings = $I::plan( array( 'local' => array( 'run_via_cron' => '1', 'check_threshold' => '168', 'timeout' => '30', 'send_email_notifications' => '', 'enabled_post_statuses' => array( 'publish' ), 'exclusion_list' => array() ) ), $current, $env );
ok( 'weekly' === $strings['items']['schedule']['set']['schedule'] && 168 === $strings['items']['schedule']['set']['recheck_hours'] && ! isset( $strings['items']['timeout'] ) && false === $strings['items']['email']['set']['notify_email'], 'blc plan: string values as Broken Link Checker saves them ("1", "", "168"); its default timeout is not imported' );

eq(
	$I::dismissable_urls( array( 'https://example.org/a#frag', 'https://example.org/a', 'mailto:x@example.org', 'https://skip.example.com/x', '/relative/path', array() ), $home, array( 'skip.example.com' ) ),
	array( 'https://example.org/a', untrailingslashit( $home ) . '/relative/path' ),
	'blc dismissed: normalised like scanned links, deduplicated, non-http and excluded dropped'
);

// ---- Dismissing in bulk ---------------------------------------------------------------------------
global $wpdb;
$bulk = array();
for ( $i = 0; $i < 5; $i++ ) {
	$bulk[ 'https://bulk' . $i . '.example.com/x' ] = false;
}
$bulk_known = LinkSentinel_DB::upsert_link( 'https://bulk0.example.com/x', 0 );
LinkSentinel_DB::save_result( $bulk_known, array( 'status' => 'broken', 'http_code' => 404 ) );
$q0 = $wpdb->num_queries;
eq( LinkSentinel_DB::dismiss_urls( $bulk, 2 ), 5, 'dismiss_urls: every URL written across chunks of 2' );
eq( $wpdb->num_queries - $q0, 3, 'dismiss_urls: one statement per chunk (5 URLs, chunks of 2: 3 queries)' );
$bulk_rows = array_map( array( 'LinkSentinel_DB', 'link_by_url' ), array_keys( $bulk ) );
ok( 5 === count( array_filter( $bulk_rows, function ( $r ) { return $r && 1 === (int) $r->dismissed; } ) ), 'dismiss_urls: all of them dismissed' );
ok( (int) $bulk_rows[0]->id === $bulk_known && 'broken' === $bulk_rows[0]->status && 404 === (int) $bulk_rows[0]->http_code, 'dismiss_urls: a stored link keeps its row and result and only gains the flag' );
ok( 'bulk3.example.com' === $bulk_rows[3]->host && md5( 'https://bulk3.example.com/x' ) === $bulk_rows[3]->url_hash && 'unchecked' === $bulk_rows[3]->status && 0 === (int) $bulk_rows[3]->is_internal, 'dismiss_urls: new rows are stored like upsert_link() stores them' );
$q0 = $wpdb->num_queries;
eq( LinkSentinel_DB::dismiss_urls( $bulk ), 5, 'dismiss_urls: running it again is harmless' );
ok( 1 === $wpdb->num_queries - $q0 && 5 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}linksentinel_links WHERE host LIKE 'bulk%.example.com'" ), 'dismiss_urls: default chunk is one statement, and no duplicate rows' );
foreach ( $bulk_rows as $row ) {
	if ( $row ) {
		LinkSentinel_DB::delete_link( (int) $row->id );
	}
}

// ---- Round trip against a stand-in of its option and table ------------------------------------------
$blc_table = $wpdb->prefix . 'blc_links';
if ( $I::detected() || $blc_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $blc_table ) ) ) ) {
	echo "SKIP  blc round trip (this site has real Broken Link Checker data)\n";
} else {
	$prev_ls  = get_option( LinkSentinel_Settings::OPTION, array() );
	$had_san  = has_filter( 'sanitize_option_' . LinkSentinel_Settings::OPTION );
	$prev_uid = get_current_user_id();
	$blc_json = wp_json_encode( array_merge( $local, array( 'exclusion_list' => array( 'twitter.com', 'affiliate', '*.cdn.example.net' ) ) ) );
	add_option( 'wsblc_options', $blc_json, '', false );
	$wpdb->query( "CREATE TABLE {$blc_table} ( link_id int unsigned NOT NULL AUTO_INCREMENT, url text NOT NULL, dismissed tinyint(1) NOT NULL DEFAULT 0, false_positive tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (link_id) )" );
	$wpdb->insert( $blc_table, array( 'url' => 'https://gone.example.com/page#section', 'dismissed' => 1 ) );
	$wpdb->insert( $blc_table, array( 'url' => 'https://flaky.example.com/', 'false_positive' => 1 ) );
	$wpdb->insert( $blc_table, array( 'url' => 'https://fine.example.com/' ) );
	$wpdb->insert( $blc_table, array( 'url' => 'https://also-gone.example.com/x', 'dismissed' => 1 ) );
	$blc_rows = $wpdb->get_results( "SELECT * FROM {$blc_table} ORDER BY link_id", ARRAY_A );
	delete_option( $I::OPTION );
	LinkSentinel_Settings::flush();
	$before = LinkSentinel_Settings::all();
	// Link Sentinel already knows one of the dismissed URLs from its own scan.
	$known = LinkSentinel_DB::upsert_link( 'https://gone.example.com/page', 0 );
	LinkSentinel_DB::save_result( $known, array( 'status' => 'broken', 'http_code' => 404 ) );

	ok( $I::detected(), 'blc import: wsblc_options is detected' );
	ob_start();
	$I::render();
	$offer = ob_get_clean();
	ok( false !== strpos( $offer, 'lsn_blc[]' ) && false !== strpos( $offer, $I::ACTION ) && false !== strpos( $offer, '_wpnonce' ), 'blc import: settings screen offers a nonce-protected preview form' );
	$box = function ( $key ) use ( $offer ) {
		return preg_match( '/<input[^>]*value="' . preg_quote( $key, '/' ) . '"[^>]*>/', $offer, $m ) ? $m[0] : '';
	};
	ok( '' !== $box( 'dismissed' ) && false === strpos( $box( 'dismissed' ), 'checked' ) && false !== strpos( $box( 'exclusions' ), "checked='checked'" ), 'blc import: the dismissed-links row starts unticked, others ticked' );
	ok( false !== strpos( $offer, 'will not check them again' ) && false !== strpos( $offer, '*.cdn.example.net' ) && false !== strpos( $offer, 'link marked “Not broken”' ), 'blc import: the preview explains dismissed links, the * entry and "Not broken" links' );

	// Post the form to the real handler; its redirect is turned into an exception so the test survives the exit.
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $admins ? (int) $admins[0] : 0 );
	$submit = function ( array $fields ) use ( $I ) {
		$_POST    = array_merge( array( 'action' => $I::ACTION, '_wpnonce' => wp_create_nonce( $I::ACTION ) ), $fields );
		$_REQUEST = $_POST;
		$stop     = function ( $location ) {
			throw new RuntimeException( $location );
		};
		$to = null;
		add_filter( 'wp_redirect', $stop, 1 );
		try {
			$I::handle();
		} catch ( RuntimeException $e ) {
			$to = $e->getMessage();
			// apply_filters() did not get to pop its hook name.
			if ( 'wp_redirect' === end( $GLOBALS['wp_current_filter'] ) ) {
				array_pop( $GLOBALS['wp_current_filter'] );
			}
		}
		remove_filter( 'wp_redirect', $stop, 1 );
		$_POST    = array();
		$_REQUEST = array();
		return $to;
	};

	$to  = $submit( array( 'lsn_blc' => array( 'exclusions', 'schedule', 'dismissed', 'no-such-item' ) ) );
	$rec = get_option( $I::OPTION );
	$ls  = LinkSentinel_Settings::all();
	ok( is_string( $to ) && false !== strpos( $to, 'lsn-blc=done' ), 'blc import: the handler imports and returns to Settings' );
	ok( in_array( 'twitter.com', LinkSentinel_Settings::exclusions(), true ) && ! in_array( 'cdn.example.net', LinkSentinel_Settings::exclusions(), true ) && 48 === (int) $ls['recheck_hours'] && $before['schedule'] === $ls['schedule'], 'blc import: selected settings saved; the * entry is not a rule; the schedule is left as it was' );
	ok( is_array( $rec ) && ! in_array( 'no-such-item', $rec['imported'], true ) && ! in_array( 'timeout', $rec['imported'], true ), 'blc import: only real, selected items applied' );
	eq( is_array( $rec ) ? $rec['dismissed'] : null, 2, 'blc import: its dismissed links imported, "Not broken" ones not' );
	$gone  = LinkSentinel_DB::link_by_url( 'https://gone.example.com/page' );
	$also  = LinkSentinel_DB::link_by_url( 'https://also-gone.example.com/x' );
	ok( $gone && (int) $gone->id === $known && 1 === (int) $gone->dismissed && 'broken' === $gone->status && $also && 1 === (int) $also->dismissed, 'blc import: they are dismissed in Link Sentinel; a link it already had keeps its row and result' );
	ok( null === LinkSentinel_DB::link_by_url( 'https://flaky.example.com/' ) && null === LinkSentinel_DB::link_by_url( 'https://fine.example.com/' ), 'blc import: "Not broken" and ordinary links are not touched' );
	ok( get_option( 'wsblc_options' ) === $blc_json && $wpdb->get_results( "SELECT * FROM {$blc_table} ORDER BY link_id", ARRAY_A ) === $blc_rows, 'blc import: its option and table are unchanged' );
	ob_start();
	$I::render();
	eq( ob_get_clean(), '', 'blc import: the offer is not shown again' );

	// The same form posted again later (Back button, a tab left open) after the user changed things.
	LinkSentinel_DB::set_dismissed( $known, false );
	update_option( LinkSentinel_Settings::OPTION, array_merge( $I::settings_input( LinkSentinel_Settings::all(), array(), array() ), array( 'exclusions' => '', 'recheck_hours' => 100 ) ) );
	LinkSentinel_Settings::flush();
	$settings_now = get_option( LinkSentinel_Settings::OPTION );
	$to           = $submit( array( 'lsn_blc' => array( 'exclusions', 'schedule', 'timeout', 'dismissed' ) ) );
	ok( is_string( $to ) && false !== strpos( $to, 'lsn-blc=done' ) && get_option( LinkSentinel_Settings::OPTION ) === $settings_now && get_option( $I::OPTION ) === $rec && 0 === (int) LinkSentinel_DB::link_by_url( 'https://gone.example.com/page' )->dismissed, 'blc import: a resubmitted form after the import redirects and changes nothing' );

	// Declined, then the import form posted anyway.
	delete_option( $I::OPTION );
	$submit( array( 'lsn_blc_skip' => '1', 'lsn_blc' => array( 'exclusions' ) ) );
	$declined = get_option( $I::OPTION );
	ok( is_array( $declined ) && array() === $declined['imported'] && get_option( LinkSentinel_Settings::OPTION ) === $settings_now, 'blc import: declining records it and changes nothing' );
	$to = $submit( array( 'lsn_blc' => array( 'exclusions', 'schedule' ) ) );
	ok( is_string( $to ) && get_option( LinkSentinel_Settings::OPTION ) === $settings_now && get_option( $I::OPTION ) === $declined, 'blc import: an import posted after declining changes nothing' );

	// cleanup
	wp_set_current_user( $prev_uid );
	if ( ! $had_san ) {
		unregister_setting( 'linksentinel', LinkSentinel_Settings::OPTION );
	}
	update_option( LinkSentinel_Settings::OPTION, $prev_ls );
	LinkSentinel_Settings::flush();
	delete_option( $I::OPTION );
	delete_option( 'wsblc_options' );
	foreach ( array( 'https://gone.example.com/page', 'https://also-gone.example.com/x' ) as $u ) {
		$row = LinkSentinel_DB::link_by_url( $u );
		if ( $row ) {
			LinkSentinel_DB::delete_link( (int) $row->id );
		}
	}
	$wpdb->query( "DROP TABLE IF EXISTS {$blc_table}" );
}
