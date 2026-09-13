<?php
/**
 * Quick Page/Post Redirect importer. Included from pro.php (shares ok(), eq(), $home).
 * The fixtures store data exactly as QPPR 5.2.4 does: Quick Redirects in two
 * options, "&" saved as "&#038;" by esc_url(), Individual Redirects as
 * _pprredirect_* post meta.
 */
global $wpdb;
$q_prev_opts = array();
foreach ( array( 'quickppr_redirects', 'quickppr_redirects_meta', 'ppr_override-redirect-type', 'ppr_override-active', 'ppr_override-URL', 'ppr_override-casesensitive', 'qppr_meta_addon_sec' ) as $o ) {
	$q_prev_opts[ $o ] = get_option( $o, null );
	delete_option( $o );
}
$q_codes = function ( $item ) {
	return $item['error'] . '|' . implode( ',', $item['notes'] );
};
$q_about = get_page_by_path( 'about' );
LinkSentinel_Redirects::install();

// ---- Mapping: Quick Redirects -------------------------------------------------
$m = LinkSentinel_Import_QPPR::map_quick( '/qppr-old-page/', '/about/' );
ok( '' === $m['error'] && home_url( '/qppr-old-page/' ) === $m['from'] && '/qppr-old-page' === $m['key'] && home_url( '/about/' ) === $m['to'] && 301 === $m['code'], 'qppr quick: relative request and target become this site\'s URLs, 301' );
$m = LinkSentinel_Import_QPPR::map_quick( '/qppr-offsite/', 'https://example.com/landing?a=1&#038;b=2' );
ok( 'https://example.com/landing?a=1&b=2' === $m['to'] && in_array( 'offsite', $m['notes'], true ) && '' === $m['error'], 'qppr quick: &#038; decoded, off-site target kept and flagged' );
$m = LinkSentinel_Import_QPPR::map_quick( '/qppr-query/?id=5&#038;x=1', $home . 'about/' );
eq( $m['key'], '/qppr-query?id=5&x=1', 'qppr quick: query string in the request stays part of the key' );
$m = LinkSentinel_Import_QPPR::map_quick( $home . 'qppr-full/', '/about/' );
ok( '' === $m['error'] && '/qppr-full' === $m['key'], 'qppr quick: full URL on this site accepted as request' );
eq( LinkSentinel_Import_QPPR::map_quick( '/qppr-js/', 'javascript:alert(1)' )['error'], 'unsafe_scheme', 'qppr quick: javascript: target refused' );
eq( LinkSentinel_Import_QPPR::map_quick( '/qppr-js2/', ' JavaScript:alert(1)' )['error'], 'unsafe_scheme', 'qppr quick: javascript: refused regardless of case and whitespace' );
eq( LinkSentinel_Import_QPPR::map_quick( '/qppr-data/', 'data:text/html;base64,PHNjcmlwdD4=' )['error'], 'unsafe_scheme', 'qppr quick: data: target refused' );
eq( LinkSentinel_Import_QPPR::map_quick( '/qppr-mail/', 'mailto:x@example.com' )['error'], 'scheme', 'qppr quick: mailto: target refused' );
eq( LinkSentinel_Import_QPPR::map_quick( '/qppr-proto/', '//evil.example/' )['error'], 'protocol_relative', 'qppr quick: protocol-relative target refused' );
eq( LinkSentinel_Import_QPPR::map_quick( '/qppr-rel/', 'new-page/' )['error'], 'relative_target', 'qppr quick: relative target without slash refused' );
eq( LinkSentinel_Import_QPPR::map_quick( 'https://other-domain.example/qppr-x/', '/about/' )['error'], 'other_host', 'qppr quick: request on another domain refused' );
eq( LinkSentinel_Import_QPPR::map_quick( 'http://old-page/', '/about/' )['error'], 'other_host', 'qppr quick: a slash-less request mangled by esc_url() is refused' );
eq( LinkSentinel_Import_QPPR::map_quick( '//evil.example/x', '/about/' )['error'], 'bad_source', 'qppr quick: protocol-relative request refused' );
eq( LinkSentinel_Import_QPPR::map_quick( '/', '/about/' )['error'], 'home', 'qppr quick: home page request refused' );
eq( LinkSentinel_Import_QPPR::map_quick( '/qppr-loop/', '/qppr-loop' )['error'], 'loop', 'qppr quick: self-redirect refused' );
eq( LinkSentinel_Import_QPPR::map_quick( '/qppr-empty/', '' )['error'], 'empty_target', 'qppr quick: empty target refused' );
eq( $q_codes( LinkSentinel_Import_QPPR::map_quick( '/qppr-flags/', '/about/', array( 'newwindow' => 1, 'nofollow' => '1' ) ) ), '|newwindow,nofollow', 'qppr quick: new-window and nofollow noted as not carried over' );

