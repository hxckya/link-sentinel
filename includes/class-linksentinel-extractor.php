<?php
/**
 * Pulls every checkable URL out of a piece of content.
 *
 * Regex, not a DOM: post_content is rarely well-formed, and a parser that
 * "repairs" markup would hide exactly the links we need to find. Each match
 * carries the raw attribute value, so a fix can be written back verbatim.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Extractor {

	/** Schemes and pseudo-links that are not fetchable and never "broken". */
	const SKIP_PREFIXES = array( 'mailto:', 'tel:', 'sms:', 'javascript:', 'data:', 'skype:', 'whatsapp:', 'viber:', 'fb-messenger:', 'geo:', 'callto:', 'file:', 'ftp:', 'magnet:', 'about:', 'chrome:' );

	/**
	 * @param string $html     Raw content.
	 * @param string $base_url URL the content lives at, for relative links.
	 * @return array[] Each: url, raw, element, anchor.
	 */
	public static function extract( $html, $base_url ) {
		$found = array();
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $found;
		}
		$push = function ( $raw, $element, $anchor = '' ) use ( &$found, $base_url ) {
			$url = self::normalize( $raw, $base_url );
			if ( null === $url ) {
				return;
			}
			$found[] = array(
				'url'     => $url,
				'raw'     => $raw,
				'element' => $element,
				'anchor'  => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $anchor, ENT_QUOTES, 'UTF-8' ) ) ) ),
			);
		};

		// <a href="...">text</a> — the text is what editors recognise a link by.
		if ( preg_match_all( '/<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a\s*>/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				$push( $a[2], 'a', $a[3] );
			}
		}
		// Anchors without a closing tag still count.
		if ( preg_match_all( '/<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>/is', $html, $m2, PREG_SET_ORDER ) ) {
			$seen = array();
			foreach ( $found as $f ) {
				$seen[ $f['raw'] ] = true;
			}
			foreach ( $m2 as $a ) {
				if ( empty( $seen[ $a[2] ] ) ) {
					$push( $a[2], 'a' );
					$seen[ $a[2] ] = true;
				}
			}
		}
		// Embedded media.
		if ( preg_match_all( '/<(img|iframe|source|video|audio|embed)\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\2/is', $html, $m3, PREG_SET_ORDER ) ) {
			foreach ( $m3 as $e ) {
				$push( $e[3], strtolower( $e[1] ) );
			}
		}
		// Responsive image candidates: "url 480w, url 800w".
		if ( preg_match_all( '/<(?:img|source)\b[^>]*?\bsrcset\s*=\s*(["\'])(.*?)\1/is', $html, $m4, PREG_SET_ORDER ) ) {
			foreach ( $m4 as $s ) {
				foreach ( explode( ',', $s[2] ) as $candidate ) {
					$parts = preg_split( '/\s+/', trim( $candidate ) );
					if ( ! empty( $parts[0] ) ) {
						$push( $parts[0], 'img' );
					}
				}
			}
		}
		return $found;
	}

	/**
	 * Absolute, fragment-free, fetchable URL — or null when the value is not a link to check.
	 */
	public static function normalize( $raw, $base_url ) {
		$url = trim( html_entity_decode( (string) $raw, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || '#' === $url[0] ) {
			return null;
		}
		// Template placeholders and shortcodes are not URLs.
		if ( false !== strpos( $url, '{' ) || false !== strpos( $url, '[' ) || false !== strpos( $url, '%%' ) ) {
			return null;
		}
		$lower = strtolower( $url );
		foreach ( self::SKIP_PREFIXES as $p ) {
			if ( 0 === strpos( $lower, $p ) ) {
				return null;
			}
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
				return null; // some other scheme
			}
			$url = WP_Http::make_absolute_url( $url, $base_url );
		}
		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}
		$url = str_replace( ' ', '%20', $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		// Local development hosts are noise to everyone but the developer.
		if ( 'localhost' === strtolower( $parts['host'] ) && ! self::is_internal( $url ) ) {
			return null;
		}
		return $url;
	}

	/** Does the URL point at this site? "www." is ignored either way. */
	public static function is_internal( $url ) {
		static $site = null;
		if ( null === $site ) {
			$site = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		}
		$host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		return '' !== $host && $host === $site;
	}

	/** Is the URL covered by a user exclusion (domain or prefix)? */
	public static function is_excluded( $url, $rules ) {
		if ( ! $rules ) {
			return false;
		}
		$lower = strtolower( $url );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( $rules as $rule ) {
			if ( 0 === strpos( $rule, 'http' ) ) {
				if ( 0 === strpos( $lower, $rule ) ) {
					return true;
				}
			} elseif ( $host === $rule || ( '' !== $rule && substr( $host, -strlen( '.' . $rule ) ) === '.' . $rule ) ) {
				return true;
			}
		}
		return false;
	}
}
