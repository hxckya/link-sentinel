<?php
/**
 * Quick Page/Post Redirect importer. Included from pro.php (shares ok(), eq(), $home).
 * The fixtures store data exactly as QPPR 5.2.4 does: Quick Redirects in two
 * options, "&" saved as "&#038;" by esc_url(), Individual Redirects as
 * _pprredirect_* post meta.
 */
global $wpdb;
$q_prev_opts = array();
foreach ( array( 'quickppr_redirects', 'quickppr_redirects_meta', 'ppr_override-redirect-type', 'ppr_override-active', 'ppr_override-URL', 'ppr_override-casesensitive', 'qppr_meta_addon_sec', LinkSentinel_Import_QPPR::OPTION ) as $o ) {
	$q_prev_opts[ $o ] = get_option( $o, null );
	delete_option( $o );
}
$q_codes = function ( $item ) {
	return $item['error'] . '|' . implode( ',', $item['notes'] );
};
$q_about = get_page_by_path( 'about' );
$q_user  = get_current_user_id();
wp_set_current_user( 1 ); // Edit links and the admin screens need an administrator.
LinkSentinel_Redirects::install();
list( , $q_counted_before ) = LinkSentinel_Import_QPPR::counts();

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
	$id        = wp_insert_post( array_merge( array( 'post_title' => 'QPPR fixture', 'post_status' => 'draft', 'post_content' => 'x', 'post_author' => 1 ), $args ) );
	$q_posts[] = $id;
	foreach ( $meta as $k => $v ) {
		add_post_meta( $id, $k, $v );
	}
	return $id;
};
$p_live  = $q_mk( array( 'post_status' => 'publish', 'post_name' => 'qppr-live-post', 'post_title' => 'QPPR live post' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => 'https://example.com/p1', '_pprredirect_type' => '307', '_pprredirect_newwindow' => '_blank', '_pprredirect_relnofollow' => '1', '_pprredirect_rewritelink' => '1' ) );
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
$p_pend  = $q_mk( array( 'post_name' => 'qppr-pending', 'post_status' => 'pending' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => '/about/', '_pprredirect_type' => '301' ) );
$p_fut   = $q_mk( array( 'post_name' => 'qppr-future', 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ), array( '_pprredirect_active' => '1', '_pprredirect_url' => '/about/', '_pprredirect_type' => '301' ) );
$p_priv  = $q_mk( array( 'post_name' => 'qppr-private', 'post_status' => 'private' ), array( '_pprredirect_active' => '1', '_pprredirect_url' => '/about/', '_pprredirect_type' => '301' ) );
$q_orphan = 987654321;
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $q_orphan, 'meta_key' => '_pprredirect_active', 'meta_value' => '1' ) );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $q_orphan, 'meta_key' => '_pprredirect_url', 'meta_value' => 'https://example.com/orphan' ) );

// Only this file's posts, so QPPR data already on a dev site is never imported by the tests.
$q_only = function ( $src ) use ( &$q_posts, $q_orphan ) {
	$src['posts'] = array_intersect_key( $src['posts'], array_flip( array_merge( $q_posts, array( $q_orphan ) ) ) );
	return $src;
};
add_filter( 'linksentinel_import_qppr_source', $q_only );

