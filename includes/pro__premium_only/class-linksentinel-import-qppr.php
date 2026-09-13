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
 *   "www.…", a post ID, a "/path" or a bare slug (2227-2237).
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

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'post_import' ) );
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
		return array(
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
	}

	/** Cheap check for the Redirects page: did QPPR leave anything behind? Returns [quick, individual]. */
	public static function counts() {
		global $wpdb;
		$quick = get_option( 'quickppr_redirects', array() );
		$posts = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_pprredirect_url' AND meta_value <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no input in this query
		return array( is_array( $quick ) ? count( $quick ) : 0, $posts );
	}

	/** QPPR's own class is loaded, i.e. the plugin is still active and redirecting before we do. */
	public static function qppr_is_active() {
		return class_exists( 'quick_page_post_reds', false );
	}

	// ----- Mapping ----------------------------------------------------------------

	/**
	 * One Quick Redirect, mapped. Items carry: source, ref, from (absolute URL on
	 * this site), key (Link Sentinel's lookup key), to, code, error (why it
	 * cannot be imported, '' when it can) and notes (behaviour that changes).
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
			$item['notes'][] = 'unpublished';
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
			'source' => $source,
			'ref'    => $ref,
			'from'   => '',
			'key'    => '',
			'to'     => '',
			'raw_to' => '',
			'code'   => 0,
			'error'  => '',
			'notes'  => array(),
			'status' => '',
		);
	}

	private static function fail( array $item, $error ) {
		$item['error']  = $error;
		$item['code']   = 0;
		$item['status'] = 'skip';
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
	 * Status per item: new, live (the address still serves a page), same (already
	 * in Link Sentinel), conflict (Link Sentinel has a different rule) or skip.
	 *
	 * @param array|null $src source() output; read from the database when null.
	 */
	public static function preview( $src = null ) {
		$src   = is_array( $src ) ? $src : self::source();
		$items = array();
		foreach ( $src['quick'] as $request => $destination ) {
			$flags   = isset( $src['quick_meta'][ $request ] ) && is_array( $src['quick_meta'][ $request ] ) ? $src['quick_meta'][ $request ] : array();
			$items[] = self::map_quick( (string) $request, is_scalar( $destination ) ? (string) $destination : '', $flags );
		}
		foreach ( $src['posts'] as $post_id => $meta ) {
			$items[] = self::map_individual( (int) $post_id, $meta, $src['settings'] );
		}

		// Quick Redirects run on init, Individual ones on template_redirect (lines 128, 131): the first rule for an address wins.
		$seen   = array();
		$counts = array( 'new' => 0, 'live' => 0, 'same' => 0, 'conflict' => 0, 'skip' => 0 );
		foreach ( $items as $i => $item ) {
			if ( '' === $item['error'] ) {
				if ( isset( $seen[ $item['key'] ] ) ) {
					$item = self::fail( $item, 'duplicate' );
				} else {
					$seen[ $item['key'] ] = true;
					$rule                 = LinkSentinel_Redirects::find( $item['key'] );
					$check                = LinkSentinel_Checker::check_internal( $item['from'] );
					$live                 = is_array( $check ) && 'ok' === $check['status'];
					if ( $live ) {
						$item['notes'][] = 'live_page';
					}
					if ( $rule && $rule->to_url === $item['to'] && (int) $rule->http_code === $item['code'] ) {
						$item['status'] = 'same';
					} elseif ( $rule ) {
						$item['status']       = 'conflict';
						$item['current']      = $rule->to_url;
						$item['current_code'] = (int) $rule->http_code;
					} else {
						$item['status'] = $live ? 'live' : 'new';
					}
				}
			}
			++$counts[ $item['status'] ];
			$items[ $i ] = $item;
		}

		$plan = array();
		foreach ( $items as $item ) {
			$plan[] = array( $item['status'], $item['key'], $item['to'], $item['code'], isset( $item['current'] ) ? $item['current'] . ' ' . $item['current_code'] : '' );
		}
		return array(
			'items'       => $items,
			'counts'      => $counts,
			'settings'    => $src['settings'],
			'has_quick'   => ! empty( $src['quick'] ),
			'fingerprint' => md5( (string) wp_json_encode( $plan ) ),
		);
	}

	/**
	 * Create the rules a preview lists. New items are always imported; conflicts
	 * and live pages only when asked. QPPR's own data is never touched.
	 */
	public static function import( array $preview, $replace_conflicts = false, $include_live = false ) {
		$result = array( 'imported' => 0, 'replaced' => 0, 'skipped' => 0, 'failed' => 0 );
		foreach ( $preview['items'] as $item ) {
			$go = 'new' === $item['status']
				|| ( 'live' === $item['status'] && $include_live )
				|| ( 'conflict' === $item['status'] && $replace_conflicts );
			if ( ! $go ) {
				++$result['skipped'];
				continue;
			}
			$id = LinkSentinel_Redirects::add( $item['from'], $item['to'], $item['code'] );
			if ( is_wp_error( $id ) ) {
				++$result['failed'];
			} elseif ( 'conflict' === $item['status'] ) {
				++$result['replaced'];
			} else {
				++$result['imported'];
			}
		}
		return $result;
	}

	// ----- Admin ------------------------------------------------------------------------

	/** Address of the review screen, with optional extra query args. */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => LinkSentinel_Redirects::PAGE, 'view' => self::VIEW ), $args ), admin_url( 'admin.php' ) );
	}

	/** Box on the Redirects page when QPPR data exists. */
	public static function notice() {
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
			'offsite'           => __( 'Sends visitors to another domain, as QPPR did.', 'link-sentinel' ),
			'meta'              => __( 'Was a meta refresh; becomes a 301 (instant) or 302 (delayed) HTTP redirect.', 'link-sentinel' ),
			'newwindow'         => __( '“Open in new window” is not carried over.', 'link-sentinel' ),
			'nofollow'          => __( '“nofollow” is not carried over.', 'link-sentinel' ),
			'rewrite'           => __( '“Show redirect URL in link” is not carried over.', 'link-sentinel' ),
			'www'               => __( '“http://” added in front of “www”, as QPPR did.', 'link-sentinel' ),
			'slug'              => __( 'Destination read as a path on this site, as QPPR did.', 'link-sentinel' ),
			'override_type'     => __( 'Type comes from QPPR’s global “redirect type” override.', 'link-sentinel' ),
			'unpublished'       => __( 'The post is not published; the rule uses its published address.', 'link-sentinel' ),
			'live_page'         => __( 'This address still shows a page. Link Sentinel only redirects addresses that would be a 404, so the rule waits until that page is unpublished or deleted.', 'link-sentinel' ),
		);
		return isset( $text[ $code ] ) ? $text[ $code ] : $code;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'link-sentinel' ) );
		}
		$p   = self::preview();
		$c   = $p['counts'];
		$msg = isset( $_GET['lsn_msg'] ) ? sanitize_key( wp_unslash( $_GET['lsn_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
		$s   = $p['settings'];
		?>
		<div class="wrap lsn-wrap">
			<h1><?php esc_html_e( 'Import from Quick Page/Post Redirect', 'link-sentinel' ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LinkSentinel_Redirects::PAGE ) ); ?>">&larr; <?php esc_html_e( 'Back to Redirects', 'link-sentinel' ); ?></a></p>

			<?php if ( 'imported' === $msg ) : ?>
				<?php
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
				$done = array();
				foreach ( array( 'imported', 'replaced', 'skipped', 'failed' ) as $k ) {
					$done[ $k ] = isset( $_GET[ $k ] ) ? absint( $_GET[ $k ] ) : 0;
				}
				// phpcs:enable
				?>
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
				</p></div>
			<?php elseif ( 'changed' === $msg ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'The redirects changed after the preview was shown, so nothing was imported. Review the list below and try again.', 'link-sentinel' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $p['items'] ) : ?>
				<p><?php esc_html_e( 'No Quick Page/Post Redirect data was found on this site.', 'link-sentinel' ); ?></p>
			</div>
				<?php
				return;
			endif;
			?>

			<p><?php esc_html_e( 'This is a dry run. Nothing has been imported yet, and importing never changes or deletes what Quick Page/Post Redirect stored.', 'link-sentinel' ); ?></p>
			<ul class="lsn-stats">
				<li class="lsn-stat lsn-ok"><strong><?php echo (int) $c['new']; ?></strong> <?php esc_html_e( 'ready to import', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-redirect"><strong><?php echo (int) $c['same']; ?></strong> <?php esc_html_e( 'already in Link Sentinel', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-blocked"><strong><?php echo (int) $c['conflict']; ?></strong> <?php esc_html_e( 'conflict with your rules', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-error"><strong><?php echo (int) $c['live']; ?></strong> <?php esc_html_e( 'for pages that still exist', 'link-sentinel' ); ?></li>
				<li class="lsn-stat lsn-broken"><strong><?php echo (int) $c['skip']; ?></strong> <?php esc_html_e( 'cannot be imported', 'link-sentinel' ); ?></li>
			</ul>

			<?php
			$warnings = array();
			if ( self::qppr_is_active() ) {
				$warnings[] = __( 'Quick Page/Post Redirect is still active and answers requests before Link Sentinel does. It was closed on WordPress.org for a security issue; deactivate it once the import is done.', 'link-sentinel' );
			}
			if ( ! empty( $s['override_active'] ) ) {
				$warnings[] = __( 'QPPR’s “turn off all redirects” setting is on, so none of these redirects were working. Importing switches them on in Link Sentinel.', 'link-sentinel' );
			}
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
			foreach ( $warnings as $w ) :
				?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $w ); ?></p></div>
			<?php endforeach; ?>

			<?php if ( $c['new'] || $c['conflict'] || $c['live'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lsn-panel">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="fingerprint" value="<?php echo esc_attr( $p['fingerprint'] ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<?php if ( $c['conflict'] ) : ?>
						<p><label><input type="checkbox" name="replace_conflicts" value="1">
							<?php
							/* translators: %d: number of addresses */
							echo esc_html( sprintf( _n( 'Replace my existing Link Sentinel rule for %d address with the QPPR one', 'Replace my existing Link Sentinel rules for %d addresses with the QPPR ones', $c['conflict'], 'link-sentinel' ), $c['conflict'] ) );
							?>
						</label></p>
					<?php endif; ?>
					<?php if ( $c['live'] ) : ?>
						<p><label><input type="checkbox" name="include_live" value="1">
							<?php
							/* translators: %d: number of redirects */
							echo esc_html( sprintf( _n( 'Also import %d redirect for a page that still exists (it takes effect once that page is unpublished or deleted)', 'Also import %d redirects for pages that still exist (they take effect once those pages are unpublished or deleted)', $c['live'], 'link-sentinel' ), $c['live'] ) );
							?>
						</label></p>
					<?php endif; ?>
					<?php submit_button( __( 'Import redirects', 'link-sentinel' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php
			$groups = array(
				'new'      => __( 'Ready to import', 'link-sentinel' ),
				'conflict' => __( 'Conflicts with your Link Sentinel rules', 'link-sentinel' ),
				'live'     => __( 'Pages that still exist', 'link-sentinel' ),
				'same'     => __( 'Already in Link Sentinel', 'link-sentinel' ),
				'skip'     => __( 'Cannot be imported', 'link-sentinel' ),
			);
			foreach ( $groups as $status => $title ) {
				$rows = array_filter(
					$p['items'],
					function ( $item ) use ( $status ) {
						return $item['status'] === $status;
					}
				);
				if ( $rows ) {
					self::render_table( $title, $rows );
				}
			}
			?>
		</div>
		<?php
	}

	private static function render_table( $title, array $rows ) {
		?>
		<h2><?php echo esc_html( $title ); ?> (<?php echo (int) count( $rows ); ?>)</h2>
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
							$edit = get_edit_post_link( (int) $item['ref'] );
							$name = get_post( (int) $item['ref'] ) ? get_the_title( (int) $item['ref'] ) : '';
							/* translators: %d: post ID */
							$label = sprintf( __( 'Post #%d', 'link-sentinel' ), (int) $item['ref'] );
							?>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $label ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $label ); ?>
							<?php endif; ?>
							<?php if ( '' !== $name ) : ?>
								<br><?php echo esc_html( $name ); ?>
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
						<?php foreach ( array_unique( $item['notes'] ) as $note ) : ?>
							<?php if ( in_array( $note, array( 'offsite', 'live_page' ), true ) ) : ?>
								<div><span class="lsn-badge lsn-badge-blocked"><?php echo esc_html( self::describe( $note ) ); ?></span></div>
							<?php else : ?>
								<div class="lsn-error-text"><?php echo esc_html( self::describe( $note ) ); ?></div>
							<?php endif; ?>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function post_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'link-sentinel' ) );
		}
		check_admin_referer( self::ACTION );
		$fingerprint = isset( $_POST['fingerprint'] ) ? sanitize_key( wp_unslash( $_POST['fingerprint'] ) ) : '';
		$preview     = self::preview();
		if ( '' === $fingerprint || ! hash_equals( $preview['fingerprint'], $fingerprint ) ) {
			self::back( array( 'lsn_msg' => 'changed' ) );
		}
		$result = self::import( $preview, ! empty( $_POST['replace_conflicts'] ), ! empty( $_POST['include_live'] ) );
		self::back( array_merge( array( 'lsn_msg' => 'imported' ), $result ) );
	}

	private static function back( array $args ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}
