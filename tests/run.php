<?php
/**
 * Integration checks, run inside WordPress: wp eval-file tests/run.php
 * No framework: each block prints PASS/FAIL and the script exits non-zero
 * on any failure, so it works from a shell and from CI alike.
 */
// eval-file runs this in function scope, so counters live in $GLOBALS explicitly.
$GLOBALS['lsn_fails'] = 0;
$GLOBALS['lsn_pass']  = 0;
function ok( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['lsn_pass']++;
		echo "PASS  $label\n";
	} else {
		$GLOBALS['lsn_fails']++;
		echo "FAIL  $label\n";
	}
}
function eq( $a, $b, $label ) {
	ok( $a === $b, $label . ( $a === $b ? '' : '  (got ' . var_export( $a, true ) . ', want ' . var_export( $b, true ) . ')' ) );
}

$home = home_url( '/' );

// ---- Extractor -------------------------------------------------------------
$html = '<p><a href="https://example.com/a">One</a> <a href=\'/rel/path?x=1#frag\'>Two <b>bold</b></a> <a href="mailto:x@y.z">mail</a> <a href="#top">top</a> <a href="tel:1">t</a> <a href="javascript:void(0)">js</a> <a href="//cdn.example.com/p">proto</a> <a href="{{url}}">tpl</a> <a href="https://example.com/a">One again</a></p>'
	. '<img src="https://img.example.com/i.png" srcset="https://img.example.com/i-480.png 480w, https://img.example.com/i-800.png 800w" /><iframe src="https://www.youtube.com/embed/x"></iframe><a href="https://example.com/open">no close';
$found = LinkSentinel_Extractor::extract( $html, $home . 'post/' );
$urls  = array_map( function ( $f ) { return $f['url']; }, $found );
ok( in_array( 'https://example.com/a', $urls, true ), 'extract: absolute href' );
ok( in_array( untrailingslashit( $home ) . '/rel/path?x=1', $urls, true ), 'extract: relative href resolved, fragment dropped' );
ok( ! in_array( 'mailto:x@y.z', $urls, true ) && ! preg_grep( '/^(tel|javascript):/', $urls ), 'extract: mailto/tel/javascript skipped' );
ok( ! preg_grep( '/#top$/', $urls ) && ! preg_grep( '/\{\{/', $urls ), 'extract: anchors and templates skipped' );
ok( in_array( 'http://cdn.example.com/p', $urls, true ) || in_array( 'https://cdn.example.com/p', $urls, true ), 'extract: protocol-relative resolved' );
ok( in_array( 'https://img.example.com/i.png', $urls, true ) && in_array( 'https://img.example.com/i-800.png', $urls, true ), 'extract: img src and srcset' );
ok( in_array( 'https://www.youtube.com/embed/x', $urls, true ), 'extract: iframe src' );
ok( in_array( 'https://example.com/open', $urls, true ), 'extract: anchor without closing tag' );
$two = array_values( array_filter( $found, function ( $f ) { return false !== strpos( $f['url'], '/rel/path' ); } ) );
eq( $two ? $two[0]['anchor'] : null, 'Two bold', 'extract: anchor text is plain text' );
eq( LinkSentinel_Extractor::normalize( ' https://example.com/x y ', $home ), 'https://example.com/x%20y', 'normalize: trims and encodes spaces' );
eq( LinkSentinel_Extractor::normalize( 'ftp://example.com/x', $home ), null, 'normalize: non-http scheme is null' );
eq( LinkSentinel_Extractor::normalize( '&#47;about&#47;', $home ), untrailingslashit( $home ) . '/about/', 'normalize: entity-encoded path' );
ok( LinkSentinel_Extractor::is_internal( $home . 'x' ), 'is_internal: own host' );
ok( ! LinkSentinel_Extractor::is_internal( 'https://example.com/' ), 'is_internal: other host' );
ok( LinkSentinel_Extractor::is_excluded( 'https://a.example.com/x', array( 'example.com' ) ), 'exclude: subdomain of a listed domain' );
ok( LinkSentinel_Extractor::is_excluded( 'https://example.org/private/1', array( 'https://example.org/private/' ) ), 'exclude: URL prefix' );
ok( ! LinkSentinel_Extractor::is_excluded( 'https://notexample.com/', array( 'example.com' ) ), 'exclude: suffix that is not a subdomain' );

// ---- Checker: interpretation --------------------------------------------
function fake_response( $code, $redirects = 0, $final = '', $first = 0 ) {
	$r              = new \WpOrg\Requests\Response();
	$r->status_code = $code;
	$r->redirects   = $redirects;
	$r->url         = $final;
	if ( $redirects ) {
		$h              = new \WpOrg\Requests\Response();
		$h->status_code = $first ? $first : 301;
		$r->history     = array( $h );
	}
	return $r;
}
eq( LinkSentinel_Checker::interpret( fake_response( 200 ) )['status'], 'ok', 'interpret: 200 ok' );
$r = LinkSentinel_Checker::interpret( fake_response( 200, 1, 'https://x/final', 301 ) );
ok( 'redirect' === $r['status'] && 301 === $r['http_code'] && 'https://x/final' === $r['final_url'], 'interpret: followed redirect keeps first hop code and final url' );
eq( LinkSentinel_Checker::interpret( fake_response( 404 ) )['status'], 'broken', 'interpret: 404 broken' );
eq( LinkSentinel_Checker::interpret( fake_response( 410 ) )['status'], 'broken', 'interpret: 410 broken' );
eq( LinkSentinel_Checker::interpret( fake_response( 403 ) )['status'], 'blocked', 'interpret: 403 blocked' );
eq( LinkSentinel_Checker::interpret( fake_response( 999 ) )['status'], 'blocked', 'interpret: 999 blocked' );
eq( LinkSentinel_Checker::interpret( fake_response( 503 ) )['status'], 'error', 'interpret: 503 transient error' );
eq( LinkSentinel_Checker::interpret( new \WpOrg\Requests\Exception( 'Operation timed out after 10000 ms', 'curlerror' ) )['error'], 'Timed out', 'interpret: timeout message' );
eq( LinkSentinel_Checker::interpret( new \WpOrg\Requests\Exception( 'Could not resolve host: nope.invalid', 'curlerror' ) )['error'], 'Domain does not resolve', 'interpret: dns message' );

