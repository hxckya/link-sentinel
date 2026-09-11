<?php
/**
 * Pro checks. Included from run.php when LinkSentinel_License::can_use_pro().
 * Shares run.php's scope: ok(), eq(), $home.
 */
$pro_prev = get_option( LinkSentinel_Settings::OPTION, array() );
$set      = function ( array $over ) use ( $pro_prev ) {
	update_option( LinkSentinel_Settings::OPTION, array_merge( is_array( $pro_prev ) ? $pro_prev : array(), $over ) );
	LinkSentinel_Settings::flush();
};

// ---- Schedules and settings -------------------------------------------------
$sch = LinkSentinel_Settings::schedules();
ok( isset( $sch['hourly'], $sch['twicedaily'], $sch['never'] ) && 'never' === array_key_last( $sch ), 'pro schedules: hourly and twice-daily offered, Never stays last' );
$san = LinkSentinel_Settings::sanitize( array( 'schedule' => 'hourly', 'notify_webhook' => ' https://hooks.slack.com/services/T/B/x ', 'scan_meta' => '1' ) );
eq( $san['schedule'], 'hourly', 'pro sanitize: hourly kept' );
eq( $san['notify_webhook'], 'https://hooks.slack.com/services/T/B/x', 'pro sanitize: webhook trimmed and kept' );
eq( $san['scan_meta'], true, 'pro sanitize: scan_meta is a bool' );
eq( LinkSentinel_Settings::sanitize( array( 'notify_webhook' => 'javascript:alert(1)' ) )['notify_webhook'], '', 'pro sanitize: non-http webhook dropped' );

// ---- Redirects ---------------------------------------------------------------
LinkSentinel_Redirects::install();
eq( LinkSentinel_Redirects::key_for( $home . 'a/b/?x=1' ), '/a/b?x=1', 'redirects: key is path plus query, no trailing slash' );
$rid = LinkSentinel_Redirects::add( $home . 'old-page/', '/about/' );
ok( is_int( $rid ) && $rid > 0, 'redirects: rule created from an internal URL' );
$rule = LinkSentinel_Redirects::for_url( $home . 'old-page' );
ok( $rule && home_url( '/about/' ) === $rule->to_url && 301 === (int) $rule->http_code, 'redirects: lookup ignores the trailing slash; relative target made absolute' );
ok( is_wp_error( LinkSentinel_Redirects::add( 'https://example.com/x', '/about/' ) ), 'redirects: external URLs refused' );
ok( is_wp_error( LinkSentinel_Redirects::add( $home . 'same/', '/same' ) ), 'redirects: self-loop refused' );
ok( is_wp_error( LinkSentinel_Redirects::add( $home, '/about/' ) ), 'redirects: home page refused' );
$captured = null;
$cap      = function ( $loc ) use ( &$captured ) { $captured = $loc; return false; };
// WP-CLI warns on any redirect; the capture filter below is the assertion.
if ( function_exists( 'WP_CLI\\Utils\\wp_redirect_handler' ) ) {
	remove_filter( 'wp_redirect', 'WP_CLI\\Utils\\wp_redirect_handler' );
}
add_filter( 'wp_redirect', $cap );
LinkSentinel_Redirects::redirect_to( $rule );
remove_filter( 'wp_redirect', $cap );
eq( $captured, $rule->to_url, 'redirects: a hit sends to the target' );
eq( (int) LinkSentinel_Redirects::for_url( $home . 'old-page/' )->hits, 1, 'redirects: hits counted' );
LinkSentinel_Redirects::add( $home . 'old-page/', 'https://example.com/moved', 302 );
$again = LinkSentinel_Redirects::for_url( $home . 'old-page' );
ok( 'https://example.com/moved' === $again->to_url && 302 === (int) $again->http_code && (int) $again->id === (int) $rule->id, 'redirects: re-adding updates the rule in place' );

