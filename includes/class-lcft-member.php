<?php
/**
 * Member identity and the details shown to operators.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Member
 *
 * Turns a WordPress user into the tokens used for the topic title and the pinned details card.
 *
 * Gravity Forms is optional throughout. Without it, or without a form configured, a member is
 * still perfectly usable — the tokens simply come from the user account alone.
 *
 * @since 0.1.0
 */
class LCFT_Member {

	/**
	 * The user meta key caching the resolved entry ID.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const ENTRY_META_KEY = '_lcft_entry_id';

	/**
	 * Returns the replacement tokens for a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The user.
	 *
	 * @return array Token name => value, with no braces.
	 */
	public static function get_tokens( $user_id ) {

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array();
		}

		$tokens = array(
			'user_id'      => $user->ID,
			'user_login'   => $user->user_login,
			'user_email'   => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
		);

		$entry = self::get_entry( $user_id );

		if ( $entry ) {

			// Every field is exposed by ID, so a site can reference a field the map does not name.
			foreach ( $entry as $key => $value ) {
				if ( is_scalar( $value ) && '' !== $value ) {
					$tokens[ 'field_' . str_replace( '.', '_', $key ) ] = $value;
				}
			}

			// Then the friendly aliases, which win: a mapped last_name is more trustworthy than
			// whatever the account happens to carry.
			foreach ( (array) LCFT_Settings::get( 'gf_field_map', array() ) as $name => $field_id ) {

				$value = self::get_field_value( $entry, $field_id );

				if ( '' !== $value ) {
					$tokens[ $name ] = $value;
				}
			}
		}

		// Fall back to the display name when the account has no first name, so a title template
		// built on {first_name} never renders empty.
		if ( '' === trim( $tokens['first_name'] . $tokens['last_name'] ) ) {
			$tokens['first_name'] = $user->display_name;
		}

