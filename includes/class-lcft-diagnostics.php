<?php
/**
 * Connection checks reported on the settings page.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Diagnostics
 *
 * Answers the only question that matters when nothing arrives: which link in the chain is broken.
 *
 * Each check reports a status and a sentence saying what to do about it, because every failure
 * here is fixed somewhere else — in BotFather, in the group's settings, or in wp-config.
 *
 * @since 0.1.0
 */
class LCFT_Diagnostics {

	/**
	 * Runs every check, in the order a message travels.
	 *
	 * @since 0.1.0
	 *
	 * @return array A list of checks, each with label, status and message.
	 */
	public static function run() {

		$checks = array();
		$api    = lcft_api();

		$bot      = self::check_token( $api );
		$checks[] = $bot['check'];

		if ( 'ok' !== $bot['check']['status'] ) {
			return $checks;
		}

		$group    = self::check_group( $api );
		$checks[] = $group['check'];

		if ( 'ok' === $group['check']['status'] || 'warning' === $group['check']['status'] ) {
			$checks[] = self::check_bot_rights( $api, $bot['id'] );
		}

		$checks[] = self::check_webhook( $api );

		return $checks;
	}

	/**
	 * Confirms the token works, and reports which bot it belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param LCFT_Telegram_API $api The API client.
	 *
	 * @return array The check, plus the bot's user ID.
	 */
	protected static function check_token( $api ) {

		$label = __( 'Bot token', 'live-chat-for-telegram' );

		if ( ! $api->has_token() ) {
			return array(
				'id'    => 0,
				'check' => self::result( $label, 'error', __( 'No token has been set yet.', 'live-chat-for-telegram' ) ),
			);
		}

		$me = $api->get_me();

		if ( is_wp_error( $me ) ) {
			return array(
				'id'    => 0,
				'check' => self::result(
					$label,
					'error',
					sprintf(
						/* translators: %s: The error returned by Telegram. */
						__( 'Telegram rejected the token: %s', 'live-chat-for-telegram' ),
						$me->get_error_message()
					)
				),
			);
		}

		return array(
			'id'    => isset( $me['id'] ) ? (int) $me['id'] : 0,
			'check' => self::result(
				$label,
				'ok',
				sprintf(
					/* translators: %s: The bot's username. */
					__( 'Connected as @%s.', 'live-chat-for-telegram' ),
					isset( $me['username'] ) ? $me['username'] : '?'
				)
			),
		);
	}

	/**
	 * Confirms the group exists and has topics turned on.
	 *
	 * @since 0.1.0
	 *
	 * @param LCFT_Telegram_API $api The API client.
	 *
	 * @return array The check, plus the chat data.
	 */
	protected static function check_group( $api ) {

		$label   = __( 'Group', 'live-chat-for-telegram' );
		$chat_id = LCFT_Settings::get_chat_id();

		if ( '' === $chat_id ) {
			return array(
				'chat'  => array(),
				'check' => self::result( $label, 'error', __( 'No group has been set yet.', 'live-chat-for-telegram' ) ),
			);
		}

		$chat = $api->get_chat( $chat_id );

		if ( is_wp_error( $chat ) ) {
			return array(
				'chat'  => array(),
				'check' => self::result(
					$label,
					'error',
					sprintf(
						/* translators: %s: The error returned by Telegram. */
						__( 'The group could not be read: %s Add the bot to the group first, then check the ID.', 'live-chat-for-telegram' ),
						$chat->get_error_message()
					)
				),
			);
		}

		$title = isset( $chat['title'] ) ? $chat['title'] : $chat_id;

		// Without topics every member would land in the same thread, which is the one arrangement
		// this plugin cannot route replies out of.
		if ( empty( $chat['is_forum'] ) ) {
			return array(
				'chat'  => (array) $chat,
				'check' => self::result(
					$label,
					'error',
					sprintf(
						/* translators: %s: The group name. */
						__( '"%s" found, but Topics are turned off. Turn them on in the group settings.', 'live-chat-for-telegram' ),
						$title
					)
				),
			);
		}

		return array(
			'chat'  => (array) $chat,
			'check' => self::result(
				$label,
				'ok',
				sprintf(
					/* translators: %s: The group name. */
					__( '"%s", with Topics enabled.', 'live-chat-for-telegram' ),
					$title
				)
			),
		);
	}