$lid = LinkSentinel_DB::upsert_link( $home . 'gone-for-good/', 1 );
LinkSentinel_DB::save_result( $lid, array( 'status' => 'broken', 'http_code' => 404 ) );
$req = new WP_REST_Request( 'POST', '/link-sentinel/v1/links/' . $lid . '/redirect' );
$req->set_param( 'id', $lid );
$req->set_param( 'url', '/about/' );
$res = LinkSentinel_Redirects::rest_add( $req );
ok( ! is_wp_error( $res ) && LinkSentinel_Redirects::for_url( $home . 'gone-for-good' ), 'redirects: REST creates a rule for a broken internal link' );
$acts = apply_filters( 'linksentinel_row_actions', array(), LinkSentinel_DB::get_link( $lid ) );
ok( isset( $acts['redirect'] ) && false !== strpos( $acts['redirect'], 'Change redirect' ), 'redirects: row action reflects the existing rule' );
$ext = LinkSentinel_DB::upsert_link( 'https://example.com/ext-broken', 0 );
LinkSentinel_DB::save_result( $ext, array( 'status' => 'broken', 'http_code' => 404 ) );
ok( ! isset( apply_filters( 'linksentinel_row_actions', array(), LinkSentinel_DB::get_link( $ext ) )['redirect'] ), 'redirects: no row action for external links' );
LinkSentinel_Redirects::delete( (int) $rule->id );
LinkSentinel_Redirects::delete( (int) LinkSentinel_Redirects::for_url( $home . 'gone-for-good' )->id );
LinkSentinel_DB::delete_link( $lid );
LinkSentinel_DB::delete_link( $ext );

// ---- Custom fields -------------------------------------------------------------
$set( array( 'scan_meta' => true ) );
$mp = wp_insert_post( array( 'post_title' => 'Meta post', 'post_status' => 'publish', 'post_content' => '<p>no links here</p>' ) );
update_post_meta( $mp, 'acf_link', 'https://example.com/meta-acf' );
update_post_meta( $mp, 'gallery', array( 'items' => array( array( 'url' => 'https://example.com/meta-ser', 'caption' => 'x' ) ) ) );
$el = '[{"id":"a1","elType":"widget","settings":{"link":{"url":"https:\/\/example.com\/meta-elementor"},"text":"<a href=\"https:\/\/example.com\/meta-html\">x<\/a>"}}]';
update_post_meta( $mp, '_elementor_data', wp_slash( $el ) );
update_post_meta( $mp, '_oembed_abc', '<iframe src="https://example.com/skipped"></iframe>' );
update_post_meta( $mp, '_edit_lock', '1:1' );
LinkSentinel_Scanner::collect_post( $mp, 777 );
$find = function ( $url ) use ( $mp ) {
	$l = LinkSentinel_DB::link_by_url( $url );
	if ( ! $l ) {
		return null;
	}
	foreach ( LinkSentinel_DB::occurrences( (int) $l->id, 20 ) as $o ) {
		if ( 'post' === $o->source_type && (int) $o->source_id === (int) $mp ) {
			return $o;
		}
	}
	return null;
};
$o1 = $find( 'https://example.com/meta-acf' );
ok( $o1 && 'meta:acf_link' === $o1->field, 'meta: plain string field found' );
$o2 = $find( 'https://example.com/meta-ser' );
ok( $o2 && 'meta:gallery' === $o2->field, 'meta: serialized array walked' );
$o3 = $find( 'https://example.com/meta-elementor' );
ok( $o3 && 'meta:_elementor_data' === $o3->field, 'meta: Elementor JSON decoded' );
$o4 = $find( 'https://example.com/meta-html' );
ok( $o4 && 'a' === $o4->element, 'meta: HTML inside JSON extracted as an anchor' );
ok( null === $find( 'https://example.com/skipped' ), 'meta: oEmbed cache skipped' );
$d = apply_filters( 'linksentinel_describe_occurrence', '', $o3 );
ok( false !== strpos( $d, 'field: _elementor_data' ) && false !== strpos( $d, 'Meta post' ), 'meta: found-in shows the field name' );

