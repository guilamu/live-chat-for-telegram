<?php
/**
 * The endpoints the chat bubble talks to.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_REST
 *
 * Every route here is for the signed in member and no one else. Authentication is WordPress's own
 * cookie plus the REST nonce, which is also what stops another site from driving these on a
 * member's behalf.
 *
 * @since 0.1.0
 */
class LCFT_REST {

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public static function register_routes() {

		$namespace = LCFT_Settings::REST_NAMESPACE;
		$guard     = array( __CLASS__, 'check_permission' );

		register_rest_route(
			$namespace,
			'/session',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_session' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			$namespace,
			'/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_messages' ),
				'permission_callback' => $guard,
				'args'                => array(
					'since' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/message',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_message' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			$namespace,
			'/typing',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_typing' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			$namespace,
			'/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_read' ),
				'permission_callback' => $guard,
				'args'                => array(
					'up_to' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/file/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'serve_file' ),
				'permission_callback' => $guard,
			)
		);
	}

	/**
	 * Allows the request when the current user may use the chat.
	 *
	 * @since 0.1.0
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission() {

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'lcft_not_logged_in',
				esc_html__( 'You need to be signed in to use the chat.', 'live-chat-for-telegram' ),
				array( 'status' => 401 )
			);
		}

		if ( ! LCFT_Settings::user_can_chat() ) {
			return new WP_Error(
				'lcft_not_allowed',
				esc_html__( 'Your account does not have access to the chat.', 'live-chat-for-telegram' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}


	// # ROUTES --------------------------------------------------------------------------------------------------------

	/**
	 * Returns everything the widget needs to draw itself on open.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function get_session() {

		$user_id      = get_current_user_id();
		$conversation = LCFT_Conversations::get_by_user( $user_id );
		$tokens       = LCFT_Member::get_tokens( $user_id );

		$messages = $conversation
			? LCFT_Conversations::get_recent_messages( (int) $conversation->id )
			: array();

		return new WP_REST_Response(
			array(
				'agent'    => array(
					'name'   => LCFT_Settings::get( 'agent_name' ),
					'avatar' => LCFT_Settings::get( 'agent_avatar_url' ),
				),
				'open'     => LCFT_Schedule::is_open(),
				'notice'   => LCFT_Format::render( LCFT_Schedule::get_notice(), $tokens ),
				'welcome'  => LCFT_Format::render( (string) LCFT_Settings::get( 'welcome_message' ), $tokens ),
				'unread'   => $conversation ? LCFT_Conversations::count_unread( (int) $conversation->id ) : 0,
				'messages' => array_map( array( __CLASS__, 'prepare_message' ), $messages ),
			),
			200
		);
	}

	/**
	 * Returns messages after a cursor.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_messages( $request ) {

		$conversation = LCFT_Conversations::get_by_user( get_current_user_id() );

		if ( ! $conversation ) {
			return new WP_REST_Response( array( 'messages' => array() ), 200 );
		}

		// While the member has the chat open, their failed messages are retried here rather than
		// waiting for the schedule.
		LCFT_Chat::redeliver( $conversation );

		$messages    = LCFT_Conversations::get_messages( (int) $conversation->id, (int) $request['since'] );
		$undelivered = LCFT_Conversations::get_undelivered( (int) $conversation->id, 50 );

		return new WP_REST_Response(
			array(
				'messages'    => array_map( array( __CLASS__, 'prepare_message' ), $messages ),
				'open'        => LCFT_Schedule::is_open(),

				// Lets the widget stop the waiting animation on messages delivered since.
				'undelivered' => array_map( 'intval', wp_list_pluck( $undelivered, 'id' ) ),
			),
			200
		);
	}

	/**
	 * Accepts a message from the member.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_message( $request ) {

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) ? $files['file'] : null;

		$result = LCFT_Chat::send_from_member(
			get_current_user_id(),
			(string) $request->get_param( 'body' ),
			$file
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		$conversation = LCFT_Conversations::get_by_user( get_current_user_id() );
		$stored       = LCFT_Conversations::get_messages( (int) $conversation->id, (int) $result['id'] - 1, 1 );

		return new WP_REST_Response(
			array(
				'message'   => $stored ? self::prepare_message( $stored[0] ) : null,
				'delivered' => $result['delivered'],
				'error'     => $result['error'],
			),
			201
		);
	}

	/**
	 * Relays the member's typing status into their topic.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function post_typing() {

		LCFT_Chat::relay_typing( get_current_user_id() );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Records that the member has seen an operator's messages.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public static function post_read( $request ) {

		$count = LCFT_Chat::acknowledge_read( get_current_user_id(), (int) $request['up_to'] );

		return new WP_REST_Response( array( 'marked' => $count ), 200 );
	}

	/**
	 * Streams an attachment to its owner.
	 *
	 * This is the only way the files are reachable: the directory itself is not served, and the
	 * names are random, so ownership is checked exactly once, here.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_Error|void
	 */
	public static function serve_file( $request ) {

		$attachment   = LCFT_Attachments::get( (int) $request['id'] );
		$conversation = LCFT_Conversations::get_by_user( get_current_user_id() );

		// One check covers both "no such file" and "not yours", and answers the same way for each
		// so the route cannot be used to discover which IDs exist.
		if ( ! $attachment || ! $conversation || (int) $attachment->conversation_id !== (int) $conversation->id ) {
			return new WP_Error(
				'lcft_not_found',
				esc_html__( 'File not found.', 'live-chat-for-telegram' ),
				array( 'status' => 404 )
			);
		}

		$path = LCFT_Attachments::absolute_path( $attachment );

		if ( ! is_readable( $path ) ) {
			return new WP_Error(
				'lcft_not_found',
				esc_html__( 'File not found.', 'live-chat-for-telegram' ),
				array( 'status' => 404 )
			);
		}

		// Images and audio are shown in place; everything else downloads. Serving a document
		// inline would let a crafted file run in the site's origin.
		$inline = in_array( $attachment->kind, array( 'photo', 'voice', 'audio', 'video', 'video_note' ), true );

		nocache_headers();
		header( 'Content-Type: ' . $attachment->mime_type );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'; sandbox' );
		header(
			sprintf(
				'Content-Disposition: %s; filename="%s"',
				$inline ? 'inline' : 'attachment',
				str_replace( '"', '', $attachment->file_name )
			)
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );

		exit;
	}


	// # SERIALIZATION -------------------------------------------------------------------------------------------------

	/**
	 * Shapes a stored message for the widget.
	 *
	 * @since 0.1.0
	 *
	 * @param object $message The message row.
	 *
	 * @return array
	 */
	public static function prepare_message( $message ) {

		$prepared = array(
			'id'        => (int) $message->id,
			'direction' => $message->direction,
			'body'      => (string) $message->body,
			'created'   => mysql2date( 'c', $message->created_at ),
			'edited'    => ! empty( $message->edited_at ),

			// Telegram sends an album as one update per file. The widget uses this to draw them
			// as a single message rather than as a burst.
			'mediaGroupId' => $message->media_group_id ? (string) $message->media_group_id : null,

			// An outgoing message with no Telegram ID never reached the group. The widget shows
			// that rather than letting the member believe someone is reading it.
			'delivered' => LCFT_Conversations::IN !== $message->direction || ! empty( $message->tg_message_id ),
		);

		if ( ! empty( $message->attachment_id ) ) {

			$attachment = LCFT_Attachments::get( (int) $message->attachment_id );

			if ( $attachment ) {
				$prepared['attachment'] = array(
					'url'      => LCFT_Attachments::url( $attachment ),
					'name'     => $attachment->file_name,
					'kind'     => $attachment->kind,
					'mime'     => $attachment->mime_type,
					'size'     => (int) $attachment->file_size,
					'sizeText' => LCFT_Format::file_size( $attachment->file_size ),
					'width'    => $attachment->width ? (int) $attachment->width : null,
					'height'   => $attachment->height ? (int) $attachment->height : null,
					'duration' => $attachment->duration ? (int) $attachment->duration : null,
				);
			}
		}

		return $prepared;
	}
}
