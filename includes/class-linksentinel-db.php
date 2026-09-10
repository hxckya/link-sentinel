<?php
/**
 * Storage: one row per distinct URL, one row per place it appears.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class LinkSentinel_DB {

	const DB_VERSION = '1';

	public static function links_table() {
		global $wpdb;
		return $wpdb->prefix . 'linksentinel_links';
	}

	public static function occurrences_table() {
		global $wpdb;
		return $wpdb->prefix . 'linksentinel_occurrences';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$links   = self::links_table();
		$occ     = self::occurrences_table();

		dbDelta(
			"CREATE TABLE {$links} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url text NOT NULL,
				url_hash char(32) NOT NULL,
				host varchar(191) NOT NULL DEFAULT '',
				is_internal tinyint(1) NOT NULL DEFAULT 0,
				status varchar(16) NOT NULL DEFAULT 'unchecked',
				http_code smallint(5) unsigned NOT NULL DEFAULT 0,
				final_url text,
				redirect_count tinyint(3) unsigned NOT NULL DEFAULT 0,
				error varchar(255) NOT NULL DEFAULT '',
				fail_count tinyint(3) unsigned NOT NULL DEFAULT 0,
				dismissed tinyint(1) NOT NULL DEFAULT 0,
				first_seen datetime NOT NULL,
				last_checked datetime DEFAULT NULL,
				check_count int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY url_hash (url_hash),
				KEY status (status,dismissed),
				KEY last_checked (last_checked)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$occ} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				link_id bigint(20) unsigned NOT NULL,
				source_type varchar(20) NOT NULL DEFAULT 'post',
				source_id bigint(20) unsigned NOT NULL,
				field varchar(64) NOT NULL DEFAULT 'content',
				element varchar(10) NOT NULL DEFAULT 'a',
				anchor_text varchar(255) NOT NULL DEFAULT '',
				raw_url text NOT NULL,
				scan_id int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY link_id (link_id),
				KEY source (source_type,source_id),
				KEY scan_id (scan_id)
			) {$charset};"
		);
		update_option( 'linksentinel_db_version', self::DB_VERSION );
	}

	/** Insert the URL if new; return its id either way. */
	public static function upsert_link( $url, $is_internal ) {
		global $wpdb;
		$hash  = md5( $url );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}linksentinel_links WHERE url_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id ) {
			return (int) $id;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$wpdb->insert(
			self::links_table(),
			array(
				'url'         => $url,
				'url_hash'    => $hash,
				'host'        => $host ? strtolower( $host ) : '',
				'is_internal' => $is_internal ? 1 : 0,
				'first_seen'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function add_occurrence( $link_id, $source_type, $source_id, $field, $element, $anchor, $raw_url, $scan_id ) {
		global $wpdb;
		$wpdb->insert(
			self::occurrences_table(),
			array(
				'link_id'     => $link_id,
				'source_type' => $source_type,
				'source_id'   => $source_id,
				'field'       => $field,
				'element'     => $element,
				'anchor_text' => mb_substr( $anchor, 0, 255 ),
				'raw_url'     => $raw_url,
				'scan_id'     => $scan_id,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/** Drop what an earlier scan saw but this one did not, then orphaned URLs. */
	public static function purge_stale( $scan_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}linksentinel_occurrences WHERE scan_id <> %d", $scan_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE l FROM {$wpdb->prefix}linksentinel_links l LEFT JOIN {$wpdb->prefix}linksentinel_occurrences o ON o.link_id = l.id WHERE o.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Remove one source's occurrences (before re-collecting it). */
	public static function delete_occurrences_for_source( $source_type, $source_id ) {
		global $wpdb;
		$wpdb->delete( self::occurrences_table(), array( 'source_type' => $source_type, 'source_id' => $source_id ), array( '%s', '%d' ) );
	}

	/** Links due for a fetch: never checked, or checked before the cutoff. */
	public static function links_to_check( $limit, $recheck_hours ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $recheck_hours * HOUR_IN_SECONDS );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}linksentinel_links WHERE dismissed = 0 AND (last_checked IS NULL OR last_checked < %s) ORDER BY last_checked IS NULL DESC, last_checked ASC LIMIT %d", $cutoff, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function link_by_url( $url ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}linksentinel_links WHERE url_hash = %s", md5( $url ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function occurrence_count( $link_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}linksentinel_occurrences WHERE link_id = %d", $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count_to_check( $recheck_hours ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $recheck_hours * HOUR_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}linksentinel_links WHERE dismissed = 0 AND (last_checked IS NULL OR last_checked < %s)", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_link( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}linksentinel_links WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function save_result( $id, $result ) {
		global $wpdb;
		$link = self::get_link( $id );
		if ( ! $link ) {
			return;
		}
		// Transient failures only count as broken once they persist.
		$fail_count = 0;
		$status     = $result['status'];
		if ( 'error' === $status && empty( $result['no_escalate'] ) ) {
			$fail_count = (int) $link->fail_count + 1;
			if ( $fail_count >= 3 ) {
				$status = 'broken';
			}
		}
		$wpdb->update(
			self::links_table(),
			array(
				'status'         => $status,
				'http_code'      => (int) $result['http_code'],
				'final_url'      => isset( $result['final_url'] ) ? $result['final_url'] : null,
				'redirect_count' => isset( $result['redirect_count'] ) ? (int) $result['redirect_count'] : 0,
				'error'          => isset( $result['error'] ) ? mb_substr( $result['error'], 0, 255 ) : '',
				'fail_count'     => $fail_count,
				'last_checked'   => current_time( 'mysql', true ),
				'check_count'    => (int) $link->check_count + 1,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%d' ),
			array( '%d' )
		);
	}

	public static function set_dismissed( $id, $flag ) {
		global $wpdb;
		$wpdb->update( self::links_table(), array( 'dismissed' => $flag ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/** Mark a link so the next step fetches it again. */
	public static function mark_unchecked( $ids ) {
		global $wpdb;
		$ids = array_map( 'intval', (array) $ids );
		if ( ! $ids ) {
			return;
		}
		$in    = implode( ',', $ids );
		$wpdb->query( "UPDATE {$wpdb->prefix}linksentinel_links SET last_checked = NULL, status = 'unchecked' WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function counts() {
		global $wpdb;
		$rows  = $wpdb->get_results( "SELECT status, dismissed, COUNT(*) AS n FROM {$wpdb->prefix}linksentinel_links GROUP BY status, dismissed" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array( 'all' => 0, 'broken' => 0, 'redirect' => 0, 'blocked' => 0, 'error' => 0, 'ok' => 0, 'unchecked' => 0, 'dismissed' => 0 );
		foreach ( $rows as $r ) {
			$out['all'] += (int) $r->n;
			if ( (int) $r->dismissed ) {
				$out['dismissed'] += (int) $r->n;
				continue;
			}
			if ( isset( $out[ $r->status ] ) ) {
				$out[ $r->status ] += (int) $r->n;
			}
		}
		return $out;
	}

	/**
	 * Rows for the admin table.
	 *
	 * @param array $args view (broken|redirect|blocked|error|ok|dismissed|all), search, orderby, order, per_page, paged.
	 */
	public static function query( $args ) {
		global $wpdb;
		$where = array( '1=1' );
		$view  = isset( $args['view'] ) ? $args['view'] : 'broken';
		if ( 'dismissed' === $view ) {
			$where[] = 'l.dismissed = 1';
		} elseif ( 'all' === $view ) {
			$where[] = 'l.dismissed = 0';
		} else {
			$where[] = $wpdb->prepare( 'l.dismissed = 0 AND l.status = %s', $view );
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( 'l.url LIKE %s', $like );
		}
		$orderby = in_array( isset( $args['orderby'] ) ? $args['orderby'] : '', array( 'url', 'status', 'http_code', 'last_checked', 'occurrences' ), true ) ? $args['orderby'] : 'last_checked';
		$order   = ( isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ) ? 'ASC' : 'DESC';
		$per     = max( 1, (int) $args['per_page'] );
		$offset  = max( 0, ( (int) $args['paged'] - 1 ) * $per );
		$w       = implode( ' AND ', $where );

		// $w holds only literals and $wpdb->prepare()d fragments (see above).
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}linksentinel_links l WHERE {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql   = $wpdb->prepare( "SELECT l.*, (SELECT COUNT(*) FROM {$wpdb->prefix}linksentinel_occurrences o WHERE o.link_id = l.id) AS occurrences FROM {$wpdb->prefix}linksentinel_links l WHERE {$w} ORDER BY {$orderby} {$order}, l.id DESC LIMIT %d OFFSET %d", $per, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $w is built from literals and prepare()d fragments above
		return array( 'rows' => $rows, 'total' => $total );
	}

	public static function occurrences( $link_id, $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}linksentinel_occurrences WHERE link_id = %d ORDER BY id ASC LIMIT %d", $link_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function delete_link( $id ) {
		global $wpdb;
		$wpdb->delete( self::occurrences_table(), array( 'link_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::links_table(), array( 'id' => $id ), array( '%d' ) );
	}

	public static function move_occurrences( $from_id, $to_id ) {
		global $wpdb;
		$wpdb->update( self::occurrences_table(), array( 'link_id' => $to_id ), array( 'link_id' => $from_id ), array( '%d' ), array( '%d' ) );
	}

	public static function update_raw_url( $link_id, $new_raw ) {
		global $wpdb;
		$wpdb->update( self::occurrences_table(), array( 'raw_url' => $new_raw ), array( 'link_id' => $link_id ), array( '%s' ), array( '%d' ) );
	}
}
// phpcs:enable
