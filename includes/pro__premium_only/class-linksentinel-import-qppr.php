<?php
/**
 * Import redirects left behind by Quick Page/Post Redirect Plugin
 * (wordpress.org slug quick-pagepost-redirect-plugin, closed 2026-04-14).
 *
 * The importer only reads QPPR's data; nothing QPPR stored is changed or
 * deleted. Where QPPR 5.2.4 (its last release) keeps redirects, with line
 * numbers in its page_post_redirect_plugin.php:
 *
 * - Quick Redirects: option `quickppr_redirects`, request => destination
 *   (lines 81, 476). Always sent as 301 (2183). A destination starting with
 *   "/" is prefixed with the home URL (2185-2190). Both sides go through
 *   esc_url() on save (2644), so "&" is stored as "&#038;". Link flags live in
 *   `quickppr_redirects_meta`, request => { newwindow, nofollow } (478-479).
 * - Individual Redirects, per post: `_pprredirect_active` ("1"),
 *   `_pprredirect_url`, `_pprredirect_type` (301, 302, 307 or "meta"; missing
 *   means 302, 1518), `_pprredirect_newwindow` ("_blank"),
 *   `_pprredirect_relnofollow`, `_pprredirect_rewritelink`,
 *   `_pprredirect_meta_secs` (1871-1877). The destination may be a full URL,
 *   "www.…", a post ID, a "/path" or a bare slug (2227-2237). They only run
 *   when a visitor can see the post (is_singular(), 2218), so redirects on
 *   drafts, pending, scheduled and private posts never reached the public.
 * - Global settings: `ppr_override-active` switches every redirect off (127),
 *   `ppr_override-URL` sends all of them to one address (2179, 2239),
 *   `ppr_override-redirect-type` replaces the type of Individual Redirects
 *   (2240), `ppr_override-casesensitive` (2118).
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class LinkSentinel_Import_QPPR {

	/** Shown inside the Redirects page as ?view=import_qppr, so the menu and title stay WordPress' own. */
	const VIEW   = 'import_qppr';
	const ACTION = 'linksentinel_import_qppr';
	const UNDO   = 'linksentinel_import_qppr_undo';

	/** What was imported and when (not autoloaded; removed on uninstall). */
	const OPTION = 'linksentinel_qppr_import';

	/**
	 * Choices on the review screen, in the order they are offered. Only 'ready' is
	 * ticked by default. An item that needs several of them is imported only when
	 * all of them are ticked.
	 */
	const GROUPS = array( 'ready', 'offsite', 'never_live', 'live', 'conflict' );

	/** Rows per table on the review screen before it links to a paged list, and rows per page there. */
	const TABLE_ROWS = 100;
	const PAGE_ROWS  = 200;

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'post_import' ) );
		add_action( 'admin_post_' . self::UNDO, array( __CLASS__, 'post_undo' ) );
		add_action( 'linksentinel_redirects_page_top', array( __CLASS__, 'notice' ) );
		add_action( 'linksentinel_redirects_view_' . self::VIEW, array( __CLASS__, 'render' ) );
	}

	// ----- Reading QPPR's data -------------------------------------------------

	/** Everything QPPR stored that matters for redirects. Read-only. */
	public static function source() {
		global $wpdb;
		$quick = get_option( 'quickppr_redirects', array() );
		$flags = get_option( 'quickppr_redirects_meta', array() );
		$rows  = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_pprredirect_active','_pprredirect_url','_pprredirect_type','_pprredirect_newwindow','_pprredirect_relnofollow','_pprredirect_rewritelink','_pprredirect_meta_secs') ORDER BY post_id ASC, meta_id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no input in this query
		$posts = array();
		foreach ( (array) $rows as $row ) {
			$posts[ (int) $row->post_id ][ $row->meta_key ] = (string) $row->meta_value;
		}
		$src = array(
			'quick'      => is_array( $quick ) ? $quick : array(),
			'quick_meta' => is_array( $flags ) ? $flags : array(),
			'posts'      => $posts,
			'settings'   => array(
				'override_active' => '1' === (string) get_option( 'ppr_override-active', '0' ),
				'override_url'    => (string) get_option( 'ppr_override-URL', '' ),
				'override_type'   => (string) get_option( 'ppr_override-redirect-type', '' ),
				'case_sensitive'  => (bool) get_option( 'ppr_override-casesensitive', false ),
				'meta_secs'       => (int) get_option( 'qppr_meta_addon_sec', get_option( 'ppr_meta-seconds', 0 ) ),
			),
		);
		/**
		 * Filters the QPPR data the importer reads (same shape as returned).
		 *
		 * @param array $src quick, quick_meta, posts and settings.
		 */
		return apply_filters( 'linksentinel_import_qppr_source', $src );
	}

	/** Changes whenever QPPR's stored redirects or settings change; cheap enough for the Redirects page. */
	public static function source_hash( $src = null ) {
		$src = is_array( $src ) ? $src : self::source();
		return md5( (string) wp_json_encode( array( $src['quick'], $src['quick_meta'], $src['posts'], $src['settings'] ) ) );
	}

	/**
	 * Cheap check for the Redirects page: did QPPR leave anything behind? Returns
	 * [quick, individual]; individual counts only switched-on redirects with a
	 * destination on posts that exist and are not in the trash.
	 */
	public static function counts() {
		global $wpdb;
		$quick = get_option( 'quickppr_redirects', array() );
		$posts = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT u.post_id) FROM {$wpdb->postmeta} u
			INNER JOIN {$wpdb->postmeta} a ON a.post_id = u.post_id AND a.meta_key = '_pprredirect_active' AND a.meta_value = '1'
			INNER JOIN {$wpdb->posts} p ON p.ID = u.post_id AND p.post_status <> 'trash'
			WHERE u.meta_key = '_pprredirect_url' AND u.meta_value NOT IN ('', 'http://www.example.com')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no input in this query
		);
		return array( is_array( $quick ) ? count( $quick ) : 0, $posts );
	}

	/** QPPR's own class is loaded, i.e. the plugin is still active and redirecting before we do. */
	public static function qppr_is_active() {
		return (bool) apply_filters( 'linksentinel_import_qppr_is_active', class_exists( 'quick_page_post_reds', false ) );
	}

	// ----- Mapping ----------------------------------------------------------------

	/**
	 * One Quick Redirect, mapped. Items carry: source, ref, from (absolute URL on
	 * this site), key (Link Sentinel's lookup key), to, code, error (why it
	 * cannot be imported, '' when it can), notes (behaviour that changes),
	 * never_live (why QPPR never ran it, '' when it did), live (the address shows
	 * a page; null until checked) and, after preview(), status and needs.
	 *
	 * @param string $request     Key of quickppr_redirects.
	 * @param string $destination Value of quickppr_redirects.
	 * @param array  $flags       Entry of quickppr_redirects_meta.
	 */
	public static function map_quick( $request, $destination, $flags = array() ) {
		$item = self::item( 'quick', (string) $request );
		$req  = self::decode( $request );
		if ( '' === $req ) {
			return self::fail( $item, 'bad_source' );
		}
		if ( preg_match( '#^https?://#i', $req ) ) {
			if ( ! LinkSentinel_Extractor::is_internal( $req ) ) {
				return self::fail( $item, 'other_host' );
			}
			$from = $req;
		} elseif ( 0 === strpos( $req, '/' ) && 0 !== strpos( $req, '//' ) ) {
			// QPPR compares the request with the home URL cut off (line 2113), so "/x/" is relative to home.
			$from = untrailingslashit( home_url() ) . $req;
		} else {
			return self::fail( $item, 'bad_source' );
		}
		$item['from'] = $from;

		$dest           = self::decode( $destination );
		$item['raw_to'] = $dest;
		if ( '' === $dest ) {
			return self::fail( $item, 'empty_target' );
		}
		if ( 0 === strpos( $dest, '//' ) ) {
			return self::fail( $item, 'protocol_relative' );
		}
		if ( 0 === strpos( $dest, '/' ) ) {
			$to = home_url( $dest );
		} elseif ( self::scheme( $dest ) ) {
			$to = $dest;
		} else {
			// QPPR would have sent this as a relative Location header, which lands somewhere different on every page.
			return self::fail( $item, 'relative_target' );
		}
		$item['code'] = 301;
		if ( ! empty( $flags['newwindow'] ) ) {
			$item['notes'][] = 'newwindow';
		}
		if ( ! empty( $flags['nofollow'] ) ) {
			$item['notes'][] = 'nofollow';
		}
		return self::finish( $item, $to );
	}

	/**
	 * One post's Individual Redirect, mapped (see map_quick for the item shape).
	 *
	 * @param int   $post_id  Post the meta belongs to.
	 * @param array $meta     meta_key => meta_value for the QPPR keys.
	 * @param array $settings The 'settings' part of source().
	 */
	public static function map_individual( $post_id, array $meta, array $settings = array() ) {
		$item = self::item( 'individual', (int) $post_id );
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return self::fail( $item, 'no_post' );
		}
		if ( 'trash' === $post->post_status ) {
			return self::fail( $item, 'trashed' ); // QPPR ignores trashed posts (line 1508).
		}
		$from = self::address_of( $post );
		if ( '' === $from ) {
			return self::fail( $item, 'no_address' );
		}
		$item['from'] = $from;
		if ( ! in_array( $post->post_status, array( 'publish', 'inherit' ), true ) ) {
			// Draft, pending, scheduled, private: visitors get a 404 before QPPR's template_redirect check.
			$item['notes'][]    = 'unpublished';
			$item['never_live'] = 'unpublished';
			$item['live']       = false;
		} elseif ( ! is_post_type_viewable( $post->post_type ) ) {
			$item['notes'][]    = 'not_viewable';
			$item['never_live'] = 'not_viewable';
			$item['live']       = false;
		} else {
			// A published post's own address shows that post, so Link Sentinel (404s only) will not redirect it.
			$item['live'] = true;
		}
		if ( ! isset( $meta['_pprredirect_active'] ) || 1 !== (int) $meta['_pprredirect_active'] ) {
			return self::fail( $item, 'inactive' );
		}

		$url            = self::decode( isset( $meta['_pprredirect_url'] ) ? $meta['_pprredirect_url'] : '' );
		$item['raw_to'] = $url;
		if ( '' === $url || 'http://www.example.com' === $url ) { // QPPR skips its own placeholder (line 2224).
			return self::fail( $item, 'empty_target' );
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return self::fail( $item, 'protocol_relative' );
		}
		if ( 0 === stripos( $url, 'www.' ) ) {
			// Checked before the scheme, or "www.example.com:8080/x" would read as scheme "www.example.com".
			$to              = 'http://' . $url;
			$item['notes'][] = 'www';
		} elseif ( self::scheme( $url ) ) {
			$to = $url;
		} elseif ( ctype_digit( $url ) ) {
			$target = get_post( (int) $url );
			$to     = $target && 'publish' === $target->post_status ? get_permalink( $target ) : home_url( '/?p=' . (int) $url );
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$to = home_url( $url );
		} else {
			$to              = home_url( '/' . $url );
			$item['notes'][] = 'slug';
		}

		$type     = isset( $meta['_pprredirect_type'] ) ? strtolower( trim( $meta['_pprredirect_type'] ) ) : '';
		$type     = '' === $type ? '302' : $type;
		$override = isset( $settings['override_type'] ) ? trim( (string) $settings['override_type'] ) : '';
		if ( '' !== $override && '0' !== $override ) {
			$type            = strtolower( $override );
			$item['notes'][] = 'override_type';
		}
		if ( 'meta' === $type ) {
			$secs = isset( $meta['_pprredirect_meta_secs'] ) && '' !== trim( $meta['_pprredirect_meta_secs'] ) ? (int) $meta['_pprredirect_meta_secs'] : ( isset( $settings['meta_secs'] ) ? (int) $settings['meta_secs'] : 0 );
			// Google treats an instant meta refresh as permanent and a delayed one as temporary.
			$item['code']    = $secs > 0 ? 302 : 301;
			$item['notes'][] = 'meta';
		} elseif ( in_array( $type, array( '301', '302', '307', '308' ), true ) ) {
			$item['code'] = (int) $type;
		} else {
			return self::fail( $item, 'bad_type' );
		}

		if ( '_blank' === ( isset( $meta['_pprredirect_newwindow'] ) ? $meta['_pprredirect_newwindow'] : '' ) ) {
			$item['notes'][] = 'newwindow';
		}
		if ( ! empty( $meta['_pprredirect_relnofollow'] ) ) {
			$item['notes'][] = 'nofollow';
		}
		if ( ! empty( $meta['_pprredirect_rewritelink'] ) ) {
			$item['notes'][] = 'rewrite';
		}
		return self::finish( $item, $to );
	}

	/** The public address of a post; for an unpublished post, the address it has when published (empty if it never had a slug). */
	public static function address_of( WP_Post $post ) {
		if ( in_array( $post->post_status, array( 'publish', 'inherit' ), true ) ) {
			return (string) get_permalink( $post );
		}
		if ( '' === $post->post_name ) {
			return '';
		}
		$as_published              = clone $post;
		$as_published->post_status = 'publish';
		return (string) get_permalink( $as_published );
	}

	private static function item( $source, $ref ) {
		return array(
			'source'     => $source,
			'ref'        => $ref,
			'from'       => '',
			'key'        => '',
			'to'         => '',
			'raw_to'     => '',
			'code'       => 0,
			'error'      => '',
			'notes'      => array(),
			'never_live' => '',
			'live'       => null,
			'status'     => '',
			'needs'      => array(),
		);
	}

	private static function fail( array $item, $error ) {
		$item['error']  = $error;
		$item['code']   = 0;
		$item['status'] = 'skip';
		$item['needs']  = array();
		return $item;
	}

	/** Validate the target the way LinkSentinel_Redirects::add() will store it. */
	private static function finish( array $item, $to ) {
		$scheme = self::scheme( $to );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return self::fail( $item, in_array( $scheme, array( 'javascript', 'data', 'vbscript' ), true ) ? 'unsafe_scheme' : 'scheme' );
		}
		$clean = esc_url_raw( $to, array( 'http', 'https' ) );
		if ( '' === $clean || '' === (string) wp_parse_url( $clean, PHP_URL_HOST ) ) {
			return self::fail( $item, 'bad_target' );
		}
		$item['to'] = $clean;
		// The front end looks rules up by esc_url_raw( REQUEST_URI ), so the key is built from the same cleaning.
		$item['from'] = esc_url_raw( $item['from'], array( 'http', 'https' ) );
		$item['key']  = '' === $item['from'] ? '' : LinkSentinel_Redirects::key_for( $item['from'] );
		if ( '' === $item['key'] || '/' === $item['key'] ) {
			return self::fail( $item, 'home' );
		}
		$internal = LinkSentinel_Extractor::is_internal( $clean );
		if ( $internal && LinkSentinel_Redirects::key_for( $clean ) === $item['key'] ) {
			return self::fail( $item, 'loop' );
		}
		if ( ! $internal ) {
			$item['notes'][] = 'offsite';
		}
		return $item;
	}

	/** QPPR stored URLs through esc_url(), which turns & into &#038;. */
	private static function decode( $url ) {
		return trim( wp_specialchars_decode( (string) $url, ENT_QUOTES ) );
	}

	/** Lower-case URL scheme, or '' when the string has none. */
	private static function scheme( $url ) {
		return preg_match( '/^([a-z][a-z0-9+.\-]*):/i', (string) $url, $m ) ? strtolower( $m[1] ) : '';
	}

	// ----- Preview and import ---------------------------------------------------------

	/**
	 * Dry run: every QPPR redirect with what importing would do to it.
	 *
	 * Status per item: new (no Link Sentinel rule for the address), conflict (a
	 * different rule exists), same (that exact rule exists) or skip. Needs lists
	 * the choices beyond 'ready' that must be ticked to import it: never_live (QPPR
	 * never ran it), live (the address still shows a page), offsite (another
	 * domain), conflict (replaces a rule).
	 *
	 * @param array|null $src source() output; read from the database when null.
	 */
	public static function preview( $src = null ) {
		$src = is_array( $src ) ? $src : self::source();
		// Load the posts in a few queries instead of one per redirect.
		foreach ( array_chunk( array_map( 'intval', array_keys( $src['posts'] ) ), 500 ) as $ids ) {
			_prime_post_caches( $ids, true, false );
		}
		$items = array();
		foreach ( $src['quick'] as $request => $destination ) {
			$flags   = isset( $src['quick_meta'][ $request ] ) && is_array( $src['quick_meta'][ $request ] ) ? $src['quick_meta'][ $request ] : array();
			$items[] = self::map_quick( (string) $request, is_scalar( $destination ) ? (string) $destination : '', $flags );
		}
		foreach ( $src['posts'] as $post_id => $meta ) {
			$items[] = self::map_individual( (int) $post_id, $meta, $src['settings'] );
		}

		// Quick Redirects run on init, Individual ones on template_redirect (lines 128, 131): the first rule for an address wins.
		$seen = array();
		foreach ( $items as $i => $item ) {
			if ( '' !== $item['error'] ) {
				continue;
			}
			if ( isset( $seen[ $item['key'] ] ) ) {
				$items[ $i ] = self::fail( $item, 'duplicate' );
			} else {
				$seen[ $item['key'] ] = true;
			}
		}
		$rules    = LinkSentinel_Redirects::find_many( array_keys( $seen ) );
		$switched = ! empty( $src['settings']['override_active'] );

		$counts = array_fill_keys( array( 'ready', 'offsite', 'never_live', 'live', 'conflict', 'same', 'skip' ), 0 );
		$needs  = array_fill_keys( self::GROUPS, 0 );
		$shared = array_fill_keys( self::GROUPS, 0 ); // Of $needs, items that need another choice too.
		$hosts  = array();
		$plan   = array();
		foreach ( $items as $i => $item ) {
			if ( '' === $item['error'] ) {
				if ( $switched ) {
					// "Turn off all redirects" stops QPPR from hooking in at all (line 127), for every redirect.
					$item['notes'][]    = 'override_active';
					$item['never_live'] = '' === $item['never_live'] ? 'override_active' : $item['never_live'];
				}
				if ( null === $item['live'] ) {
					$check        = LinkSentinel_Checker::check_internal( $item['from'] );
					$item['live'] = is_array( $check ) && 'ok' === $check['status'];
				}
				if ( $item['live'] ) {
					$item['notes'][] = 'live_page';
				}
				$need = array();
				if ( '' !== $item['never_live'] ) {
					$need[] = 'never_live';
				}
				if ( $item['live'] ) {
					$need[] = 'live';
				}
				if ( in_array( 'offsite', $item['notes'], true ) ) {
					$need[] = 'offsite';
				}
				$rule = isset( $rules[ $item['key'] ] ) ? $rules[ $item['key'] ] : null;
				if ( $rule && $rule->to_url === $item['to'] && (int) $rule->http_code === $item['code'] ) {
					$item['status'] = 'same';
				} elseif ( $rule ) {
					$item['status']       = 'conflict';
					$item['current']      = $rule->to_url;
					$item['current_code'] = (int) $rule->http_code;
					$need[]               = 'conflict';
				} else {
					$item['status'] = 'new';
				}
				$item['needs'] = $need;
				if ( 'same' !== $item['status'] ) {
					foreach ( $need ? $need : array( 'ready' ) as $g ) {
						++$needs[ $g ];
						$shared[ $g ] += count( $need ) > 1 ? 1 : 0;
					}
					if ( in_array( 'offsite', $need, true ) ) {
						$host           = strtolower( (string) wp_parse_url( $item['to'], PHP_URL_HOST ) );
						$hosts[ $host ] = isset( $hosts[ $host ] ) ? $hosts[ $host ] + 1 : 1;
					}
				}
			}
			++$counts[ self::group( $item ) ];
			$items[ $i ] = $item;
			$plan[]      = array( $item['status'], $item['key'], $item['to'], $item['code'], isset( $item['current'] ) ? $item['current'] . ' ' . $item['current_code'] : '', $item['needs'] );
		}
		arsort( $hosts );

		return array(
			'items'       => $items,
			'counts'      => $counts,
			'needs'       => $needs,
			'shared'      => $shared,
			'hosts'       => $hosts,
			'settings'    => $src['settings'],
			'has_quick'   => ! empty( $src['quick'] ),
			'fingerprint' => md5( (string) wp_json_encode( $plan ) ),
			'source_hash' => self::source_hash( $src ),
		);
	}

	/**
	 * The table an item is listed in: its first need in the order never_live,
	 * conflict, live, offsite; 'ready' when it needs nothing; or same / skip.
	 */
	public static function group( array $item ) {
		if ( ! in_array( $item['status'], array( 'new', 'conflict' ), true ) ) {
			return 'same' === $item['status'] ? 'same' : 'skip';
		}
		foreach ( array( 'never_live', 'conflict', 'live', 'offsite' ) as $g ) {
			if ( in_array( $g, $item['needs'], true ) ) {
				return $g;
			}
		}
		return 'ready';
	}

	/** Whether the ticked groups import this item: every need ticked, or 'ready' for an item that needs nothing. */
	public static function selected( array $item, array $groups ) {
		if ( ! in_array( $item['status'], array( 'new', 'conflict' ), true ) ) {
			return false;
		}
		if ( ! $item['needs'] ) {
			return in_array( 'ready', $groups, true );
		}
		return ! array_diff( $item['needs'], $groups );
	}

	/**
	 * Create the rules a preview lists, for the ticked groups only (see selected()).
	 * New rules go in with a few multi-row inserts tagged with $batch; replaced
	 * conflicts are updated one by one and are not part of the batch. QPPR's own
	 * data is never touched.
	 *
	 * @param array  $preview preview() output, built on the same request.
	 * @param array  $groups  Ticked values of self::GROUPS; anything else is ignored.
	 * @param string $batch   Tag for the rules this import creates.
	 */
	public static function import( array $preview, array $groups = array( 'ready' ), $batch = '' ) {
		$groups = array_values( array_intersect( self::GROUPS, $groups ) );
		$result = array( 'imported' => 0, 'replaced' => 0, 'skipped' => 0, 'failed' => 0 );
		$create = array();
		foreach ( $preview['items'] as $item ) {
			if ( ! self::selected( $item, $groups ) ) {
				++$result['skipped'];
			} elseif ( 'conflict' === $item['status'] ) {
				$id = LinkSentinel_Redirects::add( $item['from'], $item['to'], $item['code'] );
				++$result[ is_wp_error( $id ) ? 'failed' : 'replaced' ];
			} else {
				$create[] = array( $item['from'], $item['to'], $item['code'] );
			}
		}
		$done                = LinkSentinel_Redirects::add_many( $create, $batch );
		$result['imported'] += $done['inserted'];
		$result['skipped']  += $done['existing']; // A rule appeared for the address meanwhile; it is kept.
		$result['failed']   += $done['failed'];
		return $result;
	}

	/** A new batch id for an import. */
	public static function new_batch() {
		return 'qppr-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false ) );
	}

	/** Remember a finished import: when, which batches, and the QPPR data it was built from. */
	public static function record( array $preview, array $result, $batch ) {
		$state = self::state();
		$state = array(
			'time'     => time(),
			'source'   => $preview['source_hash'],
			'batches'  => array_values( array_unique( array_merge( $state ? $state['batches'] : array(), array( (string) $batch ) ) ) ),
			'imported' => ( $state ? $state['imported'] : 0 ) + (int) $result['imported'],
			'replaced' => ( $state ? $state['replaced'] : 0 ) + (int) $result['replaced'],
		);
		update_option( self::OPTION, $state, false );
		return $state;
	}

	/** The stored import record, or null. */
	public static function state() {
		$s = get_option( self::OPTION );
		if ( ! is_array( $s ) || empty( $s['time'] ) || ! isset( $s['source'] ) ) {
			return null;
		}
		return array(
			'time'     => (int) $s['time'],
			'source'   => (string) $s['source'],
			'batches'  => isset( $s['batches'] ) ? array_map( 'strval', (array) $s['batches'] ) : array(),
			'imported' => isset( $s['imported'] ) ? (int) $s['imported'] : 0,
			'replaced' => isset( $s['replaced'] ) ? (int) $s['replaced'] : 0,
		);
	}

	/** Rules created by recorded imports that are still unchanged. */
	public static function batch_rules( $state ) {
		$n = 0;
		foreach ( $state ? $state['batches'] : array() as $b ) {
			$n += LinkSentinel_Redirects::count_batch( $b );
		}
		return $n;
	}

	// ----- Admin ------------------------------------------------------------------------

	/** Address of the review screen, with optional extra query args. */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => LinkSentinel_Redirects::PAGE, 'view' => self::VIEW ), $args ), admin_url( 'admin.php' ) );
	}

	private static function rules_url() {
		return add_query_arg( array( 'page' => LinkSentinel_Redirects::PAGE ), admin_url( 'admin.php' ) ) . '#lsn-rules';
	}

	private static function date( $time ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $time );
	}

	/** On the Redirects page: an offer to review QPPR's redirects, or one line once they are imported and unchanged. */
	public static function notice() {
		$state = self::state();
		if ( $state && hash_equals( $state['source'], self::source_hash() ) ) {
			?>
			<p class="lsn-qppr-imported">
				<?php
				/* translators: %s: date and time */
				echo esc_html( sprintf( __( 'Redirects from Quick Page/Post Redirect were imported on %s.', 'link-sentinel' ), self::date( $state['time'] ) ) );
				?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Review the import', 'link-sentinel' ); ?></a>
			</p>
			<?php
			return;
		}
		list( $quick, $posts ) = self::counts();
		if ( ! $quick && ! $posts ) {
			return;
		}
		?>
		<div class="lsn-pro-box">
			<h2><?php esc_html_e( 'Redirects from Quick Page/Post Redirect', 'link-sentinel' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of Quick Redirects, 2: number of posts with a redirect */
						__( 'Quick Page/Post Redirect Plugin left %1$d Quick Redirects and %2$d page or post redirects on this site. Review what Link Sentinel would do with them before anything is imported; the original data is left in place.', 'link-sentinel' ),
						$quick,
						$posts
					)
				);
				?>
			</p>
			<p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Review import', 'link-sentinel' ); ?></a></p>
		</div>
		<?php
	}

	/** Explanations for error and note codes. */
	public static function describe( $code ) {
		$text = array(
			// Errors: the item is not imported.
			'bad_source'        => __( 'The request address is not a path on this site.', 'link-sentinel' ),
			'other_host'        => __( 'The request address is on another domain, so it never reaches this site.', 'link-sentinel' ),
			'empty_target'      => __( 'No destination is set.', 'link-sentinel' ),
			'protocol_relative' => __( 'The destination starts with “//”, which can point to any domain.', 'link-sentinel' ),
			'relative_target'   => __( 'The destination is a relative address without a leading “/”.', 'link-sentinel' ),
			'unsafe_scheme'     => __( 'The destination is a script or data address, which is never followed.', 'link-sentinel' ),
			'scheme'            => __( 'The destination is not a web address (http or https).', 'link-sentinel' ),
			'bad_target'        => __( 'The destination is not a valid web address.', 'link-sentinel' ),
			'home'              => __( 'The home page cannot be redirected.', 'link-sentinel' ),
			'loop'              => __( 'The destination is the same address, which would loop.', 'link-sentinel' ),
			'no_post'           => __( 'The post this redirect belonged to no longer exists.', 'link-sentinel' ),
			'trashed'           => __( 'The post is in the trash; QPPR ignored it.', 'link-sentinel' ),
			'no_address'        => __( 'The post was never published, so it has no address to redirect.', 'link-sentinel' ),
			'inactive'          => __( 'The redirect was switched off in QPPR.', 'link-sentinel' ),
			'bad_type'          => __( 'Unknown redirect type.', 'link-sentinel' ),
			'duplicate'         => __( 'An earlier QPPR redirect already covers this address.', 'link-sentinel' ),
			// Notes: imported, but behaves differently from QPPR.
			'offsite'           => __( 'Sends visitors to another domain.', 'link-sentinel' ),
			'meta'              => __( 'Was a meta refresh; becomes a 301 (instant) or 302 (delayed) HTTP redirect.', 'link-sentinel' ),
			'newwindow'         => __( '“Open in new window” is not carried over.', 'link-sentinel' ),
			'nofollow'          => __( '“nofollow” is not carried over.', 'link-sentinel' ),
			'rewrite'           => __( '“Show redirect URL in link” is not carried over.', 'link-sentinel' ),
			'www'               => __( '“http://” added in front of “www”, as QPPR did.', 'link-sentinel' ),
			'slug'              => __( 'Destination read as a path on this site, as QPPR did.', 'link-sentinel' ),
			'override_type'     => __( 'Type comes from QPPR’s global “redirect type” override.', 'link-sentinel' ),
			'unpublished'       => __( 'The post is not published, so visitors never reached this redirect. The rule would use the address the post gets when published.', 'link-sentinel' ),
			'not_viewable'      => __( 'This post type has no public pages, so visitors never reached this redirect.', 'link-sentinel' ),
			'override_active'   => __( 'QPPR’s “turn off all redirects” setting is on, so this redirect was not working.', 'link-sentinel' ),
			'live_page'         => __( 'This address still shows a page. Link Sentinel only redirects addresses that would be a 404, so the rule waits until that page is unpublished or deleted.', 'link-sentinel' ),
		);
		return isset( $text[ $code ] ) ? $text[ $code ] : $code;
	}

	/** Short label of a choice, used as a badge on the rows that need it. */
	private static function need_label( $group ) {
		$text = array(
			'offsite'    => __( 'Other domain', 'link-sentinel' ),
			'never_live' => __( 'Never live in QPPR', 'link-sentinel' ),
			'live'       => __( 'Page still exists', 'link-sentinel' ),
			'conflict'   => __( 'Replaces your rule', 'link-sentinel' ),
		);
		return isset( $text[ $group ] ) ? $text[ $group ] : $group;
	}

	/** Table titles, in display order. */
	private static function group_titles() {
		return array(
			'ready'      => __( 'Ready to import', 'link-sentinel' ),
			'offsite'    => __( 'Redirects to other domains', 'link-sentinel' ),
			'never_live' => __( 'Never live in Quick Page/Post Redirect', 'link-sentinel' ),
			'live'       => __( 'Pages that still exist', 'link-sentinel' ),
			'conflict'   => __( 'Conflicts with your Link Sentinel rules', 'link-sentinel' ),
			'same'       => __( 'Already in Link Sentinel', 'link-sentinel' ),
			'skip'       => __( 'Cannot be imported', 'link-sentinel' ),
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'link-sentinel' ) );
		}
		$p      = self::preview();
		$c      = $p['counts'];
		$n      = $p['needs'];
		$s      = $p['settings'];
		$titles = self::group_titles();
		$state  = self::state();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
		$msg   = isset( $_GET['lsn_msg'] ) ? sanitize_key( wp_unslash( $_GET['lsn_msg'] ) ) : '';
		$show  = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : '';
		$show  = isset( $titles[ $show ] ) ? $show : '';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$done  = array();
		foreach ( array( 'imported', 'replaced', 'skipped', 'failed', 'removed' ) as $k ) {
			$done[ $k ] = isset( $_GET[ $k ] ) ? absint( $_GET[ $k ] ) : 0;
		}
		// phpcs:enable
		?>
		<div class="wrap lsn-wrap">
			<h1><?php esc_html_e( 'Import from Quick Page/Post Redirect', 'link-sentinel' ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LinkSentinel_Redirects::PAGE ) ); ?>">&larr; <?php esc_html_e( 'Back to Redirects', 'link-sentinel' ); ?></a></p>

			<?php if ( 'imported' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: rules created, 2: rules replaced, 3: items skipped, 4: items that failed */
							__( 'Import finished: %1$d created, %2$d replaced, %3$d skipped, %4$d failed. Quick Page/Post Redirect’s own data was not changed.', 'link-sentinel' ),
							$done['imported'],
							$done['replaced'],
							$done['skipped'],
							$done['failed']
						)
					);
					?>
					<a href="<?php echo esc_url( self::rules_url() ); ?>"><?php esc_html_e( 'See the rules on the Redirects page', 'link-sentinel' ); ?></a>
				</p></div>
			<?php elseif ( 'changed' === $msg ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'The redirects changed after the preview was shown, so nothing was imported. Review the list below and try again.', 'link-sentinel' ); ?></p></div>
			<?php elseif ( 'removed' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of rules */
					echo esc_html( sprintf( _n( 'Removed %d rule created by the import.', 'Removed %d rules created by the import.', $done['removed'], 'link-sentinel' ), $done['removed'] ) );
					?>
				</p></div>
			<?php elseif ( 'confirm' === $msg ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Tick the box to confirm before removing the imported rules.', 'link-sentinel' ); ?></p></div>
			<?php endif; ?>

			<?php
			if ( $state ) {
				self::render_state( $state );
			}
			if ( ! $p['items'] ) :
				?>
				<p><?php esc_html_e( 'No Quick Page/Post Redirect data was found on this site.', 'link-sentinel' ); ?></p>
			</div>
				<?php
				return;
			endif;
			?>

			<p><?php esc_html_e( 'This is a dry run. Nothing has been imported yet, and importing never changes or deletes what Quick Page/Post Redirect stored.', 'link-sentinel' ); ?></p>
			<ul class="lsn-stats">
				<li class="lsn-stat lsn-ok"><strong><?php echo (int) $c['ready']; ?></strong> <?php esc_html_e( 'ready to import', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-blocked"><strong><?php echo (int) $c['offsite']; ?></strong> <?php esc_html_e( 'to other domains', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-blocked"><strong><?php echo (int) $c['never_live']; ?></strong> <?php esc_html_e( 'never live in QPPR', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-error"><strong><?php echo (int) $c['live']; ?></strong> <?php esc_html_e( 'for pages that still exist', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-blocked"><strong><?php echo (int) $c['conflict']; ?></strong> <?php esc_html_e( 'conflict with your rules', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-redirect"><strong><?php echo (int) $c['same']; ?></strong> <?php esc_html_e( 'already in Link Sentinel', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-broken"><strong><?php echo (int) $c['skip']; ?></strong> <?php esc_html_e( 'cannot be imported', 'link-sentinel' ); ?></li>
			</ul>

			<?php
			self::render_warnings( $p );

			if ( $n['ready'] || $n['offsite'] || $n['never_live'] || $n['live'] || $n['conflict'] ) :
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lsn-panel">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="fingerprint" value="<?php echo esc_attr( $p['fingerprint'] ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<?php if ( $n['ready'] ) : ?>
						<p><label><input type="checkbox" name="groups[]" value="ready" checked>
							<?php
							/* translators: %d: number of redirects */
							echo esc_html( sprintf( _n( 'Import %d redirect that is ready', 'Import %d redirects that are ready', $n['ready'], 'link-sentinel' ), $n['ready'] ) );
							?>
						</label><br><span class="description"><?php esc_html_e( 'Working in Quick Page/Post Redirect, for an address that shows a 404, pointing to this site, with no Link Sentinel rule yet.', 'link-sentinel' ); ?></span></p>
					<?php endif; ?>
					<?php if ( $n['offsite'] ) : ?>
						<p><label><input type="checkbox" name="groups[]" value="offsite">
							<?php
							/* translators: %d: number of redirects */
							echo esc_html( sprintf( _n( 'Also import %d redirect to another domain', 'Also import %d redirects to other domains', $n['offsite'], 'link-sentinel' ), $n['offsite'] ) );
							?>
						</label><br><span class="description"><?php echo esc_html( self::hosts_text( $p['hosts'] ) . self::shared_text( $p['shared']['offsite'] ) ); ?></span></p>
					<?php endif; ?>
					<?php if ( $n['never_live'] ) : ?>
						<p><label><input type="checkbox" name="groups[]" value="never_live">
							<?php
							/* translators: %d: number of redirects */
							echo esc_html( sprintf( _n( 'Also import %d redirect that was never live in Quick Page/Post Redirect', 'Also import %d redirects that were never live in Quick Page/Post Redirect', $n['never_live'], 'link-sentinel' ), $n['never_live'] ) );
							?>
						</label><br><span class="description">
							<?php
							echo esc_html(
								! empty( $s['override_active'] )
									? __( 'QPPR’s “turn off all redirects” setting is on, so no visitor was redirected by any of them. Importing switches them on.', 'link-sentinel' )
									: __( 'They belong to drafts, pending, scheduled or private posts, which visitors could not open, so nobody was redirected by them. Status and author are shown for each.', 'link-sentinel' )
							) . esc_html( self::shared_text( $p['shared']['never_live'] ) );
							?>
						</span></p>
					<?php endif; ?>
					<?php if ( $n['live'] ) : ?>
						<p><label><input type="checkbox" name="groups[]" value="live">
							<?php
							/* translators: %d: number of redirects */
							echo esc_html( sprintf( _n( 'Also import %d redirect for a page that still exists (it takes effect once that page is unpublished or deleted)', 'Also import %d redirects for pages that still exist (they take effect once those pages are unpublished or deleted)', $n['live'], 'link-sentinel' ), $n['live'] ) );
							?>
						</label><?php echo $p['shared']['live'] ? '<br><span class="description">' . esc_html( trim( self::shared_text( $p['shared']['live'] ) ) ) . '</span>' : ''; ?></p>
					<?php endif; ?>
					<?php if ( $n['conflict'] ) : ?>
						<p><label><input type="checkbox" name="groups[]" value="conflict">
							<?php
							/* translators: %d: number of addresses */
							echo esc_html( sprintf( _n( 'Replace my existing Link Sentinel rule for %d address with the QPPR one', 'Replace my existing Link Sentinel rules for %d addresses with the QPPR ones', $n['conflict'], 'link-sentinel' ), $n['conflict'] ) );
							?>
						</label><?php echo $p['shared']['conflict'] ? '<br><span class="description">' . esc_html( trim( self::shared_text( $p['shared']['conflict'] ) ) ) . '</span>' : ''; ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'A redirect that falls under more than one of these is imported only when every box that applies to it is ticked; its row in the tables below shows which.', 'link-sentinel' ); ?></p>
					<?php submit_button( __( 'Import redirects', 'link-sentinel' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php
			$by_group = array_fill_keys( array_keys( $titles ), array() );
			foreach ( $p['items'] as $item ) {
				$by_group[ self::group( $item ) ][] = $item;
			}
			if ( '' !== $show ) {
				?>
				<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All groups', 'link-sentinel' ); ?></a></p>
				<?php
				self::render_table( $show, $titles[ $show ], $by_group[ $show ], $paged );
			} else {
				foreach ( $titles as $group => $title ) {
					if ( $by_group[ $group ] ) {
						self::render_table( $group, $title, $by_group[ $group ], 0 );
					}
				}
			}
			?>
		</div>
		<?php
	}

	/** " 3 of them are imported only if another box that applies to them is ticked too.", or '' for none. */
	private static function shared_text( $count ) {
		if ( ! $count ) {
			return '';
		}
		/* translators: %d: number of redirects */
		return ' ' . sprintf( _n( '%d of them is imported only if another box that applies to it is ticked too.', '%d of them are imported only if another box that applies to them is ticked too.', $count, 'link-sentinel' ), $count );
	}

	/** "example.com (12), shop.example.net (3) and 4 more domains". */
	private static function hosts_text( array $hosts ) {
		$shown = array();
		foreach ( array_slice( $hosts, 0, 20, true ) as $host => $count ) {
			$shown[] = sprintf( '%s (%s)', $host, number_format_i18n( $count ) );
		}
		$more = count( $hosts ) - count( $shown );
		/* translators: %s: comma-separated list of domains with counts */
		$text = sprintf( __( 'Destinations: %s', 'link-sentinel' ), implode( ', ', $shown ) );
		if ( $more > 0 ) {
			/* translators: %d: number of further domains */
			$text .= ' ' . sprintf( _n( 'and %d more domain', 'and %d more domains', $more, 'link-sentinel' ), $more );
		}
		return $text . '.';
	}

	/** Deactivation advice, the pages that keep showing once QPPR is gone, and settings that change behaviour. */
	private static function render_warnings( array $p ) {
		$s = $p['settings'];
		// Redirects QPPR runs today whose address shows a page: Link Sentinel redirects only 404s, so these stop working without QPPR.
		$live = array();
		foreach ( $p['items'] as $item ) {
			if ( in_array( $item['status'], array( 'new', 'conflict', 'same' ), true ) && $item['live'] && '' === $item['never_live'] ) {
				$live[] = $item;
			}
		}
		$count  = count( $live );
		$active = self::qppr_is_active();
		if ( $active || $count ) {
			?>
			<div class="notice notice-warning inline">
				<?php if ( $active ) : ?>
					<p><?php esc_html_e( 'Quick Page/Post Redirect Plugin was closed on WordPress.org on 14 April 2026 and gets no updates. It is still active here and answers requests before Link Sentinel does. Once it is deactivated, only the redirects you imported keep working.', 'link-sentinel' ); ?></p>
				<?php endif; ?>
				<?php if ( $count ) : ?>
					<p><strong>
						<?php
						echo esc_html(
							sprintf(
								$active
									/* translators: %d: number of redirects */
									? _n( '%d of its redirects is for a page that still exists. Link Sentinel only redirects addresses that would otherwise show a 404, so once Quick Page/Post Redirect is deactivated that address shows its page instead of redirecting, whether or not you import the redirect. To keep it redirecting, unpublish or delete the page:', '%d of its redirects are for pages that still exist. Link Sentinel only redirects addresses that would otherwise show a 404, so once Quick Page/Post Redirect is deactivated those addresses show their pages instead of redirecting, whether or not you import the redirects. To keep them redirecting, unpublish or delete the pages:', $count, 'link-sentinel' )
									/* translators: %d: number of redirects */
									: _n( '%d of these redirects is for a page that still exists. Link Sentinel only redirects addresses that would otherwise show a 404, so that address shows its page instead of redirecting until the page is unpublished or deleted:', '%d of these redirects are for pages that still exist. Link Sentinel only redirects addresses that would otherwise show a 404, so those addresses show their pages instead of redirecting until the pages are unpublished or deleted:', $count, 'link-sentinel' ),
								$count
							)
						);
						?>
					</strong></p>
					<ul class="lsn-qppr-live">
						<?php foreach ( array_slice( $live, 0, 50 ) as $item ) : ?>
							<li><?php self::render_page_ref( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $count > 50 ) : ?>
						<p>
							<?php
							/* translators: %d: number of further pages */
							echo esc_html( sprintf( _n( 'And %d more.', 'And %d more.', $count - 50, 'link-sentinel' ), $count - 50 ) );
							?>
						</p>
					<?php endif; ?>
				<?php elseif ( $active ) : ?>
					<p><?php esc_html_e( 'Deactivate it once you have imported the redirects you want to keep.', 'link-sentinel' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
		}

		$warnings = array();
		if ( '' !== $s['override_url'] ) {
			/* translators: %s: URL */
			$warnings[] = sprintf( __( 'QPPR sent every redirect to %s (global override). Link Sentinel imports each redirect’s own destination instead.', 'link-sentinel' ), $s['override_url'] );
		}
		if ( $p['has_quick'] ) {
			if ( empty( $s['case_sensitive'] ) ) {
				$warnings[] = __( 'QPPR matched Quick Redirects regardless of upper or lower case. Link Sentinel matches the address as listed.', 'link-sentinel' );
			}
			$warnings[] = __( 'QPPR passed a visitor’s query string (for example ?utm_source=…) on to the destination. Link Sentinel matches the address including its query string, so /old-page/?utm_source=x is not covered by a rule for /old-page/.', 'link-sentinel' );
		}
		foreach ( $warnings as $w ) {
			?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( $w ); ?></p></div>
			<?php
		}
	}

	/** The post shown at an item's address (0 when none); resolved only for the rows that are displayed. */
	public static function page_id( array $item ) {
		if ( 'individual' === $item['source'] ) {
			return (int) $item['ref'];
		}
		return '' === $item['from'] ? 0 : (int) url_to_postid( $item['from'] );
	}

	/** The page at a redirect's address: title with an edit link when there is a post, and the address. */
	private static function render_page_ref( array $item ) {
		$id   = self::page_id( $item );
		$post = $id ? get_post( $id ) : null;
		if ( $post ) {
			$title = get_the_title( $post );
			$title = '' === $title ? sprintf( /* translators: %d: post ID */ __( 'Post #%d', 'link-sentinel' ), (int) $post->ID ) : $title;
			$edit  = get_edit_post_link( $post->ID );
			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
			echo ' ';
		}
		echo '<code>' . esc_html( $item['key'] ) . '</code>';
	}

	/** What earlier imports left, with a way to remove the rules they created. */
	private static function render_state( array $state ) {
		$left = self::batch_rules( $state );
		?>
		<div class="lsn-panel">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: date and time, 2: rules created, 3: rules replaced */
						__( 'Last import: %1$s. Imports so far created %2$d rules and replaced %3$d.', 'link-sentinel' ),
						self::date( $state['time'] ),
						$state['imported'],
						$state['replaced']
					)
				);
				?>
				<a href="<?php echo esc_url( self::rules_url() ); ?>"><?php esc_html_e( 'See the rules', 'link-sentinel' ); ?></a>
			</p>
			<?php if ( $left ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::UNDO ); ?>">
					<?php wp_nonce_field( self::UNDO ); ?>
					<p><label><input type="checkbox" name="confirm" value="1" required>
						<?php
						/* translators: %d: number of rules */
						echo esc_html( sprintf( _n( 'Remove the %d rule these imports created', 'Remove the %d rules these imports created', $left, 'link-sentinel' ), $left ) );
						?>
					</label><br><span class="description"><?php esc_html_e( 'Rules you changed since, and rules the import replaced, stay as they are.', 'link-sentinel' ); ?></span></p>
					<?php submit_button( __( 'Remove imported rules', 'link-sentinel' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One group's table. With $paged 0 (the overview) at most TABLE_ROWS rows and a
	 * link to the full list; otherwise PAGE_ROWS rows of that page.
	 */
	private static function render_table( $group, $title, array $rows, $paged ) {
		$total = count( $rows );
		if ( $paged ) {
			$pages = max( 1, (int) ceil( $total / self::PAGE_ROWS ) );
			$paged = min( $paged, $pages );
			$rows  = array_slice( $rows, ( $paged - 1 ) * self::PAGE_ROWS, self::PAGE_ROWS );
		} else {
			$rows = array_slice( $rows, 0, self::TABLE_ROWS );
		}
		$status_labels = array();
		?>
		<h2 id="lsn-qppr-<?php echo esc_attr( $group ); ?>"><?php echo esc_html( $title ); ?> (<?php echo esc_html( number_format_i18n( $total ) ); ?>)</h2>
		<?php if ( $paged && $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php LinkSentinel_Redirects::page_links( self::url( array( 'show' => $group ) ), $paged, $pages ); ?>
			</div></div>
		<?php endif; ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'QPPR redirect', 'link-sentinel' ); ?></th>
				<th><?php esc_html_e( 'From', 'link-sentinel' ); ?></th>
				<th><?php esc_html_e( 'To', 'link-sentinel' ); ?></th>
				<th><?php esc_html_e( 'Code', 'link-sentinel' ); ?></th>
				<th><?php esc_html_e( 'Notes', 'link-sentinel' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $item ) : ?>
				<tr>
					<td>
						<?php if ( 'quick' === $item['source'] ) : ?>
							<?php esc_html_e( 'Quick Redirect', 'link-sentinel' ); ?><br><code><?php echo esc_html( self::decode( $item['ref'] ) ); ?></code>
						<?php else : ?>
							<?php
							$post = get_post( (int) $item['ref'] );
							$edit = $post ? get_edit_post_link( $post->ID ) : '';
							/* translators: %d: post ID */
							$label = sprintf( __( 'Post #%d', 'link-sentinel' ), (int) $item['ref'] );
							?>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $label ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $label ); ?>
							<?php endif; ?>
							<?php if ( $post ) : ?>
								<?php
								if ( ! isset( $status_labels[ $post->post_status ] ) ) {
									$obj                                  = get_post_status_object( $post->post_status );
									$status_labels[ $post->post_status ] = $obj ? $obj->label : $post->post_status;
								}
								$author = $post->post_author ? get_the_author_meta( 'display_name', (int) $post->post_author ) : '';
								?>
								<?php if ( '' !== get_the_title( $post ) ) : ?>
									<br><?php echo esc_html( get_the_title( $post ) ); ?>
								<?php endif; ?>
								<br><span class="lsn-error-text lsn-qppr-post-meta">
									<?php
									echo esc_html(
										'' !== $author
											/* translators: 1: post status, 2: author name */
											? sprintf( __( '%1$s, by %2$s', 'link-sentinel' ), $status_labels[ $post->post_status ], $author )
											: $status_labels[ $post->post_status ]
									);
									?>
								</span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td class="lsn-url"><?php echo '' !== $item['key'] ? '<code>' . esc_html( $item['key'] ) . '</code>' : esc_html( $item['from'] ); ?></td>
					<td class="lsn-url">
						<?php if ( '' !== $item['to'] ) : ?>
							<?php echo esc_html( $item['to'] ); ?>
						<?php elseif ( '' !== $item['raw_to'] ) : ?>
							<code><?php echo esc_html( $item['raw_to'] ); ?></code>
						<?php endif; ?>
						<?php if ( isset( $item['current'] ) ) : ?>
							<br><em>
							<?php
							/* translators: 1: URL, 2: HTTP status code */
							echo esc_html( sprintf( __( 'Now: %1$s (%2$d)', 'link-sentinel' ), $item['current'], $item['current_code'] ) );
							?>
							</em>
						<?php endif; ?>
					</td>
					<td><?php echo $item['code'] ? (int) $item['code'] : '—'; ?></td>
					<td>
						<?php if ( '' !== $item['error'] ) : ?>
							<span class="lsn-badge lsn-badge-broken"><?php echo esc_html( self::describe( $item['error'] ) ); ?></span>
						<?php endif; ?>
						<?php if ( in_array( $item['status'], array( 'new', 'conflict' ), true ) && $item['needs'] ) : ?>
							<div class="lsn-qppr-needs">
								<?php esc_html_e( 'Imported only with:', 'link-sentinel' ); ?>
								<?php foreach ( $item['needs'] as $need ) : ?>
									<span class="lsn-badge lsn-badge-blocked"><?php echo esc_html( self::need_label( $need ) ); ?></span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<?php foreach ( array_unique( $item['notes'] ) as $note ) : ?>
							<div class="lsn-error-text"><?php echo esc_html( self::describe( $note ) ); ?></div>
						<?php endforeach; ?>
						<?php if ( $item['live'] && 'quick' === $item['source'] && '' === $item['error'] ) : ?>
							<div class="lsn-error-text"><?php esc_html_e( 'Page at this address:', 'link-sentinel' ); ?> <?php self::render_page_ref( $item ); ?></div>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! $paged && $total > count( $rows ) ) : ?>
			<p>
				<?php
				/* translators: 1: rows shown, 2: rows in the group */
				echo esc_html( sprintf( __( 'Showing the first %1$s of %2$s.', 'link-sentinel' ), number_format_i18n( count( $rows ) ), number_format_i18n( $total ) ) );
				?>
				<a href="<?php echo esc_url( self::url( array( 'show' => $group ) ) ); ?>"><?php esc_html_e( 'Show all', 'link-sentinel' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function post_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'link-sentinel' ) );
		}
		check_admin_referer( self::ACTION );
		$fingerprint = isset( $_POST['fingerprint'] ) ? sanitize_key( wp_unslash( $_POST['fingerprint'] ) ) : '';
		$groups      = isset( $_POST['groups'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['groups'] ) ) : array();
		// The plan is rebuilt here, never taken from the form; the fingerprint proves it is the one that was reviewed.
		$preview = self::preview();
		if ( '' === $fingerprint || ! hash_equals( $preview['fingerprint'], $fingerprint ) ) {
			self::back( array( 'lsn_msg' => 'changed' ) );
		}
		$batch  = self::new_batch();
		$result = self::import( $preview, $groups, $batch );
		if ( $result['imported'] || $result['replaced'] ) {
			self::record( $preview, $result, $batch );
		}
		self::back( array_merge( array( 'lsn_msg' => 'imported' ), $result ) );
	}

	public static function post_undo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'link-sentinel' ) );
		}
		check_admin_referer( self::UNDO );
		if ( empty( $_POST['confirm'] ) ) {
			self::back( array( 'lsn_msg' => 'confirm' ) );
		}
		$state   = self::state();
		$removed = 0;
		foreach ( $state ? $state['batches'] : array() as $batch ) {
			$removed += LinkSentinel_Redirects::delete_batch( $batch );
		}
		delete_option( self::OPTION );
		self::back( array( 'lsn_msg' => 'removed', 'removed' => $removed ) );
	}

	private static function back( array $args ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}
