<?php
/**
 * Links inside post meta: ACF fields, Elementor and other page-builder data,
 * SEO plugin fields. Values may be plain strings, serialized arrays or JSON;
 * every string inside them is searched for anchors, images and bare URLs.
 * Fixes are written back through the same structure so nothing is corrupted.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Meta {

	/** Keys that hold caches, locks or ids rather than content. */
	const SKIP = '/^_(edit_|wp_|oembed_|thumbnail_id$|pingme$|encloseme$|elementor_(css|page_assets|controls_usage|element_cache|version|template_type|edit_mode)|yoast_wpseo_(content_score|estimated|primary|wordproof|linkdex|inclusive)|menu_item_|last_editor|rank_math_(seo_score|analytic|internal|primary|contentai))/';

	public static function init() {
		add_filter( 'linksentinel_post_links', array( __CLASS__, 'collect' ), 10, 4 );
		add_filter( 'linksentinel_rewrite_source', array( __CLASS__, 'rewrite' ), 10, 4 );
		add_filter( 'linksentinel_describe_occurrence', array( __CLASS__, 'describe' ), 10, 2 );
	}

	public static function collect( $found, $post, $base, $scan_id ) {
		if ( empty( LinkSentinel_Settings::get( 'scan_meta' ) ) ) {
			return $found;
		}
		$meta = get_post_meta( $post->ID );
		if ( ! is_array( $meta ) ) {
			return $found;
		}
		foreach ( $meta as $key => $values ) {
			if ( preg_match( self::SKIP, $key ) ) {
				continue;
			}
			foreach ( (array) $values as $raw ) {
				foreach ( self::strings( maybe_unserialize( $raw ) ) as $text ) {
					foreach ( self::urls_in( $text, $base ) as $f ) {
						$f['field'] = 'meta:' . $key;
						$found[]    = $f;
					}
				}
			}
		}
		return $found;
	}

	/** Every string inside a value: scalars, arrays, objects, and JSON strings decoded. */
	public static function strings( $value, $depth = 0 ) {
		if ( $depth > 8 ) {
			return array();
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$out = array();
			foreach ( (array) $value as $v ) {
				foreach ( self::strings( $v, $depth + 1 ) as $s ) {
					$out[] = $s;
				}
			}
			return $out;
		}
		if ( ! is_string( $value ) || strlen( $value ) < 8 ) {
			return array();
		}
		$json = self::decode_json( $value );
		if ( null !== $json ) {
			return self::strings( $json, $depth + 1 );
		}
		return array( $value );
	}

	private static function decode_json( $s ) {
		$t = ltrim( $s );
		if ( '' === $t || ( '[' !== $t[0] && '{' !== $t[0] ) ) {
			return null;
		}
		$j = json_decode( $s, true );
		return is_array( $j ) ? $j : null;
	}

	/** Anchors and images in HTML-looking strings, plus bare URLs anywhere. */
	public static function urls_in( $text, $base ) {
		$found = array();
		if ( false !== stripos( $text, '<a' ) || false !== stripos( $text, '<img' ) || false !== stripos( $text, 'src=' ) ) {
			foreach ( LinkSentinel_Extractor::extract( $text, $base ) as $f ) {
				$f['element'] = 'a' === $f['element'] ? 'a' : $f['element'];
				$found[]      = $f;
			}
		}
		if ( preg_match_all( '~https?://[^\s"\'<>\\\\)\]}]+~i', $text, $m ) ) {
			foreach ( $m[0] as $raw ) {
				$raw = rtrim( $raw, '.,;:!?' );
				$url = LinkSentinel_Extractor::normalize( $raw, $base );
				if ( $url ) {
					$found[] = array( 'url' => $url, 'raw' => $raw, 'element' => 'meta', 'anchor' => '' );
				}
			}
		}
		return $found;
	}

	/** Fixer hook: rewrite one meta occurrence. Returns bool, or null for sources that are not ours. */
	public static function rewrite( $handled, $o, $old_raw, $new_url ) {
		if ( null !== $handled || 'post' !== $o->source_type || 0 !== strpos( (string) $o->field, 'meta:' ) ) {
			return $handled;
		}
		$key     = substr( $o->field, 5 );
		$post_id = (int) $o->source_id;
		$changed = false;
		foreach ( get_post_meta( $post_id, $key ) as $value ) {
			$new = self::replace_deep( $value, $old_raw, $new_url );
			if ( $new !== $value ) {
				update_post_meta( $post_id, $key, wp_slash( $new ), $value );
				$changed = true;
			}
		}
		return $changed;
	}

	public static function replace_deep( $value, $old, $new, $depth = 0 ) {
		if ( $depth > 8 ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::replace_deep( $v, $old, $new, $depth + 1 );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = self::replace_deep( $v, $old, $new, $depth + 1 );
			}
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$json = self::decode_json( $value );
		if ( null !== $json ) {
			$re = self::replace_deep( $json, $old, $new, $depth + 1 );
			return $re === $json ? $value : wp_json_encode( $re );
		}
		$search  = array( $old );
		$replace = array( $new );
		if ( esc_url( $old ) !== $old ) {
			$search[]  = esc_url( $old );
			$replace[] = esc_url( $new );
		}
		return str_replace( $search, $replace, $value );
	}

	/** "Found in" label for meta occurrences: post title plus the field name. */
	public static function describe( $label, $o ) {
		if ( 'post' !== $o->source_type || 0 !== strpos( (string) $o->field, 'meta:' ) ) {
			return $label;
		}
		$title = get_the_title( (int) $o->source_id );
		$title = '' !== $title ? $title : __( '(no title)', 'link-sentinel' );
		$edit  = get_edit_post_link( (int) $o->source_id );
		/* translators: %s: custom field name */
		$field = sprintf( __( 'field: %s', 'link-sentinel' ), substr( $o->field, 5 ) );
		return ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . ' <span class="description">' . esc_html( $field ) . '</span>';
	}
}
