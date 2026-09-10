<?php
/**
 * Writes fixes back into content: swap a URL everywhere it appears, or
 * unwrap an anchor and keep its text. Edits go through wp_update_post so
 * revisions keep the previous version.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Fixer {

	/** Replace the link's URL in every occurrence. Returns the number of sources updated, or WP_Error. */
	public static function replace_url( $link_id, $new_url ) {
		$link = LinkSentinel_DB::get_link( $link_id );
		if ( ! $link ) {
			return new WP_Error( 'not_found', __( 'Link not found.', 'link-sentinel' ) );
		}
		$new_url = trim( (string) $new_url );
		if ( ! preg_match( '#^https?://#i', $new_url ) && ! preg_match( '#^/#', $new_url ) ) {
			return new WP_Error( 'bad_url', __( 'Enter a full URL starting with http(s):// or a path starting with /.', 'link-sentinel' ) );
		}
		$updated = 0;
		$sources = array();
		foreach ( LinkSentinel_DB::occurrences( $link_id, 500 ) as $o ) {
			if ( self::rewrite_source( $o, $o->raw_url, $new_url ) ) {
				$updated++;
			}
			$sources[ $o->source_type . ':' . $o->source_id ] = $o;
		}
		// The table follows the content: re-read every touched source, then
		// drop the old URL only if nothing still points at it.
		foreach ( $sources as $o ) {
			LinkSentinel_Scanner::recollect( $o->source_type, (int) $o->source_id );
		}
		if ( 0 === LinkSentinel_DB::occurrence_count( $link_id ) ) {
			LinkSentinel_DB::delete_link( $link_id );
		}
		$abs = LinkSentinel_Extractor::normalize( $new_url, home_url( '/' ) );
		$new = $abs ? LinkSentinel_DB::link_by_url( $abs ) : null;
		if ( $new ) {
			LinkSentinel_Scanner::recheck( array( (int) $new->id ) );
		}
		return $updated;
	}

	/** Remove the anchor tags pointing at this URL, keeping their text. */
	public static function unlink( $link_id ) {
		$link = LinkSentinel_DB::get_link( $link_id );
		if ( ! $link ) {
			return new WP_Error( 'not_found', __( 'Link not found.', 'link-sentinel' ) );
		}
		$updated = 0;
		$sources = array();
		foreach ( LinkSentinel_DB::occurrences( $link_id, 500 ) as $o ) {
			if ( 'a' !== $o->element ) {
				continue; // images and embeds have no text to keep; edit those by hand
			}
			$sources[ $o->source_type . ':' . $o->source_id ] = $o;
			$content = self::read_source( $o );
			if ( null === $content ) {
				continue;
			}
			$q       = preg_quote( $o->raw_url, '#' );
			$q_enc   = preg_quote( esc_url( $o->raw_url ), '#' );
			$pattern = '#<a\b[^>]*?\bhref\s*=\s*(["\'])(?:' . $q . '|' . $q_enc . ')\1[^>]*>(.*?)</a\s*>#is';
			$new     = preg_replace( $pattern, '$2', $content );
			if ( null !== $new && $new !== $content && self::write_source( $o, $new ) ) {
				$updated++;
			}
		}
		foreach ( $sources as $o ) {
			LinkSentinel_Scanner::recollect( $o->source_type, (int) $o->source_id );
		}
		if ( 0 === LinkSentinel_DB::occurrence_count( $link_id ) ) {
			LinkSentinel_DB::delete_link( $link_id );
		}
		return $updated;
	}

	private static function rewrite_source( $o, $old_raw, $new_url ) {
		if ( 'menu' === $o->source_type ) {
			return (bool) update_post_meta( (int) $o->source_id, '_menu_item_url', esc_url_raw( $new_url ) );
		}
		$content = self::read_source( $o );
		if ( null === $content ) {
			return false;
		}
		// Only the attribute value is touched, in both raw and entity-encoded spellings.
		$search  = array( '"' . $old_raw . '"', "'" . $old_raw . "'", '"' . esc_url( $old_raw ) . '"', "'" . esc_url( $old_raw ) . "'" );
		$replace = array( '"' . $new_url . '"', "'" . $new_url . "'", '"' . esc_url( $new_url ) . '"', "'" . esc_url( $new_url ) . "'" );
		$new     = str_replace( array_unique( $search ), array_unique( $replace ), $content );
		if ( $new === $content ) {
			return false;
		}
		return self::write_source( $o, $new );
	}

	private static function read_source( $o ) {
		if ( 'post' === $o->source_type ) {
			$post = get_post( (int) $o->source_id );
			return $post ? $post->post_content : null;
		}
		if ( 'comment' === $o->source_type ) {
			$c = get_comment( (int) $o->source_id );
			return $c ? $c->comment_content : null;
		}
		if ( 'widget' === $o->source_type ) {
			$all = get_option( 'widget_block', array() );
			return isset( $all[ (int) $o->source_id ]['content'] ) ? (string) $all[ (int) $o->source_id ]['content'] : null;
		}
		if ( 'term' === $o->source_type ) {
			$term = get_term( (int) $o->source_id );
			return $term && ! is_wp_error( $term ) ? (string) $term->description : null;
		}
		return null;
	}

	private static function write_source( $o, $content ) {
		if ( 'post' === $o->source_type ) {
			$r = wp_update_post( array( 'ID' => (int) $o->source_id, 'post_content' => $content ), true );
			return ! is_wp_error( $r ) && $r;
		}
		if ( 'comment' === $o->source_type ) {
			return (bool) wp_update_comment( array( 'comment_ID' => (int) $o->source_id, 'comment_content' => $content ) );
		}
		if ( 'widget' === $o->source_type ) {
			$all = get_option( 'widget_block', array() );
			if ( ! isset( $all[ (int) $o->source_id ] ) ) {
				return false;
			}
			$all[ (int) $o->source_id ]['content'] = $content;
			return (bool) update_option( 'widget_block', $all );
		}
		if ( 'term' === $o->source_type ) {
			$term = get_term( (int) $o->source_id );
			if ( ! $term || is_wp_error( $term ) ) {
				return false;
			}
			$r = wp_update_term( $term->term_id, $term->taxonomy, array( 'description' => $content ) );
			return ! is_wp_error( $r );
		}
		return false;
	}
}