	/**
	 * Confirms the bot can actually create topics in the group.
	 *
	 * @since 0.1.0
	 *
	 * @param LCFT_Telegram_API $api    The API client.
	 * @param int               $bot_id The bot's user ID.
	 *
	 * @return array
	 */
	protected static function check_bot_rights( $api, $bot_id ) {

		$label = __( 'Bot permissions', 'live-chat-for-telegram' );

		if ( ! $bot_id ) {
			return self::result( $label, 'warning', __( 'The bot ID could not be read, so its rights were not checked.', 'live-chat-for-telegram' ) );
		}

		$member = $api->get_chat_member( LCFT_Settings::get_chat_id(), $bot_id );

		if ( is_wp_error( $member ) ) {
			return self::result(
				$label,
				'warning',
				sprintf(
					/* translators: %s: The error returned by Telegram. */
					__( 'The rights could not be checked: %s', 'live-chat-for-telegram' ),
					$member->get_error_message()
				)
			);
		}

		$status = isset( $member['status'] ) ? $member['status'] : '';

		if ( 'administrator' !== $status ) {
			return self::result(
				$label,
				'error',
				__( 'The bot is not an administrator of the group, so it cannot create topics.', 'live-chat-for-telegram' )
			);
		}

		if ( empty( $member['can_manage_topics'] ) ) {
			return self::result(
				$label,
				'error',
				__( 'The bot is an administrator but is missing the "Manage topics" permission.', 'live-chat-for-telegram' )
			);
		}

		return self::result( $label, 'ok', __( 'Administrator, with permission to manage topics.', 'live-chat-for-telegram' ) );
	}

	/**
	 * Reports whether Telegram is delivering updates to this site.
	 *
	 * @since 0.1.0
	 *
	 * @param LCFT_Telegram_API $api The API client.
	 *
	 * @return array
	 */
	protected static function check_webhook( $api ) {

		$label = __( 'Webhook', 'live-chat-for-telegram' );
		$info  = $api->get_webhook_info();

		if ( is_wp_error( $info ) ) {
			return self::result(
				$label,
				'error',
				sprintf(
					/* translators: %s: The error returned by Telegram. */
					__( 'The webhook could not be read: %s', 'live-chat-for-telegram' ),
					$info->get_error_message()
				)
			);
		}

		$registered = isset( $info['url'] ) ? (string) $info['url'] : '';
		$expected   = LCFT_Settings::get_webhook_url();

		if ( '' === $registered ) {
			return self::result( $label, 'error', __( 'No webhook is registered. Replies from Telegram will not reach the site.', 'live-chat-for-telegram' ) );
		}

		// The bot can only have one webhook. Another site holding it is the usual reason replies
		// stop arriving here, and naming the URL is what makes that obvious.
		if ( $registered !== $expected ) {
			return self::result(
				$label,
				'error',
				sprintf(
					/* translators: %s: The URL currently registered. */
					__( 'This bot is delivering updates to another address: %s Give this site its own bot, or take the webhook over.', 'live-chat-for-telegram' ),
					esc_url( $registered )
				)
			);
		}

		$last_error = isset( $info['last_error_message'] ) ? (string) $info['last_error_message'] : '';
		$pending    = isset( $info['pending_update_count'] ) ? (int) $info['pending_update_count'] : 0;

		if ( '' !== $last_error ) {
			return self::result(
				$label,
				'warning',
				sprintf(
					/* translators: 1: The last error Telegram saw. 2: The number of undelivered updates. */
					__( 'Registered, but Telegram last saw: %1$s (%2$d update(s) waiting).', 'live-chat-for-telegram' ),
					$last_error,
					$pending
				)
			);
		}

		return self::result( $label, 'ok', __( 'Registered and delivering.', 'live-chat-for-telegram' ) );
	}

	/**
	 * Shapes one check result.
	 *
	 * @since 0.1.0
	 *
	 * @param string $label   What was checked.
	 * @param string $status  ok, warning or error.
	 * @param string $message What to tell the operator.
	 *
	 * @return array
	 */
	protected static function result( $label, $status, $message ) {

		return array(
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
	}
}
