<?php
/**
 * Telegram Bot API client.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Telegram_API
 *
 * A thin wrapper around the Bot API; no SDK, no dependencies. Every method returns either the
 * decoded `result` payload or a WP_Error built from Telegram's own error_code and description.
 *
 * Unlike a notification-only client this one also reads: forum topics are created here, and files
 * an operator sends from their phone are pulled back down to the site.
 *
 * @since 0.1.0
 *
 * @link https://core.telegram.org/bots/api
 */
class LCFT_Telegram_API {

	/**
	 * The default Telegram Bot API host.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const DEFAULT_BASE_URL = 'https://api.telegram.org';

	/**
	 * The placeholder written to logs in place of the bot token.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const TOKEN_MASK = '[bot-token-redacted]';

	/**
	 * The longest retry_after, in seconds, this client will wait before giving up on a 429.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MAX_RETRY_AFTER = 5;

	/**
	 * The maximum length of a single message, as enforced by Telegram.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MAX_MESSAGE_LENGTH = 4096;

	/**
	 * The maximum length of a photo or document caption.
	 *
	 * Much shorter than a message: a long note sent alongside a file has to follow as its own
	 * message rather than ride along with it.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MAX_CAPTION_LENGTH = 1024;

	/**
	 * The largest file the Bot API accepts as a document upload.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MAX_DOCUMENT_BYTES = 52428800;

	/**
	 * The largest file the Bot API accepts as a photo upload.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MAX_PHOTO_BYTES = 10485760;

	/**
	 * The largest file getFile will hand back.
	 *
	 * Note the asymmetry: a member can upload 50 MB to Telegram, but an operator replying with
	 * anything over 20 MB cannot be mirrored back to the site. Callers are expected to surface
	 * that to the operator rather than fail silently.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	const MAX_DOWNLOAD_BYTES = 20971520;

	/**
	 * The bot token, as issued by @BotFather.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $bot_token;

	/**
	 * The API base URL. Only differs from the default when a self hosted Bot API server or a
	 * proxy is in use.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected $base_url;

	/**
	 * Initializes the API client.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bot_token The bot token.
	 * @param string $base_url  The API base URL. Defaults to https://api.telegram.org.
	 */
	public function __construct( $bot_token, $base_url = self::DEFAULT_BASE_URL ) {
		$this->bot_token = (string) $bot_token;
		$this->base_url  = untrailingslashit( $base_url ? $base_url : self::DEFAULT_BASE_URL );
	}

	/**
	 * Indicates whether a token has been configured at all.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_token() {
		return '' !== trim( $this->bot_token );
	}


	// # BOT AND WEBHOOK -----------------------------------------------------------------------------------------------

	/**
	 * Returns basic information about the bot. Used to validate the token.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#getme
	 *
	 * @return array|WP_Error
	 */
	public function get_me() {
		return $this->make_request( 'getMe' );
	}

	/**
	 * Returns information about a chat, used to confirm a group is a forum before topics are
	 * created in it.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#getchat
	 *
	 * @param int|string $chat_id The chat ID.
	 *
	 * @return array|WP_Error
	 */
	public function get_chat( $chat_id ) {
		return $this->make_request( 'getChat', array( 'chat_id' => $chat_id ) );
	}