$q_src  = LinkSentinel_Import_QPPR::source();
$q_meta = function ( $id ) use ( $q_src ) {
	return isset( $q_src['posts'][ $id ] ) ? $q_src['posts'][ $id ] : array();
};
ok( isset( $q_src['posts'][ $p_live ]['_pprredirect_url'], $q_src['posts'][ $q_orphan ] ), 'qppr source: post meta read, including orphaned rows' );
$m = LinkSentinel_Import_QPPR::map_individual( $p_live, $q_meta( $p_live ), $q_src['settings'] );
ok( '' === $m['error'] && get_permalink( $p_live ) === $m['from'] && 307 === $m['code'] && 'https://example.com/p1' === $m['to'] && true === $m['live'] && '' === $m['never_live'], 'qppr individual: published post maps from its permalink with its type, and its page exists' );
eq( $q_codes( $m ), '|newwindow,nofollow,rewrite,offsite', 'qppr individual: link-only options noted' );
$m = LinkSentinel_Import_QPPR::map_individual( $p_draft, $q_meta( $p_draft ), $q_src['settings'] );
ok( '' === $m['error'] && home_url( '/qppr-draft-gone/' ) === $m['from'] && 302 === $m['code'] && home_url( '/about/' ) === $m['to'] && 'unpublished' === $m['never_live'] && false === $m['live'], 'qppr individual: draft uses its published address, missing type means 302 like QPPR, and it was never live' );
foreach ( array( 'pending' => $p_pend, 'future' => $p_fut, 'private' => $p_priv ) as $q_status => $q_id ) {
	$m = LinkSentinel_Import_QPPR::map_individual( $q_id, $q_meta( $q_id ), $q_src['settings'] );
	ok( $q_status === get_post_status( $q_id ) && '' === $m['error'] && 'unpublished' === $m['never_live'], "qppr individual: redirect on a {$q_status} post was never live" );
}
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
		'/qppr-xss/"><script>alert(1)</script>' => 'https://shop.example.org/"><img src=x onerror=alert(2)>',
	)
);
update_option( 'quickppr_redirects_meta', array( '/qppr-offsite/' => array( 'newwindow' => 1, 'nofollow' => 0 ) ) );
update_option( 'ppr_override-casesensitive', '1' );
$r_conflict = LinkSentinel_Redirects::add( $home . 'qppr-conflict/', 'https://example.com/mine', 302 );
$r_same     = LinkSentinel_Redirects::add( $home . 'qppr-same/', '/about/', 301 );
$q_before   = array( get_option( 'quickppr_redirects' ), get_option( 'quickppr_redirects_meta' ), get_post_meta( $p_live ), get_post_meta( $p_draft ) );
$rules_before = LinkSentinel_Redirects::total();

$q_log = array();
$q_logger = function ( $sql ) use ( &$q_log ) {
	$q_log[] = $sql;
	return $sql;
};
$q_grep = function ( $needle ) use ( &$q_log ) {
	return count( array_filter( $q_log, function ( $sql ) use ( $needle ) {
		return false !== stripos( $sql, $needle );
	} ) );
};
add_filter( 'query', $q_logger );
$pv = LinkSentinel_Import_QPPR::preview();
remove_filter( 'query', $q_logger );
$by = function ( $pv, $source, $ref ) {
	foreach ( $pv['items'] as $it ) {
		if ( $it['source'] === $source && $it['ref'] === $ref ) {
			return $it;
		}
	}
	return null;
};
eq( LinkSentinel_Redirects::total(), $rules_before, 'qppr preview: a dry run creates no rules' );
ok( 0 === $q_grep( 'linksentinel_redirects WHERE from_hash = ' ) && 1 === $q_grep( 'linksentinel_redirects WHERE from_hash IN' ), 'qppr preview: existing rules loaded in one query, not one per redirect' );
$it = $by( $pv, 'quick', '/qppr-old-page/' );
ok( 'new' === $it['status'] && array() === $it['needs'] && 'ready' === LinkSentinel_Import_QPPR::group( $it ), 'qppr preview: new on-site address that shows a 404 is ready to import' );
eq( $pv['needs']['ready'], 1, 'qppr preview: only redirects that need nothing extra count as ready' );
$it = $by( $pv, 'quick', '/qppr-offsite/' );
ok( 'new' === $it['status'] && array( 'offsite' ) === $it['needs'] && 'offsite' === LinkSentinel_Import_QPPR::group( $it ), 'qppr preview: off-site target is set apart in its own group' );
ok( isset( $pv['hosts']['example.com'], $pv['hosts']['shop.example.org'] ) && 'example.com' === array_key_first( $pv['hosts'] ), 'qppr preview: distinct destination domains listed, most used first' );
$it = $by( $pv, 'quick', '/about/' );
ok( 'live' === LinkSentinel_Import_QPPR::group( $it ) && array( 'live', 'offsite' ) === $it['needs'] && in_array( 'live_page', $it['notes'], true ) && (int) $q_about->ID === LinkSentinel_Import_QPPR::page_id( $it ), 'qppr preview: redirect over a page that exists needs that choice (and the off-site one), and knows the page' );
$it = $by( $pv, 'quick', '/qppr-conflict/' );
ok( 'conflict' === $it['status'] && array( 'conflict' ) === $it['needs'] && 'https://example.com/mine' === $it['current'] && 302 === $it['current_code'], 'qppr preview: different existing rule is a conflict and shows the current target' );
eq( $by( $pv, 'quick', '/qppr-same/' )['status'], 'same', 'qppr preview: identical existing rule is not imported again' );
$it = $by( $pv, 'quick', '/qppr-old-page' );
ok( 'skip' === $it['status'] && 'duplicate' === $it['error'], 'qppr preview: second rule for the same address is a duplicate' );
eq( $by( $pv, 'individual', $p_dup )['error'], 'duplicate', 'qppr preview: Quick Redirect wins over an Individual one for the same address, as in QPPR' );
eq( $by( $pv, 'quick', '/qppr-js/' )['status'], 'skip', 'qppr preview: unsafe target listed as not importable' );
$it = $by( $pv, 'individual', $p_live );
ok( 'new' === $it['status'] && array( 'live', 'offsite' ) === $it['needs'] && 'live' === LinkSentinel_Import_QPPR::group( $it ), 'qppr preview: redirect on a published post is for a page that still exists' );
$it = $by( $pv, 'individual', $p_draft );
ok( array( 'never_live' ) === $it['needs'] && 'never_live' === LinkSentinel_Import_QPPR::group( $it ), 'qppr preview: redirect on a draft is set apart as never live' );
ok( array( 'never_live' ) === $by( $pv, 'individual', $p_pend )['needs'] && array( 'never_live' ) === $by( $pv, 'individual', $p_fut )['needs'] && array( 'never_live' ) === $by( $pv, 'individual', $p_priv )['needs'], 'qppr preview: pending, scheduled and private posts are never live too' );
eq( $by( $pv, 'individual', $p_meta0 )['needs'], array( 'never_live', 'offsite' ), 'qppr preview: a never-live off-site redirect needs both choices' );
eq( $by( $pv, 'individual', $q_orphan )['status'], 'skip', 'qppr preview: orphan listed as not importable' );
eq( array_sum( $pv['counts'] ), count( $pv['items'] ), 'qppr preview: counts add up' );
eq( LinkSentinel_Import_QPPR::preview()['fingerprint'], $pv['fingerprint'], 'qppr preview: fingerprint is stable while nothing changes' );