// ---- Mapping: Individual Redirects ---------------------------------------------
$q_posts = array();
$q_mk    = function ( $args, $meta ) use ( &$q_posts ) {
	$id        = wp_insert_post( array_merge( array( 'post_title' => 'QPPR fixture', 'post_status' => 'draft', 'post_content' => 'x' ), $args ) );
	$q_posts[] = $id;
	foreach ( $meta as $k => $v ) {
		add_post_meta( $id, $k, $v );
	}
	return $id;
};
$p_live  = $q_mk( array( 'post_status' => 'publish', 'post_name' => 'qppr-live-post' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => 'https://example.com/p1', '_pprredirect_type' => '307', '_pprredirect_newwindow' => '_blank', '_pprredirect_relnofollow' => '1', '_pprredirect_rewritelink' => '1' ) );
$p_draft = $q_mk( array( 'post_name' => 'qppr-draft-gone' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => '/about/' ) );
$p_noslug = $q_mk( array( 'post_title' => '' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => '/about/', '_pprredirect_type' => '301' ) );
$wpdb->update( $wpdb->posts, array( 'post_name' => '' ), array( 'ID' => $p_noslug ) );
clean_post_cache( $p_noslug );
$p_off   = $q_mk( array( 'post_name' => 'qppr-inactive' ), array( '_pprredirect_active' => '0', '_pprredirect_url' => '/about/', '_pprredirect_type' => '301' ) );
$p_trash = $q_mk( array( 'post_name' => 'qppr-trashed', 'post_status' => 'trash' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => '/about/', '_pprredirect_type' => '301' ) );
$p_meta0 = $q_mk( array( 'post_name' => 'qppr-meta-instant' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => 'http://example.net/m0', '_pprredirect_type' => 'meta' ) );
$p_meta5 = $q_mk( array( 'post_name' => 'qppr-meta-delayed' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => 'http://example.net/m5', '_pprredirect_type' => 'meta', '_pprredirect_meta_secs' => '5' ) );
$p_num   = $q_mk( array( 'post_name' => 'qppr-to-id' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => (string) $q_about->ID, '_pprredirect_type' => '301' ) );
$p_www   = $q_mk( array( 'post_name' => 'qppr-www' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => 'www.example.org/x', '_pprredirect_type' => '302' ) );
$p_slug  = $q_mk( array( 'post_name' => 'qppr-slug' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => 'some-slug', '_pprredirect_type' => '302' ) );
$p_badt  = $q_mk( array( 'post_name' => 'qppr-bad-type' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => '/about/', '_pprredirect_type' => '200' ) );
$p_js    = $q_mk( array( 'post_name' => 'qppr-js-post' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => 'javascript:alert(document.cookie)', '_pprredirect_type' => '301' ) );
$p_dup   = $q_mk( array( 'post_name' => 'qppr-old-page' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => 'https://example.com/dup', '_pprredirect_type' => '301' ) );
$q_orphan = 987654321;
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $q_orphan, 'meta_key' => '_pprredirect_active', 'meta_value' => '1' ) );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $q_orphan, 'meta_key' => '_pprredirect_url', 'meta_value' => 'https://example.com/orphan' ) );

