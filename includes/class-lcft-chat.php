<?php
/**
 * The member to Telegram direction.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Chat
 *
 * Orchestrates everything that travels outwards: a member's message, the typing status, and the
 * read receipt placed on an operator's reply.
 *
 * @since 0.1.0
 */
class LCFT_Chat {

	/**
	 * Sends a member's message to their Telegram topic.
	 *
	 * The message is stored before it is sent. If Telegram is unreachable the member still sees
	 * what they wrote, and the row is left with no Telegram message ID — which is what marks it as
	 * undelivered everywhere else in the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id The member.
	 * @param string $text    The message body.
	 * @param array  $file    Optional. One entry from $_FILES.
	 *
	 * @return array|WP_Error The stored message row, plus a `delivered` flag.
	 */
	public static function send_from_member( $user_id, $text, $file = null ) {

		$text = trim( (string) $text );

		if ( '' === $text && empty( $file ) ) {
			return new WP_Error( 'empty_message', esc_html__( 'The message is empty.', 'live-chat-for-telegram' ) );
		}

		$conversation = LCFT_Conversations::get_or_create( $user_id );

		if ( ! $conversation ) {
			return new WP_Error( 'no_conversation', esc_html__( 'The conversation could not be opened.', 'live-chat-for-telegram' ) );
		}

		$attachment = null;

		if ( ! empty( $file ) ) {

			if ( ! LCFT_Settings::get( 'attachments_enabled', true ) ) {
				return new WP_Error( 'attachments_disabled', esc_html__( 'Attachments are not accepted.', 'live-chat-for-telegram' ) );
			}

			$attachment = LCFT_Attachments::store_upload( (int) $conversation->id, $file );

			if ( is_wp_error( $attachment ) ) {
				return $attachment;
			}
		}

		$message_id = LCFT_Conversations::add_message(
			(int) $conversation->id,
			array(
				'direction'     => LCFT_Conversations::IN,
				'body'          => $text,
				'attachment_id' => $attachment ? (int) $attachment->id : null,
			)
		);

		if ( ! $message_id ) {
			return new WP_Error( 'store_failed', esc_html__( 'The message could not be saved.', 'live-chat-for-telegram' ) );
		}

		$sent = self::deliver( $conversation, $text, $attachment );

		if ( ! is_wp_error( $sent ) ) {
			LCFT_Conversations::update_message( $message_id, array( 'tg_message_id' => (int) $sent ) );
		}

		return array(
			'id'        => $message_id,
			'delivered' => ! is_wp_error( $sent ),
			'error'     => is_wp_error( $sent ) ? $sent->get_error_message() : '',
		);
	}

	/**
	 * Delivers a member's message into their topic, recreating the topic if it has vanished.
	 *
	 * @since 0.1.0
	 *
	 * @param object      $conversation The conversation row.
	 * @param string      $text         The message body.
	 * @param object|null $attachment   The attachment row, when there is one.
	 * @param bool        $is_retry     Whether this is the second attempt after a missing topic.
	 *
	 * @return int|WP_Error The Telegram message ID of the first part sent.
	 */
	protected static function deliver( $conversation, $text, $attachment = null, $is_retry = false ) {

		$topic_id = LCFT_Conversations::ensure_topic( $conversation );

		if ( is_wp_error( $topic_id ) ) {
			return $topic_id;
		}

		$api    = lcft_api();
		$common = array(
			'chat_id'           => $conversation->chat_id,
			'message_thread_id' => $topic_id,
		);

		$first = null;

		if ( $attachment ) {

			// Measured before escaping: Telegram applies the limit after parsing entities, in
			// UTF-16 code units.
			$caption = LCFT_Format::escape_html( $text );
			$inline  = LCFT_Format::length( $text ) <= LCFT_Telegram_API::MAX_CAPTION_LENGTH;

			$args = array_merge(
				$common,
				array(
					'caption'    => $inline && '' !== $caption ? $caption : null,
					'parse_mode' => 'HTML',
				)
			);

			$path = LCFT_Attachments::absolute_path( $attachment );

			$result = 'photo' === $attachment->kind
				? $api->send_photo( $args, $path )
				: $api->send_document( $args, $path );

			if ( is_wp_error( $result ) ) {
				return self::maybe_retry( $result, $conversation, $text, $attachment, $is_retry );
			}

			$first = isset( $result['message_id'] ) ? (int) $result['message_id'] : null;

			// A caption too long to ride along follows as its own message rather than being cut.
			if ( ! $inline ) {
				self::send_text( $api, $common, $text );
			}

			return $first;
		}

		return self::send_text( $api, $common, $text, $conversation, $is_retry );
	}