// QPPR's "turn off all redirects": nothing was live, Quick Redirects included.
update_option( 'ppr_override-active', '1' );
$pv_off = LinkSentinel_Import_QPPR::preview();
$q_all_never = true;
foreach ( $pv_off['items'] as $it ) {
	if ( 'skip' !== $it['status'] && ( '' === $it['never_live'] || ! in_array( 'override_active', $it['notes'], true ) || ( 'same' !== $it['status'] && ! in_array( 'never_live', $it['needs'], true ) ) ) ) {
		$q_all_never = false;
	}
}
ok( $q_all_never && 0 === $pv_off['needs']['ready'] && 'never_live' === LinkSentinel_Import_QPPR::group( $by( $pv_off, 'quick', '/qppr-old-page/' ) ), 'qppr override: with all redirects switched off in QPPR, every item is never live' );
ok( $pv_off['fingerprint'] !== $pv['fingerprint'], 'qppr override: switching QPPR off changes the fingerprint' );
eq( LinkSentinel_Import_QPPR::import( $pv_off, array( 'ready' ) )['imported'], 0, 'qppr override: the default choice imports nothing' );
$q_render = function ( array $get ) {
	$prev = $_GET;
	$_GET = $get;
	ob_start();
	LinkSentinel_Redirects::render();
	$html = ob_get_clean();
	$_GET = $prev;
	return $html;
};
$q_html = $q_render( array( 'view' => LinkSentinel_Import_QPPR::VIEW ) );
ok( false !== strpos( $q_html, 'value="never_live">' ) && false === strpos( $q_html, 'value="ready" checked' ) && false !== strpos( $q_html, 'turn off all redirects” setting is on, so no visitor was redirected' ), 'qppr override: the page offers them only as an unticked never-live choice' );
delete_option( 'ppr_override-active' );

