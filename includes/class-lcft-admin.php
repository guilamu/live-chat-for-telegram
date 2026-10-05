<?php
/**
 * The settings screen.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Admin
 *
 * @since 0.1.0
 */
class LCFT_Admin {

	/**
	 * The capability required to see or change anything here.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The settings page slug.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const SLUG = 'lcft-settings';

	/**
	 * Hooks the screen up.
	 *
	 * @since 0.1.0
	 */
	public static function init() {

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_lcft_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'wp_ajax_lcft_diagnose', array( __CLASS__, 'ajax_diagnose' ) );
		add_action( 'wp_ajax_lcft_register_webhook', array( __CLASS__, 'ajax_register_webhook' ) );
		add_action( 'wp_ajax_lcft_remove_webhook', array( __CLASS__, 'ajax_remove_webhook' ) );
		add_action( 'wp_ajax_lcft_test_message', array( __CLASS__, 'ajax_test_message' ) );
		add_action( 'wp_ajax_lcft_detect_group', array( __CLASS__, 'ajax_detect_group' ) );
	}

	/**
	 * Adds the menu entry.
	 *
	 * @since 0.1.0
	 */
	public static function add_menu() {

		add_menu_page(
			__( 'Live Chat', 'live-chat-for-telegram' ),
			__( 'Live Chat', 'live-chat-for-telegram' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-format-chat',
			58
		);
	}

	/**
	 * Loads the screen's assets, and nothing anywhere else.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook The current admin page.
	 */
	public static function enqueue( $hook ) {

		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style( 'lcft-admin', LCFT_URL . 'assets/css/admin.css', array(), LCFT_VERSION );
		wp_enqueue_script( 'lcft-admin', LCFT_URL . 'assets/js/admin.js', array( 'media-editor' ), LCFT_VERSION, true );

		wp_localize_script(
			'lcft-admin',
			'lcftAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lcft_admin' ),
				'i18n'    => array(
					'working'     => __( 'Working…', 'live-chat-for-telegram' ),
					'failed'      => __( 'The request failed.', 'live-chat-for-telegram' ),
					'chooseImage' => __( 'Choose an avatar', 'live-chat-for-telegram' ),
					'useImage'    => __( 'Use this image', 'live-chat-for-telegram' ),
				),
			)
		);
	}

	/**
	 * Returns the tabs, in order.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	protected static function tabs() {

		return array(
			'connection' => __( 'Connection', 'live-chat-for-telegram' ),
			'widget'     => __( 'Widget', 'live-chat-for-telegram' ),
			'hours'      => __( 'Hours', 'live-chat-for-telegram' ),
			'members'    => __( 'Member details', 'live-chat-for-telegram' ),
			'advanced'   => __( 'Advanced', 'live-chat-for-telegram' ),
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @since 0.1.0
	 */
	public static function render() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'live-chat-for-telegram' ) );
		}

		$tabs = self::tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which tab to draw.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection';

		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'connection';
		}

		echo '<div class="wrap lcft-settings">';
		echo '<h1>' . esc_html__( 'Live Chat for Telegram', 'live-chat-for-telegram' ) . '</h1>';

		// Tells WordPress where to park admin notices. Without it, core moves them after the first
		// heading it finds, which on a tabbed screen can be the tab bar itself.
		echo '<hr class="wp-header-end" />';

		if ( ! LCFT_Settings::is_configured() ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Add a bot token and a group before the chat can run.', 'live-chat-for-telegram' )
				. '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Settings saved.', 'live-chat-for-telegram' )
				. '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$stripped = isset( $_GET['stripped'] ) ? array_filter( array_map( 'sanitize_key', explode( ',', wp_unslash( $_GET['stripped'] ) ) ) ) : array();

		if ( $stripped ) {
			echo '<div class="notice notice-warning"><p>'
				. sprintf(
					/* translators: 1: The tags removed from the card template. 2: The tags Telegram supports. */
					esc_html__( 'Telegram does not support these HTML tags, so they were removed from the card template: %1$s. Use only: %2$s.', 'live-chat-for-telegram' ),
					'<code>' . esc_html( implode( ', ', $stripped ) ) . '</code>',
					esc_html( implode( ', ', array_keys( self::telegram_html() ) ) )
				)
				. '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ),
				esc_attr( $slug === $current ? 'nav-tab-active' : '' ),
				esc_html( $label )
			);
		}

		echo '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lcft_save', 'lcft_nonce' );
		echo '<input type="hidden" name="action" value="lcft_save" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $current ) . '" />';

		$method = 'render_' . $current;
		self::$method();

		submit_button();

		echo '</form>';
		echo '</div>';
	}


	// # TABS ----------------------------------------------------------------------------------------------------------

	/**
	 * The connection tab.
	 *
	 * @since 0.1.0
	 */
	protected static function render_connection() {

		echo '<table class="form-table" role="presentation"><tbody>';

		if ( LCFT_Settings::token_is_constant() ) {
			self::row(
				__( 'Bot token', 'live-chat-for-telegram' ),
				'<p><code>LCFT_BOT_TOKEN</code> ' . esc_html__( 'is defined in wp-config.php, so the token is not stored in the database.', 'live-chat-for-telegram' ) . '</p>'
			);
		} else {

			// The field itself is always blank, by design: a stored bearer credential must never
			// be written into the page. That leaves nothing but a greyed-out placeholder to say a
			// token is already saved, which is easy to miss and reads as "empty and broken" even
			// while the connection underneath is working — so the state gets its own visible line
			// instead of resting on the placeholder alone.
			$has_token = '' !== LCFT_Settings::get_bot_token();

			self::row(
				__( 'Bot token', 'live-chat-for-telegram' ),
				self::password_field( 'bot_token' )
				. '<p class="lcft-token-status">'
				. ( $has_token
					? '✔ ' . esc_html__( 'A token is saved. The field stays empty for security — leave it blank to keep it, or type a new one to replace it.', 'live-chat-for-telegram' )
					: '— ' . esc_html__( 'No token saved yet.', 'live-chat-for-telegram' ) )
				. '</p>',
				sprintf(
					/* translators: %s: A link to BotFather. */
					__( 'Create a bot with %s and paste its token. Use a bot dedicated to this site: a bot can only deliver updates to one place.', 'live-chat-for-telegram' ),
					'<a href="https://t.me/botfather" target="_blank" rel="noopener">@BotFather</a>'
				)
			);
		}

		self::row(
			__( 'Group ID', 'live-chat-for-telegram' ),
			self::text_field( 'chat_id', 'regular-text' ),
			__( 'The supergroup where conversations appear, for example <code>-1001234567890</code>. You can paste a message link from the group instead and the ID will be worked out. Topics must be enabled, and the bot must be an administrator able to manage them.', 'live-chat-for-telegram' )
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Status', 'live-chat-for-telegram' ) . '</h2>';
		echo '<p>' . esc_html__( 'Checks run in the order a message travels, so the first failure is the one to fix.', 'live-chat-for-telegram' ) . '</p>';

		echo '<p class="lcft-actions">';
		echo '<button type="button" class="button" data-lcft-action="diagnose">' . esc_html__( 'Run checks', 'live-chat-for-telegram' ) . '</button> ';
		echo '<button type="button" class="button" data-lcft-action="register_webhook">' . esc_html__( 'Register webhook', 'live-chat-for-telegram' ) . '</button> ';
		echo '<button type="button" class="button" data-lcft-action="detect_group">' . esc_html__( 'Detect group', 'live-chat-for-telegram' ) . '</button> ';
		echo '<button type="button" class="button" data-lcft-action="remove_webhook">' . esc_html__( 'Remove webhook', 'live-chat-for-telegram' ) . '</button> ';
		echo '<button type="button" class="button" data-lcft-action="test_message">' . esc_html__( 'Send a test message', 'live-chat-for-telegram' ) . '</button>';
		echo '</p>';

		echo '<div id="lcft-diagnostics" class="lcft-diagnostics"></div>';

		echo '<p class="description">'
			. esc_html__( 'Telegram delivers updates to this address:', 'live-chat-for-telegram' )
			. ' <code>' . esc_html( LCFT_Settings::get_webhook_url() ) . '</code></p>';
	}

	/**
	 * The widget tab.
	 *
	 * @since 0.1.0
	 */
	protected static function render_widget() {

		echo '<table class="form-table" role="presentation"><tbody>';

		self::row(
			__( 'Show the bubble', 'live-chat-for-telegram' ),
			self::checkbox_field( 'widget_enabled', __( 'Display the chat bubble on the site', 'live-chat-for-telegram' ) )
		);

		self::row(
			__( 'Support name', 'live-chat-for-telegram' ),
			self::text_field( 'agent_name' ),
			__( 'Shown to the member on every reply. A single name for the whole team is intentional: they are talking to a support desk, not to whichever phone answered.', 'live-chat-for-telegram' )
		);

		self::row( __( 'Avatar URL', 'live-chat-for-telegram' ), self::avatar_field() );

		self::row(
			__( 'Welcome message', 'live-chat-for-telegram' ),
			self::with_placeholders( self::textarea_field( 'welcome_message', 3 ), 'welcome_message' ),
			__( 'Shown above the conversation when the member has never written.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Position', 'live-chat-for-telegram' ),
			self::select_field(
				'widget_position',
				array(
					'right' => __( 'Bottom right', 'live-chat-for-telegram' ),
					'left'  => __( 'Bottom left', 'live-chat-for-telegram' ),
				)
			)
		);

		self::row(
			__( 'Accent colour', 'live-chat-for-telegram' ),
			self::checkbox_field( 'widget_theme_accent', __( "Use the theme's main colour when it defines one (Divi 5, block themes)", 'live-chat-for-telegram' ) )
				. '<br />' . self::color_field( 'widget_accent' ),
			__( 'The colour picked here is used otherwise.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Open automatically', 'live-chat-for-telegram' ),
			self::number_field( 'auto_open_delay', 0, 300 ) . ' ' . esc_html__( 'seconds', 'live-chat-for-telegram' ),
			__( 'Zero leaves the bubble closed until the member clicks it.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Signed out visitors', 'live-chat-for-telegram' ),
			self::select_field(
				'logged_out_mode',
				array(
					'hide'   => __( 'Show nothing', 'live-chat-for-telegram' ),
					'invite' => __( 'Show the bubble, inviting them to sign in', 'live-chat-for-telegram' ),
				)
			),
			__( 'The chat itself is for signed in members only. That is what keeps spam out: there is no anonymous way in.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Limit to roles', 'live-chat-for-telegram' ),
			self::roles_field(),
			__( 'Leave everything unticked to allow every signed in user.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Custom CSS', 'live-chat-for-telegram' ),
			self::textarea_field( 'custom_css', 10 ),
			sprintf(
				/* translators: %s: The list of CSS custom properties. */
				__( 'Loaded after the widget stylesheet, wherever the bubble appears. Set these properties on .lcft to restyle it: %s.', 'live-chat-for-telegram' ),
				'--lcft-accent, --lcft-accent-hover, --lcft-surface, --lcft-text, --lcft-muted, --lcft-border, --lcft-them, --lcft-radius, --lcft-control-radius, --lcft-shadow, --lcft-font, --lcft-heading-font'
			)
		);

		echo '</tbody></table>';
	}

	/**
	 * The hours tab.
	 *
	 * @since 0.1.0
	 */
	protected static function render_hours() {

		echo '<table class="form-table" role="presentation"><tbody>';

		self::row(
			__( 'Opening hours', 'live-chat-for-telegram' ),
			self::checkbox_field( 'schedule_enabled', __( 'Tell members when the desk is staffed', 'live-chat-for-telegram' ) ),
			__( 'Messages are always accepted. Outside these hours the member is told when to expect an answer rather than being turned away.', 'live-chat-for-telegram' )
		);

		$schedule = (array) LCFT_Settings::get( 'schedule', LCFT_Settings::default_schedule() );
		$rows     = '';

		// Start the week on the site's chosen first day rather than always on Sunday.
		$start = (int) get_option( 'start_of_week', 1 );

		for ( $i = 0; $i < 7; $i++ ) {

			$day  = ( $start + $i ) % 7;
			$data = isset( $schedule[ $day ] ) ? (array) $schedule[ $day ] : array();

			$rows .= '<tr><th scope="row">' . esc_html( self::weekday_name( $day ) ) . '</th><td>';
			$rows .= '<label><input type="checkbox" name="schedule[' . $day . '][enabled]" value="1" '
				. checked( ! empty( $data['enabled'] ), true, false ) . ' /> '
				. esc_html__( 'Open', 'live-chat-for-telegram' ) . '</label> ';
			$rows .= '<input type="time" name="schedule[' . $day . '][open]" value="'
				. esc_attr( isset( $data['open'] ) ? $data['open'] : '09:00' ) . '" /> ';
			$rows .= '<span>' . esc_html__( 'to', 'live-chat-for-telegram' ) . '</span> ';
			$rows .= '<input type="time" name="schedule[' . $day . '][close]" value="'
				. esc_attr( isset( $data['close'] ) ? $data['close'] : '18:00' ) . '" />';
			$rows .= '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<table class="form-table lcft-schedule" role="presentation"><tbody>' . wp_kses( $rows, self::allowed_html() ) . '</tbody></table>';

		echo '<table class="form-table" role="presentation"><tbody>';

		self::row(
			__( 'Exceptions', 'live-chat-for-telegram' ),
			self::textarea_field( 'schedule_exceptions', 5, self::format_exceptions() ),
			__( 'One per line: <code>2026-08-15 = closed</code>, or <code>2026-08-15 = 09:00-12:00</code> for a short day. Exceptions override the weekly pattern.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Closed message', 'live-chat-for-telegram' ),
			self::with_placeholders( self::textarea_field( 'closed_message', 2 ), 'closed_message' ),
			__( 'Leave empty to announce the next opening automatically.', 'live-chat-for-telegram' )
		);

		echo '</tbody></table>';
	}

	/**
	 * The member details tab.
	 *
	 * @since 0.1.0
	 */
	protected static function render_members() {

		echo '<p>' . esc_html__( 'Each member gets one Telegram topic, named from their details, with a card pinned at the top. Gravity Forms is optional: without it the details come from the WordPress account alone.', 'live-chat-for-telegram' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		self::row(
			__( 'Gravity Forms form', 'live-chat-for-telegram' ),
			self::number_field( 'gf_form_id', 0, 999999 ),
			class_exists( 'GFAPI' )
				? __( 'The form members register with. Zero disables the lookup.', 'live-chat-for-telegram' )
				: __( 'Gravity Forms is not active, so this is ignored.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Field map', 'live-chat-for-telegram' ),
			self::with_gf_fields( self::textarea_field( 'gf_field_map', 8, self::format_field_map() ), 'gf_field_map' ),
			__( 'One per line, <code>name = field ID</code>. Each name becomes a <code>{placeholder}</code> below. <code>user_id</code> and <code>email</code> are also used to match a member to their entry.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Topic title', 'live-chat-for-telegram' ),
			self::with_placeholders( self::text_field( 'topic_title_template', 'large-text' ), 'topic_title_template' ),
			__( 'Placeholders that resolve to nothing are removed, along with the punctuation left around them.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Pinned card', 'live-chat-for-telegram' ),
			self::with_placeholders( self::textarea_field( 'card_template', 6 ), 'card_template' ),
			__( 'Telegram HTML: <code>&lt;b&gt;</code>, <code>&lt;i&gt;</code>, <code>&lt;code&gt;</code>, <code>&lt;a&gt;</code>. Leave empty for a default card built from the account. Whatever you put here is sent to Telegram, so only include what the team needs.', 'live-chat-for-telegram' )
		);

		echo '</tbody></table>';
	}

	/**
	 * The advanced tab.
	 *
	 * @since 0.1.0
	 */
	protected static function render_advanced() {

		echo '<table class="form-table" role="presentation"><tbody>';

		self::row(
			__( 'Attachments', 'live-chat-for-telegram' ),
			self::checkbox_field( 'attachments_enabled', __( 'Let members send files', 'live-chat-for-telegram' ) )
		);

		self::row(
			__( 'Emoji', 'live-chat-for-telegram' ),
			self::checkbox_field( 'emoji_enabled', __( 'Let members insert emoji from the composer', 'live-chat-for-telegram' ) )
		);

		self::row(
			__( 'Maximum upload', 'live-chat-for-telegram' ),
			self::number_field( 'max_upload_bytes', 0, LCFT_Telegram_API::MAX_DOCUMENT_BYTES, 'lcft-number-wide' ) . ' ' . esc_html__( 'bytes', 'live-chat-for-telegram' ),
			sprintf(
				/* translators: %s: The download limit, already formatted. */
				__( 'Telegram accepts up to 50 MB, but only hands files back under %s — so a reply carrying a larger file cannot reach the member.', 'live-chat-for-telegram' ),
				LCFT_Format::file_size( LCFT_Telegram_API::MAX_DOWNLOAD_BYTES )
			)
		);

		self::row(
			__( 'Polling interval', 'live-chat-for-telegram' ),
			self::number_field( 'poll_interval', 1000, 60000, 'lcft-number-wide' ) . ' ' . esc_html__( 'milliseconds', 'live-chat-for-telegram' ),
			__( 'How often an open bubble checks for a reply. The widget slows down on its own when the tab is in the background.', 'live-chat-for-telegram' )
		);

		self::row(
			__( 'Keep messages for', 'live-chat-for-telegram' ),
			self::number_field( 'retention_days', 0, 3650, 'lcft-number-wide' ) . ' ' . esc_html__( 'days', 'live-chat-for-telegram' ),
			__( 'Zero keeps everything. Anything else deletes older messages and their files daily. The Telegram side is never touched.', 'live-chat-for-telegram' )
		);

		echo '</tbody></table>';
	}


	// # SAVING --------------------------------------------------------------------------------------------------------

	/**
	 * Validates and stores the submitted tab.
	 *
	 * @since 0.1.0
	 */
	public static function save() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'live-chat-for-telegram' ) );
		}

		check_admin_referer( 'lcft_save', 'lcft_nonce' );

		$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$values        = array();
		$stripped_tags = array();

		switch ( $tab ) {

			case 'connection':
				if ( ! LCFT_Settings::token_is_constant() ) {

					$submitted = isset( $_POST['bot_token'] )
						? trim( sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) )
						: '';

					// The field is rendered empty, so an empty submission means "unchanged".
					// Clearing the token is deliberate and has its own checkbox.
					if ( ! empty( $_POST['bot_token_clear'] ) ) {
						$values['bot_token'] = '';
					} elseif ( '' !== $submitted ) {
						$values['bot_token'] = $submitted;
					}
				}

				$chat_id           = isset( $_POST['chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_id'] ) ) : '';
				$values['chat_id'] = self::normalize_chat_id( $chat_id );
				break;

			case 'widget':
				$values['widget_enabled']   = ! empty( $_POST['widget_enabled'] );
				$values['agent_name']       = isset( $_POST['agent_name'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_name'] ) ) : '';
				$values['agent_avatar_url'] = isset( $_POST['agent_avatar_url'] ) ? esc_url_raw( wp_unslash( $_POST['agent_avatar_url'] ) ) : '';
				$values['welcome_message']  = isset( $_POST['welcome_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['welcome_message'] ) ) : '';
				$values['widget_position']  = isset( $_POST['widget_position'] ) && 'left' === $_POST['widget_position'] ? 'left' : 'right';
				$values['widget_accent']    = isset( $_POST['widget_accent'] ) ? sanitize_hex_color( wp_unslash( $_POST['widget_accent'] ) ) : '#1c3f94';
				$values['widget_theme_accent'] = ! empty( $_POST['widget_theme_accent'] );

				// Tags are stripped so the CSS can never close the <style> element it is printed in.
				$values['custom_css'] = isset( $_POST['custom_css'] ) ? trim( wp_strip_all_tags( wp_unslash( $_POST['custom_css'] ) ) ) : '';
				$values['auto_open_delay']  = isset( $_POST['auto_open_delay'] ) ? min( 300, absint( $_POST['auto_open_delay'] ) ) : 0;
				$values['logged_out_mode']  = isset( $_POST['logged_out_mode'] ) && 'invite' === $_POST['logged_out_mode'] ? 'invite' : 'hide';

				$roles                   = isset( $_POST['allowed_roles'] ) ? (array) wp_unslash( $_POST['allowed_roles'] ) : array();
				$values['allowed_roles'] = array_values( array_intersect( array_map( 'sanitize_key', $roles ), array_keys( wp_roles()->roles ) ) );
				break;

			case 'hours':
				$values['schedule_enabled']    = ! empty( $_POST['schedule_enabled'] );
				$values['schedule']            = self::sanitize_schedule( isset( $_POST['schedule'] ) ? (array) wp_unslash( $_POST['schedule'] ) : array() );
				$values['schedule_exceptions'] = self::parse_exceptions( isset( $_POST['schedule_exceptions'] ) ? (string) wp_unslash( $_POST['schedule_exceptions'] ) : '' );
				$values['closed_message']      = isset( $_POST['closed_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['closed_message'] ) ) : '';
				break;

			case 'members':
				$values['gf_form_id']           = isset( $_POST['gf_form_id'] ) ? absint( $_POST['gf_form_id'] ) : 0;
				$values['gf_field_map']         = self::parse_field_map( isset( $_POST['gf_field_map'] ) ? (string) wp_unslash( $_POST['gf_field_map'] ) : '' );
				$values['topic_title_template'] = isset( $_POST['topic_title_template'] ) ? sanitize_text_field( wp_unslash( $_POST['topic_title_template'] ) ) : '';

				// The card is Telegram HTML, so the tags Telegram understands have to survive. The
				// others are stripped, since one of them would make every card fail to post, but the
				// admin is told which ones went rather than finding their formatting gone.
				$card_template           = isset( $_POST['card_template'] ) ? trim( (string) wp_unslash( $_POST['card_template'] ) ) : '';
				$stripped_tags           = LCFT_Format::get_disallowed_tags( $card_template );
				$values['card_template'] = wp_kses( $card_template, self::telegram_html() );

				// The form or the map changing invalidates every cached entry lookup.
				self::flush_entry_cache();
				break;

			case 'advanced':
				$values['attachments_enabled'] = ! empty( $_POST['attachments_enabled'] );
				$values['emoji_enabled']       = ! empty( $_POST['emoji_enabled'] );
				$values['max_upload_bytes']    = isset( $_POST['max_upload_bytes'] )
					? min( LCFT_Telegram_API::MAX_DOCUMENT_BYTES, absint( $_POST['max_upload_bytes'] ) )
					: 10485760;
				$values['poll_interval']       = isset( $_POST['poll_interval'] )
					? max( 1000, min( 60000, absint( $_POST['poll_interval'] ) ) )
					: 3000;
				$values['retention_days']      = isset( $_POST['retention_days'] ) ? min( 3650, absint( $_POST['retention_days'] ) ) : 0;
				break;
		}

		if ( $values ) {
			LCFT_Settings::update( $values );
		}

		$query = array(
			'page'  => self::SLUG,
			'tab'   => $tab,
			'saved' => 1,
		);

		if ( ! empty( $stripped_tags ) ) {
			$query['stripped'] = implode( ',', $stripped_tags );
		}

		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );

		exit;
	}

	/**
	 * Turns whatever was pasted into a supergroup ID the Bot API will accept.
	 *
	 * The number people have to hand is rarely the one Telegram wants. A message link reads
	 * t.me/c/4413298170/2, and the ID for that group is -1004413298170 — the same digits with a
	 * prefix nothing in the interface mentions. Asking for it verbatim only produces "chat not
	 * found", so the prefix is added here instead.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The submitted value.
	 *
	 * @return string The chat ID, or an empty string when it cannot be one.
	 */
	public static function normalize_chat_id( $value ) {

		// Tolerate a pasted message link rather than only the number inside it.
		if ( preg_match( '#t\.me/c/(\d+)#', (string) $value, $matches ) ) {
			return '-100' . $matches[1];
		}

		$value = preg_replace( '/[^\d-]/', '', (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// Already in the right shape.
		if ( 0 === strpos( $value, '-100' ) ) {
			return $value;
		}

		// A negative number without the prefix is a basic group, which cannot hold topics. It will
		// be reported as unusable by the checks rather than silently rewritten into a valid ID.
		if ( 0 === strpos( $value, '-' ) ) {
			return $value;
		}

		return '-100' . $value;
	}

	/**
	 * Forgets every cached Gravity Forms entry lookup.
	 *
	 * @since 0.1.0
	 */
	protected static function flush_entry_cache() {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => LCFT_Member::ENTRY_META_KEY ) );
	}


	// # AJAX ----------------------------------------------------------------------------------------------------------

	/**
	 * Refuses anyone who should not be pressing these buttons.
	 *
	 * @since 0.1.0
	 */
	protected static function guard_ajax() {

		check_ajax_referer( 'lcft_admin', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'live-chat-for-telegram' ) ), 403 );
		}
	}

	/**
	 * Runs the connection checks.
	 *
	 * @since 0.1.0
	 */
	public static function ajax_diagnose() {

		self::guard_ajax();

		wp_send_json_success( array( 'checks' => LCFT_Diagnostics::run() ) );
	}

	/**
	 * Points the bot's webhook at this site.
	 *
	 * @since 0.1.0
	 */
	public static function ajax_register_webhook() {

		self::guard_ajax();

		$result = lcft_api()->set_webhook( LCFT_Settings::get_webhook_url(), LCFT_Settings::get_webhook_secret() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Webhook registered.', 'live-chat-for-telegram' ) ) );
	}

	/**
	 * Removes the webhook.
	 *
	 * @since 0.1.0
	 */
	public static function ajax_remove_webhook() {

		self::guard_ajax();

		$result = lcft_api()->delete_webhook();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Webhook removed. Replies will no longer reach the site.', 'live-chat-for-telegram' ) ) );
	}

	/**
	 * Offers the group the webhook last saw a message from.
	 *
	 * @since 0.1.0
	 */
	public static function ajax_detect_group() {

		self::guard_ajax();

		$seen = get_transient( LCFT_Webhook::SEEN_CHAT_KEY );

		if ( ! $seen || empty( $seen['id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Nothing received yet. Register the webhook, post any message in the group, then try again.', 'live-chat-for-telegram' ),
				)
			);
		}

		if ( empty( $seen['is_forum'] ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: The group name. 2: The group ID. */
						__( 'Found "%1$s" (%2$s), but Topics are turned off in it. Turn them on, then detect again — converting a group changes its ID.', 'live-chat-for-telegram' ),
						$seen['title'],
						$seen['id']
					),
				)
			);
		}

		LCFT_Settings::update( array( 'chat_id' => $seen['id'] ) );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: The group name. 2: The group ID. */
					__( 'Saved "%1$s" (%2$s). Reload this page to see it in the field.', 'live-chat-for-telegram' ),
					$seen['title'],
					$seen['id']
				),
			)
		);
	}

	/**
	 * Posts a message into the group, outside any topic.
	 *
	 * @since 0.1.0
	 */
	public static function ajax_test_message() {

		self::guard_ajax();

		$result = lcft_api()->send_message(
			array(
				'chat_id' => LCFT_Settings::get_chat_id(),
				'text'    => sprintf(
					/* translators: %s: The site name. */
					__( 'Test message from %s. If you can read this, the site can reach the group.', 'live-chat-for-telegram' ),
					get_bloginfo( 'name' )
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Sent. Check the group.', 'live-chat-for-telegram' ) ) );
	}


	// # FIELD HELPERS -------------------------------------------------------------------------------------------------

	/**
	 * Prints one settings row.
	 *
	 * @since 0.1.0
	 *
	 * @param string $label       The field label.
	 * @param string $control     The control's HTML.
	 * @param string $description An optional hint, which may contain inline markup.
	 */
	protected static function row( $label, $control, $description = '' ) {

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo wp_kses( $control, self::allowed_html() );

		if ( $description ) {
			echo '<p class="description">' . wp_kses( $description, self::allowed_html() ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Returns a text input.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The setting name.
	 * @param string $class The CSS class.
	 *
	 * @return string
	 */
	protected static function text_field( $key, $class = 'regular-text' ) {

		return sprintf(
			'<input type="text" name="%1$s" id="%1$s" value="%2$s" class="%3$s" />',
			esc_attr( $key ),
			esc_attr( (string) LCFT_Settings::get( $key, '' ) ),
			esc_attr( $class )
		);
	}

	/**
	 * Returns a secret input which never writes the stored value into the page.
	 *
	 * A bot token is a bearer credential: anything that can read it can post as the bot. Rendering
	 * it into the markup would leak it to every browser extension on an administrator's machine,
	 * and into any screenshot of this screen. The field stays empty, and an empty submission is
	 * read as "leave it alone" rather than as "clear it".
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The setting name.
	 *
	 * @return string
	 */
	protected static function password_field( $key ) {

		$stored = (string) LCFT_Settings::get( $key, '' );

		$html = sprintf(
			'<input type="password" name="%1$s" id="%1$s" value="" class="regular-text" autocomplete="off" placeholder="%2$s" />',
			esc_attr( $key ),
			esc_attr(
				'' === $stored
					? __( 'Paste the token', 'live-chat-for-telegram' )
					: __( 'Stored — type a new one to replace it', 'live-chat-for-telegram' )
			)
		);

		if ( '' !== $stored ) {
			$html .= sprintf(
				' <label class="lcft-clear"><input type="checkbox" name="%s_clear" value="1" /> %s</label>',
				esc_attr( $key ),
				esc_html__( 'Clear', 'live-chat-for-telegram' )
			);
		}

		return $html;
	}

	/**
	 * Returns a textarea.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $key   The setting name.
	 * @param int         $rows  The number of rows.
	 * @param string|null $value An explicit value, when the stored one is not a plain string.
	 *
	 * @return string
	 */
	protected static function textarea_field( $key, $rows = 3, $value = null ) {

		if ( null === $value ) {
			$value = (string) LCFT_Settings::get( $key, '' );
		}

		return sprintf(
			'<textarea name="%1$s" id="%1$s" rows="%2$d" class="large-text code">%3$s</textarea>',
			esc_attr( $key ),
			(int) $rows,
			esc_textarea( $value )
		);
	}

	/**
	 * Returns a checkbox with its label.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The setting name.
	 * @param string $label The label text.
	 *
	 * @return string
	 */
	protected static function checkbox_field( $key, $label ) {

		return sprintf(
			'<label><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( $key ),
			checked( (bool) LCFT_Settings::get( $key, false ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Returns a number input.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The setting name.
	 * @param int    $min   The lowest allowed value.
	 * @param int    $max   The highest allowed value.
	 * @param string $class The CSS class. Widen this for settings whose value can run to several
	 *                      digits, so the whole number stays visible rather than scrolling inside
	 *                      the field.
	 *
	 * @return string
	 */
	protected static function number_field( $key, $min = 0, $max = 100, $class = 'small-text' ) {

		return sprintf(
			'<input type="number" name="%1$s" id="%1$s" value="%2$d" min="%3$d" max="%4$d" class="%5$s" />',
			esc_attr( $key ),
			(int) LCFT_Settings::get( $key, 0 ),
			(int) $min,
			(int) $max,
			esc_attr( $class )
		);
	}

	/**
	 * Returns the avatar URL field: the text input plus a preview and a Media Library picker.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function avatar_field() {

		$url = (string) LCFT_Settings::get( 'agent_avatar_url', '' );

		// Matches core's own "Add Media" button (icon + label), rather than a custom style: the
		// dashicon it uses is loaded on every admin screen, while the classic editor's own
		// .wp-media-buttons-icon rule is only enqueued on post editing screens.
		return '<div class="lcft-media-picker">'
			. self::text_field( 'agent_avatar_url', 'regular-text' )
			. ' <button type="button" class="button" data-lcft-media-picker="agent_avatar_url">'
			. '<span class="dashicons dashicons-admin-media"></span> '
			. esc_html__( 'Choose from Media Library', 'live-chat-for-telegram' )
			. '</button>'
			. '<img src="' . esc_url( $url ) . '" alt="" id="agent_avatar_url_preview" class="lcft-media-picker__preview"'
			. ( '' === $url ? ' hidden' : '' ) . ' />'
			. '</div>';
	}

	/**
	 * Returns the names available for use as a <code>{placeholder}</code>.
	 *
	 * The fixed set always comes from the WordPress account. The Gravity Forms names on top of it
	 * are only offered once a form is actually configured — offering them regardless would suggest
	 * placeholders that can never resolve to anything, since the lookup itself is skipped while
	 * `gf_form_id` is zero.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	protected static function available_placeholders() {

		$tokens = array( 'first_name', 'last_name', 'display_name', 'user_login', 'user_email', 'user_id' );

		if ( (int) LCFT_Settings::get( 'gf_form_id', 0 ) > 0 ) {
			$tokens = array_merge( $tokens, array_keys( (array) LCFT_Settings::get( 'gf_field_map', array() ) ) );
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Wraps a field with a merge-tag style placeholder picker: a small icon button sitting in the
	 * field's own corner, in the style of Gravity Forms' "Insert Merge Tag" control, rather than a
	 * separate button taking up a row of its own.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field_html The field's own HTML, as returned by text_field() or
	 *                           textarea_field().
	 * @param string $target_id  The id of the field the picker inserts into.
	 *
	 * @return string
	 */
	protected static function with_placeholders( $field_html, $target_id ) {

		$tokens = self::available_placeholders();

		if ( ! $tokens ) {
			return $field_html;
		}

		$html = '<span class="lcft-mt">' . $field_html
			. '<button type="button" class="lcft-mt__toggle" data-lcft-mt-toggle="' . esc_attr( $target_id ) . '" '
			. 'aria-haspopup="true" aria-expanded="false" title="' . esc_attr__( 'Insert placeholder', 'live-chat-for-telegram' ) . '">'
			. '<span class="dashicons dashicons-editor-code"></span>'
			. '</button>'
			. '<div class="lcft-mt__panel" data-lcft-mt-panel="' . esc_attr( $target_id ) . '" hidden>'
			. '<input type="text" class="lcft-mt__search" data-lcft-mt-search placeholder="' . esc_attr__( 'Search placeholders…', 'live-chat-for-telegram' ) . '" />'
			. '<div class="lcft-mt__list">';

		foreach ( $tokens as $token ) {
			$html .= sprintf(
				'<button type="button" class="lcft-mt__item" data-lcft-placeholder="%1$s" data-lcft-insert-target="%2$s">{%1$s}</button>',
				esc_attr( $token ),
				esc_attr( $target_id )
			);
		}

		$html .= '</div></div></span>';

		return $html;
	}

	/**
	 * Returns the fields of the currently configured Gravity Forms form, for the field map browser.
	 *
	 * Structural elements (pages, sections, HTML blocks, the CAPTCHA) are skipped: they never hold a
	 * value worth mapping.
	 *
	 * @since 0.1.0
	 *
	 * @return array[] Each with id, label and a suggested placeholder name.
	 */
	protected static function available_gf_fields() {

		$form_id = (int) LCFT_Settings::get( 'gf_form_id', 0 );

		if ( ! $form_id || ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$form = GFAPI::get_form( $form_id );

		if ( ! is_array( $form ) || empty( $form['fields'] ) ) {
			return array();
		}

		$skip   = array( 'page', 'section', 'html', 'captcha' );
		$fields = array();

		foreach ( $form['fields'] as $field ) {

			if ( empty( $field->id ) || in_array( $field->type, $skip, true ) ) {
				continue;
			}

			$label = '' !== trim( (string) $field->label )
				? $field->label
				/* translators: %d: The field ID, used when a field has no label. */
				: sprintf( __( 'Field #%d', 'live-chat-for-telegram' ), (int) $field->id );

			$fields[] = array(
				'id'    => (string) $field->id,
				'label' => $label,
				'slug'  => self::slugify_label( $label ),
			);
		}

		return $fields;
	}

	/**
	 * Turns a Gravity Forms field label into a usable placeholder name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $label The field label.
	 *
	 * @return string
	 */
	protected static function slugify_label( $label ) {

		$slug = str_replace( '-', '_', sanitize_title( $label ) );
		$slug = preg_replace( '/[^a-z0-9_]/', '', $slug );

		return '' !== $slug ? $slug : 'field';
	}

	/**
	 * Wraps the field map textarea with a browser listing the current form's actual fields, so
	 * their IDs never have to be looked up by hand in the form editor.
	 *
	 * Clicking a field appends a ready-made <code>name = id</code> line, using a name derived from
	 * the field's label — editable afterwards like any other line.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field_html The field map textarea's own HTML.
	 * @param string $target_id  Its element id.
	 *
	 * @return string
	 */
	protected static function with_gf_fields( $field_html, $target_id ) {

		$fields = self::available_gf_fields();

		if ( ! $fields ) {
			return $field_html;
		}

		$html = '<span class="lcft-mt">' . $field_html
			. '<button type="button" class="lcft-mt__toggle" data-lcft-mt-toggle="' . esc_attr( $target_id ) . '" '
			. 'aria-haspopup="true" aria-expanded="false" title="' . esc_attr__( 'Browse form fields', 'live-chat-for-telegram' ) . '">'
			. '<span class="dashicons dashicons-list-view"></span>'
			. '</button>'
			. '<div class="lcft-mt__panel" data-lcft-mt-panel="' . esc_attr( $target_id ) . '" hidden>'
			. '<input type="text" class="lcft-mt__search" data-lcft-mt-search placeholder="' . esc_attr__( 'Search fields…', 'live-chat-for-telegram' ) . '" />'
			. '<div class="lcft-mt__list">';

		foreach ( $fields as $field ) {

			$line = $field['slug'] . ' = ' . $field['id'];

			$html .= sprintf(
				'<button type="button" class="lcft-mt__item" data-lcft-fieldmap-line="%1$s" data-lcft-search="%2$s">'
				. '<span class="lcft-mt__item-label">%3$s</span><span class="lcft-mt__item-id">#%4$s</span></button>',
				esc_attr( $line ),
				esc_attr( strtolower( $field['label'] . ' ' . $field['id'] ) ),
				esc_html( $field['label'] ),
				esc_html( $field['id'] )
			);
		}

		$html .= '</div></div></span>';

		return $html;
	}

	/**
	 * Returns a colour input.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The setting name.
	 *
	 * @return string
	 */
	protected static function color_field( $key ) {

		return sprintf(
			'<input type="color" name="%1$s" id="%1$s" value="%2$s" />',
			esc_attr( $key ),
			esc_attr( (string) LCFT_Settings::get( $key, '#1c3f94' ) )
		);
	}

	/**
	 * Returns a select.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key     The setting name.
	 * @param array  $choices Value => label.
	 *
	 * @return string
	 */
	protected static function select_field( $key, $choices ) {

		$current = (string) LCFT_Settings::get( $key, '' );
		$html    = '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '">';

		foreach ( $choices as $value => $label ) {
			$html .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		return $html . '</select>';
	}

	/**
	 * Returns a checkbox per role.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function roles_field() {

		$allowed = (array) LCFT_Settings::get( 'allowed_roles', array() );
		$html    = '';

		foreach ( wp_roles()->roles as $slug => $role ) {
			$html .= sprintf(
				'<label class="lcft-role"><input type="checkbox" name="allowed_roles[]" value="%s" %s /> %s</label>',
				esc_attr( $slug ),
				checked( in_array( $slug, $allowed, true ), true, false ),
				esc_html( translate_user_role( $role['name'] ) )
			);
		}

		return $html;
	}

	/**
	 * Returns the day name for a weekday number.
	 *
	 * @since 0.1.0
	 *
	 * @param int $day 0 for Sunday through 6 for Saturday.
	 *
	 * @return string
	 */
	protected static function weekday_name( $day ) {

		global $wp_locale;

		return $wp_locale ? $wp_locale->get_weekday( $day ) : (string) $day;
	}


	// # TEXT FORMATS --------------------------------------------------------------------------------------------------

	/**
	 * Renders the field map as editable text.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function format_field_map() {

		$lines = array();

		foreach ( (array) LCFT_Settings::get( 'gf_field_map', array() ) as $name => $field_id ) {
			$lines[] = $name . ' = ' . $field_id;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Reads the field map back out of the textarea.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The submitted text.
	 *
	 * @return array
	 */
	protected static function parse_field_map( $text ) {

		$map = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {

			if ( ! strpos( $line, '=' ) ) {
				continue;
			}

			list( $name, $field_id ) = array_map( 'trim', explode( '=', $line, 2 ) );

			$name = sanitize_key( $name );

			// Field IDs may be "11" or "65.3", so this is not simply an integer.
			if ( '' === $name || ! preg_match( '/^\d+(\.\d+)?$/', $field_id ) ) {
				continue;
			}

			$map[ $name ] = $field_id;
		}

		return $map;
	}

	/**
	 * Renders the schedule exceptions as editable text.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected static function format_exceptions() {

		$lines = array();

		foreach ( (array) LCFT_Settings::get( 'schedule_exceptions', array() ) as $date => $rule ) {

			$rule = (array) $rule;

			$lines[] = empty( $rule['enabled'] )
				? $date . ' = closed'
				: $date . ' = ' . $rule['open'] . '-' . $rule['close'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * Reads the schedule exceptions back out of the textarea.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The submitted text.
	 *
	 * @return array
	 */
	protected static function parse_exceptions( $text ) {

		$exceptions = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {

			if ( ! strpos( $line, '=' ) ) {
				continue;
			}

			list( $date, $rule ) = array_map( 'trim', explode( '=', $line, 2 ) );

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}

			if ( preg_match( '/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $rule, $matches ) ) {
				$exceptions[ $date ] = array(
					'enabled' => true,
					'open'    => $matches[1],
					'close'   => $matches[2],
				);

				continue;
			}

			$exceptions[ $date ] = array( 'enabled' => false );
		}

		return $exceptions;
	}

	/**
	 * Validates the weekly schedule.
	 *
	 * @since 0.1.0
	 *
	 * @param array $submitted The submitted schedule.
	 *
	 * @return array
	 */
	protected static function sanitize_schedule( $submitted ) {

		$schedule = LCFT_Settings::default_schedule();

		foreach ( $schedule as $day => $defaults ) {

			$data = isset( $submitted[ $day ] ) ? (array) $submitted[ $day ] : array();

			$schedule[ $day ] = array(
				'enabled' => ! empty( $data['enabled'] ),
				'open'    => self::sanitize_time( isset( $data['open'] ) ? $data['open'] : '', $defaults['open'] ),
				'close'   => self::sanitize_time( isset( $data['close'] ) ? $data['close'] : '', $defaults['close'] ),
			);
		}

		return $schedule;
	}

	/**
	 * Validates a 24 hour time.
	 *
	 * @since 0.1.0
	 *
	 * @param string $time     The submitted value.
	 * @param string $fallback Used when the value is not a time.
	 *
	 * @return string
	 */
	protected static function sanitize_time( $time, $fallback ) {

		$time = trim( (string) $time );

		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : $fallback;
	}

	/**
	 * Returns the tags allowed in the pinned card.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	protected static function telegram_html() {
		return LCFT_Format::telegram_html();
	}

	/**
	 * Returns the tags allowed in the labels and hints on this screen.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	protected static function allowed_html() {

		return array(
			'input'    => array(
				'type'         => array(),
				'name'         => array(),
				'id'           => array(),
				'value'        => array(),
				'class'        => array(),
				'min'          => array(),
				'max'          => array(),
				'checked'      => array(),
				'autocomplete' => array(),
				'placeholder'  => array(),
				'data-lcft-mt-search' => array(),
			),
			'select'   => array(
				'name' => array(),
				'id'   => array(),
			),
			'option'   => array(
				'value'    => array(),
				'selected' => array(),
			),
			'textarea' => array(
				'name'  => array(),
				'id'    => array(),
				'rows'  => array(),
				'class' => array(),
			),
			'label'    => array( 'class' => array() ),
			'span'     => array( 'class' => array() ),
			'code'     => array(),
			'a'        => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'p'        => array( 'class' => array() ),
			'tr'       => array(),
			'th'       => array( 'scope' => array() ),
			'td'       => array(),
			'br'       => array(),
			'div'      => array(
				'class'             => array(),
				'id'                => array(),
				'hidden'            => array(),
				'data-lcft-mt-panel' => array(),
			),
			'button'   => array(
				'type'                    => array(),
				'class'                   => array(),
				'title'                   => array(),
				'data-lcft-media-picker'  => array(),
				'data-lcft-placeholder'   => array(),
				'data-lcft-insert-target' => array(),
				'data-lcft-mt-toggle'     => array(),
				'data-lcft-fieldmap-line' => array(),
				'data-lcft-search'        => array(),
				'aria-haspopup'           => array(),
				'aria-expanded'           => array(),
			),
			'img'      => array(
				'src'    => array(),
				'alt'    => array(),
				'id'     => array(),
				'class'  => array(),
				'width'  => array(),
				'height' => array(),
				'hidden' => array(),
			),
		);
	}
}
