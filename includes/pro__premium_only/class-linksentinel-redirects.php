<?php
/**
 * Redirects for dead URLs on this site. A broken internal link usually means
 * a page that moved; besides fixing the link, the old address should send
 * visitors and search engines to the new one. Only 404s are redirected, so
 * a rule can never shadow a page that exists.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class LinkSentinel_Redirects {

	const PAGE     = 'link-sentinel-redirects';
	const PER_PAGE = 100;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'linksentinel_redirects';
	}

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_filter( 'linksentinel_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_linksentinel_redirect_add', array( __CLASS__, 'post_add' ) );
		add_action( 'admin_post_linksentinel_redirect_delete', array( __CLASS__, 'post_delete' ) );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				from_hash char(32) NOT NULL,
				from_path text NOT NULL,
				to_url text NOT NULL,
				http_code smallint(5) unsigned NOT NULL DEFAULT 301,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				last_hit datetime DEFAULT NULL,
				created datetime NOT NULL,
				import_batch varchar(40) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY from_hash (from_hash),
				KEY import_batch (import_batch)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * The part of a URL (or request URI) that identifies it on this site:
	 * path plus query, without host, site subdirectory or trailing slash.
	 */
	public static function key_for( $url ) {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) ) {
			return '';
		}
		$path      = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
		}
		if ( ! empty( $p['query'] ) ) {
			$path .= '?' . $p['query'];
		}
		return $path;
	}

	/**
	 * Checks and normalises one rule the way it is stored. Returns
	 * array( key, to_url, code ) or WP_Error.
	 */
	public static function prepare( $from_url, $to_url, $code = 301 ) {
		$from_url = trim( (string) $from_url );
		$to_url   = trim( (string) $to_url );
		if ( ! LinkSentinel_Extractor::is_internal( $from_url ) ) {
			return new WP_Error( 'external', __( 'Only URLs on this site can be redirected.', 'link-sentinel' ) );
		}
		if ( 0 === strpos( $to_url, '/' ) ) {
			$to_url = home_url( $to_url );
		}
		$to_url = esc_url_raw( $to_url, array( 'http', 'https' ) );
		if ( '' === $to_url ) {
			return new WP_Error( 'bad_url', __( 'Enter a full URL starting with http(s):// or a path starting with /.', 'link-sentinel' ) );
		}
		$key = self::key_for( $from_url );
		if ( '' === $key || '/' === $key ) {
			return new WP_Error( 'bad_from', __( 'The home page cannot be redirected.', 'link-sentinel' ) );
		}
		if ( LinkSentinel_Extractor::is_internal( $to_url ) && self::key_for( $to_url ) === $key ) {
			return new WP_Error( 'loop', __( 'A URL cannot redirect to itself.', 'link-sentinel' ) );
		}
		$code = in_array( (int) $code, array( 301, 302, 307, 308 ), true ) ? (int) $code : 301;
		return array( $key, $to_url, $code );
	}

	/** Create or replace the rule for $from_url. Returns the row id or WP_Error. */
	public static function add( $from_url, $to_url, $code = 301 ) {
		global $wpdb;
		$rule = self::prepare( $from_url, $to_url, $code );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		list( $key, $to_url, $code ) = $rule;
		$row = self::find( $key );
		if ( $row ) {
			// A rule changed by hand no longer belongs to the import that created it, so undoing that import keeps it.
			$wpdb->update( self::table(), array( 'to_url' => $to_url, 'http_code' => $code, 'import_batch' => '' ), array( 'id' => (int) $row->id ), array( '%s', '%d', '%s' ), array( '%d' ) );
			return (int) $row->id;
		}
		$wpdb->insert(
			self::table(),
			array(
				'from_hash' => md5( $key ),
				'from_path' => $key,
				'to_url'    => $to_url,
				'http_code' => $code,
				'created'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Create many new rules with a few multi-row INSERTs. An address that already
	 * has a rule keeps it (INSERT IGNORE on the unique hash), so this never replaces
	 * anything. Rules are tagged with $batch so they can be removed together.
	 *
	 * @param array  $rules List of array( from_url, to_url, code ).
	 * @param string $batch Import batch id, '' for none.
	 * @return array inserted, existing (address already had a rule) and failed counts.
	 */
	public static function add_many( array $rules, $batch = '', $chunk = 200 ) {
		global $wpdb;
		$out     = array( 'inserted' => 0, 'existing' => 0, 'failed' => 0 );
		$now     = current_time( 'mysql', true );
		$batch   = substr( (string) $batch, 0, 40 );
		$pending = array();
		foreach ( $rules as $r ) {
			$rule = self::prepare( $r[0], $r[1], isset( $r[2] ) ? $r[2] : 301 );
			if ( is_wp_error( $rule ) ) {
				++$out['failed'];
				continue;
			}
			$pending[ md5( $rule[0] ) ] = $rule; // One row per address, as the unique key demands.
		}
		foreach ( array_chunk( $pending, max( 1, (int) $chunk ), true ) as $rows ) {
			$args = array();
			foreach ( $rows as $hash => $rule ) {
				array_push( $args, $hash, $rule[0], $rule[1], $rule[2], $now, $batch );
			}
			$values = implode( ',', array_fill( 0, count( $rows ), '(%s,%s,%s,%d,%s,%s)' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder group per row
			$done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}linksentinel_redirects (from_hash, from_path, to_url, http_code, created, import_batch) VALUES {$values}", $args ) );
			if ( false === $done ) {
				$out['failed'] += count( $rows );
				continue;
			}
			$out['inserted'] += (int) $done;
			$out['existing'] += count( $rows ) - (int) $done;
		}
		return $out;
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/** The rule for a key (see key_for), or null. */
	public static function find( $key ) {
		global $wpdb;
		if ( '' === $key ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}linksentinel_redirects WHERE from_hash = %s", md5( $key ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * The rules for many keys at once, keyed by key; keys without a rule are absent.
	 * One query per $chunk keys instead of one per key.
	 */
	public static function find_many( array $keys, $chunk = 500 ) {
		global $wpdb;
		$by_hash = array();
		foreach ( $keys as $key ) {
			if ( '' !== (string) $key ) {
				$by_hash[ md5( (string) $key ) ] = (string) $key;
			}
		}
		$out = array();
		foreach ( array_chunk( array_keys( $by_hash ), max( 1, (int) $chunk ) ) as $hashes ) {
			$in = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per hash
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}linksentinel_redirects WHERE from_hash IN ({$in})", $hashes ) );
			foreach ( (array) $rows as $row ) {
				if ( isset( $by_hash[ $row->from_hash ] ) ) {
					$out[ $by_hash[ $row->from_hash ] ] = $row;
				}
			}
		}
		return $out;
	}

	public static function for_url( $url ) {
		return self::find( self::key_for( $url ) );
	}

	/** Rules, newest first, one page at a time. */
	public static function all( $limit = 1000, $offset = 0 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}linksentinel_redirects ORDER BY created DESC, id DESC LIMIT %d OFFSET %d", max( 1, (int) $limit ), max( 0, (int) $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function total() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}linksentinel_redirects" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** How many rules an import created that are still unchanged. */
	public static function count_batch( $batch ) {
		global $wpdb;
		if ( '' === (string) $batch ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}linksentinel_redirects WHERE import_batch = %s", (string) $batch ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Remove the rules an import created, except those changed since. Returns how many were removed. */
	public static function delete_batch( $batch ) {
		global $wpdb;
		if ( '' === (string) $batch ) {
			return 0;
		}
		return (int) $wpdb->delete( self::table(), array( 'import_batch' => (string) $batch ), array( '%s' ) );
	}

	/** Front end: a 404 whose address has a rule is redirected. */
	public static function handle() {
		if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$key = self::key_for( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only as a lookup key after esc_url_raw
		$row = self::find( $key );
		if ( ! $row ) {
			return;
		}
		self::redirect_to( $row );
	}

	/** Records the hit and sends the redirect. Exits unless a filter cancels wp_redirect. */
	public static function redirect_to( $row ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}linksentinel_redirects SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql', true ), (int) $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// The target was chosen by an administrator and may be off-site, so wp_safe_redirect would be wrong here.
		if ( wp_redirect( $row->to_url, (int) $row->http_code, 'Link Sentinel' ) ) { // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
	}

	// ----- REST + row action ---------------------------------------------------

	public static function rest() {
		register_rest_route(
			'link-sentinel/v1',
			'/links/(?P<id>\d+)/redirect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_add' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'id'  => array( 'type' => 'integer', 'required' => true ),
					'url' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	public static function rest_add( WP_REST_Request $r ) {
		$link = LinkSentinel_DB::get_link( (int) $r['id'] );
		if ( ! $link ) {
			return new WP_Error( 'not_found', __( 'Link not found.', 'link-sentinel' ), array( 'status' => 404 ) );
		}
		$id = self::add( $link->url, $r->get_param( 'url' ) );
		if ( is_wp_error( $id ) ) {
			$id->add_data( array( 'status' => 400 ) );
			return $id;
		}
		return rest_ensure_response( array( 'ok' => true, 'redirect' => self::for_url( $link->url ) ) );
	}

	public static function row_actions( $actions, $item ) {
		if ( (int) $item->dismissed || ! (int) $item->is_internal || ! in_array( $item->status, array( 'broken', 'error', 'redirect' ), true ) ) {
			return $actions;
		}
		$rule    = self::for_url( $item->url );
		$prefill = $rule ? $rule->to_url : ( 'redirect' === $item->status && $item->final_url ? $item->final_url : '' );
		$label   = $rule ? __( 'Change redirect', 'link-sentinel' ) : __( 'Redirect…', 'link-sentinel' );
		$actions['redirect'] = sprintf( '<button type="button" class="button-link lsn-action" data-action="redirect" data-id="%d" data-prefill="%s">%s</button>', (int) $item->id, esc_attr( $prefill ), esc_html( $label ) );
		return $actions;
	}

	// ----- Admin page --------------------------------------------------------

	public static function menu() {
		add_submenu_page( 'link-sentinel', __( 'Redirects', 'link-sentinel' ), __( 'Redirects', 'link-sentinel' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a screen, changes nothing
		if ( '' !== $view && has_action( 'linksentinel_redirects_view_' . $view ) ) {
			/** Fires instead of the rules list for ?view=<name>; importers render their screens here. */
			do_action( 'linksentinel_redirects_view_' . $view );
			return;
		}
		$total = self::total();
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = isset( $_GET['paged'] ) ? min( $pages, max( 1, absint( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a page, changes nothing
		$rows  = $total ? self::all( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) : array();
		$msg   = isset( $_GET['lsn_msg'] ) ? sanitize_key( wp_unslash( $_GET['lsn_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
		?>
		<div class="wrap lsn-wrap">
			<h1><?php esc_html_e( 'Redirects', 'link-sentinel' ); ?></h1>
			<?php if ( 'added' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirect saved.', 'link-sentinel' ); ?></p></div>
			<?php elseif ( 'deleted' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirect removed.', 'link-sentinel' ); ?></p></div>
			<?php elseif ( 'error' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That redirect could not be saved. Check both addresses.', 'link-sentinel' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'These rules apply only to addresses that would otherwise show a 404, so they can never hide an existing page. Create them from the Broken Links list (“Redirect…”) or here.', 'link-sentinel' ); ?></p>
			<?php
			/** Fires below the Redirects page intro; importers put their offers here. */
			do_action( 'linksentinel_redirects_page_top' );
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="linksentinel_redirect_add">
				<?php wp_nonce_field( 'linksentinel_redirect_add' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lsn-from"><?php esc_html_e( 'From (path on this site)', 'link-sentinel' ); ?></label></th>
						<td><input type="text" id="lsn-from" name="from" class="regular-text code" placeholder="/old-page/" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsn-to"><?php esc_html_e( 'To (URL or path)', 'link-sentinel' ); ?></label></th>
						<td><input type="text" id="lsn-to" name="to" class="regular-text code" placeholder="/new-page/" required>
							<select name="code">
								<option value="301">301</option>
								<option value="302">302</option>
								<option value="307">307</option>
								<option value="308">308</option>
							</select></td>
					</tr>
				</table>
				<?php submit_button( __( 'Add redirect', 'link-sentinel' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2 id="lsn-rules"><?php esc_html_e( 'Active rules', 'link-sentinel' ); ?> (<?php echo esc_html( number_format_i18n( $total ) ); ?>)</h2>
			<?php if ( ! $rows ) : ?>
				<p><?php esc_html_e( 'No redirects yet.', 'link-sentinel' ); ?></p>
			<?php else : ?>
				<?php self::pagination( $total, $paged, $pages ); ?>
				<table class="widefat striped lsn-redirects">
					<thead><tr>
						<th><?php esc_html_e( 'From', 'link-sentinel' ); ?></th>
						<th><?php esc_html_e( 'To', 'link-sentinel' ); ?></th>
						<th><?php esc_html_e( 'Code', 'link-sentinel' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'link-sentinel' ); ?></th>
						<th><?php esc_html_e( 'Last hit', 'link-sentinel' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( $r->from_path ); ?></code></td>
							<td><a href="<?php echo esc_url( $r->to_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $r->to_url ); ?></a></td>
							<td><?php echo (int) $r->http_code; ?></td>
							<td><?php echo (int) $r->hits; ?></td>
							<td><?php echo $r->last_hit ? esc_html( human_time_diff( strtotime( $r->last_hit . ' UTC' ) ) . ' ' . __( 'ago', 'link-sentinel' ) ) : '—'; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lsn-inline-form">
									<input type="hidden" name="action" value="linksentinel_redirect_delete">
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
									<?php wp_nonce_field( 'linksentinel_redirect_delete_' . (int) $r->id ); ?>
									<button type="submit" class="button-link lsn-danger"><?php esc_html_e( 'Remove', 'link-sentinel' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php self::pagination( $total, $paged, $pages ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** "Showing 101–200 of 5,000" and page links, when there is more than one page. */
	private static function pagination( $total, $paged, $pages ) {
		if ( $pages < 2 ) {
			return;
		}
		$first = ( $paged - 1 ) * self::PER_PAGE + 1;
		$last  = min( $total, $paged * self::PER_PAGE );
		?>
		<div class="tablenav"><div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				/* translators: 1: first row shown, 2: last row shown, 3: number of rules */
				echo esc_html( sprintf( __( 'Showing %1$s–%2$s of %3$s', 'link-sentinel' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) ) );
				?>
			</span>
			<?php self::page_links( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ), $paged, $pages, '#lsn-rules' ); ?>
		</div></div>
		<?php
	}

	/**
	 * Previous / "Page 2 of 5" / next links for an admin screen. Only $base and
	 * 'paged' make up the links, so one-off query args such as lsn_msg are not
	 * carried from page to page.
	 */
	public static function page_links( $base, $paged, $pages, $fragment = '' ) {
		if ( $pages < 2 ) {
			return;
		}
		?>
		<span class="pagination-links">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base ) . $fragment ); ?>" aria-label="<?php esc_attr_e( 'First page', 'link-sentinel' ); ?>">&laquo;</a>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base ) . $fragment ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'link-sentinel' ); ?>">&lsaquo;</a>
			<?php endif; ?>
			<span class="paging-input">
				<?php
				/* translators: 1: current page, 2: number of pages */
				echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'link-sentinel' ), number_format_i18n( $paged ), number_format_i18n( $pages ) ) );
				?>
			</span>
			<?php if ( $paged < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base ) . $fragment ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'link-sentinel' ); ?>">&rsaquo;</a>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $pages, $base ) . $fragment ); ?>" aria-label="<?php esc_attr_e( 'Last page', 'link-sentinel' ); ?>">&raquo;</a>
			<?php endif; ?>
		</span>
		<?php
	}

	public static function post_add() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'link-sentinel' ) );
		}
		check_admin_referer( 'linksentinel_redirect_add' );
		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? esc_url_raw( wp_unslash( $_POST['to'] ) ) : '';
		$code = isset( $_POST['code'] ) ? (int) $_POST['code'] : 301;
		if ( 0 === strpos( $from, '/' ) ) {
			$from = home_url( $from );
		}
		$r = self::add( $from, $to, $code );
		self::back( is_wp_error( $r ) ? 'error' : 'added' );
	}

	public static function post_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'link-sentinel' ) );
		}
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line with the id
		check_admin_referer( 'linksentinel_redirect_delete_' . $id );
		self::delete( $id );
		self::back( 'deleted' );
	}

	private static function back( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'lsn_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
