<?php
/**
 * The links table.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LinkSentinel_List_Table extends WP_List_Table {

	public $view = 'broken';

	public function __construct() {
		parent::__construct( array( 'singular' => 'link', 'plural' => 'links', 'ajax' => false ) );
		$view       = isset( $_REQUEST['view'] ) ? sanitize_key( wp_unslash( $_REQUEST['view'] ) ) : 'broken'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->view = in_array( $view, array( 'broken', 'redirect', 'blocked', 'error', 'ok', 'dismissed', 'all' ), true ) ? $view : 'broken';
	}

	protected function get_views() {
		$c      = LinkSentinel_DB::counts();
		$labels = array(
			'broken'    => __( 'Broken', 'link-sentinel' ),
			'redirect'  => __( 'Redirects', 'link-sentinel' ),
			'blocked'   => __( 'Blocked', 'link-sentinel' ),
			'error'     => __( 'Unreachable', 'link-sentinel' ),
			'ok'        => __( 'Fine', 'link-sentinel' ),
			'dismissed' => __( 'Dismissed', 'link-sentinel' ),
			'all'       => __( 'All', 'link-sentinel' ),
		);
		$views = array();
		foreach ( $labels as $key => $label ) {
			$n       = 'all' === $key ? $c['all'] - $c['dismissed'] : $c[ $key ];
			$class   = $key === $this->view ? ' class="current"' : '';
			$views[] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( add_query_arg( array( 'page' => LinkSentinel_Admin::PAGE, 'view' => $key ), admin_url( 'admin.php' ) ) ), $class, esc_html( $label ), (int) $n );
		}
		return $views;
	}

	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox">',
			'url'          => __( 'Link', 'link-sentinel' ),
			'status'       => __( 'Status', 'link-sentinel' ),
			'found_in'     => __( 'Found in', 'link-sentinel' ),
			'last_checked' => __( 'Checked', 'link-sentinel' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'url'          => array( 'url', false ),
			'status'       => array( 'http_code', false ),
			'found_in'     => array( 'occurrences', false ),
			'last_checked' => array( 'last_checked', true ),
		);
	}

	protected function get_bulk_actions() {
		$actions = array( 'recheck' => __( 'Re-check', 'link-sentinel' ) );
		if ( 'dismissed' === $this->view ) {
			$actions['restore'] = __( 'Restore', 'link-sentinel' );
		} else {
			$actions['dismiss'] = __( 'Dismiss', 'link-sentinel' );
		}
		return $actions;
	}

	public function process_bulk_action() {
		$action = $this->current_action();
		if ( ! $action || empty( $_POST['link'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-links' );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ids = array_map( 'absint', (array) wp_unslash( $_POST['link'] ) );
		if ( 'recheck' === $action ) {
			LinkSentinel_DB::mark_unchecked( $ids );
			LinkSentinel_Scanner::recheck( $ids );
		} elseif ( 'dismiss' === $action || 'restore' === $action ) {
			foreach ( $ids as $id ) {
				LinkSentinel_DB::set_dismissed( $id, 'dismiss' === $action );
			}
		}
		wp_safe_redirect( remove_query_arg( array( 'action', 'action2', 'link', '_wpnonce', '_wp_http_referer' ) ) );
		exit;
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'linksentinel_per_page', 30 );
		$paged    = $this->get_pagenum();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'last_checked';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		// phpcs:enable
		$result = LinkSentinel_DB::query(
			array(
				'view'     => $this->view,
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);
		$this->items = $result['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'url' );
		$this->set_pagination_args( array( 'total_items' => $result['total'], 'per_page' => $per_page ) );
	}

	public function no_items() {
		if ( 'broken' === $this->view ) {
			esc_html_e( 'No broken links. Either everything is fine or no scan has run yet.', 'link-sentinel' );
		} else {
			esc_html_e( 'Nothing here.', 'link-sentinel' );
		}
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="link[]" value="%d">', (int) $item->id );
	}

	protected function column_url( $item ) {
		$url  = $item->url;
		$show = mb_strlen( $url ) > 90 ? mb_substr( $url, 0, 87 ) . '…' : $url;
		$html = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer" class="lsn-url" title="%s">%s</a>', esc_url( $url ), esc_attr( $url ), esc_html( $show ) );
		if ( 'redirect' === $item->status && $item->final_url ) {
			$html .= '<br><span class="lsn-final">→ ' . esc_html( mb_strlen( $item->final_url ) > 90 ? mb_substr( $item->final_url, 0, 87 ) . '…' : $item->final_url ) . '</span>';
		}
		$has_anchor = false;
		foreach ( LinkSentinel_DB::occurrences( (int) $item->id, 50 ) as $o ) {
			if ( 'a' === $o->element ) {
				$has_anchor = true;
				break;
			}
		}
		$actions = array(
			'recheck' => sprintf( '<button type="button" class="button-link lsn-action" data-action="recheck" data-id="%d">%s</button>', (int) $item->id, esc_html__( 'Re-check', 'link-sentinel' ) ),
			'edit'    => sprintf( '<button type="button" class="button-link lsn-action" data-action="url" data-id="%d" data-url="%s">%s</button>', (int) $item->id, esc_attr( $url ), esc_html__( 'Edit URL', 'link-sentinel' ) ),
		);
		if ( 'redirect' === $item->status && $item->final_url ) {
			$actions['fix'] = sprintf( '<button type="button" class="button-link lsn-action" data-action="url" data-id="%d" data-url="%s" data-prefill="%s">%s</button>', (int) $item->id, esc_attr( $url ), esc_attr( $item->final_url ), esc_html__( 'Use final URL', 'link-sentinel' ) );
		}
		if ( $has_anchor ) {
			$actions['unlink'] = sprintf( '<button type="button" class="button-link lsn-action lsn-danger" data-action="unlink" data-id="%d">%s</button>', (int) $item->id, esc_html__( 'Unlink', 'link-sentinel' ) );
		}
		if ( (int) $item->dismissed ) {
			$actions['restore'] = sprintf( '<button type="button" class="button-link lsn-action" data-action="restore" data-id="%d">%s</button>', (int) $item->id, esc_html__( 'Restore', 'link-sentinel' ) );
		} else {
			$actions['dismiss'] = sprintf( '<button type="button" class="button-link lsn-action" data-action="dismiss" data-id="%d">%s</button>', (int) $item->id, esc_html__( 'Dismiss', 'link-sentinel' ) );
		}
		return $html . $this->row_actions( $actions );
	}

	protected function column_status( $item ) {
		$labels = array(
			'broken'    => __( 'Broken', 'link-sentinel' ),
			'redirect'  => __( 'Redirect', 'link-sentinel' ),
			'blocked'   => __( 'Blocked', 'link-sentinel' ),
			'error'     => __( 'Unreachable', 'link-sentinel' ),
			'ok'        => __( 'Fine', 'link-sentinel' ),
			'unchecked' => __( 'Not checked', 'link-sentinel' ),
		);
		$label = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status;
		$code  = (int) $item->http_code ? ' ' . (int) $item->http_code : '';
		$html  = sprintf( '<span class="lsn-badge lsn-badge-%s">%s%s</span>', esc_attr( $item->status ), esc_html( $label ), esc_html( $code ) );
		if ( $item->error ) {
			$html .= '<br><span class="lsn-error-text">' . esc_html( $item->error ) . '</span>';
		}
		if ( 'error' === $item->status && (int) $item->fail_count ) {
			/* translators: %d: consecutive failures */
			$html .= '<br><span class="description">' . esc_html( sprintf( _n( '%d failure in a row', '%d failures in a row', (int) $item->fail_count, 'link-sentinel' ), (int) $item->fail_count ) ) . '</span>';
		}
		if ( (int) $item->is_internal ) {
			$html .= '<br><span class="description">' . esc_html__( 'internal', 'link-sentinel' ) . '</span>';
		}
		return $html;
	}

	protected function column_found_in( $item ) {
		$occ   = LinkSentinel_DB::occurrences( (int) $item->id, 4 );
		$total = (int) $item->occurrences;
		$parts = array();
		foreach ( array_slice( $occ, 0, 3 ) as $o ) {
			$parts[] = $this->describe_occurrence( $o );
		}
		if ( $total > 3 ) {
			/* translators: %d: number of additional places */
			$parts[] = '<span class="description">' . esc_html( sprintf( __( '+%d more', 'link-sentinel' ), $total - 3 ) ) . '</span>';
		}
		return implode( '<br>', $parts );
	}

	private function describe_occurrence( $o ) {
		$anchor = '' !== $o->anchor_text ? ' <span class="lsn-anchor">“' . esc_html( mb_substr( $o->anchor_text, 0, 60 ) ) . '”</span>' : '';
		$kind   = 'a' === $o->element ? '' : ' <span class="description">(' . esc_html( $o->element ) . ')</span>';
		if ( 'post' === $o->source_type ) {
			$title = get_the_title( (int) $o->source_id );
			$title = '' !== $title ? $title : __( '(no title)', 'link-sentinel' );
			$edit  = get_edit_post_link( (int) $o->source_id );
			$type  = get_post_type_object( (string) get_post_type( (int) $o->source_id ) );
			$label = $type ? $type->labels->singular_name : '';
			return ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . ' <span class="description">' . esc_html( $label ) . '</span>' . $anchor . $kind;
		}
		if ( 'menu' === $o->source_type ) {
			return '<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">' . esc_html__( 'Menu item', 'link-sentinel' ) . '</a>' . $anchor;
		}
		if ( 'comment' === $o->source_type ) {
			return '<a href="' . esc_url( admin_url( 'comment.php?action=editcomment&c=' . (int) $o->source_id ) ) . '">' . esc_html__( 'Comment', 'link-sentinel' ) . ' #' . (int) $o->source_id . '</a>' . $anchor;
		}
		if ( 'widget' === $o->source_type ) {
			return '<a href="' . esc_url( admin_url( 'widgets.php' ) ) . '">' . esc_html__( 'Block widget', 'link-sentinel' ) . '</a>' . $anchor . $kind;
		}
		if ( 'term' === $o->source_type ) {
			$term = get_term( (int) $o->source_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$tax   = get_taxonomy( $term->taxonomy );
				$label = $tax ? $tax->labels->singular_name : $term->taxonomy;
				$edit  = get_edit_term_link( $term->term_id, $term->taxonomy );
				return ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $term->name ) . '</a>' : esc_html( $term->name ) ) . ' <span class="description">' . esc_html( $label ) . '</span>' . $anchor . $kind;
			}
		}
		return esc_html( $o->source_type . ' #' . $o->source_id );
	}

	protected function column_last_checked( $item ) {
		if ( ! $item->last_checked ) {
			return '<span class="description">—</span>';
		}
		$ts = strtotime( $item->last_checked . ' UTC' );
		/* translators: %s: human time diff */
		return esc_html( sprintf( __( '%s ago', 'link-sentinel' ), human_time_diff( $ts ) ) );
	}
}
