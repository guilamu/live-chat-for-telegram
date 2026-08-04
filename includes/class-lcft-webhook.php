<?php
/**
 * The Telegram to member direction.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Webhook
 *
 * Receives updates from Telegram and turns an operator's reply into a message the widget will
 * show.
 *
 * This endpoint is necessarily public — Telegram authenticates with nothing but the secret token
 * it echoes back — so that header is checked before anything else happens, and every other guard
 * in here assumes the payload is hostile until it passes.
 *
 * @since 0.1.0
 */
class LCFT_Webhook {

	/**
	 * Lets requests to this plugin's own REST routes through a site-wide "REST API for logged in
	 * users only" restriction.
	 *
	 * Plugins such as Password Protected (common on staging sites, which is exactly where this was
	 * first hit) filter `rest_authentication_errors` to block anonymous REST access everywhere,
	 * with no per-route exception. Telegram can never carry a WordPress session, so the webhook is
	 * unreachable behind such a restriction no matter how correctly it is configured — and a member
	 * without an editor-level role can fail the same check on the chat's own routes even while
	 * signed in, if the site additionally requires its own visitor password.
	 *
	 * This does not weaken anything: it only stops an unrelated site-wide policy from pre-empting
	 * this plugin's own checks, which still run immediately afterwards — verify_secret() for the
	 * webhook, is_user_logged_in() for everything else.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error|null|true $errors The current authentication result.
	 *
	 * @return WP_Error|null|true
	 */
	public static function allow_own_routes_through_rest_lockdown( $errors ) {

		// Read directly rather than waiting for full route dispatch: this filter runs early enough
		// that $GLOBALS['wp']->query_vars is not reliably populated yet, and covers both permalink
		// styles a site might use for the REST API.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read-only route match, nothing is output or stored.
		$route = isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : (string) ( $_SERVER['REQUEST_URI'] ?? '' );

		if ( false === strpos( $route, 'lcft/v1/' ) ) {
			return $errors;
		}

		return null;
	}