	/**
	 * Returns a member of a chat, used to confirm the bot has the rights it needs.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#getchatmember
	 *
	 * @param int|string $chat_id The chat ID.
	 * @param int        $user_id The user to look up.
	 *
	 * @return array|WP_Error
	 */
	public function get_chat_member( $chat_id, $user_id ) {

		return $this->make_request(
			'getChatMember',
			array(
				'chat_id' => $chat_id,
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * Returns the bot's current webhook registration, if any.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#getwebhookinfo
	 *
	 * @return array|WP_Error
	 */
	public function get_webhook_info() {
		return $this->make_request( 'getWebhookInfo' );
	}

	/**
	 * Registers the webhook Telegram should deliver updates to.
	 *
	 * The secret token is not optional in practice. Telegram echoes it back in the
	 * X-Telegram-Bot-Api-Secret-Token header, and it is the only thing distinguishing a genuine
	 * update from anyone who has guessed the endpoint URL.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#setwebhook
	 *
	 * @param string $url             The HTTPS URL to deliver updates to.
	 * @param string $secret_token    The shared secret echoed back on every delivery.
	 * @param array  $allowed_updates The update types to receive. Defaults to messages only.
	 *
	 * @return array|WP_Error
	 */
	public function set_webhook( $url, $secret_token, $allowed_updates = null ) {

		if ( null === $allowed_updates ) {
			$allowed_updates = array( 'message', 'edited_message' );
		}

		return $this->make_request(
			'setWebhook',
			array(
				'url'                  => $url,
				'secret_token'         => $secret_token,
				'allowed_updates'      => $allowed_updates,
				'drop_pending_updates' => true,
				'max_connections'      => 40,
			)
		);
	}

	/**
	 * Removes the webhook registration.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#deletewebhook
	 *
	 * @return array|WP_Error
	 */
	public function delete_webhook() {
		return $this->make_request( 'deleteWebhook', array( 'drop_pending_updates' => false ) );
	}


	// # SENDING -------------------------------------------------------------------------------------------------------

	/**
	 * Sends a text message.
	 *
	 * The message is sent exactly as given: this client does not split, truncate or escape the
	 * text. Callers are expected to have prepared it for the requested parse mode.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#sendmessage
	 *
	 * @param array $args The sendMessage arguments. chat_id and text are required.
	 *
	 * @return array|WP_Error The sent Message object, or an error.
	 */
	public function send_message( $args ) {

		$args = (array) $args;

		if ( self::is_blank( self::get( $args, 'chat_id' ) ) ) {
			return new WP_Error( 'missing_chat_id', esc_html__( 'No Telegram chat ID was provided.', 'live-chat-for-telegram' ) );
		}

		if ( self::is_blank( self::get( $args, 'text' ) ) ) {
			return new WP_Error( 'missing_text', esc_html__( 'The message is empty.', 'live-chat-for-telegram' ) );
		}

		return $this->make_request( 'sendMessage', $args );
	}

	/**
	 * Sends a file as a document, preserving it exactly as uploaded.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#senddocument
	 *
	 * @param array  $args      The sendDocument arguments. chat_id is required.
	 * @param string $file_path Absolute path to the file to upload.
	 *
	 * @return array|WP_Error
	 */
	public function send_document( $args, $file_path ) {

		$args = (array) $args;

		if ( self::is_blank( self::get( $args, 'chat_id' ) ) ) {
			return new WP_Error( 'missing_chat_id', esc_html__( 'No Telegram chat ID was provided.', 'live-chat-for-telegram' ) );
		}

		return $this->make_request( 'sendDocument', $args, array( 'document' => $file_path ) );
	}

	/**
	 * Sends a file as a photo.
	 *
	 * Telegram recompresses photos, so this loses the original file. Screenshots of small text are
	 * usually better sent as documents.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#sendphoto
	 *
	 * @param array  $args      The sendPhoto arguments. chat_id is required.
	 * @param string $file_path Absolute path to the image to upload.
	 *
	 * @return array|WP_Error
	 */
	public function send_photo( $args, $file_path ) {

		$args = (array) $args;

		if ( self::is_blank( self::get( $args, 'chat_id' ) ) ) {
			return new WP_Error( 'missing_chat_id', esc_html__( 'No Telegram chat ID was provided.', 'live-chat-for-telegram' ) );
		}

		return $this->make_request( 'sendPhoto', $args, array( 'photo' => $file_path ) );
	}

	/**
	 * Shows a status such as "typing" in a chat or topic.
	 *
	 * Telegram clears the status after five seconds or when the bot posts, whichever comes first,
	 * so callers repeat this while the member is still typing rather than cancelling it.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#sendchataction
	 *
	 * @param int|string $chat_id   The chat ID.
	 * @param string     $action    The action, e.g. typing or upload_document.
	 * @param int|null   $thread_id The forum topic to show the status in, when applicable.
	 *
	 * @return array|WP_Error
	 */
	public function send_chat_action( $chat_id, $action = 'typing', $thread_id = null ) {

		return $this->make_request(
			'sendChatAction',
			array(
				'chat_id'           => $chat_id,
				'action'            => $action,
				'message_thread_id' => $thread_id,
			)
		);
	}

	/**
	 * Sets, or clears, the bot's reaction to a message.
	 *
	 * Used as the read receipt: when the member's browser displays an operator's reply, the site
	 * reacts to that message so the operator can see it landed.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#setmessagereaction
	 *
	 * @param int|string $chat_id    The chat ID.
	 * @param int        $message_id The message to react to.
	 * @param string     $emoji      The reaction emoji, or an empty string to remove it.
	 *
	 * @return array|WP_Error
	 */
	public function set_message_reaction( $chat_id, $message_id, $emoji = '👀' ) {

		$reaction = array();

		if ( '' !== $emoji ) {
			$reaction[] = array(
				'type'  => 'emoji',
				'emoji' => $emoji,
			);
		}

		return $this->make_request(
			'setMessageReaction',
			array(
				'chat_id'    => $chat_id,
				'message_id' => $message_id,
				'reaction'   => $reaction,
				'is_big'     => false,
			)
		);
	}

	/**
	 * Pins a message, so the member's details card stays at the top of their topic.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#pinchatmessage
	 *
	 * @param int|string $chat_id    The chat ID.
	 * @param int        $message_id The message to pin.
	 *
	 * @return array|WP_Error
	 */
	public function pin_chat_message( $chat_id, $message_id ) {

		return $this->make_request(
			'pinChatMessage',
			array(
				'chat_id'              => $chat_id,
				'message_id'           => $message_id,
				'disable_notification' => true,
			)
		);
	}


	// # FORUM TOPICS --------------------------------------------------------------------------------------------------

	/**
	 * Creates a forum topic.
	 *
	 * Requires the group to be a supergroup with topics enabled, and the bot to be an
	 * administrator with can_manage_topics.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#createforumtopic
	 *
	 * @param int|string $chat_id    The supergroup ID.
	 * @param string     $name       The topic name, at most 128 characters.
	 * @param int|null   $icon_color The topic colour, from Telegram's fixed palette.
	 *
	 * @return array|WP_Error The ForumTopic object, whose message_thread_id identifies the topic.
	 */
	public function create_forum_topic( $chat_id, $name, $icon_color = null ) {

		return $this->make_request(
			'createForumTopic',
			array(
				'chat_id'    => $chat_id,
				'name'       => $this->truncate( $name, 128 ),
				'icon_color' => $icon_color,
			)
		);
	}

	/**
	 * Renames a forum topic, so a member who updates their details does not leave a stale title
	 * behind.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#editforumtopic
	 *
	 * @param int|string $chat_id   The supergroup ID.
	 * @param int        $thread_id The topic to rename.
	 * @param string     $name      The new name.
	 *
	 * @return array|WP_Error
	 */
	public function edit_forum_topic( $chat_id, $thread_id, $name ) {

		return $this->make_request(
			'editForumTopic',
			array(
				'chat_id'           => $chat_id,
				'message_thread_id' => $thread_id,
				'name'              => $this->truncate( $name, 128 ),
			)
		);
	}

	/**
	 * Closes a forum topic without deleting it, keeping the history readable.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#closeforumtopic
	 *
	 * @param int|string $chat_id   The supergroup ID.
	 * @param int        $thread_id The topic to close.
	 *
	 * @return array|WP_Error
	 */
	public function close_forum_topic( $chat_id, $thread_id ) {

		return $this->make_request(
			'closeForumTopic',
			array(
				'chat_id'           => $chat_id,
				'message_thread_id' => $thread_id,
			)
		);
	}

	/**
	 * Reopens a closed forum topic, which is what happens when a member writes again.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#reopenforumtopic
	 *
	 * @param int|string $chat_id   The supergroup ID.
	 * @param int        $thread_id The topic to reopen.
	 *
	 * @return array|WP_Error
	 */
	public function reopen_forum_topic( $chat_id, $thread_id ) {

		return $this->make_request(
			'reopenForumTopic',
			array(
				'chat_id'           => $chat_id,
				'message_thread_id' => $thread_id,
			)
		);
	}


	// # RECEIVING FILES -----------------------------------------------------------------------------------------------

	/**
	 * Resolves a file_id into a temporary download path.
	 *
	 * @since 0.1.0
	 *
	 * @link https://core.telegram.org/bots/api#getfile
	 *
	 * @param string $file_id The file identifier taken from an update.
	 *
	 * @return array|WP_Error The File object, whose file_path is valid for about one hour.
	 */
	public function get_file( $file_id ) {

		if ( self::is_blank( $file_id ) ) {
			return new WP_Error( 'missing_file_id', esc_html__( 'No file identifier was provided.', 'live-chat-for-telegram' ) );
		}

		return $this->make_request( 'getFile', array( 'file_id' => $file_id ) );
	}

	/**
	 * Downloads a file to disk.
	 *
	 * Streamed straight to the destination rather than held in memory: a 20 MB video would
	 * otherwise be loaded whole on a host that may only allow 40 MB per request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file_path   The file_path returned by getFile.
	 * @param string $destination Absolute path to write the file to.
	 *
	 * @return string|WP_Error The destination path on success.
	 */
	public function download_file( $file_path, $destination ) {

		if ( self::is_blank( $file_path ) ) {
			return new WP_Error( 'missing_file_path', esc_html__( 'No file path was provided.', 'live-chat-for-telegram' ) );
		}

		$url = sprintf( '%s/file/bot%s/%s', $this->base_url, $this->bot_token, ltrim( $file_path, '/' ) );

		$response = wp_remote_get(
			$url,
			array(
				/** This filter is documented in wp-includes/class-wp-http.php */
				'timeout'  => apply_filters( 'http_request_timeout', 120, $url ),
				'stream'   => true,
				'filename' => $destination,
			)
		);

		if ( is_wp_error( $response ) ) {

			// A streamed request writes as it reads, so a failure part way through leaves a
			// truncated file behind.
			$this->delete_file( $destination );

			return new WP_Error(
				$response->get_error_code(),
				$this->mask( $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {

			// On an error Telegram sends a JSON envelope, which the stream has just written into
			// the destination file. Remove it rather than leave a stub that looks like a download.
			$this->delete_file( $destination );

			return new WP_Error(
				'download_failed',
				sprintf(
					/* translators: %d: The HTTP response code. */
					esc_html__( 'The file could not be downloaded from Telegram (HTTP %d).', 'live-chat-for-telegram' ),
					$code
				)
			);
		}

		return $destination;
	}


	// # HELPERS -------------------------------------------------------------------------------------------------------

	/**
	 * Replaces the bot token with a placeholder so it is never written to a log.
	 *
	 * The token appears in every request URL, so this must be applied to anything derived from a
	 * request before it is logged.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text to mask.
	 *
	 * @return string
	 */
	public function mask( $text ) {

		if ( self::is_blank( $this->bot_token ) ) {
			return (string) $text;
		}

		return str_replace( $this->bot_token, self::TOKEN_MASK, (string) $text );
	}

	/**
	 * Reads a key from an array, returning a default when it is absent.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $array   The array to read from.
	 * @param string $key     The key to read.
	 * @param mixed  $default The value to return when the key is missing.
	 *
	 * @return mixed
	 */
	protected static function get( $array, $key, $default = null ) {
		return isset( $array[ $key ] ) ? $array[ $key ] : $default;
	}

	/**
	 * Indicates whether a value is empty, without treating "0" as empty.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value to test.
	 *
	 * @return bool
	 */
	protected static function is_blank( $value ) {

		if ( is_array( $value ) ) {
			return empty( $value );
		}

		return null === $value || '' === trim( (string) $value );
	}

	/**
	 * Shortens a string to a character count, counting the way Telegram does.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text   The text to shorten.
	 * @param int    $length The maximum length.
	 *
	 * @return string
	 */
	protected function truncate( $text, $length ) {
		return LCFT_Format::truncate( $text, $length );
	}

	/**
	 * Deletes a file, through WP_Filesystem when it is available.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path to the file.
	 */
	protected function delete_file( $path ) {

		if ( ! file_exists( $path ) ) {
			return;
		}

		global $wp_filesystem;

		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $path );

			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		@unlink( $path );
	}


	// # REQUEST METHODS -----------------------------------------------------------------------------------------------

	/**
	 * Makes an API request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method   The Bot API method name, e.g. sendMessage.
	 * @param array  $args     The method arguments.
	 * @param array  $files    Files to upload, keyed by the parameter name Telegram expects.
	 * @param bool   $is_retry Indicates if this is the retry which follows a rate limit response.
	 *
	 * @return array|WP_Error
	 */
	protected function make_request( $method, $args = array(), $files = array(), $is_retry = false ) {

		if ( ! $this->has_token() ) {
			return new WP_Error( 'missing_token', esc_html__( 'No Telegram bot token has been configured.', 'live-chat-for-telegram' ) );
		}

		$request_url = sprintf( '%s/bot%s/%s', $this->base_url, $this->bot_token, $method );

		if ( ! empty( $files ) ) {

			$boundary = '----LCFT' . md5( uniqid( '', true ) );
			$body     = $this->build_multipart_body( $args, $files, $boundary );

			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$content_type = 'multipart/form-data; boundary=' . $boundary;

		} else {

			// The request is sent as JSON rather than form encoded. Nested parameters such as
			// reply_markup and reaction would otherwise be flattened by http_build_query into
			// bracketed keys that Telegram rejects, and booleans would arrive as "1" and "".
			$body         = wp_json_encode( $this->prepare_args( $args ) );
			$content_type = 'application/json';

			if ( false === $body ) {
				return new WP_Error( 'invalid_request', esc_html__( 'The message could not be encoded for sending.', 'live-chat-for-telegram' ) );
			}
		}

		$request_args = array(
			'method'  => 'POST',
			'body'    => $body,
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => $content_type,
			),
			/** This filter is documented in wp-includes/class-wp-http.php */
			'timeout' => apply_filters( 'http_request_timeout', empty( $files ) ? 15 : 120, $request_url ),
		);

		$response = wp_remote_post( $request_url, $request_args );

		// A transport level failure: no response was received at all.
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				$response->get_error_code(),
				$this->mask( $response->get_error_message() ),
				$this->mask( (string) $response->get_error_data() )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$response_data = json_decode( $response_body, true );

		// Anything that is not a Telegram JSON envelope: a proxy error page, an HTML 502, etc.
		if ( ! is_array( $response_data ) || ! isset( $response_data['ok'] ) ) {
			return new WP_Error(
				'invalid_response',
				sprintf(
					/* translators: %d: The HTTP response code. */
					esc_html__( 'The API returned an unexpected response (HTTP %d).', 'live-chat-for-telegram' ),
					$response_code
				),
				$this->mask( substr( $response_body, 0, 500 ) )
			);
		}

		if ( ! empty( $response_data['ok'] ) ) {
			return self::get( $response_data, 'result' );
		}

		$error_code  = (int) self::get( $response_data, 'error_code', $response_code );
		$description = (string) self::get( $response_data, 'description', '' );
		$parameters  = (array) self::get( $response_data, 'parameters', array() );

		// Telegram asks callers to back off; honor a single short retry.
		$retry_after = (int) self::get( $parameters, 'retry_after', 0 );
		if ( 429 === $error_code && ! $is_retry && $retry_after > 0 && $retry_after <= self::MAX_RETRY_AFTER ) {
			sleep( $retry_after );

			return $this->make_request( $method, $args, $files, true );
		}

		return new WP_Error( $error_code, $this->mask( $description ), $parameters );
	}

	/**
	 * Assembles a multipart/form-data request body.
	 *
	 * WordPress has no helper for this: wp_remote_post() sends an array body as form encoded data
	 * and offers no way to attach a file, so the body is built by hand.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $args     The method arguments.
	 * @param array  $files    Files to upload, keyed by the parameter name Telegram expects.
	 * @param string $boundary The multipart boundary.
	 *
	 * @return string|WP_Error
	 */
	protected function build_multipart_body( $args, $files, $boundary ) {

		$body = '';

		foreach ( $this->prepare_args( $args ) as $name => $value ) {

			// Everything crosses the wire as text here, so the types JSON would have carried have
			// to be spelled out: Telegram reads "true" and "false", not "1" and "".
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}

			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
			$body .= $value . "\r\n";
		}

		foreach ( (array) $files as $name => $path ) {

			if ( ! is_readable( $path ) ) {
				return new WP_Error(
					'unreadable_file',
					sprintf(
						/* translators: %s: The file name. */
						esc_html__( 'The file %s could not be read.', 'live-chat-for-telegram' ),
						basename( $path )
					)
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $path );

			if ( false === $contents ) {
				return new WP_Error(
					'unreadable_file',
					sprintf(
						/* translators: %s: The file name. */
						esc_html__( 'The file %s could not be read.', 'live-chat-for-telegram' ),
						basename( $path )
					)
				);
			}

			// A quote in the file name would end the header field early.
			$filename = str_replace( array( '"', "\r", "\n" ), '', basename( $path ) );

			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $filename . '"' . "\r\n";
			$body .= "Content-Type: application/octet-stream\r\n\r\n";
			$body .= $contents . "\r\n";
		}

		$body .= '--' . $boundary . "--\r\n";

		return $body;
	}

	/**
	 * Removes arguments which have no value so optional parameters can be passed unconditionally.
	 *
	 * Only the top level is filtered. Nested structures are built by this plugin rather than by
	 * user input, and filtering them would risk turning a JSON array into a JSON object by leaving
	 * gaps in its keys.
	 *
	 * @since 0.1.0
	 *
	 * @param array $args The arguments to filter.
	 *
	 * @return array
	 */
	protected function prepare_args( $args ) {

		$prepared = array();

		foreach ( (array) $args as $key => $value ) {

			if ( is_null( $value ) ) {
				continue;
			}

			$prepared[ $key ] = $value;
		}

		return $prepared;
	}
}