$q_src  = LinkSentinel_Import_QPPR::source();
$q_meta = function ( $id ) use ( $q_src ) {
	return isset( $q_src['posts'][ $id ] ) ? $q_src['posts'][ $id ] : array();
};
ok( isset( $q_src['posts'][ $p_live ]['_pprredirect_url'], $q_src['posts'][ $q_orphan ] ), 'qppr source: post meta read, including orphaned rows' );
$m = LinkSentinel_Import_QPPR::map_individual( $p_live, $q_meta( $p_live ), $q_src['settings'] );
ok( '' === $m['error'] && get_permalink( $p_live ) === $m['from'] && 307 === $m['code'] && 'https://example.com/p1' === $m['to'], 'qppr individual: published post maps from its permalink with its type' );
eq( $q_codes( $m ), '|newwindow,nofollow,rewrite,offsite', 'qppr individual: link-only options noted' );
$m = LinkSentinel_Import_QPPR::map_individual( $p_draft, $q_meta( $p_draft ), $q_src['settings'] );
ok( '' === $m['error'] && home_url( '/qppr-draft-gone/' ) === $m['from'] && 302 === $m['code'] && home_url( '/about/' ) === $m['to'] && in_array( 'unpublished', $m['notes'], true ), 'qppr individual: draft uses its published address; missing type means 302 like QPPR' );
eq( LinkSentinel_Import_QPPR::map_individual( $p_noslug, $q_meta( $p_noslug ), $q_src['settings'] )['error'], 'no_address', 'qppr individual: never-published post has no address' );
eq( LinkSentinel_Import_QPPR::map_individual( $p_off, $q_meta( $p_off ), $q_src['settings'] )['error'], 'inactive', 'qppr individual: inactive redirect skipped' );
eq( LinkSentinel_Import_QPPR::map_individual( $p_trash, $q_meta( $p_trash ), $q_src['settings'] )['error'], 'trashed', 'qppr individual: trashed post skipped' );
eq( LinkSentinel_Import_QPPR::map_individual( $q_orphan, $q_meta( $q_orphan ), $q_src['settings'] )['error'], 'no_post', 'qppr individual: orphaned meta skipped' );
$m = LinkSentinel_Import_QPPR::map_individual( $p_meta0, $q_meta( $p_meta0 ), $q_src['settings'] );
ok( 301 === $m['code'] && in_array( 'meta', $m['notes'], true ), 'qppr individual: instant meta refresh becomes 301' );
eq( LinkSentinel_Import_QPPR::map_individual( $p_meta5, $q_meta( $p_meta5 ), $q_src['settings'] )['code'], 302, 'qppr individual: delayed meta refresh becomes 302' );
eq( LinkSentinel_Import_QPPR::map_individual( $p_meta0, $q_meta( $p_meta0 ), array_merge( $q_src['settings'], array( 'meta_secs' => 3 ) ) )['code'], 302, 'qppr individual: global meta delay applies when the post has none' );
eq( LinkSentinel_Import_QPPR::map_individual( $p_num, $q_meta( $p_num ), $q_src['settings'] )['to'], get_permalink( $q_about ), 'qppr individual: post ID target resolves to that post\'s permalink' );
$m = LinkSentinel_Import_QPPR::map_individual( $p_www, $q_meta( $p_www ), $q_src['settings'] );
ok( 'http://www.example.org/x' === $m['to'] && in_array( 'www', $m['notes'], true ), 'qppr individual: www target gets http:// like QPPR' );
eq( LinkSentinel_Import_QPPR::map_individual( $p_www, array_merge( $q_meta( $p_www ), array( '_pprredirect_url' => 'www.example.org:8080/x' ) ), $q_src['settings'] )['to'], 'http://www.example.org:8080/x', 'qppr individual: www target with a port is not mistaken for a scheme' );
$m = LinkSentinel_Import_QPPR::map_individual( $p_slug, $q_meta( $p_slug ), $q_src['settings'] );
ok( home_url( '/some-slug' ) === $m['to'] && in_array( 'slug', $m['notes'], true ), 'qppr individual: bare slug target is a path on this site like QPPR' );
eq( LinkSentinel_Import_QPPR::map_individual( $p_badt, $q_meta( $p_badt ), $q_src['settings'] )['error'], 'bad_type', 'qppr individual: unknown type refused' );
eq( LinkSentinel_Import_QPPR::map_individual( $p_js, $q_meta( $p_js ), $q_src['settings'] )['error'], 'unsafe_scheme', 'qppr individual: javascript: target refused' );
$m = LinkSentinel_Import_QPPR::map_individual( $p_draft, $q_meta( $p_draft ), array_merge( $q_src['settings'], array( 'override_type' => '307' ) ) );
ok( 307 === $m['code'] && in_array( 'override_type', $m['notes'], true ), 'qppr individual: global type override applied and noted' );

