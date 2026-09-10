<?php
/**
 * Runs a scan in small steps so it fits shared hosting: collect links from
 * content in batches, then fetch them in batches. Each step has a time
 * budget and a lock, so the admin page, WP-Cron, or both can drive it.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Scanner {

	const OPTION     = 'linksentinel_scan';
	const LOCK       = 'linksentinel_lock';
	const POST_BATCH = 40;
	const LINK_BATCH = 24;

	public static function state() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'id'         => 0,
				'phase'      => 'idle', // idle | collect | check | done
				'cursor'     => 0,
				'stage'      => 'posts', // posts | menus | comments
				'started'    => 0,
				'finished'   => 0,
				'sources'    => 0,
				'found'      => 0,
				'to_check'   => 0,
				'checked'    => 0,
				'force_all'  => false,
				'trigger'    => 'manual', // manual | schedule
				'last_error' => '',
			)
		);
	}

	private static function save( $state ) {
		update_option( self::OPTION, $state, false );
		return $state;
	}

	/** Begin a fresh scan. $force_all re-fetches every URL regardless of recheck interval. */
	public static function start( $force_all = false, $trigger = 'manual' ) {
		$state = self::state();
		if ( in_array( $state['phase'], array( 'collect', 'check' ), true ) ) {
			return $state;
		}
		$state = array_merge(
			$state,
			array(
				'id'         => (int) $state['id'] + 1,
				'phase'      => 'collect',
				'cursor'     => 0,
				'stage'      => 'posts',
				'started'    => time(),
				'finished'   => 0,
				'sources'    => 0,
				'found'      => 0,
				'to_check'   => 0,
				'checked'    => 0,
				'force_all'  => (bool) $force_all,
				'trigger'    => 'schedule' === $trigger ? 'schedule' : 'manual',
				'last_error' => '',
			)
		);
		self::save( $state );
		self::schedule_tick();
		return $state;
	}

	public static function stop() {
		$state             = self::state();
		$state['phase']    = 'idle';
		$state['finished'] = time();
		wp_clear_scheduled_hook( 'linksentinel_tick' );
		return self::save( $state );
	}

	public static function is_running() {
		return in_array( self::state()['phase'], array( 'collect', 'check' ), true );
	}

	/** Make sure WP-Cron keeps the scan moving when nobody is watching the page. */
	public static function schedule_tick() {
		if ( ! wp_next_scheduled( 'linksentinel_tick' ) ) {
			wp_schedule_single_event( time() + 30, 'linksentinel_tick' );
		}
	}

	/**
	 * Do up to $budget seconds of work. Safe to call from anywhere.
	 */
	public static function step( $budget = 8 ) {
		$state = self::state();
		if ( ! in_array( $state['phase'], array( 'collect', 'check' ), true ) ) {
			return $state;
		}
		if ( get_transient( self::LOCK ) ) {
			return $state; // another runner has it
		}
		set_transient( self::LOCK, 1, max( 30, $budget * 4 ) );
		$timeout  = (int) LinkSentinel_Settings::get( 'timeout' );
		$deadline = microtime( true ) + $budget;
		// A batch of requests cannot be interrupted, so make room for one
		// full round (HEAD, then GET for refusals) past the budget.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $budget + 2 * $timeout + 10 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- one round of requests must be allowed to finish
		}
		$rounds = 0;
		try {
			while ( microtime( true ) < $deadline ) {
				if ( 'collect' === $state['phase'] ) {
					$state = self::collect_step( $state );
				} elseif ( 'check' === $state['phase'] ) {
					// Always make progress; after the first round, do not start
					// another that could not finish inside the budget.
					if ( $rounds > 0 && microtime( true ) + $timeout > $deadline ) {
						break;
					}
					$state = self::check_step( $state );
					$rounds++;
				} else {
					break;
				}
				self::save( $state );
			}
		} catch ( \Throwable $e ) {
			$state['last_error'] = $e->getMessage();
			self::save( $state );
		}
		delete_transient( self::LOCK );
		if ( in_array( $state['phase'], array( 'collect', 'check' ), true ) ) {
			self::schedule_tick();
		} else {
			wp_clear_scheduled_hook( 'linksentinel_tick' );
		}
		return $state;
	}

	// ----- Phase 1: collect --------------------------------------------------

	private static function collect_step( $state ) {
		global $wpdb;
		$settings = LinkSentinel_Settings::all();

		if ( 'posts' === $state['stage'] ) {
			$types    = array_map( 'sanitize_key', (array) $settings['post_types'] );
			$statuses = array_map( 'sanitize_key', (array) $settings['post_statuses'] );
			$ph_types = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$ph_stat  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$args     = array_merge( array( (int) $state['cursor'] ), $types, $statuses, array( self::POST_BATCH ) );
			$ids      = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$ph_types}) AND post_status IN ({$ph_stat}) ORDER BY ID ASC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders are generated to match $args
			if ( ! $ids ) {
				$state['stage']  = 'menus';
				$state['cursor'] = 0;
				return $state;
			}
			foreach ( $ids as $id ) {
				$state['found']  += self::collect_post( (int) $id, (int) $state['id'] );
				$state['sources']++;
				$state['cursor'] = (int) $id;
			}
			return $state;
		}

		if ( 'menus' === $state['stage'] ) {
			if ( ! empty( $settings['scan_menus'] ) ) {
				$state['found'] += self::collect_menus( (int) $state['id'] );
			}
			$state['stage']  = 'widgets';
			$state['cursor'] = 0;
			return $state;
		}

		if ( 'widgets' === $state['stage'] ) {
			if ( ! empty( $settings['scan_widgets'] ) ) {
				$state['found'] += self::collect_widgets( (int) $state['id'] );
			}
			$state['stage']  = 'terms';
			$state['cursor'] = 0;
			return $state;
		}

		if ( 'terms' === $state['stage'] ) {
			if ( ! empty( $settings['scan_terms'] ) ) {
				$state['found'] += self::collect_terms( (int) $state['id'] );
			}
			$state['stage']  = 'comments';
			$state['cursor'] = 0;
			return $state;
		}

		if ( 'comments' === $state['stage'] ) {
			if ( empty( $settings['scan_comments'] ) ) {
				return self::finish_collect( $state );
			}
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID > %d AND comment_approved = '1' ORDER BY comment_ID ASC LIMIT %d", (int) $state['cursor'], self::POST_BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $ids ) {
				return self::finish_collect( $state );
			}
			foreach ( $ids as $id ) {
				$c = get_comment( $id );
				if ( $c ) {
					$state['found'] += self::store( 'comment', (int) $id, 'content', LinkSentinel_Extractor::extract( $c->comment_content, get_permalink( $c->comment_post_ID ) ), (int) $state['id'] );
					$state['sources']++;
				}
				$state['cursor'] = (int) $id;
			}
			return $state;
		}
		return self::finish_collect( $state );
	}

	private static function finish_collect( $state ) {
		LinkSentinel_DB::purge_stale( (int) $state['id'] );
		if ( ! empty( $state['force_all'] ) ) {
			global $wpdb;
			$wpdb->query( "UPDATE {$wpdb->prefix}linksentinel_links SET last_checked = NULL WHERE dismissed = 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$state['phase']    = 'check';
		$state['cursor']   = 0;
		$state['to_check'] = LinkSentinel_DB::count_to_check( (int) LinkSentinel_Settings::get( 'recheck_hours' ) );
		$state['checked']  = 0;
		return $state;
	}

	/** Extract and store one post's links. Returns how many were found. */
	public static function collect_post( $post_id, $scan_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0;
		}
		$base  = get_permalink( $post ) ? get_permalink( $post ) : home_url( '/' );
		$found = LinkSentinel_Extractor::extract( $post->post_content, $base );
		if ( '' !== $post->post_excerpt ) {
			foreach ( LinkSentinel_Extractor::extract( $post->post_excerpt, $base ) as $f ) {
				$found[] = $f;
			}
		}
		return self::store( 'post', $post_id, 'content', $found, $scan_id );
	}

	private static function collect_menus( $scan_id ) {
		$n     = 0;
		$items = get_posts(
			array(
				'post_type'      => 'nav_menu_item',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_key'       => '_menu_item_type', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'custom', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $items as $id ) {
			$url = get_post_meta( $id, '_menu_item_url', true );
			if ( ! $url ) {
				continue;
			}
			$title = get_the_title( $id );
			$n    += self::store( 'menu', (int) $id, '_menu_item_url', array( array( 'url' => LinkSentinel_Extractor::normalize( $url, home_url( '/' ) ), 'raw' => $url, 'element' => 'a', 'anchor' => $title ) ), $scan_id );
		}
		return $n;
	}

	/** Block widgets live in one option, one HTML blob per instance. */
	private static function collect_widgets( $scan_id ) {
		$n         = 0;
		$instances = get_option( 'widget_block', array() );
		if ( ! is_array( $instances ) ) {
			return 0;
		}
		foreach ( $instances as $key => $instance ) {
			if ( ! is_int( $key ) || empty( $instance['content'] ) ) {
				continue;
			}
			$n += self::store( 'widget', (int) $key, 'content', LinkSentinel_Extractor::extract( $instance['content'], home_url( '/' ) ), $scan_id );
		}
		return $n;
	}

	/** Category, tag and custom taxonomy descriptions. */
	private static function collect_terms( $scan_id ) {
		$n     = 0;
		$taxes = get_taxonomies( array( 'public' => true ), 'names' );
		$terms = get_terms( array( 'taxonomy' => array_values( $taxes ), 'hide_empty' => false, 'fields' => 'all', 'number' => 2000 ) );
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		foreach ( $terms as $term ) {
			if ( '' === trim( (string) $term->description ) ) {
				continue;
			}
			$link = get_term_link( $term );
			$n   += self::store( 'term', (int) $term->term_id, 'description', LinkSentinel_Extractor::extract( $term->description, is_wp_error( $link ) ? home_url( '/' ) : $link ), $scan_id );
		}
		return $n;
	}

	/** Persist extracted links for one source, deduplicated per URL. */
	private static function store( $source_type, $source_id, $field, array $found, $scan_id ) {
		$rules = LinkSentinel_Settings::exclusions();
		LinkSentinel_DB::delete_occurrences_for_source( $source_type, $source_id );
		$seen = array();
		$n    = 0;
		foreach ( $found as $f ) {
			if ( empty( $f['url'] ) || isset( $seen[ $f['url'] ] ) ) {
				continue;
			}
			if ( LinkSentinel_Extractor::is_excluded( $f['url'], $rules ) ) {
				continue;
			}
			$seen[ $f['url'] ] = true;
			$link_id           = LinkSentinel_DB::upsert_link( $f['url'], LinkSentinel_Extractor::is_internal( $f['url'] ) );
			LinkSentinel_DB::add_occurrence( $link_id, $source_type, $source_id, $field, $f['element'], $f['anchor'], $f['raw'], $scan_id );
			$n++;
		}
		return $n;
	}

	// ----- Phase 2: check ----------------------------------------------------

	private static function check_step( $state ) {
		$hours = (int) LinkSentinel_Settings::get( 'recheck_hours' );
		$batch = min( self::LINK_BATCH, max( 2, 2 * (int) LinkSentinel_Settings::get( 'concurrency' ) ) );
		$links = LinkSentinel_DB::links_to_check( $batch, $hours );
		if ( ! $links ) {
			$state['phase']    = 'done';
			$state['finished'] = time();
			self::save( $state );
			LinkSentinel_Notifier::maybe_send( $state );
			return $state;
		}
		$results = LinkSentinel_Checker::check( $links );
		foreach ( $links as $link ) {
			$id = (int) $link->id;
			LinkSentinel_DB::save_result( $id, isset( $results[ $id ] ) ? $results[ $id ] : array( 'status' => 'error', 'http_code' => 0, 'error' => 'No result' ) );
			$state['checked']++;
		}
		return $state;
	}

	/** Re-read one source after an edit so the table matches the content. */
	public static function recollect( $source_type, $source_id ) {
		$scan_id = (int) self::state()['id'];
		if ( 'post' === $source_type ) {
			return self::collect_post( (int) $source_id, $scan_id );
		}
		if ( 'comment' === $source_type ) {
			$c = get_comment( (int) $source_id );
			return $c ? self::store( 'comment', (int) $source_id, 'content', LinkSentinel_Extractor::extract( $c->comment_content, get_permalink( $c->comment_post_ID ) ), $scan_id ) : 0;
		}
		if ( 'menu' === $source_type ) {
			return self::collect_menus( $scan_id );
		}
		if ( 'widget' === $source_type ) {
			return self::collect_widgets( $scan_id );
		}
		if ( 'term' === $source_type ) {
			$term = get_term( (int) $source_id );
			if ( ! $term || is_wp_error( $term ) ) {
				LinkSentinel_DB::delete_occurrences_for_source( 'term', (int) $source_id );
				return 0;
			}
			$link = get_term_link( $term );
			return self::store( 'term', (int) $term->term_id, 'description', LinkSentinel_Extractor::extract( $term->description, is_wp_error( $link ) ? home_url( '/' ) : $link ), $scan_id );
		}
		return 0;
	}

	/** Re-fetch specific links right now (row action). */
	public static function recheck( array $ids ) {
		$links = array();
		foreach ( $ids as $id ) {
			$l = LinkSentinel_DB::get_link( (int) $id );
			if ( $l ) {
				$links[] = $l;
			}
		}
		if ( ! $links ) {
			return array();
		}
		$results = LinkSentinel_Checker::check( $links );
		foreach ( $links as $link ) {
			$id = (int) $link->id;
			if ( isset( $results[ $id ] ) ) {
				LinkSentinel_DB::save_result( $id, $results[ $id ] );
			}
		}
		return $results;
	}

	/** Rescan one post after it was saved, so the table never lags an edit. */
	public static function on_post_saved( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$settings = LinkSentinel_Settings::all();
		if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
			return;
		}
		if ( ! in_array( $post->post_status, (array) $settings['post_statuses'], true ) ) {
			LinkSentinel_DB::delete_occurrences_for_source( 'post', $post_id );
			return;
		}
		$state = self::state();
		self::collect_post( $post_id, (int) $state['id'] );
	}
}
