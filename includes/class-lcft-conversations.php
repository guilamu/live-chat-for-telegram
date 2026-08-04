<?php
/**
 * Conversation and message storage, and the forum topic lifecycle.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Conversations
 *
 * A conversation is a member: one row per user, one Telegram topic per row, both reused for as
 * long as the account exists. Nothing here is scoped to a browser session, which is what makes the
 * history survive a logout, a new device, or a six month gap.
 *
 * @since 0.1.0
 */
class LCFT_Conversations {

	/**
	 * Direction of a message written by the member and sent out to Telegram.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const IN = 'in';

	/**
	 * Direction of a message written by an operator in Telegram and shown in the widget.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const OUT = 'out';

	/**
	 * Returns the conversation for a user, creating the row if this is their first message.
	 *
	 * The Telegram topic is not created here. A member who opens the widget and reads their
	 * history without writing should not produce an empty topic in the group.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The user.
	 *
	 * @return object|null The conversation row.
	 */
	public static function get_or_create( $user_id ) {

		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return null;
		}

		$conversation = self::get_by_user( $user_id );

		if ( $conversation ) {
			return $conversation;
		}

		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			LCFT_DB::conversations_table(),
			array(
				'user_id'    => $user_id,
				'status'     => 'open',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		// A duplicate key here means a second request created the row first, which is normal when
		// the widget opens and sends in the same moment. Read theirs rather than fail.
		return self::get_by_user( $user_id );
	}

	/**
	 * Returns the conversation belonging to a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The user.
	 *
	 * @return object|null
	 */
	public static function get_by_user( $user_id ) {

		global $wpdb;

		$table = LCFT_DB::conversations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", (int) $user_id ) );
	}