// ---- Preview (dry run) ---------------------------------------------------------------
update_option(
	'quickppr_redirects',
	array(
		'/qppr-old-page/'                        => '/about/',
		'/qppr-offsite/'                         => 'https://example.com/landing?a=1&#038;b=2',
		'/qppr-js/'                              => 'javascript:alert(1)',
		'/about/'                                => 'https://example.com/moved',
		'/qppr-conflict/'                        => '/about/',
		'/qppr-same/'                            => '/about/',
		'/qppr-old-page'                         => '/elsewhere/',
		'https://other-domain.example/qppr-x/'   => '/about/',
		'/qppr-xss/"><script>alert(1)</script>' => 'https://example.com/"><img src=x onerror=alert(2)>',
	)
);
update_option( 'quickppr_redirects_meta', array( '/qppr-offsite/' => array( 'newwindow' => 1, 'nofollow' => 0 ) ) );
update_option( 'ppr_override-casesensitive', '1' );
$r_conflict = LinkSentinel_Redirects::add( $home . 'qppr-conflict/', 'https://example.com/mine', 302 );
$r_same     = LinkSentinel_Redirects::add( $home . 'qppr-same/', '/about/', 301 );
$q_before   = array( get_option( 'quickppr_redirects' ), get_option( 'quickppr_redirects_meta' ), get_post_meta( $p_live ), get_post_meta( $p_draft ) );
$rules_before = count( LinkSentinel_Redirects::all() );

// Only this file's posts, so QPPR data already on a dev site is never imported by the tests.
$q_only = function () use ( &$q_posts, $q_orphan ) {
	$src          = LinkSentinel_Import_QPPR::source();
	$src['posts'] = array_intersect_key( $src['posts'], array_flip( array_merge( $q_posts, array( $q_orphan ) ) ) );
	return $src;
};
$pv  = LinkSentinel_Import_QPPR::preview( $q_only() );
$by  = function ( $pv, $source, $ref ) {
	foreach ( $pv['items'] as $it ) {
		if ( $it['source'] === $source && $it['ref'] === $ref ) {
			return $it;
		}
	}
	return null;
};
eq( count( LinkSentinel_Redirects::all() ), $rules_before, 'qppr preview: a dry run creates no rules' );
eq( $by( $pv, 'quick', '/qppr-old-page/' )['status'], 'new', 'qppr preview: new address is ready to import' );
eq( $by( $pv, 'quick', '/qppr-offsite/' )['status'], 'new', 'qppr preview: off-site target is importable' );
$it = $by( $pv, 'quick', '/about/' );
ok( 'live' === $it['status'] && in_array( 'live_page', $it['notes'], true ), 'qppr preview: redirect over a page that exists is set apart' );
$it = $by( $pv, 'quick', '/qppr-conflict/' );
ok( 'conflict' === $it['status'] && 'https://example.com/mine' === $it['current'] && 302 === $it['current_code'], 'qppr preview: different existing rule is a conflict and shows the current target' );
eq( $by( $pv, 'quick', '/qppr-same/' )['status'], 'same', 'qppr preview: identical existing rule is not imported again' );
$it = $by( $pv, 'quick', '/qppr-old-page' );
ok( 'skip' === $it['status'] && 'duplicate' === $it['error'], 'qppr preview: second rule for the same address is a duplicate' );
eq( $by( $pv, 'individual', $p_dup )['error'], 'duplicate', 'qppr preview: Quick Redirect wins over an Individual one for the same address, as in QPPR' );
eq( $by( $pv, 'quick', '/qppr-js/' )['status'], 'skip', 'qppr preview: unsafe target listed as not importable' );
eq( $by( $pv, 'individual', $p_live )['status'], 'live', 'qppr preview: redirect on a published post is set apart' );
eq( $by( $pv, 'individual', $p_draft )['status'], 'new', 'qppr preview: redirect on a draft is importable' );
eq( $by( $pv, 'individual', $q_orphan )['status'], 'skip', 'qppr preview: orphan listed as not importable' );
eq( array_sum( $pv['counts'] ), count( $pv['items'] ), 'qppr preview: counts add up' );
eq( LinkSentinel_Import_QPPR::preview( $q_only() )['fingerprint'], $pv['fingerprint'], 'qppr preview: fingerprint is stable while nothing changes' );
list( $cq, $cp ) = LinkSentinel_Import_QPPR::counts();
ok( 9 === $cq && $cp >= 14, 'qppr counts: quick and per-post totals' );
ob_start();
LinkSentinel_Import_QPPR::notice();
$q_html = ob_get_clean();
ok( false !== strpos( $q_html, esc_url( LinkSentinel_Import_QPPR::url() ) ) && false !== strpos( $q_html, '9 Quick Redirects' ), 'qppr notice: Redirects page offers the review' );
$q_user = get_current_user_id();
wp_set_current_user( 1 );
ob_start();
$_GET['view'] = LinkSentinel_Import_QPPR::VIEW;
LinkSentinel_Redirects::render();
unset( $_GET['view'] );
$q_html = ob_get_clean();
wp_set_current_user( $q_user );
ok( false !== strpos( $q_html, 'name="fingerprint" value="' . LinkSentinel_Import_QPPR::preview()['fingerprint'] . '"' ) && false !== strpos( $q_html, '_wpnonce' ) && false !== strpos( $q_html, 'replace_conflicts' ) && false !== strpos( $q_html, 'include_live' ), 'qppr page: Redirects page ?view=import_qppr shows the form with nonce, fingerprint and both choices' );
ok( false !== strpos( $q_html, '<code>javascript:alert(1)</code>' ) && ! preg_match( '/href=["\']\s*javascript:/i', $q_html ), 'qppr page: unsafe target shown as text for review, never as a link' );
ok( false === strpos( $q_html, '<script>alert(1)' ) && false === strpos( $q_html, '<img src=x' ) && false !== strpos( $q_html, '&lt;script&gt;alert(1)' ), 'qppr page: markup in stored redirects is escaped' );