$l3 = LinkSentinel_DB::link_by_url( 'https://example.com/meta-elementor' );
LinkSentinel_Fixer::replace_url( (int) $l3->id, 'https://example.com/meta-elementor-new' );
$raw = get_post_meta( $mp, '_elementor_data', true );
$dec = json_decode( $raw, true );
ok( is_array( $dec ) && 'https://example.com/meta-elementor-new' === $dec[0]['settings']['link']['url'], 'meta: fix rewrites inside JSON and keeps it valid' );
ok( false !== strpos( $raw, 'https:\/\/example.com\/meta-html' ), 'meta: other JSON strings keep their escaped slashes' );
ok( null === $find( 'https://example.com/meta-elementor' ) && $find( 'https://example.com/meta-elementor-new' ), 'meta: the table follows the fix' );
$l2 = LinkSentinel_DB::link_by_url( 'https://example.com/meta-ser' );
LinkSentinel_Fixer::replace_url( (int) $l2->id, 'https://example.com/meta-ser-new' );
$g = get_post_meta( $mp, 'gallery', true );
eq( isset( $g['items'][0]['url'] ) ? $g['items'][0]['url'] : null, 'https://example.com/meta-ser-new', 'meta: fix rewrites inside a serialized array' );
$l1 = LinkSentinel_DB::link_by_url( 'https://example.com/meta-acf' );
LinkSentinel_Fixer::replace_url( (int) $l1->id, 'https://example.com/meta-acf-new' );
eq( get_post_meta( $mp, 'acf_link', true ), 'https://example.com/meta-acf-new', 'meta: fix rewrites a plain string field' );
$set( array( 'scan_meta' => false ) );
LinkSentinel_Scanner::collect_post( $mp, 778 );
ok( null === $find( 'https://example.com/meta-acf-new' ), 'meta: switched off, meta links are no longer collected' );
wp_delete_post( $mp, true );
foreach ( array( 'https://example.com/meta-acf-new', 'https://example.com/meta-ser-new', 'https://example.com/meta-elementor-new', 'https://example.com/meta-html' ) as $u ) {
	$l = LinkSentinel_DB::link_by_url( $u );
	if ( $l ) {
		LinkSentinel_DB::delete_link( (int) $l->id );
	}
}

// ---- Export ------------------------------------------------------------------------
$xl = LinkSentinel_DB::upsert_link( 'https://csv.example.com/broken-1', 0 );
LinkSentinel_DB::save_result( $xl, array( 'status' => 'broken', 'http_code' => 404 ) );
$csv = LinkSentinel_Export::csv( 'broken' );
ok( 0 === strpos( $csv, "\xEF\xBB\xBF" . 'status,http_code,url' ) && false !== strpos( $csv, 'https://csv.example.com/broken-1' ), 'export: CSV has a header and the broken link' );
ok( false === strpos( LinkSentinel_Export::csv( 'ok' ), 'csv.example.com/broken-1' ), 'export: the view is respected' );
LinkSentinel_DB::delete_link( $xl );

// ---- Webhook ----------------------------------------------------------------------
$set( array( 'notify_webhook' => 'https://hooks.slack.com/services/T/B/x' ) );
$bl = LinkSentinel_DB::upsert_link( 'https://hook.example.com/broken', 0 );
LinkSentinel_DB::save_result( $bl, array( 'status' => 'broken', 'http_code' => 404 ) );
$hook_req = null;
$short    = function ( $pre, $args, $url ) use ( &$hook_req ) {
	$hook_req = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => 'ok', 'headers' => array(), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $short, 10, 3 );
delete_option( LinkSentinel_Webhook::OPTION );
$st   = array_merge( LinkSentinel_Scanner::state(), array( 'id' => 990101, 'trigger' => 'schedule' ) );
$sent = LinkSentinel_Webhook::maybe_send( $st );
$body = $hook_req ? json_decode( $hook_req['args']['body'], true ) : null;
ok( true === $sent && $hook_req && 'https://hooks.slack.com/services/T/B/x' === $hook_req['url'], 'webhook: a scheduled scan posts to the configured URL' );
ok( $body && isset( $body['text'] ) && false !== strpos( $body['text'], 'https://hook.example.com/broken' ) && ! isset( $body['links'] ), 'webhook: Slack gets a text message' );
eq( LinkSentinel_Webhook::maybe_send( $st ), false, 'webhook: the same scan is not posted twice' );
eq( LinkSentinel_Webhook::maybe_send( array_merge( $st, array( 'id' => 990102, 'trigger' => 'manual' ) ) ), false, 'webhook: manual scans do not post' );
$set( array( 'notify_webhook' => 'https://example.org/hook' ) );
$hook_req = null;
LinkSentinel_Webhook::maybe_send( array_merge( $st, array( 'id' => 990103 ) ) );
$body = $hook_req ? json_decode( $hook_req['args']['body'], true ) : null;
ok( $body && isset( $body['links'][0]['url'], $body['counts']['broken'] ) && 'scan.finished' === $body['event'], 'webhook: other URLs get structured JSON' );
remove_filter( 'pre_http_request', $short, 10 );
delete_option( LinkSentinel_Webhook::OPTION );
LinkSentinel_DB::delete_link( $bl );

update_option( LinkSentinel_Settings::OPTION, $pro_prev );
LinkSentinel_Settings::flush();
