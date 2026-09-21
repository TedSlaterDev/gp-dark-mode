<?php
/**
 * GP Dark Mode — print-time CSS minifier (no WordPress dependencies).
 *
 * The plugin prints its stylesheet layers INLINE on every page, so every
 * byte ships with every pageview. Source files stay readable — maintainers'
 * docblocks alone are ~47% of the shipped layers — and only the printed
 * output is reduced. Comments and whitespace only: the rule set is provably
 * unchanged (tests/coverage.php parses raw and minified output to identical
 * selector+declaration maps and checks quoted strings byte-for-byte).
 *
 * @package GP_Dark_Mode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OGM_GPDM_CSS {

	/** A double- or single-quoted CSS string, escapes honored. */
	const STRING = '"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'';

	/**
	 * Minify a stylesheet: strip comments, collapse whitespace outside quoted
	 * strings.
	 *
	 * Quoted strings pass through verbatim, so attribute selectors such as
	 * [style*="position: fixed"] keep their exact text. Whitespace is removed
	 * around { } ; , > and AFTER a colon — never before one, because
	 * "a :hover" (any hovered descendant) and "a:hover" are different
	 * selectors. Everything else collapses to a single space, which keeps
	 * calc() operators and "!important" intact.
	 *
	 * @param string $css Raw CSS.
	 * @return string Minified CSS.
	 */
	public static function minify( $css ) {
		$css = (string) $css;
		if ( '' === trim( $css ) ) {
			return '';
		}

		// 1. Comments: well-formed pairs only. An unterminated comment is left
		//    alone — the browser discards it to end-of-sheet either way.
		$css = preg_replace( '~/\*.*?\*/~s', '', $css );

		// 2. Whitespace, string-aware.
		$css = preg_replace_callback(
			'~(' . self::STRING . ')|(\s*[{};,>]\s*)|(:\s+)|(\s+)~s',
			function ( $m ) {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					return $m[1];
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return trim( $m[2] );
				}
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					return ':';
				}
				return ' ';
			},
			$css
		);

		// 3. A semicolon before a closing brace is redundant (string-safe).
		$css = preg_replace_callback(
			'~(' . self::STRING . ')|;\}~s',
			function ( $m ) {
				return ( isset( $m[1] ) && '' !== $m[1] ) ? $m[1] : '}';
			},
			$css
		);

		return trim( $css );
	}
}