// ---- Import --------------------------------------------------------------------------
$res = LinkSentinel_Import_QPPR::import( $pv );
ok( $res['imported'] === $pv['counts']['new'] && 0 === $res['replaced'] && 0 === $res['failed'], 'qppr import: every ready item created, nothing replaced' );
$rule = LinkSentinel_Redirects::for_url( $home . 'qppr-offsite' );
ok( $rule && 'https://example.com/landing?a=1&b=2' === $rule->to_url && 301 === (int) $rule->http_code, 'qppr import: rule stored with the decoded target' );
$rule = LinkSentinel_Redirects::for_url( $home . 'qppr-draft-gone/' );
ok( $rule && home_url( '/about/' ) === $rule->to_url && 302 === (int) $rule->http_code, 'qppr import: draft post rule stored with QPPR\'s type' );
eq( LinkSentinel_Redirects::for_url( $home . 'qppr-conflict' )->to_url, 'https://example.com/mine', 'qppr import: conflicting rule left alone unless asked' );
eq( LinkSentinel_Redirects::for_url( $home . 'about' ), null, 'qppr import: redirect over a live page not created unless asked' );
ok( null === LinkSentinel_Redirects::find( '/qppr-js' ), 'qppr import: unsafe item not created' );
eq( array( get_option( 'quickppr_redirects' ), get_option( 'quickppr_redirects_meta' ), get_post_meta( $p_live ), get_post_meta( $p_draft ) ), $q_before, 'qppr import: QPPR options and post meta untouched' );
$pv2 = LinkSentinel_Import_QPPR::preview( $q_only() );
ok( 0 === $pv2['counts']['new'] && $pv2['fingerprint'] !== $pv['fingerprint'], 'qppr import: afterwards nothing is new and the old preview no longer matches' );
$res2 = LinkSentinel_Import_QPPR::import( $pv2, true, true );
ok( 1 === $res2['replaced'] && $res2['imported'] === $pv2['counts']['live'] && 0 === $res2['failed'], 'qppr import: replace and live-page choices applied when asked' );
eq( LinkSentinel_Redirects::for_url( $home . 'qppr-conflict' )->to_url, home_url( '/about/' ), 'qppr import: conflict replaced with the QPPR target' );
ok( LinkSentinel_Redirects::for_url( $home . 'about' ) && LinkSentinel_Redirects::for_url( get_permalink( $p_live ) ), 'qppr import: live-page rules created on request' );
$q_captured = 'none';
$q_cap      = function ( $loc ) use ( &$q_captured ) {
	$q_captured = $loc;
	return false;
};
add_filter( 'wp_redirect', $q_cap );
LinkSentinel_Redirects::redirect_to( LinkSentinel_Redirects::for_url( $home . 'qppr-old-page/' ) );
remove_filter( 'wp_redirect', $q_cap );
eq( $q_captured, home_url( '/about/' ), 'qppr import: an imported rule redirects' );

// ---- cleanup ---------------------------------------------------------------------------
foreach ( LinkSentinel_Redirects::all() as $r ) {
	if ( false !== strpos( $r->from_path, 'qppr-' ) || '/about' === $r->from_path ) {
		LinkSentinel_Redirects::delete( (int) $r->id );
	}
}
foreach ( $q_posts as $id ) {
	wp_delete_post( $id, true );
}
$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $q_orphan ) );
foreach ( $q_prev_opts as $o => $v ) {
	if ( null === $v ) {
		delete_option( $o );
	} else {
		update_option( $o, $v );
	}
}