	/**
	 * Returns the conversation by its ID.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id The conversation ID.
	 *
	 * @return object|null
	 */
	public static function get( $id ) {

		global $wpdb;

		$table = LCFT_DB::conversations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Returns the conversation a Telegram topic belongs to.
	 *
	 * This is the reverse lookup the webhook depends on: an operator types in a topic, and this is
	 * what turns that topic back into a member.
	 *
	 * @since 0.1.0
	 *
	 * @param int|string $chat_id  The supergroup.
	 * @param int        $topic_id The message_thread_id.
	 *
	 * @return object|null
	 */
	public static function get_by_topic( $chat_id, $topic_id ) {

		global $wpdb;

		$table = LCFT_DB::conversations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE chat_id = %d AND topic_id = %d",
				(int) $chat_id,
				(int) $topic_id
			)
		);
	}

	/**
	 * Updates columns on a conversation.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $id     The conversation ID.
	 * @param array $values Column => value.
	 *
	 * @return bool
	 */
	public static function update( $id, $values ) {

		global $wpdb;

		$values['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			LCFT_DB::conversations_table(),
			$values,
			array( 'id' => (int) $id )
		);
	}


	// # FORUM TOPICS --------------------------------------------------------------------------------------------------

	/**
	 * Makes sure the conversation has a usable topic, creating one when it does not.
	 *
	 * @since 0.1.0
	 *
	 * @param object $conversation The conversation row.
	 *
	 * @return int|WP_Error The message_thread_id.
	 */
	public static function ensure_topic( $conversation ) {

		$chat_id = LCFT_Settings::get_chat_id();

		if ( '' === $chat_id ) {
			return new WP_Error( 'no_chat_id', esc_html__( 'No Telegram group has been configured.', 'live-chat-for-telegram' ) );
		}

		// A topic is only valid in the group it was created in. If the site now points at a
		// different group, the stored ID is meaningless and a new topic is needed.
		if ( ! empty( $conversation->topic_id ) && (string) $conversation->chat_id === (string) $chat_id ) {
			return (int) $conversation->topic_id;
		}

		$api    = lcft_api();
		$title  = LCFT_Member::get_topic_title( (int) $conversation->user_id );
		$result = $api->create_forum_topic( $chat_id, $title );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$topic_id = isset( $result['message_thread_id'] ) ? (int) $result['message_thread_id'] : 0;

		if ( ! $topic_id ) {
			return new WP_Error( 'no_topic_id', esc_html__( 'Telegram did not return a topic ID.', 'live-chat-for-telegram' ) );
		}

		self::update(
			(int) $conversation->id,
			array(
				'chat_id'  => $chat_id,
				'topic_id' => $topic_id,
			)
		);

		$conversation->chat_id  = $chat_id;
		$conversation->topic_id = $topic_id;

		self::post_details_card( $conversation );

		return $topic_id;
	}

	/**
	 * Posts the member's details as the first message in their topic, and pins it.
	 *
	 * Failure is deliberately not fatal: the card is a convenience, and an operator would rather
	 * receive the member's question without a header than not receive it at all.
	 *
	 * @since 0.1.0
	 *
	 * @param object $conversation The conversation row.
	 */
	protected static function post_details_card( $conversation ) {

		$card = LCFT_Member::get_card( (int) $conversation->user_id );

		if ( '' === trim( $card ) ) {
			return;
		}

		$api = lcft_api();

		$message = $api->send_message(
			array(
				'chat_id'                  => $conversation->chat_id,
				'message_thread_id'        => (int) $conversation->topic_id,
				'text'                     => $card,
				'parse_mode'               => 'HTML',
				'disable_notification'     => true,
				'link_preview_options'     => array( 'is_disabled' => true ),
			)
		);

		if ( is_wp_error( $message ) || empty( $message['message_id'] ) ) {
			return;
		}

		$api->pin_chat_message( $conversation->chat_id, (int) $message['message_id'] );
	}

	/**
	 * Forgets the topic, so the next message creates a fresh one.
	 *
	 * Used when Telegram reports the thread no longer exists, which is what an operator deleting a
	 * topic from the group looks like from here.
	 *
	 * @since 0.1.0
	 *
	 * @param int $conversation_id The conversation ID.
	 */
	public static function reset_topic( $conversation_id ) {

		self::update(
			(int) $conversation_id,
			array(
				'topic_id' => null,
				'chat_id'  => null,
			)
		);
	}


	// # MESSAGES ------------------------------------------------------------------------------------------------------

	/**
	 * Stores a message.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $conversation_id The conversation.
	 * @param array $args            Column values. `direction` is required.
	 *
	 * @return int The new message ID, or zero on failure.
	 */
	public static function add_message( $conversation_id, $args ) {

		global $wpdb;

		$now = current_time( 'mysql' );

		$row = wp_parse_args(
			$args,
			array(
				'conversation_id' => (int) $conversation_id,
				'direction'       => self::IN,
				'body'            => '',
				'attachment_id'   => null,
				'tg_message_id'   => null,
				'tg_from_id'      => null,
				'tg_from_name'    => null,
				'media_group_id'  => null,
				'created_at'      => $now,
			)
		);

		$row['conversation_id'] = (int) $conversation_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( LCFT_DB::messages_table(), $row );

		if ( ! $inserted ) {
			return 0;
		}

		$fields = array( 'last_message_at' => $now );

		if ( self::OUT === $row['direction'] ) {
			$fields['last_agent_message_at'] = $now;
		}

		self::update( $conversation_id, $fields );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Returns messages after a cursor, oldest first.
	 *
	 * The cursor is the primary key rather than a timestamp: two messages stored in the same
	 * second would otherwise be indistinguishable, and the widget would replay or skip one.
	 *
	 * @since 0.1.0
	 *
	 * @param int $conversation_id The conversation.
	 * @param int $since_id        Return messages with an ID greater than this.
	 * @param int $limit           The maximum number of messages.
	 *
	 * @return array
	 */
	public static function get_messages( $conversation_id, $since_id = 0, $limit = 50 ) {

		global $wpdb;

		$table = LCFT_DB::messages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d AND id > %d ORDER BY id ASC LIMIT %d",
				(int) $conversation_id,
				(int) $since_id,
				(int) $limit
			)
		);

		return $rows ? $rows : array();
	}

	/**
	 * Returns the most recent messages, oldest first.
	 *
	 * Used when the widget opens: the member sees the tail of their history rather than every
	 * message they have ever sent.
	 *
	 * @since 0.1.0
	 *
	 * @param int $conversation_id The conversation.
	 * @param int $limit           How many messages to return.
	 *
	 * @return array
	 */
	public static function get_recent_messages( $conversation_id, $limit = 30 ) {

		global $wpdb;

		$table = LCFT_DB::messages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id DESC LIMIT %d",
				(int) $conversation_id,
				(int) $limit
			)
		);

		return $rows ? array_reverse( $rows ) : array();
	}

	/**
	 * Returns a message by the Telegram message ID it was sent as.
	 *
	 * @since 0.1.0
	 *
	 * @param int $conversation_id The conversation.
	 * @param int $tg_message_id   The Telegram message ID.
	 *
	 * @return object|null
	 */
	public static function get_by_tg_message_id( $conversation_id, $tg_message_id ) {

		global $wpdb;

		$table = LCFT_DB::messages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d AND tg_message_id = %d",
				(int) $conversation_id,
				(int) $tg_message_id
			)
		);
	}

	/**
	 * Counts the operator messages the member has not seen yet.
	 *
	 * The widget cannot work this out for itself: on a fresh page load every reply is new to it,
	 * and counting them all would badge the bubble with the whole conversation.
	 *
	 * @since 0.1.0
	 *
	 * @param int $conversation_id The conversation.
	 *
	 * @return int
	 */
	public static function count_unread( $conversation_id ) {

		global $wpdb;

		$table = LCFT_DB::messages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE conversation_id = %d AND direction = %s AND read_at IS NULL",
				(int) $conversation_id,
				self::OUT
			)
		);
	}

	/**
	 * Updates columns on a message.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $id     The message ID.
	 * @param array $values Column => value.
	 *
	 * @return bool
	 */
	public static function update_message( $id, $values ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( LCFT_DB::messages_table(), $values, array( 'id' => (int) $id ) );
	}

	/**
	 * Marks every operator message up to a cursor as seen by the member.
	 *
	 * Returns the Telegram message IDs that changed state, so the caller can place the read
	 * receipt reaction on exactly those and not on messages already acknowledged.
	 *
	 * @since 0.1.0
	 *
	 * @param int $conversation_id The conversation.
	 * @param int $up_to_id        The highest message ID the member has seen.
	 *
	 * @return array The Telegram message IDs newly marked as read.
	 */
	public static function mark_read( $conversation_id, $up_to_id ) {

		global $wpdb;

		$table = LCFT_DB::messages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT tg_message_id FROM {$table}
				 WHERE conversation_id = %d AND id <= %d AND direction = %s
				 AND read_at IS NULL AND tg_message_id IS NOT NULL",
				(int) $conversation_id,
				(int) $up_to_id,
				self::OUT
			)
		);

		if ( empty( $pending ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET read_at = %s
				 WHERE conversation_id = %d AND id <= %d AND direction = %s AND read_at IS NULL",
				current_time( 'mysql' ),
				(int) $conversation_id,
				(int) $up_to_id,
				self::OUT
			)
		);

		return array_map( 'intval', $pending );
	}


	// # RETENTION -----------------------------------------------------------------------------------------------------

	/**
	 * Deletes messages older than the configured retention, and their files.
	 *
	 * Conversations themselves are kept: the row is what ties a member to their Telegram topic, and
	 * dropping it would strand the topic and start a second one for the same person.
	 *
	 * @since 0.1.0
	 *
	 * @return int The number of messages removed.
	 */
	public static function purge() {

		$days = (int) LCFT_Settings::get( 'retention_days', 0 );

		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;

		$table  = LCFT_DB::messages_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', (int) current_time( 'timestamp' ) ) );

		// Files first: deleting the rows before the files on disk would leave orphans nothing
		// references and nothing will ever clean up.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$table} WHERE created_at < %s AND attachment_id IS NOT NULL",
				$cutoff
			)
		);

		foreach ( (array) $attachment_ids as $attachment_id ) {

			$attachment = LCFT_Attachments::get( (int) $attachment_id );

			if ( $attachment ) {
				LCFT_Attachments::delete( $attachment );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );

		return (int) $deleted;
	}
}