// ---- Checker: internal resolution without HTTP -----------------------------
$about = get_page_by_path( 'about' );
if ( ! $about ) {
	$about_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_name' => 'about', 'post_content' => 'x' ) );
} else {
	$about_id = $about->ID;
}
eq( LinkSentinel_Checker::check_internal( get_permalink( $about_id ) )['status'], 'ok', 'internal: published page resolves ok' );
eq( LinkSentinel_Checker::check_internal( $home . 'no-such-page-xyz/' )['status'], 'broken', 'internal: unknown pretty URL is broken (rewrite rules)' );
eq( LinkSentinel_Checker::check_internal( $home . 'wp-admin/' )['status'], 'ok', 'internal: wp-admin is a system route' );
eq( LinkSentinel_Checker::check_internal( $home . 'wp-content/plugins/link-sentinel/link-sentinel.php' )['status'], 'ok', 'internal: existing plugin file' );
eq( LinkSentinel_Checker::check_internal( $home . 'wp-content/uploads/2020/01/nope.png' )['status'], 'broken', 'internal: missing upload' );
eq( LinkSentinel_Checker::check_internal( $home . 'category/uncategorized/' )['status'], 'ok', 'internal: existing category archive' );
eq( LinkSentinel_Checker::check_internal( $home . 'category/does-not-exist/' )['status'], 'broken', 'internal: missing category archive' );
eq( LinkSentinel_Checker::check_internal( $home . '?p=999999' )['status'], 'broken', 'internal: ?p= to a missing post' );
eq( LinkSentinel_Checker::check_internal( $home . 'feed/' )['status'], 'ok', 'internal: feed' );
$draft_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Draft target', 'post_name' => 'draft-target-lsn', 'post_content' => 'x' ) );
$draft_url = $home . 'draft-target-lsn/';
eq( LinkSentinel_Checker::check_internal( $draft_url )['status'], 'broken', 'internal: link to a draft is broken for visitors' );

// ---- Fixer round trip --------------------------------------------------------
$pid = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Fixer test', 'post_content' => '<p><a href="https://old.example.com/page">Old</a> and <a href="https://keep.example.com/">Keep</a> and <a href="https://old.example.com/page">Old twice</a></p>' ) );
$pid2 = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Fixer test 2', 'post_content' => '<p><a href="https://old.example.com/page">Elsewhere</a></p>' ) );
$state = LinkSentinel_Scanner::state();
LinkSentinel_Scanner::collect_post( $pid, (int) $state['id'] );
LinkSentinel_Scanner::collect_post( $pid2, (int) $state['id'] );
$old = LinkSentinel_DB::link_by_url( 'https://old.example.com/page' );
ok( $old && 2 === LinkSentinel_DB::occurrence_count( (int) $old->id ), 'collect: one occurrence per post per url' );
$n = LinkSentinel_Fixer::replace_url( (int) $old->id, 'https://new.example.com/page' );
eq( $n, 2, 'replace_url: both posts updated' );
$c1 = get_post( $pid )->post_content;
ok( false === strpos( $c1, 'old.example.com' ) && 2 === substr_count( $c1, 'https://new.example.com/page' ) && false !== strpos( $c1, 'keep.example.com' ), 'replace_url: every occurrence swapped, other links untouched' );
ok( null === LinkSentinel_DB::link_by_url( 'https://old.example.com/page' ), 'replace_url: old url row gone' );
$new = LinkSentinel_DB::link_by_url( 'https://new.example.com/page' );
ok( $new && 2 === LinkSentinel_DB::occurrence_count( (int) $new->id ), 'replace_url: new url row has both occurrences' );
ok( wp_get_post_revisions( $pid ) !== array(), 'replace_url: a revision was kept' );
$u = LinkSentinel_Fixer::unlink( (int) $new->id );
eq( $u, 2, 'unlink: both posts updated' );
$c1 = get_post( $pid )->post_content;
ok( false === strpos( $c1, 'new.example.com' ) && false !== strpos( $c1, '<p>Old and' ) && false !== strpos( $c1, 'Old twice</p>' ) && false !== strpos( $c1, '<a href="https://keep.example.com/">Keep</a>' ), 'unlink: anchors unwrapped, text and other links kept' );
ok( null === LinkSentinel_DB::link_by_url( 'https://new.example.com/page' ), 'unlink: url row gone' );

// cleanup
foreach ( array( $pid, $pid2, $draft_id ) as $id ) {
	wp_delete_post( $id, true );
}

echo "\n{$GLOBALS['lsn_pass']} passed, {$GLOBALS['lsn_fails']} failed\n";
exit( $GLOBALS['lsn_fails'] ? 1 : 0 );
