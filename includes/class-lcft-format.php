<?php
/**
 * Text preparation for Telegram and for the browser.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Format
 *
 * Everything sent to Telegram uses HTML parse mode, which needs its own escaping: Telegram's HTML
 * is a five tag subset, not real HTML, and an unescaped angle bracket in a member's message makes
 * the whole send fail rather than merely render oddly.
 *
 * @since 0.1.0
 */
class LCFT_Format {

	/**
	 * Escapes text for Telegram's HTML parse mode.
	 *
	 * Only the three characters Telegram's parser treats as markup are escaped. Running the text
	 * through esc_html() instead would encode quotes and apostrophes into entities that Telegram
	 * shows literally.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text to escape.
	 *
	 * @return string
	 */
	public static function escape_html( $text ) {

		return str_replace(
			array( '&', '<', '>' ),
			array( '&amp;', '&lt;', '&gt;' ),
			(string) $text
		);
	}

	/**
	 * Splits a message into chunks Telegram will accept.
	 *
	 * Cuts on line boundaries where possible, and only falls back to a hard character cut for a
	 * single line longer than the limit. No tag reopening is needed here: message bodies are sent
	 * fully escaped, and the only markup is added by the caller around the whole chunk.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text  The text to split.
	 * @param int    $limit The maximum chunk length.
	 *
	 * @return array
	 */
	public static function split( $text, $limit = LCFT_Telegram_API::MAX_MESSAGE_LENGTH ) {

		$text = (string) $text;

		if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
			return array( $text );
		}

		$chunks  = array();
		$current = '';

		foreach ( explode( "\n", $text ) as $line ) {

			// A single line too long to ever fit: cut it into pieces of its own.
			while ( mb_strlen( $line, 'UTF-8' ) > $limit ) {

				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}

				$chunks[] = mb_substr( $line, 0, $limit, 'UTF-8' );
				$line     = mb_substr( $line, $limit, null, 'UTF-8' );
			}

			$candidate = '' === $current ? $line : $current . "\n" . $line;

			if ( mb_strlen( $candidate, 'UTF-8' ) > $limit ) {
				$chunks[] = $current;
				$current  = $line;

				continue;
			}

			$current = $candidate;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Replaces {placeholders} in a template.
	 *
	 * Unknown placeholders are removed rather than left in place: a half substituted topic title
	 * showing "{union}" is worse than one simply missing the union.
	 *
	 * @since 0.1.0
	 *
	 * @param string $template The template.
	 * @param array  $tokens   Replacement values, keyed by placeholder name without the braces.
	 *
	 * @return string
	 */
	public static function render( $template, $tokens ) {

		$template = (string) $template;

		foreach ( (array) $tokens as $name => $value ) {
			$template = str_replace( '{' . $name . '}', (string) $value, $template );
		}

		$template = preg_replace( '/\{[a-z0-9_]+\}/i', '', $template );

		return self::tidy( $template );
	}

	/**
	 * Cleans up the punctuation left behind when a placeholder resolves to nothing.
	 *
	 * A template like "{first_name} {last_name} — {union} ({department})" should read
	 * "Marie Dupont" for a member with no union on file, not "Marie Dupont —  ()".
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text to tidy.
	 *
	 * @return string
	 */
	public static function tidy( $text ) {

		// Empty bracketed groups, then repeated separators, then the ones left dangling at either
		// end. Order matters: removing "()" first is what leaves the stray dash to collect.
		$text = preg_replace( '/\(\s*\)|\[\s*\]/u', '', (string) $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text );
		$text = preg_replace( '/(?:\s*[—–\-•|,]\s*){2,}/u', ' — ', $text );
		$text = preg_replace( '/^[\s—–\-•|,]+|[\s—–\-•|,]+$/u', '', $text );

		return trim( $text );
	}

	/**
	 * Returns a human readable file size.
	 *
	 * @since 0.1.0
	 *
	 * @param int $bytes The size in bytes.
	 *
	 * @return string
	 */
	public static function file_size( $bytes ) {
		return size_format( (int) $bytes, 1 );
	}
}