		/**
		 * Filters the tokens available to the topic title and details card.
		 *
		 * @since 0.1.0
		 *
		 * @param array $tokens  The tokens.
		 * @param int   $user_id The user they describe.
		 * @param array $entry   The Gravity Forms entry, or an empty array.
		 */
		return apply_filters( 'lcft_member_tokens', $tokens, $user_id, (array) $entry );
	}

	/**
	 * Builds the forum topic title for a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The user.
	 *
	 * @return string
	 */
	public static function get_topic_title( $user_id ) {

		$tokens   = self::get_tokens( $user_id );
		$template = (string) LCFT_Settings::get( 'topic_title_template', '{first_name} {last_name}' );

		$title = LCFT_Format::render( $template, $tokens );

		// Telegram rejects createForumTopic with an empty name, and a member with nothing on file
		// would otherwise produce one.
		if ( '' === $title ) {
			/* translators: %d: The WordPress user ID. */
			$title = sprintf( __( 'Member #%d', 'live-chat-for-telegram' ), (int) $user_id );
		}

		return $title;
	}

	/**
	 * Builds the details card pinned at the top of a member's topic.
	 *
	 * Returns Telegram HTML. When no card template is configured, a sensible default is assembled
	 * from whatever the map provides, so a site gets something useful before configuring anything.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The user.
	 *
	 * @return string
	 */
	public static function get_card( $user_id ) {

		$tokens = self::get_tokens( $user_id );

		// Values reach the card straight from user input, so they are escaped before any markup is
		// added around them. Escaping the assembled card instead would eat the tags. Quotes are
		// escaped too, since a placeholder may sit inside an href in the template.
		$escaped = array_map( array( 'LCFT_Format', 'escape_template_value' ), $tokens );

		$template = (string) LCFT_Settings::get( 'card_template', '' );

		if ( '' !== trim( $template ) ) {
			return LCFT_Format::render( $template, $escaped );
		}

		$lines = array();

		$lines[] = '<b>' . ( isset( $escaped['display_name'] ) ? $escaped['display_name'] : '' ) . '</b>';

		if ( ! empty( $escaped['user_email'] ) ) {
			$lines[] = '✉️ ' . $escaped['user_email'];
		}

		$profile_url = admin_url( 'user-edit.php?user_id=' . (int) $user_id );
		$lines[]     = '<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Open profile', 'live-chat-for-telegram' ) . '</a>';

		return implode( "\n", array_filter( $lines ) );
	}


	// # GRAVITY FORMS -------------------------------------------------------------------------------------------------

	/**
	 * Returns the Gravity Forms entry describing a member, or an empty array.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The user.
	 *
	 * @return array
	 */
	public static function get_entry( $user_id ) {

		$entry_id = self::resolve_entry_id( $user_id );

		if ( ! $entry_id ) {
			return array();
		}

		$entry = GFAPI::get_entry( $entry_id );

		if ( is_wp_error( $entry ) ) {
			return array();
		}

		return (array) $entry;
	}

	/**
	 * Finds the entry belonging to a user, caching the answer on the user.
	 *
	 * Three strategies are tried in turn because no single one is reliable on its own: created_by
	 * is empty when the entry predates the account, the user ID field is only populated by sites
	 * that added one, and matching on email breaks if the member later changes it. Whichever hits
	 * first is remembered.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The user.
	 *
	 * @return int The entry ID, or zero when there is none.
	 */
	public static function resolve_entry_id( $user_id ) {

		$user_id = (int) $user_id;
		$form_id = (int) LCFT_Settings::get( 'gf_form_id', 0 );

		if ( ! $user_id || ! $form_id || ! class_exists( 'GFAPI' ) ) {
			return 0;
		}

		$cached = get_user_meta( $user_id, self::ENTRY_META_KEY, true );

		// A stored zero is a remembered miss: without it every message from a member with no entry
		// would run all three searches again.
		if ( '' !== $cached ) {
			return (int) $cached;
		}

		$entry_id = 0;
		$user     = get_userdata( $user_id );

		$searches = array( array( 'key' => 'created_by', 'value' => $user_id ) );

		$map            = (array) LCFT_Settings::get( 'gf_field_map', array() );
		$user_id_field  = isset( $map['user_id'] ) ? $map['user_id'] : 0;
		$email_field    = isset( $map['email'] ) ? $map['email'] : 0;

		if ( $user_id_field ) {
			$searches[] = array( 'key' => (string) $user_id_field, 'value' => (string) $user_id );
		}

		if ( $email_field && $user ) {
			$searches[] = array( 'key' => (string) $email_field, 'value' => $user->user_email );
		}

		foreach ( $searches as $filter ) {

			$entries = GFAPI::get_entries(
				$form_id,
				array( 'field_filters' => array( $filter ) ),
				array( 'key' => 'id', 'direction' => 'DESC' ),
				array( 'offset' => 0, 'page_size' => 1 )
			);

			if ( ! is_wp_error( $entries ) && ! empty( $entries[0]['id'] ) ) {
				$entry_id = (int) $entries[0]['id'];

				break;
			}
		}

		update_user_meta( $user_id, self::ENTRY_META_KEY, $entry_id );

		return $entry_id;
	}

	/**
	 * Reads a field from an entry, joining the inputs of a multi input field.
	 *
	 * @since 0.1.0
	 *
	 * @param array      $entry    The entry.
	 * @param int|string $field_id The field ID.
	 *
	 * @return string
	 */
	protected static function get_field_value( $entry, $field_id ) {

		$field_id = (string) $field_id;

		if ( '' === $field_id ) {
			return '';
		}

		if ( isset( $entry[ $field_id ] ) && '' !== $entry[ $field_id ] ) {
			return self::flatten( $entry[ $field_id ] );
		}

		// Checkboxes, names and addresses store one row per input, keyed "65.3" and so on. There is
		// no single "65" key to read, so the parts are collected and joined.
		$parts = array();

		foreach ( (array) $entry as $key => $value ) {

			if ( 0 !== strpos( (string) $key, $field_id . '.' ) ) {
				continue;
			}

			$value = self::flatten( $value );

			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Reduces an entry value to a plain string.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	protected static function flatten( $value ) {

		if ( is_array( $value ) ) {
			return implode( ', ', array_filter( array_map( 'strval', $value ) ) );
		}

		return trim( (string) $value );
	}

	/**
	 * Forgets the cached entry for a user, so the next lookup searches again.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The user.
	 */
	public static function forget_entry( $user_id ) {
		delete_user_meta( (int) $user_id, self::ENTRY_META_KEY );
	}
}
