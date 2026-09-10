<?php
/**
 * Decides whether a URL is alive. Internal links are answered from the
 * database without a request; external ones are fetched in parallel with
 * HEAD first and GET only when a server refuses HEAD.
 *
 * Verdicts:
 *   ok        2xx
 *   redirect  2xx reached through one or more redirects (not broken; shown separately)
 *   broken    404, 410, or a transient failure that has persisted for three checks
 *   blocked   401, 403, 429, 999 — the server refuses automated visitors; not evidence the page is gone
 *   error     5xx, timeout, DNS, TLS — transient until proven otherwise
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Checker {

	/**
	 * @param object[] $links Rows from the links table.
	 * @return array id => result array (status, http_code, final_url, redirect_count, error).
	 */
	public static function check( array $links ) {
		$results = array();
		$remote  = array();
		$internal = array();
		foreach ( $links as $link ) {
			if ( (int) $link->is_internal ) {
				$local = self::check_internal( $link->url );
				if ( null !== $local ) {
					$results[ (int) $link->id ] = $local;
					continue;
				}
				$internal[ (int) $link->id ] = true;
			}
			$remote[ (int) $link->id ] = $link->url;
		}
		if ( $remote ) {
			foreach ( self::check_remote( $remote ) as $id => $r ) {
				// A site that cannot reach itself says nothing about the link.
				if ( isset( $internal[ $id ] ) && 'error' === $r['status'] && 0 === (int) $r['http_code'] ) {
					$r['error']       = __( 'This site could not request itself (loopback). Check the page in a browser.', 'link-sentinel' );
					$r['no_escalate'] = true;
				}
				$results[ $id ] = $r;
			}
		}
		return $results;
	}

	/**
	 * Answer an internal URL from WordPress itself. Returns null when only
	 * a request can tell (a theme route, a rewrite we cannot resolve).
	 */
	public static function check_internal( $url ) {
		$home = untrailingslashit( home_url() );
		if ( untrailingslashit( $url ) === $home ) {
			return self::result( 'ok', 200 );
		}
		// Uploaded files: the filesystem is the truth.
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['baseurl'] ) && 0 === strpos( $url, $uploads['baseurl'] ) ) {
			$path = rawurldecode( substr( $url, strlen( $uploads['baseurl'] ) ) );
			$path = preg_replace( '/\?.*$/', '', $path );
			$file = $uploads['basedir'] . $path;
			if ( file_exists( $file ) ) {
				return self::result( 'ok', 200 );
			}
			// A missing intermediate size still has an original next to it.
			if ( preg_match( '/-\d+x\d+(\.[a-z0-9]+)$/i', $file, $m ) && file_exists( preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $file ) ) ) {
				return self::result( 'ok', 200 );
			}
			return self::result( 'broken', 404, null, 0, __( 'File not found in uploads', 'link-sentinel' ) );
		}
		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && in_array( $post->post_status, array( 'publish', 'inherit' ), true ) ) {
				return self::result( 'ok', 200 );
			}
			return self::result( 'broken', 404, null, 0, $post ? sprintf( /* translators: %s: post status */ __( 'Target is %s', 'link-sentinel' ), $post->post_status ) : __( 'Target was deleted', 'link-sentinel' ) );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$rel  = ltrim( substr( $path, strlen( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) ) ), '/' );
		// Files shipped with WordPress, themes and plugins: check the disk.
		if ( preg_match( '#^(wp-content|wp-includes)/#', $rel ) ) {
			$file = ABSPATH . rawurldecode( $rel );
			return file_exists( $file ) ? self::result( 'ok', 200 ) : self::result( 'broken', 404, null, 0, __( 'File not found', 'link-sentinel' ) );
		}
		// Admin, login, REST and feeds are routes WordPress always answers.
		if ( preg_match( '#^(wp-admin(/|$)|wp-login\.php|wp-json(/|$)|xmlrpc\.php|feed(/|$)|wp-cron\.php)#', $rel ) ) {
			return self::result( 'ok', 200 );
		}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $rel && $query ) {
			return self::answer_query_vars( self::vars_from_query( $query ) );
		}
		return self::answer_query_vars( self::vars_from_rewrite( $rel, $query ) );
	}

	/** Query vars a pretty URL maps to, via the site's own rewrite rules; null when nothing matches. */
	private static function vars_from_rewrite( $rel, $query ) {
		global $wp_rewrite;
		if ( ! $wp_rewrite || ! $wp_rewrite->using_permalinks() ) {
			return null;
		}
		$rules = $wp_rewrite->wp_rewrite_rules();
		if ( ! is_array( $rules ) ) {
			return null;
		}
		$request = rawurldecode( $rel );
		foreach ( $rules as $match => $target ) {
			if ( preg_match( '#^' . $match . '#', $request, $m ) ) {
				$target = preg_replace_callback( '/\$matches\[(\d+)\]/', function ( $x ) use ( $m ) { return isset( $m[ (int) $x[1] ] ) ? $m[ (int) $x[1] ] : ''; }, $target );
				$qs     = (string) wp_parse_url( $target, PHP_URL_QUERY );
				$vars   = self::vars_from_query( $qs );
				if ( $query ) {
					$vars += self::vars_from_query( $query );
				}
				return $vars;
			}
		}
		return null;
	}

	private static function vars_from_query( $qs ) {
		$vars = array();
		parse_str( (string) $qs, $vars );
		return $vars;
	}

	/** Decide from query vars alone, without a request. null = cannot tell, fall back to HTTP. */
	private static function answer_query_vars( $vars ) {
		if ( null === $vars ) {
			return null;
		}
		if ( isset( $vars['p'] ) || isset( $vars['page_id'] ) || isset( $vars['attachment_id'] ) ) {
			$id   = (int) ( isset( $vars['p'] ) ? $vars['p'] : ( isset( $vars['page_id'] ) ? $vars['page_id'] : $vars['attachment_id'] ) );
			$post = get_post( $id );
			return $post && in_array( $post->post_status, array( 'publish', 'inherit' ), true ) ? self::result( 'ok', 200 ) : self::result( 'broken', 404, null, 0, __( 'No such post', 'link-sentinel' ) );
		}
		if ( isset( $vars['category_name'] ) ) {
			$slug = basename( $vars['category_name'] );
			return get_term_by( 'slug', $slug, 'category' ) ? self::result( 'ok', 200 ) : self::result( 'broken', 404, null, 0, __( 'No such category', 'link-sentinel' ) );
		}
		if ( isset( $vars['tag'] ) ) {
			return get_term_by( 'slug', $vars['tag'], 'post_tag' ) ? self::result( 'ok', 200 ) : self::result( 'broken', 404, null, 0, __( 'No such tag', 'link-sentinel' ) );
		}
		if ( isset( $vars['author_name'] ) ) {
			return get_user_by( 'slug', $vars['author_name'] ) ? self::result( 'ok', 200 ) : self::result( 'broken', 404, null, 0, __( 'No such author', 'link-sentinel' ) );
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( isset( $vars[ $tax->query_var ] ) && $tax->query_var ) {
				return get_term_by( 'slug', basename( $vars[ $tax->query_var ] ), $tax->name ) ? self::result( 'ok', 200 ) : self::result( 'broken', 404, null, 0, __( 'No such term', 'link-sentinel' ) );
			}
		}
		if ( isset( $vars['name'] ) || isset( $vars['pagename'] ) ) {
			$slug  = isset( $vars['name'] ) ? $vars['name'] : basename( $vars['pagename'] );
			$found = get_posts( array( 'name' => $slug, 'post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
			return $found ? self::result( 'ok', 200 ) : self::result( 'broken', 404, null, 0, __( 'No published content with this slug', 'link-sentinel' ) );
		}
		foreach ( array( 'year', 'monthnum', 'day', 's', 'feed', 'paged', 'post_type', 'search' ) as $always ) {
			if ( isset( $vars[ $always ] ) ) {
				return self::result( 'ok', 200 );
			}
		}
		return null;
	}

	/**
	 * Fetch many URLs at once.
	 *
	 * @param array $urls id => url.
	 * @return array id => result.
	 */
	public static function check_remote( array $urls ) {
		$results  = array();
		$requests = array();
		foreach ( $urls as $id => $url ) {
			$requests[ $id ] = array( 'url' => $url, 'type' => \WpOrg\Requests\Requests::HEAD );
		}
		$responses = self::multi( $requests );
		$retry     = array();
		foreach ( $responses as $id => $response ) {
			$r = self::interpret( $response );
			// HEAD is optional for servers; any HTTP answer but a clean one gets a
			// GET. A timeout or refused connection would only repeat, so it does not.
			if ( 'ok' !== $r['status'] && 'redirect' !== $r['status'] && $response instanceof \WpOrg\Requests\Response ) {
				$retry[ $id ] = array( 'url' => $urls[ $id ], 'type' => \WpOrg\Requests\Requests::GET );
				continue;
			}
			$results[ $id ] = $r;
		}
		if ( $retry ) {
			foreach ( self::multi( $retry ) as $id => $response ) {
				$results[ $id ] = self::interpret( $response );
			}
		}
		return $results;
	}

	/** request_multiple() fires everything at once; feed it the configured number at a time. */
	private static function multi( array $requests ) {
		$size = max( 1, (int) LinkSentinel_Settings::get( 'concurrency' ) );
		if ( count( $requests ) <= $size ) {
			return self::multi_chunk( $requests );
		}
		$out = array();
		foreach ( array_chunk( $requests, $size, true ) as $chunk ) {
			$out += self::multi_chunk( $chunk );
		}
		return $out;
	}

	private static function multi_chunk( array $requests ) {
		$timeout = (int) LinkSentinel_Settings::get( 'timeout' );
		$options = array(
			'timeout'          => $timeout,
			'connect_timeout'  => min( $timeout, 10 ),
			'follow_redirects' => true,
			'redirects'        => 10,
			'useragent'        => LinkSentinel_Settings::get( 'user_agent' ),
			'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'max_bytes'        => 65536,
			'headers'          => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
			),
		);
		try {
			return \WpOrg\Requests\Requests::request_multiple( $requests, $options );
		} catch ( \Exception $e ) {
			$out = array();
			foreach ( $requests as $id => $r ) {
				$out[ $id ] = $e;
			}
			return $out;
		}
	}

	/** Turn a Requests response (or exception) into a verdict. */
	public static function interpret( $response ) {
		if ( $response instanceof \WpOrg\Requests\Response ) {
			$code      = (int) $response->status_code;
			$redirects = (int) $response->redirects;
			$final     = $redirects > 0 ? (string) $response->url : null;
			$first     = $redirects > 0 && ! empty( $response->history[0] ) ? (int) $response->history[0]->status_code : $code;
			if ( $code >= 200 && $code < 300 ) {
				return $redirects > 0 ? self::result( 'redirect', $first, $final, $redirects ) : self::result( 'ok', $code );
			}
			if ( in_array( $code, array( 401, 403, 429, 999, 405, 406 ), true ) ) {
				$status = LinkSentinel_Settings::get( 'blocked_is_broken' ) ? 'broken' : 'blocked';
				return self::result( $status, $code, $final, $redirects, __( 'The server refuses automated requests', 'link-sentinel' ) );
			}
			if ( in_array( $code, array( 404, 410 ), true ) ) {
				return self::result( 'broken', $code, $final, $redirects );
			}
			if ( $code >= 500 ) {
				return self::result( 'error', $code, $final, $redirects, __( 'Server error', 'link-sentinel' ) );
			}
			if ( $code >= 300 && $code < 400 ) {
				// Redirect that was not followed (loop or limit).
				return self::result( 'error', $code, $final, $redirects, __( 'Redirect could not be followed', 'link-sentinel' ) );
			}
			return self::result( 'error', $code, $final, $redirects, __( 'Unexpected response', 'link-sentinel' ) );
		}
		$msg = $response instanceof \Exception ? $response->getMessage() : __( 'Unknown failure', 'link-sentinel' );
		$low = strtolower( $msg );
		if ( false !== strpos( $low, 'timed out' ) || false !== strpos( $low, 'timeout' ) ) {
			return self::result( 'error', 0, null, 0, __( 'Timed out', 'link-sentinel' ) );
		}
		if ( false !== strpos( $low, 'resolve host' ) || false !== strpos( $low, 'name or service not known' ) || false !== strpos( $low, 'nodename nor servname' ) ) {
			return self::result( 'error', 0, null, 0, __( 'Domain does not resolve', 'link-sentinel' ) );
		}
		if ( false !== strpos( $low, 'ssl' ) || false !== strpos( $low, 'certificate' ) ) {
			return self::result( 'error', 0, null, 0, __( 'TLS certificate problem', 'link-sentinel' ) );
		}
		if ( false !== strpos( $low, 'too many redirects' ) ) {
			return self::result( 'error', 0, null, 0, __( 'Redirect loop', 'link-sentinel' ) );
		}
		return self::result( 'error', 0, null, 0, wp_strip_all_tags( $msg ) );
	}

	private static function result( $status, $code, $final = null, $redirects = 0, $error = '' ) {
		return array(
			'status'         => $status,
			'http_code'      => (int) $code,
			'final_url'      => $final,
			'redirect_count' => (int) $redirects,
			'error'          => (string) $error,
		);
	}
}
