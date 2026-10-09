<?php
/**
 * The chat bubble on the front end.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Widget
 *
 * Prints the bubble and hands the browser everything it needs to run it.
 *
 * The markup is deliberately a shell: messages are never rendered server side, because the widget
 * has to draw them as they arrive anyway and two rendering paths would drift apart.
 *
 * @since 0.1.0
 */
class LCFT_Widget {

	/**
	 * Hooks the widget up.
	 *
	 * @since 0.1.0
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Flags pages where the member can chat, so a theme can hide a contact button the bubble
	 * replaces. Not added for the signed out invitation, which offers no chat by itself.
	 *
	 * @since 1.1.1
	 *
	 * @param string[] $classes The body classes.
	 *
	 * @return string[]
	 */
	public static function body_class( $classes ) {

		if ( LCFT_Settings::user_can_chat() && self::should_display() ) {
			$classes[] = 'lcft-chat-active';
		}

		return $classes;
	}

	/**
	 * Indicates whether the bubble should appear on this request.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function should_display() {

		if ( ! LCFT_Settings::get( 'widget_enabled', true ) || is_admin() ) {
			return false;
		}

		if ( ! LCFT_Settings::is_configured() ) {
			return false;
		}

		if ( ! LCFT_Settings::get( 'widget_in_builders', false ) && self::is_builder_request() ) {
			return false;
		}

		$excluded = (array) LCFT_Settings::get( 'excluded_post_ids', array() );

		if ( $excluded && is_singular() && in_array( get_the_ID(), array_map( 'intval', $excluded ), true ) ) {
			return false;
		}

		// A signed out visitor either sees an invitation to sign in, or nothing at all. They never
		// see a composer: there is no anonymous way into the chat.
		if ( ! LCFT_Settings::user_can_chat() ) {
			return 'invite' === LCFT_Settings::get( 'logged_out_mode', 'hide' ) && ! is_user_logged_in();
		}

		/**
		 * Filters whether the chat bubble is printed on this request.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $display Whether to print the bubble.
		 */
		return (bool) apply_filters( 'lcft_should_display', true );
	}

	/**
	 * Indicates whether the page is being edited or previewed in a page builder rather than visited.
	 *
	 * @since 1.1.8
	 *
	 * @return bool
	 */
	public static function is_builder_request() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only detection.
		$builder_params = array( 'et_fb', 'et_pb_preview', 'et_bfb', 'et_block_layout_preview', 'app_window', 'elementor-preview', 'fl_builder', 'ct_builder', 'bricks', 'brizy-edit-iframe', 'vc_editable' );

		foreach ( $builder_params as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				return true;
			}
		}
		// phpcs:enable

		$is_builder = is_customize_preview()
			|| ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() )
			|| ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST );

		/**
		 * Filters whether the current request is a page builder or editor preview, where the bubble
		 * is hidden unless "Also display the bubble while editing pages" is checked.
		 *
		 * @since 1.1.8
		 *
		 * @param bool $is_builder Whether the request comes from a page builder.
		 */
		return (bool) apply_filters( 'lcft_is_builder_request', $is_builder );
	}

	/**
	 * Loads the widget's assets.
	 *
	 * @since 0.1.0
	 */
	public static function enqueue() {

		if ( ! self::should_display() ) {
			return;
		}

		wp_enqueue_style( 'lcft-widget', LCFT_URL . 'assets/css/widget.css', array(), LCFT_VERSION );

		$accent = sanitize_hex_color( (string) LCFT_Settings::get( 'widget_accent', '#1c3f94' ) );
		$accent = $accent ? $accent : '#1c3f94';

		// The theme's own main colour, when it publishes one: Divi 5 global colours first, then a
		// block theme's "primary" preset. The picked colour is the last fallback.
		if ( LCFT_Settings::get( 'widget_theme_accent', true ) ) {
			$accent = 'var(--gcid-primary-color,var(--wp--preset--color--primary,' . $accent . '))';
		}

		// Declared on .lcft itself, not :root: the stylesheet's default lives on .lcft and would
		// otherwise shadow anything inherited from above.
		wp_add_inline_style( 'lcft-widget', '.lcft{--lcft-accent:' . $accent . ';}' );

		// Printed after the accent, so the site's own rules win at equal specificity. Tags were
		// stripped on save; stripping again covers a value written straight into the option.
		$custom_css = trim( wp_strip_all_tags( (string) LCFT_Settings::get( 'custom_css', '' ) ) );

		if ( '' !== $custom_css ) {
			wp_add_inline_style( 'lcft-widget', $custom_css );
		}

		// A signed out visitor gets the stylesheet and the button, and no script at all: there is
		// nothing for it to do but send them to the login page, which a link already does.
		if ( ! LCFT_Settings::user_can_chat() ) {
			return;
		}

		wp_enqueue_script( 'lcft-widget', LCFT_URL . 'assets/js/widget.js', array(), LCFT_VERSION, true );

		wp_localize_script(
			'lcft-widget',
			'lcftWidget',
			array(
				'root'         => esc_url_raw( rest_url( LCFT_Settings::REST_NAMESPACE ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'pollInterval' => (int) LCFT_Settings::get( 'poll_interval', 3000 ),
				'autoOpen'     => (int) LCFT_Settings::get( 'auto_open_delay', 0 ),
				'attachments'  => (bool) LCFT_Settings::get( 'attachments_enabled', true ),
				'maxUpload'    => (int) LCFT_Settings::get( 'max_upload_bytes', 10485760 ),
				'i18n'         => array(
					'title'        => LCFT_Settings::get( 'agent_name', __( 'Support', 'live-chat-for-telegram' ) ),
					'placeholder'  => __( 'Write your message…', 'live-chat-for-telegram' ),
					'send'         => __( 'Send', 'live-chat-for-telegram' ),
					'attach'       => __( 'Attach a file', 'live-chat-for-telegram' ),
					'close'        => __( 'Close', 'live-chat-for-telegram' ),
					'open'         => __( 'Open the chat', 'live-chat-for-telegram' ),
					'you'          => __( 'You', 'live-chat-for-telegram' ),
					'sending'      => __( 'Sending…', 'live-chat-for-telegram' ),
					'edited'       => __( 'edited', 'live-chat-for-telegram' ),
					'today'        => __( 'Today', 'live-chat-for-telegram' ),
					'yesterday'    => __( 'Yesterday', 'live-chat-for-telegram' ),
					'tooLarge'     => __( 'That file is too large.', 'live-chat-for-telegram' ),
					/* translators: Base name given to a pasted screenshot, followed by the date and time. */
					'screenshot'   => __( 'screenshot', 'live-chat-for-telegram' ),
					'download'     => __( 'Download', 'live-chat-for-telegram' ),
					'audioFallback' => __( 'Your browser cannot play this recording.', 'live-chat-for-telegram' ),
					'expired'      => __( 'Your session has expired. Reload the page to carry on.', 'live-chat-for-telegram' ),
					'failed'       => __( 'The message could not be sent.', 'live-chat-for-telegram' ),
				),
			)
		);
	}

	/**
	 * Prints the bubble.
	 *
	 * @since 0.1.0
	 */
	public static function render() {

		if ( ! self::should_display() ) {
			return;
		}

		$position = 'left' === LCFT_Settings::get( 'widget_position', 'right' ) ? 'lcft--left' : 'lcft--right';

		if ( ! LCFT_Settings::user_can_chat() ) {
			self::render_invitation( $position );

			return;
		}

		?>
		<div class="lcft <?php echo esc_attr( $position ); ?>" data-lcft hidden style="<?php echo esc_attr( self::initials_style() ); ?>">
			<div class="lcft__panel" role="dialog" aria-live="polite"
				aria-label="<?php echo esc_attr( LCFT_Settings::get( 'agent_name' ) ); ?>" data-lcft-panel hidden>

				<header class="lcft__header">
					<?php $avatar = LCFT_Settings::get( 'agent_avatar_url' ); ?>
					<?php if ( $avatar ) : ?>
						<img class="lcft__avatar" src="<?php echo esc_url( $avatar ); ?>" alt="" width="32" height="32" />
					<?php endif; ?>
					<span class="lcft__title"><?php echo esc_html( LCFT_Settings::get( 'agent_name' ) ); ?></span>
					<button type="button" class="lcft__close" data-lcft-close
						aria-label="<?php esc_attr_e( 'Close', 'live-chat-for-telegram' ); ?>">&times;</button>
				</header>

				<div class="lcft__notice" data-lcft-notice hidden></div>
				<div class="lcft__log" data-lcft-log tabindex="0"></div>

				<div class="lcft__staged" data-lcft-staged hidden>
					<img class="lcft__staged-thumb" data-lcft-staged-thumb alt="" hidden />
					<span class="lcft__staged-name" data-lcft-staged-name></span>
					<button type="button" class="lcft__staged-remove" data-lcft-staged-remove
						aria-label="<?php esc_attr_e( 'Remove the file', 'live-chat-for-telegram' ); ?>">&times;</button>
				</div>

				<form class="lcft__composer" data-lcft-form>
					<label class="screen-reader-text" for="lcft-input"><?php esc_html_e( 'Your message', 'live-chat-for-telegram' ); ?></label>
					<textarea id="lcft-input" class="lcft__input" rows="1" data-lcft-input
						placeholder="<?php esc_attr_e( 'Write your message…', 'live-chat-for-telegram' ); ?>"></textarea>

					<?php if ( LCFT_Settings::get( 'emoji_enabled', true ) ) : ?>
						<div class="lcft__emoji-wrap">
							<button type="button" class="lcft__emoji" data-lcft-emoji-toggle
								aria-haspopup="true" aria-expanded="false"
								title="<?php esc_attr_e( 'Insert an emoji', 'live-chat-for-telegram' ); ?>">
								<span aria-hidden="true"><?php echo self::composer_icon( 'emoji' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Insert an emoji', 'live-chat-for-telegram' ); ?></span>
							</button>
							<div class="lcft__emoji-panel" data-lcft-emoji-panel hidden>
								<?php foreach ( self::emoji_set() as $emoji ) : ?>
									<button type="button" class="lcft__emoji-item" data-lcft-emoji="<?php echo esc_attr( $emoji ); ?>"><?php echo esc_html( $emoji ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( LCFT_Settings::get( 'attachments_enabled', true ) ) : ?>
						<label class="lcft__attach" title="<?php esc_attr_e( 'Attach a file', 'live-chat-for-telegram' ); ?>">
							<input type="file" data-lcft-file hidden />
							<span aria-hidden="true"><?php echo self::composer_icon( 'attach' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Attach a file', 'live-chat-for-telegram' ); ?></span>
						</label>
					<?php endif; ?>

					<button type="submit" class="lcft__send">
						<span aria-hidden="true"><?php echo self::composer_icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Send', 'live-chat-for-telegram' ); ?></span>
					</button>
				</form>
			</div>

			<button type="button" class="lcft__bubble" data-lcft-toggle
				aria-label="<?php esc_attr_e( 'Open the chat', 'live-chat-for-telegram' ); ?>">
				<span class="lcft__bubble-icon" aria-hidden="true"><?php echo self::chat_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
				<span class="lcft__badge" data-lcft-badge hidden>0</span>
			</button>
		</div>
		<?php
	}

	/**
	 * Prints the signed out variant: a button that leads to the login page.
	 *
	 * @since 0.1.0
	 *
	 * @param string $position The position class.
	 */
	protected static function render_invitation( $position ) {

		?>
		<div class="lcft lcft--invite <?php echo esc_attr( $position ); ?>">
			<a class="lcft__bubble" href="<?php echo esc_url( wp_login_url( self::current_url() ) ); ?>">
				<span class="lcft__bubble-icon" aria-hidden="true"><?php echo self::chat_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
				<span class="lcft__invite-label"><?php esc_html_e( 'Sign in to chat with us', 'live-chat-for-telegram' ); ?></span>
			</a>
		</div>
		<?php
	}

	/**
	 * Returns the URL of the current request, to return to after signing in.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function current_url() {

		global $wp;

		return home_url( add_query_arg( array(), $wp->request ) );
	}

	/**
	 * Returns the custom properties holding the member's and the support's initials.
	 *
	 * The widget draws no avatars itself, but a site may want a letter in a circle beside each
	 * message. CSS alone cannot know whose page it is on, so the letters are handed over as
	 * properties for a stylesheet to use, e.g. content: var( --lcft-member-initial ).
	 *
	 * The member's comes from their account rather than the Gravity Forms entry, which would cost
	 * a lookup on every page they visit.
	 *
	 * @since 1.1.6
	 *
	 * @return string
	 */
	protected static function initials_style() {

		$user   = wp_get_current_user();
		$member = self::initial( '' !== trim( $user->first_name ) ? $user->first_name : $user->display_name );
		$agent  = self::initial( (string) LCFT_Settings::get( 'agent_name', '' ) );

		return sprintf( '--lcft-member-initial:"%s";--lcft-agent-initial:"%s";', $member, $agent );
	}

	/**
	 * Returns the first letter or digit of a name, uppercased, or an empty string.
	 *
	 * Anything else is dropped, which also keeps the value safe inside a CSS string.
	 *
	 * @since 1.1.6
	 *
	 * @param string $name The name.
	 *
	 * @return string
	 */
	protected static function initial( $name ) {

		if ( ! preg_match( '/[\p{L}\p{N}]/u', (string) $name, $match ) ) {
			return '';
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $match[0], 'UTF-8' ) : strtoupper( $match[0] );
	}

	/**
	 * Returns the speech bubble icon shown on the chat button.
	 *
	 * An inline SVG rather than an emoji: an emoji is drawn by the visitor's system font, or swapped
	 * for an image by WordPress, so it never matches the theme. The SVG takes the text colour, and
	 * its three dots bounce briefly every 30 seconds, like someone typing.
	 *
	 * @since 1.1.2
	 *
	 * @return string
	 */
	public static function chat_icon() {

		return '<svg class="lcft__icon" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
			. '<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20.5l1.6-5.1A8.5 8.5 0 1 1 21 11.5z"/>'
			. '<circle class="lcft__dot" cx="8.5" cy="11.5" r="1" fill="currentColor" stroke="none"/>'
			. '<circle class="lcft__dot" cx="12.5" cy="11.5" r="1" fill="currentColor" stroke="none"/>'
			. '<circle class="lcft__dot" cx="16.5" cy="11.5" r="1" fill="currentColor" stroke="none"/>'
			. '</svg>';
	}

	/**
	 * Returns one of the composer's button icons.
	 *
	 * Inline SVG for the same reason as the chat button: emoji are drawn by the visitor's system
	 * and never match the theme. All three share one stroke style and take the button's colour.
	 *
	 * @since 1.1.5
	 *
	 * @param string $name emoji, attach or send.
	 *
	 * @return string
	 */
	public static function composer_icon( $name ) {

		$shapes = array(
			'emoji'  => '<circle cx="12" cy="12" r="9"/>'
				. '<path d="M8.5 14.5a4.5 4.5 0 0 0 7 0"/>'
				. '<circle cx="9" cy="9.75" r="1" fill="currentColor" stroke="none"/>'
				. '<circle cx="15" cy="9.75" r="1" fill="currentColor" stroke="none"/>',
			'attach' => '<path d="M20.5 11.5l-8.2 8.2a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4l7.8-7.8"/>',
			'send'   => '<path d="M21 3L10.5 13.5"/>'
				. '<path d="M21 3l-6.5 18-4-7.5L3 9.5z"/>',
		);

		if ( ! isset( $shapes[ $name ] ) ) {
			return '';
		}

		return '<svg class="lcft__tool-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
			. $shapes[ $name ]
			. '</svg>';
	}

	/**
	 * Returns the emoji offered by the composer's picker.
	 *
	 * A fixed, curated set rather than a full emoji keyboard: this is a support chat, not a
	 * messaging app, and a short list beats scrolling through thousands of symbols nobody sends to
	 * a union rep.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	protected static function emoji_set() {

		$emoji = array(
			'😀', '😁', '😂', '🤣', '😊', '😉', '😍', '😘', '😜', '🤔',
			'😐', '😴', '😢', '😭', '😡', '😱', '🥳', '😎', '🙄', '🥲',
			'👍', '👎', '👏', '🙏', '💪', '🤝', '👋', '✌️', '🤞', '👌',
			'❤️', '🔥', '🎉', '⭐', '✅', '❌', '❗', '❓', '💯', '🙌',
		);

		/**
		 * Filters the emoji offered by the chat composer's picker.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $emoji The emoji, as literal characters.
		 */
		return apply_filters( 'lcft_emoji_set', $emoji );
	}
}