	/**
	 * Sends a text body, split across as many messages as Telegram's limit requires.
	 *
	 * @since 0.1.0
	 *
	 * @param LCFT_Telegram_API $api          The API client.
	 * @param array             $common       chat_id and message_thread_id.
	 * @param string            $text         The body.
	 * @param object|null       $conversation The conversation, when a retry should be possible.
	 * @param bool              $is_retry     Whether this is the second attempt.
	 *
	 * @return int|WP_Error The Telegram message ID of the first part.
	 */
	protected static function send_text( $api, $common, $text, $conversation = null, $is_retry = false ) {

		$first = null;

		foreach ( LCFT_Format::split( $text ) as $chunk ) {

			$result = $api->send_message(
				array_merge(
					$common,
					array(
						'text'                 => LCFT_Format::escape_html( $chunk ),
						'parse_mode'           => 'HTML',
						'link_preview_options' => array( 'is_disabled' => true ),
					)
				)
			);

			if ( is_wp_error( $result ) ) {

				if ( $conversation ) {
					return self::maybe_retry( $result, $conversation, $text, null, $is_retry );
				}

				return $result;
			}

			if ( null === $first ) {
				$first = isset( $result['message_id'] ) ? (int) $result['message_id'] : null;
			}
		}

		return null === $first ? new WP_Error( 'not_sent', esc_html__( 'Telegram accepted nothing.', 'live-chat-for-telegram' ) ) : $first;
	}

	/**
	 * Recreates a topic that no longer exists and sends again, once.
	 *
	 * An operator deleting a topic from the group is invisible to the site until the next send
	 * fails. Rather than lose the message, the topic is rebuilt and the message replayed.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error    $error        The error Telegram returned.
	 * @param object      $conversation The conversation row.
	 * @param string      $text         The message body.
	 * @param object|null $attachment   The attachment, when there is one.
	 * @param bool        $is_retry     Whether a retry has already happened.
	 *
	 * @return int|WP_Error
	 */
	protected static function maybe_retry( $error, $conversation, $text, $attachment, $is_retry ) {

		if ( $is_retry || ! self::is_missing_topic( $error ) ) {
			return $error;
		}

		LCFT_Conversations::reset_topic( (int) $conversation->id );

		$conversation->topic_id = null;
		$conversation->chat_id  = null;

		return self::deliver( $conversation, $text, $attachment, true );
	}

	/**
	 * Recognises the errors Telegram returns for a topic that is gone.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $error The error.
	 *
	 * @return bool
	 */
	protected static function is_missing_topic( $error ) {

		$message = strtolower( $error->get_error_message() );

		foreach ( array( 'thread not found', 'topic_deleted', 'topic deleted', 'message thread' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}


	// # STATUS --------------------------------------------------------------------------------------------------------

	/**
	 * Shows "typing" in the member's topic.
	 *
	 * Throttled server side as well as in the browser: the widget is not in a position to be
	 * trusted about how often it calls, and Telegram rate limits per chat.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The member.
	 *
	 * @return bool Whether the status was sent.
	 */
	public static function relay_typing( $user_id ) {

		$conversation = LCFT_Conversations::get_by_user( $user_id );

		// No topic yet means the member has never written. There is nowhere to show the status,
		// and creating a topic for someone who may never send anything would be noise.
		if ( ! $conversation || empty( $conversation->topic_id ) ) {
			return false;
		}

		$throttle_key = 'lcft_typing_' . (int) $conversation->id;

		if ( get_transient( $throttle_key ) ) {
			return false;
		}

		// Telegram clears the status after five seconds, so refreshing every four keeps it on
		// without a gap.
		set_transient( $throttle_key, 1, 4 );

		$result = lcft_api()->send_chat_action( $conversation->chat_id, 'typing', (int) $conversation->topic_id );

		return ! is_wp_error( $result );
	}

	/**
	 * Marks operator messages as read and shows that in Telegram.
	 *
	 * Only the most recent acknowledged message gets a reaction. Reacting to every message at once
	 * would spend an API call each and read as clutter in the topic; the newest one already says
	 * "seen up to here".
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id  The member.
	 * @param int $up_to_id The highest message ID they have seen.
	 *
	 * @return int The number of messages marked as read.
	 */
	public static function acknowledge_read( $user_id, $up_to_id ) {

		$conversation = LCFT_Conversations::get_by_user( $user_id );

		if ( ! $conversation ) {
			return 0;
		}

		$acknowledged = LCFT_Conversations::mark_read( (int) $conversation->id, (int) $up_to_id );

		if ( empty( $acknowledged ) ) {
			return 0;
		}

		if ( ! empty( $conversation->chat_id ) ) {
			lcft_api()->set_message_reaction( $conversation->chat_id, max( $acknowledged ) );
		}

		return count( $acknowledged );
	}
}