list( $cq, $cp ) = LinkSentinel_Import_QPPR::counts();
ok( 9 === $cq && 14 === $cp - $q_counted_before, 'qppr counts: Quick Redirects, and page redirects without the inactive, trashed and orphaned ones' );
ob_start();
LinkSentinel_Import_QPPR::notice();
$q_html = ob_get_clean();
ok( false !== strpos( $q_html, esc_url( LinkSentinel_Import_QPPR::url() ) ) && false !== strpos( $q_html, '9 Quick Redirects' ), 'qppr notice: Redirects page offers the review' );

// ---- Review screen ---------------------------------------------------------------------
$q_html = $q_render( array( 'view' => LinkSentinel_Import_QPPR::VIEW ) );
ok( false !== strpos( $q_html, 'name="fingerprint" value="' . $pv['fingerprint'] . '"' ) && false !== strpos( $q_html, '_wpnonce' ), 'qppr page: form carries nonce and fingerprint' );
ok( false !== strpos( $q_html, 'value="ready" checked' ) && false !== strpos( $q_html, 'value="offsite">' ) && false !== strpos( $q_html, 'value="never_live">' ) && false !== strpos( $q_html, 'value="live">' ) && false !== strpos( $q_html, 'value="conflict">' ), 'qppr page: ready is ticked; other domains, never live, live pages and conflicts are separate unticked choices' );
ok( 1 === preg_match_all( '/name="groups\[\]" value="[a-z_]+" checked/', $q_html ), 'qppr page: nothing but the ready group is ticked by default' );
ok( false !== strpos( $q_html, 'Destinations: example.com (' ) && false !== strpos( $q_html, 'shop.example.org (1)' ), 'qppr page: off-site choice lists the destination domains' );
ok( 7 === $pv['needs']['offsite'] && 5 === $pv['shared']['offsite'] && false !== strpos( $q_html, '5 of them are imported only if another box that applies to them is ticked too.' ), 'qppr page: each choice says how many of its redirects also need another box' );
ok( false !== strpos( $q_html, 'Draft, by admin' ) && false !== strpos( $q_html, 'Pending, by admin' ) && false !== strpos( $q_html, 'Scheduled, by admin' ) && false !== strpos( $q_html, 'Private, by admin' ), 'qppr page: never-live rows show the post status and author' );
ok( false !== strpos( $q_html, '<code>javascript:alert(1)</code>' ) && ! preg_match( '/href=["\']\s*javascript:/i', $q_html ), 'qppr page: unsafe target shown as text for review, never as a link' );
ok( false === strpos( $q_html, '<script>alert(1)' ) && false === strpos( $q_html, '<img src=x' ) && false !== strpos( $q_html, '&lt;script&gt;alert(1)' ), 'qppr page: markup in stored redirects is escaped' );
ok( false === stripos( $q_html, 'security' ), 'qppr page: no claim about why QPPR was closed' );
ok( false === strpos( $q_html, 'Quick Page/Post Redirect Plugin was closed' ), 'qppr page: with QPPR inactive, no deactivation advice' );

add_filter( 'linksentinel_import_qppr_is_active', '__return_true' );
$q_html = $q_render( array( 'view' => LinkSentinel_Import_QPPR::VIEW ) );
ok( false !== strpos( $q_html, 'Quick Page/Post Redirect Plugin was closed on WordPress.org on 14 April 2026 and gets no updates.' ) && false !== strpos( $q_html, 'only the redirects you imported keep working' ) && false === stripos( $q_html, 'security' ), 'qppr page: QPPR still active; closure stated as a dated fact, and what deactivating it means' );
ok( false !== strpos( $q_html, '2 of its redirects are for pages that still exist' ) && false !== strpos( $q_html, 'once Quick Page/Post Redirect is deactivated those addresses show their pages instead of redirecting' ) && false === strpos( $q_html, 'Deactivate it once' ), 'qppr page: with live pages, deactivation advice says those addresses stop redirecting, with the count' );
ok( false !== strpos( $q_html, 'href="' . esc_url( get_edit_post_link( $p_live ) ) . '">QPPR live post</a>' ) && false !== strpos( $q_html, 'href="' . esc_url( get_edit_post_link( $q_about->ID ) ) . '">' ), 'qppr page: the pages that keep showing are listed with edit links' );
$q_one = function ( $src ) {
	$src['quick'] = array( '/qppr-old-page/' => '/about/' );
	$src['posts'] = array();
	return $src;
};
add_filter( 'linksentinel_import_qppr_source', $q_one, 20 );
$q_html = $q_render( array( 'view' => LinkSentinel_Import_QPPR::VIEW ) );
remove_filter( 'linksentinel_import_qppr_source', $q_one, 20 );
ok( false !== strpos( $q_html, 'Deactivate it once you have imported the redirects you want to keep.' ) && false === strpos( $q_html, 'of its redirects' ), 'qppr page: without live pages, plain deactivation advice' );
remove_filter( 'linksentinel_import_qppr_is_active', '__return_true' );

