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
	 * Escapes a value placed into an admin written template.
	 *
	 * Unlike a message body, a template value may land inside an attribute, such as
	 * <a href="{profile}">, where an unescaped quote ends the attribute early and Telegram rejects
	 * the whole message. Telegram decodes &quot; and numeric entities, so the quotes still read
	 * normally wherever the value ends up.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text The value to escape.
	 *
	 * @return string
	 */
	public static function escape_template_value( $text ) {

		return str_replace(
			array( '"', "'" ),
			array( '&quot;', '&#39;' ),
			self::escape_html( $text )
		);
	}

	/**
	 * Returns the tags Telegram accepts in HTML parse mode.
	 *
	 * Anything else makes the send fail, so admin written templates are filtered against this.
	 *
	 * @since 1.1.0
	 *
	 * @link https://core.telegram.org/bots/api#html-style
	 *
	 * @return array A wp_kses() allowed list.
	 */
	public static function telegram_html() {

		return array(
			'b'          => array(),
			'strong'     => array(),
			'i'          => array(),
			'em'         => array(),
			'u'          => array(),
			'ins'        => array(),
			's'          => array(),
			'strike'     => array(),
			'del'        => array(),
			'a'          => array( 'href' => array() ),
			'code'       => array( 'class' => array() ),
			'pre'        => array(),
			'blockquote' => array( 'expandable' => array() ),
			'span'       => array( 'class' => array() ),
			'tg-spoiler' => array(),
			'tg-emoji'   => array( 'emoji-id' => array() ),
		);
	}

	/**
	 * Returns the tags in a template which Telegram does not accept.
	 *
	 * Only tag names are compared. Comparing the template with its wp_kses() version would also
	 * flag a bare ampersand, which wp_kses() normalises into an entity rather than removes.
	 *
	 * @since 1.1.0
	 *
	 * @param string $template The template.
	 *
	 * @return array The disallowed tag names, lowercased and without duplicates.
	 */
	public static function get_disallowed_tags( $template ) {

		if ( ! preg_match_all( '#</?([a-z][a-z0-9-]*)[^>]*>#i', (string) $template, $matches ) ) {
			return array();
		}

		$allowed    = array_keys( self::telegram_html() );
		$disallowed = array();

		foreach ( $matches[1] as $name ) {

			$name = strtolower( $name );

			if ( ! in_array( $name, $allowed, true ) ) {
				$disallowed[] = $name;
			}
		}

		return array_values( array_unique( $disallowed ) );
	}

	/**
	 * Returns the length of a string the way Telegram counts it.
	 *
	 * Telegram counts UTF-16 code units, so anything outside the basic multilingual plane — most
	 * emoji — counts as two rather than one. Counting with mb_strlen() alone lets an emoji heavy
	 * message through at 4096 characters that Telegram then rejects as too long.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text The text to measure.
	 *
	 * @return int
	 */
	public static function length( $text ) {

		$text = (string) $text;

		return mb_strlen( $text, 'UTF-8' ) + (int) preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $text );
	}

	/**
	 * Returns the longest leading part of a string which fits the given length.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text  The text to cut.
	 * @param int    $limit The maximum length, counted as Telegram counts it.
	 *
	 * @return string
	 */
	public static function truncate( $text, $limit ) {

		$text = (string) $text;

		if ( self::length( $text ) <= $limit ) {
			return $text;
		}

		$result = '';
		$length = 0;

		foreach ( (array) preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $character ) {

			$width = self::length( $character );

			if ( $length + $width > $limit ) {
				break;
			}

			$result .= $character;
			$length += $width;
		}

		return $result;
	}

	/**
	 * Splits a message into chunks Telegram will accept.
	 *
	 * Cuts on line boundaries where possible, and only falls back to a hard character cut for a
	 * single line longer than the limit. No tag reopening is needed here: message bodies are sent
	 * fully escaped, and the only markup is added by the caller around the whole chunk.
	 *
	 * The text is split before it is escaped, which is right: Telegram applies the limit after
	 * parsing entities, so &amp; counts as one character, not five.
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

		if ( self::length( $text ) <= $limit ) {
			return array( $text );
		}

		$chunks  = array();
		$current = '';

		foreach ( explode( "\n", $text ) as $line ) {

			// A single line too long to ever fit: cut it into pieces of its own.
			while ( self::length( $line ) > $limit ) {

				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}

				$head     = self::truncate( $line, $limit );
				$chunks[] = $head;
				$line     = mb_substr( $line, mb_strlen( $head, 'UTF-8' ), null, 'UTF-8' );
			}

			$candidate = '' === $current ? $line : $current . "\n" . $line;

			if ( self::length( $candidate ) > $limit ) {
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