	/**
	 * Registers the route.
	 *
	 * @since 0.1.0
	 */
	public static function register_routes() {

		register_rest_route(
			LCFT_Settings::REST_NAMESPACE,
			'/telegram',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'verify_secret' ),
			)
		);
	}

	/**
	 * Confirms the request really came from Telegram.
	 *
	 * The secret is compared with hash_equals so the comparison takes the same time whatever the
	 * input, and a missing configuration refuses everything rather than falling open.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool
	 */
	public static function verify_secret( $request ) {

		$expected = LCFT_Settings::get( 'webhook_secret', '' );

		if ( defined( 'LCFT_WEBHOOK_SECRET' ) && LCFT_WEBHOOK_SECRET ) {
			$expected = LCFT_WEBHOOK_SECRET;
		}

		// Never generate the secret here. get_webhook_secret() would happily mint one, and a
		// request arriving before the webhook was ever registered would then authenticate itself.
		if ( '' === (string) $expected ) {
			return false;
		}

		$provided = (string) $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );

		return hash_equals( (string) $expected, $provided );
	}

	/**
	 * Handles an update.
	 *
	 * Always answers 200, whatever happens. A non-200 tells Telegram to deliver the same update
	 * again, which on a message we have already stored means showing the member a duplicate.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {

		$update = (array) $request->get_json_params();

		$message = isset( $update['message'] ) ? (array) $update['message'] : (array) ( isset( $update['edited_message'] ) ? $update['edited_message'] : array() );

		if ( $message ) {
			self::remember_chat( $message );
		}

		if ( isset( $update['edited_message'] ) ) {
			self::handle_edit( (array) $update['edited_message'] );

			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		if ( isset( $update['message'] ) ) {
			self::handle_message( (array) $update['message'] );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * The transient holding the last group the bot was spoken to in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const SEEN_CHAT_KEY = 'lcft_seen_chat';

	/**
	 * Remembers the chat an update came from, so the settings page can offer it.
	 *
	 * The ID Telegram wants is not the one its interface shows anywhere, and getting it wrong
	 * produces a "chat not found" that says nothing about which digits were missing. Since the
	 * webhook is already receiving these messages, it may as well read the ID off one.
	 *
	 * Recorded before any authorisation check on purpose: an unconfigured site has no group to
	 * compare against yet, which is precisely when this is needed. Nothing acts on the value — it
	 * is shown to an administrator who chooses whether to save it.
	 *
	 * @since 0.1.0
	 *
	 * @param array $message The Message object.
	 */
	protected static function remember_chat( $message ) {

		$chat = isset( $message['chat'] ) ? (array) $message['chat'] : array();

		if ( empty( $chat['id'] ) || empty( $chat['type'] ) || 'supergroup' !== $chat['type'] ) {
			return;
		}

		set_transient(
			self::SEEN_CHAT_KEY,
			array(
				'id'       => (string) $chat['id'],
				'title'    => isset( $chat['title'] ) ? (string) $chat['title'] : '',
				'is_forum' => ! empty( $chat['is_forum'] ),
			),
			15 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Stores an operator's message against the member whose topic it was written in.
	 *
	 * @since 0.1.0
	 *
	 * @param array $message The Telegram Message object.
	 */
	protected static function handle_message( $message ) {

		$conversation = self::resolve_conversation( $message );

		if ( ! $conversation ) {
			return;
		}

		$tg_message_id = isset( $message['message_id'] ) ? (int) $message['message_id'] : 0;

		// Telegram redelivers an update it believes failed. Without this check a slow response
		// would show the member the same reply twice.
		if ( $tg_message_id && LCFT_Conversations::get_by_tg_message_id( (int) $conversation->id, $tg_message_id ) ) {
			return;
		}

		// Joins, leaves, topic creation and pins all arrive as messages. None of them are things
		// an operator typed.
		if ( self::is_service_message( $message ) ) {
			return;
		}

		$body  = self::extract_text( $message );
		$media = self::extract_media( $message );

		if ( '' === $body && ! $media ) {
			return;
		}

		$attachment_id = null;

		if ( $media ) {

			$attachment = LCFT_Attachments::store_from_telegram(
				(int) $conversation->id,
				$media['file_id'],
				$media['kind'],
				$media
			);

			if ( is_wp_error( $attachment ) ) {

				// Tell the operator in the topic. Silently dropping the file would leave them
				// believing the member received something they never did.
				self::warn_operator( $conversation, $message, $attachment->get_error_message() );

				if ( '' === $body ) {
					return;
				}
			} else {
				$attachment_id = (int) $attachment->id;
			}
		}

		LCFT_Conversations::add_message(
			(int) $conversation->id,
			array(
				'direction'      => LCFT_Conversations::OUT,
				'body'           => $body,
				'attachment_id'  => $attachment_id,
				'tg_message_id'  => $tg_message_id ? $tg_message_id : null,
				'tg_from_id'     => isset( $message['from']['id'] ) ? (int) $message['from']['id'] : null,
				'tg_from_name'   => self::operator_name( $message ),
				'media_group_id' => isset( $message['media_group_id'] ) ? (string) $message['media_group_id'] : null,
			)
		);

		// A member who wrote while nobody was around gets their answer without having to reload.
		if ( 'closed' === $conversation->status ) {
			LCFT_Conversations::update( (int) $conversation->id, array( 'status' => 'open' ) );
		}
	}

	/**
	 * Applies an edit an operator made in Telegram to the message the member already has.
	 *
	 * @since 0.1.0
	 *
	 * @param array $message The edited Message object.
	 */
	protected static function handle_edit( $message ) {

		$conversation = self::resolve_conversation( $message );

		if ( ! $conversation || empty( $message['message_id'] ) ) {
			return;
		}

		$existing = LCFT_Conversations::get_by_tg_message_id( (int) $conversation->id, (int) $message['message_id'] );

		if ( ! $existing ) {
			return;
		}

		LCFT_Conversations::update_message(
			(int) $existing->id,
			array(
				'body'      => self::extract_text( $message ),
				'edited_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Finds the member a topic belongs to, rejecting anything from outside the configured group.
	 *
	 * @since 0.1.0
	 *
	 * @param array $message The Message object.
	 *
	 * @return object|null
	 */
	protected static function resolve_conversation( $message ) {

		$chat_id = isset( $message['chat']['id'] ) ? (string) $message['chat']['id'] : '';

		// The bot may well be in other groups. Only the one this site was pointed at may drive it.
		if ( '' === $chat_id || $chat_id !== (string) LCFT_Settings::get_chat_id() ) {
			return null;
		}

		// Another bot posting in the group is not an operator.
		if ( ! empty( $message['from']['is_bot'] ) ) {
			return null;
		}

		$topic_id = isset( $message['message_thread_id'] ) ? (int) $message['message_thread_id'] : 0;

		// No thread means the General topic, where operators talk among themselves. There is no
		// member on the other end of it.
		if ( ! $topic_id ) {
			return null;
		}

		return LCFT_Conversations::get_by_topic( $chat_id, $topic_id );
	}

	/**
	 * Returns the text of a message, wherever Telegram put it.
	 *
	 * A photo carries its note in `caption`, not in `text`. Reading only `text` is how a reply
	 * sent with an image loses the sentence that explained it.
	 *
	 * @since 0.1.0
	 *
	 * @param array $message The Message object.
	 *
	 * @return string
	 */
	protected static function extract_text( $message ) {

		foreach ( array( 'text', 'caption' ) as $key ) {
			if ( isset( $message[ $key ] ) && '' !== trim( (string) $message[ $key ] ) ) {
				return trim( (string) $message[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Describes the file attached to a message, if any.
	 *
	 * @since 0.1.0
	 *
	 * @param array $message The Message object.
	 *
	 * @return array|null file_id, kind and whatever metadata Telegram supplied.
	 */
	protected static function extract_media( $message ) {

		// Photos arrive as a list of sizes, smallest first. The last is the original.
		if ( ! empty( $message['photo'] ) && is_array( $message['photo'] ) ) {

			$largest = end( $message['photo'] );

			return array(
				'kind'      => 'photo',
				'file_id'   => (string) $largest['file_id'],
				'width'     => isset( $largest['width'] ) ? (int) $largest['width'] : null,
				'height'    => isset( $largest['height'] ) ? (int) $largest['height'] : null,
				'mime_type' => 'image/jpeg',
				'file_name' => '',
			);
		}

		$kinds = array( 'document', 'voice', 'audio', 'video', 'video_note' );

		foreach ( $kinds as $kind ) {

			if ( empty( $message[ $kind ]['file_id'] ) ) {
				continue;
			}

			$file = $message[ $kind ];

			return array(
				'kind'      => $kind,
				'file_id'   => (string) $file['file_id'],
				'file_name' => isset( $file['file_name'] ) ? (string) $file['file_name'] : '',
				'mime_type' => isset( $file['mime_type'] ) ? (string) $file['mime_type'] : '',
				'width'     => isset( $file['width'] ) ? (int) $file['width'] : null,
				'height'    => isset( $file['height'] ) ? (int) $file['height'] : null,
				'duration'  => isset( $file['duration'] ) ? (int) $file['duration'] : null,
			);
		}

		return null;
	}

	/**
	 * Indicates whether a message is one Telegram generated rather than one a person wrote.
	 *
	 * @since 0.1.0
	 *
	 * @param array $message The Message object.
	 *
	 * @return bool
	 */
	protected static function is_service_message( $message ) {

		$service_keys = array(
			'new_chat_members',
			'left_chat_member',
			'new_chat_title',
			'new_chat_photo',
			'delete_chat_photo',
			'pinned_message',
			'forum_topic_created',
			'forum_topic_edited',
			'forum_topic_closed',
			'forum_topic_reopened',
			'message_auto_delete_timer_changed',
		);

		foreach ( $service_keys as $key ) {
			if ( isset( $message[ $key ] ) ) {
				return true;
			}
		}

		// Stickers and dice would need rendering the widget has no business doing.
		return isset( $message['sticker'] ) || isset( $message['dice'] );
	}

	/**
	 * Returns the operator's name, for the record rather than for display.
	 *
	 * Members see a single support identity, not whichever phone answered, so this only ever
	 * appears in the admin history.
	 *
	 * @since 0.1.0
	 *
	 * @param array $message The Message object.
	 *
	 * @return string
	 */
	protected static function operator_name( $message ) {

		$from = isset( $message['from'] ) ? (array) $message['from'] : array();

		$name = trim(
			( isset( $from['first_name'] ) ? $from['first_name'] : '' ) . ' ' .
			( isset( $from['last_name'] ) ? $from['last_name'] : '' )
		);

		if ( '' !== $name ) {
			return $name;
		}

		return isset( $from['username'] ) ? (string) $from['username'] : '';
	}

	/**
	 * Replies in the topic to tell the operator something did not go through.
	 *
	 * @since 0.1.0
	 *
	 * @param object $conversation The conversation.
	 * @param array  $message      The message being answered.
	 * @param string $reason       The failure, already human readable.
	 */
	protected static function warn_operator( $conversation, $message, $reason ) {

		lcft_api()->send_message(
			array(
				'chat_id'             => $conversation->chat_id,
				'message_thread_id'   => (int) $conversation->topic_id,
				'reply_to_message_id' => isset( $message['message_id'] ) ? (int) $message['message_id'] : null,
				'text'                => '⚠️ ' . LCFT_Format::escape_html( $reason ),
				'parse_mode'          => 'HTML',
			)
		);
	}
}