// ---- Import through the form handler ------------------------------------------------------
if ( function_exists( 'WP_CLI\\Utils\\wp_redirect_handler' ) ) {
	remove_filter( 'wp_redirect', 'WP_CLI\\Utils\\wp_redirect_handler' );
}
$q_submit = function ( $handler, $nonce_action, array $post ) {
	$prev_post    = $_POST;
	$prev_request = $_REQUEST;
	$_POST        = array_merge( $post, array( '_wpnonce' => wp_create_nonce( $nonce_action ) ) );
	$_REQUEST     = $_POST;
	$location     = null;
	$stop         = function ( $to ) {
		throw new RuntimeException( $to );
	};
	add_filter( 'wp_redirect', $stop, 1 );
	try {
		call_user_func( $handler );
	} catch ( RuntimeException $e ) {
		$location = $e->getMessage();
	}
	remove_filter( 'wp_redirect', $stop, 1 );
	$_POST    = $prev_post;
	$_REQUEST = $prev_request;
	$args     = array();
	wp_parse_str( (string) wp_parse_url( (string) $location, PHP_URL_QUERY ), $args );
	return $args;
};
$q_import = array( 'LinkSentinel_Import_QPPR', 'post_import' );
$q_undo   = array( 'LinkSentinel_Import_QPPR', 'post_undo' );

$back = $q_submit( $q_import, LinkSentinel_Import_QPPR::ACTION, array( 'fingerprint' => md5( 'stale' ), 'groups' => array( 'ready', 'offsite' ) ) );
ok( 'changed' === $back['lsn_msg'] && $rules_before === LinkSentinel_Redirects::total(), 'qppr post: a stale fingerprint imports nothing' );

