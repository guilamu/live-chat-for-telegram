<?php
/**
 * Settings storage and derived values.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Settings
 *
 * The single source of truth for configuration. Everything else in the plugin reads from here
 * rather than calling get_option() directly, so defaults live in exactly one place.
 *
 * @since 0.1.0
 */
class LCFT_Settings {

	/**
	 * The REST namespace shared by the widget and webhook routes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'lcft/v1';

	/**
	 * The cached settings array.
	 *
	 * @since 0.1.0
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Returns the default value for every setting.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function defaults() {

		return array(

			// Telegram connection.
			'bot_token'            => '',
			'chat_id'              => '',
			'webhook_secret'       => '',

			// Who the member thinks they are talking to. A single shared identity is deliberate:
			// several operators answer from the same group, and the member should see one support
			// desk rather than whichever phone happened to reply.
			'agent_name'           => __( 'Support', 'live-chat-for-telegram' ),
			'agent_avatar_url'     => '',

			// Widget appearance and behaviour.
			'widget_enabled'       => true,
			'widget_position'      => 'right',
			'widget_accent'        => '#1c3f94',
			'widget_theme_accent'  => true,
			'custom_css'           => '',
			'welcome_message'      => '',
			'auto_open_delay'      => 0,
			'logged_out_mode'      => 'hide',
			'allowed_roles'        => array(),
			'excluded_post_ids'    => array(),

			// Polling cadence, in milliseconds, used by the widget while it is open. Raised
			// automatically by the widget when the tab loses focus.
			'poll_interval'        => 3000,

			// Opening hours. Messages are still accepted when closed; the member is told when to
			// expect an answer rather than being turned away, since we already know who they are.
			'schedule_enabled'     => false,
			'schedule'             => self::default_schedule(),
			'schedule_exceptions'  => array(),
			'closed_message'       => '',

			// Member details pulled from a Gravity Forms entry. Left empty by default: the field
			// IDs are specific to each site's form and there is no sensible generic guess.
			'gf_form_id'           => 0,
			'gf_field_map'         => array(),
			'topic_title_template' => '{first_name} {last_name}',
			'card_template'        => '',

			// Attachments.
			'attachments_enabled'  => true,
			'max_upload_bytes'     => 10485760,

			// The composer's emoji picker.
			'emoji_enabled'        => true,

			// Retention, in days. Zero keeps everything.
			'retention_days'       => 0,
		);
	}

	/**
	 * Returns the default weekly schedule: closed every day, so enabling the feature without
	 * configuring it cannot silently advertise hours nobody works.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function default_schedule() {

		$week = array();

		// 0 is Sunday, matching PHP's date( 'w' ).
		for ( $day = 0; $day <= 6; $day++ ) {
			$week[ $day ] = array(
				'enabled' => false,
				'open'    => '09:00',
				'close'   => '18:00',
			);
		}

		return $week;
	}

	/**
	 * Returns every setting, with defaults filled in.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function all() {

		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( LCFT_OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = array_merge( self::defaults(), $stored );

		return self::$cache;
	}

	/**
	 * Returns one setting.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key     The setting name.
	 * @param mixed  $default Returned when the setting is missing entirely.
	 *
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {

		$settings = self::all();

		if ( ! array_key_exists( $key, $settings ) ) {
			return $default;
		}

		return $settings[ $key ];
	}

	/**
	 * Merges values into the stored settings.
	 *
	 * @since 0.1.0
	 *
	 * @param array $values The settings to change.
	 *
	 * @return bool
	 */
	public static function update( $values ) {

		$settings = array_merge( self::all(), (array) $values );

		self::$cache = $settings;

		return update_option( LCFT_OPTION_KEY, $settings, true );
	}

	/**
	 * Empties the in-request cache, so the next read hits the database.
	 *
	 * @since 0.1.0
	 */
	public static function flush() {
		self::$cache = null;
	}


	// # TELEGRAM CONNECTION -------------------------------------------------------------------------------------------

	/**
	 * Returns the bot token, preferring the constant when one is defined.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_bot_token() {

		if ( defined( 'LCFT_BOT_TOKEN' ) && LCFT_BOT_TOKEN ) {
			return (string) LCFT_BOT_TOKEN;
		}

		return (string) self::get( 'bot_token', '' );
	}

	/**
	 * Indicates whether the token is pinned in wp-config.php, in which case the settings field is
	 * hidden rather than shown holding a value that has no effect.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function token_is_constant() {
		return defined( 'LCFT_BOT_TOKEN' ) && LCFT_BOT_TOKEN;
	}

	/**
	 * Returns the supergroup the topics live in.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_chat_id() {
		return (string) self::get( 'chat_id', '' );
	}

	/**
	 * Returns the shared secret Telegram echoes back on every webhook delivery, generating one on
	 * first use.
	 *
	 * This is the only thing separating a real update from a forged POST to a URL someone guessed,
	 * so it is never left empty.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_webhook_secret() {

		if ( defined( 'LCFT_WEBHOOK_SECRET' ) && LCFT_WEBHOOK_SECRET ) {
			return (string) LCFT_WEBHOOK_SECRET;
		}

		$secret = (string) self::get( 'webhook_secret', '' );

		if ( '' !== $secret ) {
			return $secret;
		}

		// Telegram only accepts A-Z, a-z, 0-9, _ and -, at most 256 characters.
		$secret = wp_generate_password( 48, false, false );

		self::update( array( 'webhook_secret' => $secret ) );

		return $secret;
	}

	/**
	 * Returns the URL Telegram should deliver updates to.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		return rest_url( self::REST_NAMESPACE . '/telegram' );
	}

	/**
	 * Indicates whether the plugin has everything it needs to run.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::get_bot_token() && '' !== self::get_chat_id();
	}


	// # AUDIENCE ------------------------------------------------------------------------------------------------------

	/**
	 * Indicates whether a user is allowed to use the chat.
	 *
	 * The chat is for signed in users only. That is the anti-spam design: there is no anonymous
	 * path in, so there is nothing to rate limit or CAPTCHA.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id The user to test. Defaults to the current user.
	 *
	 * @return bool
	 */
	public static function user_can_chat( $user_id = 0 ) {

		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$allowed = (array) self::get( 'allowed_roles', array() );

		// An empty list means every signed in user, which is the common case.
		if ( empty( $allowed ) ) {
			$can = true;
		} else {
			$user = get_userdata( $user_id );
			$can  = $user && array_intersect( $allowed, (array) $user->roles );
		}

		/**
		 * Filters whether a user may use the chat.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $can     Whether the user is allowed.
		 * @param int  $user_id The user being tested.
		 */
		return (bool) apply_filters( 'lcft_user_can_chat', (bool) $can, $user_id );
	}
}