$back = $q_submit( $q_import, LinkSentinel_Import_QPPR::ACTION, array( 'fingerprint' => $pv['fingerprint'], 'groups' => array( 'ready' ) ) );
ok( 'imported' === $back['lsn_msg'] && '1' === $back['imported'] && '0' === $back['replaced'] && '0' === $back['failed'], 'qppr post: the default choice creates only the ready redirect' );
$rule = LinkSentinel_Redirects::for_url( $home . 'qppr-old-page/' );
ok( $rule && home_url( '/about/' ) === $rule->to_url && 301 === (int) $rule->http_code && 0 === strpos( $rule->import_batch, 'qppr-' ), 'qppr post: ready rule stored and tagged with its import batch' );
ok( null === LinkSentinel_Redirects::for_url( $home . 'qppr-offsite' ) && null === LinkSentinel_Redirects::for_url( $home . 'qppr-draft-gone' ) && null === LinkSentinel_Redirects::for_url( $home . 'about' ), 'qppr post: off-site, never-live and live-page redirects not created by default' );
eq( LinkSentinel_Redirects::for_url( $home . 'qppr-conflict' )->to_url, 'https://example.com/mine', 'qppr post: conflicting rule left alone unless asked' );
eq( array( get_option( 'quickppr_redirects' ), get_option( 'quickppr_redirects_meta' ), get_post_meta( $p_live ), get_post_meta( $p_draft ) ), $q_before, 'qppr post: QPPR options and post meta untouched' );
$q_state = LinkSentinel_Import_QPPR::state();
$q_opt   = $wpdb->get_row( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", LinkSentinel_Import_QPPR::OPTION ) );
ok( $q_state && 1 === count( $q_state['batches'] ) && $q_state['source'] === LinkSentinel_Import_QPPR::source_hash() && $q_opt && ! in_array( $q_opt->autoload, array( 'yes', 'on', 'auto-on' ), true ), 'qppr post: the import is recorded, not autoloaded' );
$q_html = $q_render( array( 'view' => LinkSentinel_Import_QPPR::VIEW, 'lsn_msg' => 'imported', 'imported' => '1' ) );
ok( false !== strpos( $q_html, 'Import finished: 1 created' ) && false !== strpos( $q_html, esc_url( admin_url( 'admin.php?page=' . LinkSentinel_Redirects::PAGE ) . '#lsn-rules' ) ), 'qppr page: success notice links to the rules' );
ob_start();
LinkSentinel_Import_QPPR::notice();
$q_html = ob_get_clean();
ok( false !== strpos( $q_html, 'were imported on' ) && false === strpos( $q_html, 'lsn-pro-box' ), 'qppr notice: after the import, one line instead of the offer' );

$pv2  = LinkSentinel_Import_QPPR::preview();
$back = $q_submit( $q_import, LinkSentinel_Import_QPPR::ACTION, array( 'fingerprint' => $pv2['fingerprint'], 'groups' => array( 'offsite', 'everything', 'never-live' ) ) );
ok( '2' === $back['imported'] && LinkSentinel_Redirects::for_url( $home . 'qppr-offsite' ) && 'https://example.com/landing?a=1&b=2' === LinkSentinel_Redirects::for_url( $home . 'qppr-offsite' )->to_url, 'qppr post: ticking other domains imports the off-site redirects that need only that' );
ok( null === LinkSentinel_Redirects::for_url( get_permalink( $p_meta0 ) ) && null === LinkSentinel_Redirects::for_url( $home . 'about' ) && 'https://example.com/mine' === LinkSentinel_Redirects::for_url( $home . 'qppr-conflict' )->to_url, 'qppr post: unknown group values ignored; items that also need another choice stay out' );
eq( count( LinkSentinel_Import_QPPR::state()['batches'] ), 2, 'qppr post: a second import adds its batch to the record' );

// ---- Import: choices combine ------------------------------------------------------------
$pv3  = LinkSentinel_Import_QPPR::preview();
$res3 = LinkSentinel_Import_QPPR::import( $pv3, array( 'ready', 'never_live' ) );
$rule = LinkSentinel_Redirects::for_url( $home . 'qppr-draft-gone/' );
ok( $rule && home_url( '/about/' ) === $rule->to_url && 302 === (int) $rule->http_code && '' === $rule->import_batch, 'qppr import: never-live draft rule created when asked, with QPPR\'s type' );
ok( LinkSentinel_Redirects::for_url( $home . 'qppr-private' ) && null === LinkSentinel_Redirects::for_url( $home . 'qppr-meta-instant' ), 'qppr import: a never-live off-site redirect still waits for the off-site choice' );
$pv4  = LinkSentinel_Import_QPPR::preview();
$q_new = count( array_filter( $pv4['items'], function ( $it ) {
	return 'new' === $it['status'];
} ) );
$res4 = LinkSentinel_Import_QPPR::import( $pv4, LinkSentinel_Import_QPPR::GROUPS );
ok( 1 === $res4['replaced'] && $q_new === $res4['imported'] && 0 === $res4['failed'], 'qppr import: every choice ticked imports the rest and replaces the conflict' );
eq( LinkSentinel_Redirects::for_url( $home . 'qppr-conflict' )->to_url, home_url( '/about/' ), 'qppr import: conflict replaced with the QPPR target' );
ok( LinkSentinel_Redirects::for_url( $home . 'about' ) && LinkSentinel_Redirects::for_url( get_permalink( $p_live ) ) && LinkSentinel_Redirects::for_url( $home . 'qppr-meta-instant' ), 'qppr import: live-page and never-live off-site rules created on request' );
ok( null === LinkSentinel_Redirects::find( '/qppr-js' ), 'qppr import: unsafe item never created' );
$pv5 = LinkSentinel_Import_QPPR::preview();
ok( 0 === $pv5['needs']['ready'] + $pv5['needs']['offsite'] + $pv5['needs']['never_live'] + $pv5['needs']['live'] + $pv5['needs']['conflict'], 'qppr import: afterwards nothing is left to import' );
$q_captured = 'none';
$q_cap      = function ( $loc ) use ( &$q_captured ) {
	$q_captured = $loc;
	return false;
};
add_filter( 'wp_redirect', $q_cap );
LinkSentinel_Redirects::redirect_to( LinkSentinel_Redirects::for_url( $home . 'qppr-old-page/' ) );
remove_filter( 'wp_redirect', $q_cap );
eq( $q_captured, home_url( '/about/' ), 'qppr import: an imported rule redirects' );

// ---- Many redirects: batched queries, paged tables -------------------------------------------
$q_bulk = array();
for ( $i = 1; $i <= 250; $i++ ) {
	$q_bulk[ '/qppr-bulk-' . $i . '/' ] = '/about/';
}
update_option( 'quickppr_redirects', array_merge( get_option( 'quickppr_redirects' ), $q_bulk ) );
ob_start();
LinkSentinel_Import_QPPR::notice();
$q_html = ob_get_clean();
ok( false !== strpos( $q_html, 'lsn-pro-box' ) && false !== strpos( $q_html, '259 Quick Redirects' ), 'qppr notice: when QPPR data changes after an import, the offer comes back' );
$pvb = LinkSentinel_Import_QPPR::preview();
eq( $pvb['needs']['ready'], 250, 'qppr bulk: 250 new redirects ready' );
eq( count( LinkSentinel_Redirects::find_many( array_merge( array( '/qppr-old-page', '/qppr-offsite', '/qppr-nothing-here' ), array_map( function ( $k ) {
	return rtrim( $k, '/' );
}, array_keys( $q_bulk ) ) ), 100 ) ), 2, 'qppr bulk: find_many returns only addresses that have a rule, across chunks' );
$q_html = $q_render( array( 'view' => LinkSentinel_Import_QPPR::VIEW ) );
ok( false !== strpos( $q_html, 'Ready to import (250)' ) && false !== strpos( $q_html, 'Showing the first 100 of 250.' ) && false !== strpos( $q_html, esc_url( LinkSentinel_Import_QPPR::url( array( 'show' => 'ready' ) ) ) ) && substr_count( $q_html, 'Quick Redirect<br><code>/qppr-bulk-' ) === 100, 'qppr bulk: the overview table stops at 100 rows and links to the full list' );
$q_html = $q_render( array( 'view' => LinkSentinel_Import_QPPR::VIEW, 'show' => 'ready', 'paged' => '2' ) );
ok( false !== strpos( $q_html, 'Page 2 of 2' ) && substr_count( $q_html, 'Quick Redirect<br><code>/qppr-bulk-' ) === 50 && false === strpos( $q_html, 'Cannot be imported (' ), 'qppr bulk: the full list is paged and shows only that group' );
$q_log = array();
add_filter( 'query', $q_logger );
$back = $q_submit( $q_import, LinkSentinel_Import_QPPR::ACTION, array( 'fingerprint' => $pvb['fingerprint'], 'groups' => array( 'ready' ) ) );
remove_filter( 'query', $q_logger );
ok( '250' === $back['imported'] && 2 === $q_grep( 'INSERT IGNORE INTO' ) && 0 === $q_grep( 'linksentinel_redirects WHERE from_hash = ' ), 'qppr bulk: 250 rules created in two multi-row inserts, without a lookup per rule' );
$q_chunked = LinkSentinel_Redirects::add_many( array( array( $home . 'qppr-bulk-1/', 'https://example.com/other' ), array( $home . 'qppr-addmany-a/', '/about/' ), array( $home . 'qppr-addmany-a', '/about/?dup' ), array( 'https://example.com/x', '/about/' ) ), 'qppr-test' );
ok( 1 === $q_chunked['inserted'] && 1 === $q_chunked['existing'] && 1 === $q_chunked['failed'] && home_url( '/about/' ) === LinkSentinel_Redirects::for_url( $home . 'qppr-bulk-1' )->to_url, 'qppr bulk: add_many never replaces an existing rule, keeps one row per address, refuses external sources' );
LinkSentinel_Redirects::delete( (int) LinkSentinel_Redirects::for_url( $home . 'qppr-addmany-a' )->id );

// ---- Redirects page: every rule reachable ------------------------------------------------------
$q_total = LinkSentinel_Redirects::total();
ok( $q_total > 260 && 100 === count( LinkSentinel_Redirects::all( 100, 100 ) ), 'redirects page: total counted; rules readable a page at a time' );
$q_html = $q_render( array( 'paged' => '2', 'lsn_msg' => 'deleted' ) );
$q_pages = (int) ceil( $q_total / LinkSentinel_Redirects::PER_PAGE );
ok( false !== strpos( $q_html, 'Active rules (' . number_format_i18n( $q_total ) . ')' ) && false !== strpos( $q_html, 'Showing 101–200 of ' . number_format_i18n( $q_total ) ) && false !== strpos( $q_html, 'Page 2 of ' . $q_pages ), 'redirects page: rules table is paged with a total' );
preg_match_all( '/href="([^"]*paged=[^"]*)"/', $q_html, $q_links );
ok( $q_links[1] && ! preg_grep( '/lsn_msg/', $q_links[1] ) && preg_grep( '/paged=3/', $q_links[1] ), 'redirects page: page links lead on without repeating one-off messages' );
ok( 1 === substr_count( $q_html, 'were imported on' ) && false === strpos( $q_html, 'lsn-pro-box' ), 'redirects page: after the bulk import the importer notice is one line again' );

// ---- Remove what the imports created ----------------------------------------------------------------
$q_state = LinkSentinel_Import_QPPR::state();
$q_left  = LinkSentinel_Import_QPPR::batch_rules( $q_state );
eq( $q_left, 253, 'qppr undo: the three recorded imports created 253 rules' );
LinkSentinel_Redirects::add( $home . 'qppr-bulk-7/', '/about/?edited', 301 );
eq( LinkSentinel_Import_QPPR::batch_rules( $q_state ), 252, 'qppr undo: a rule edited by hand leaves the batch' );
$q_html = $q_render( array( 'view' => LinkSentinel_Import_QPPR::VIEW ) );
ok( false !== strpos( $q_html, 'name="action" value="' . LinkSentinel_Import_QPPR::UNDO . '"' ) && false !== strpos( $q_html, 'Remove the 252 rules these imports created' ) && false !== strpos( $q_html, 'name="confirm" value="1" required' ), 'qppr undo: review screen offers removal, with a required confirmation' );
$back = $q_submit( $q_undo, LinkSentinel_Import_QPPR::UNDO, array() );
ok( 'confirm' === $back['lsn_msg'] && 252 === LinkSentinel_Import_QPPR::batch_rules( $q_state ), 'qppr undo: nothing removed without the confirmation' );
$back = $q_submit( $q_undo, LinkSentinel_Import_QPPR::UNDO, array( 'confirm' => '1' ) );
ok( 'removed' === $back['lsn_msg'] && '252' === $back['removed'] && null === LinkSentinel_Import_QPPR::state(), 'qppr undo: the imported rules are removed and the record cleared' );
ok( null === LinkSentinel_Redirects::for_url( $home . 'qppr-old-page' ) && null === LinkSentinel_Redirects::for_url( $home . 'qppr-offsite' ) && null === LinkSentinel_Redirects::for_url( $home . 'qppr-bulk-8' ), 'qppr undo: rules from each recorded import are gone' );
ok( LinkSentinel_Redirects::for_url( $home . 'qppr-bulk-7' ) && LinkSentinel_Redirects::for_url( $home . 'qppr-conflict' ) && LinkSentinel_Redirects::for_url( $home . 'qppr-draft-gone' ) && LinkSentinel_Redirects::for_url( $home . 'qppr-same' ), 'qppr undo: edited, replaced, unrecorded and pre-existing rules stay' );

$q_uninstall = new ReflectionMethod( 'LinkSentinel_Plugin', 'uninstall' );
$q_lines     = array_slice( file( $q_uninstall->getFileName() ), $q_uninstall->getStartLine() - 1, $q_uninstall->getEndLine() - $q_uninstall->getStartLine() + 1 );
ok( false !== strpos( implode( '', $q_lines ), "'" . LinkSentinel_Import_QPPR::OPTION . "'" ), 'qppr uninstall: the import record is deleted on uninstall' );

// ---- cleanup ---------------------------------------------------------------------------
$wpdb->query( "DELETE FROM {$wpdb->prefix}linksentinel_redirects WHERE from_path LIKE '%qppr-%' OR from_path = '/about'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
remove_filter( 'linksentinel_import_qppr_source', $q_only );
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
wp_set_current_user( $q_user );
