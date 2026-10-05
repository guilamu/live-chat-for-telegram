<?php
/**
 * Plugin Name: Live Chat for Telegram
 * Plugin URI: https://github.com/guilamu/live-chat-for-telegram
 * Description: A live chat bubble for logged in users, answered from a Telegram group. Each member gets their own forum topic, so replies come from any phone without a dedicated app.
 * Version: 1.1.3
 * Author: Guilamu
 * Author URI: https://github.com/guilamu
 * Update URI: https://github.com/guilamu/live-chat-for-telegram/
 * Text Domain: live-chat-for-telegram
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 *
 * This plugin is not affiliated with, endorsed by, or sponsored by Telegram FZ-LLC.
 *
 * ------------------------------------------------------------------------
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 * GNU Affero General Public License as published by the Free Software Foundation, either version 3
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCFT_VERSION', '1.1.3' );
define( 'LCFT_PLUGIN_FILE', __FILE__ );
define( 'LCFT_PATH', plugin_dir_path( __FILE__ ) );
define( 'LCFT_URL', plugin_dir_url( __FILE__ ) );
define( 'LCFT_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The option holding every plugin setting.
 *
 * A single option rather than one per setting: the widget bootstrap reads most of them on every
 * front end request, and one autoloaded row is cheaper than a dozen.
 *
 * @since 0.1.0
 *
 * @var string
 */
define( 'LCFT_OPTION_KEY', 'lcft_settings' );

// GitHub auto-updates. Loaded first and unconditionally so updates keep working even if the
// plugin later refuses to boot on an unsupported environment.
require_once LCFT_PATH . 'includes/class-lcft-github-updater.php';

require_once LCFT_PATH . 'includes/class-lcft-db.php';
require_once LCFT_PATH . 'includes/class-lcft-settings.php';
require_once LCFT_PATH . 'includes/class-lcft-format.php';
require_once LCFT_PATH . 'includes/class-lcft-telegram-api.php';
require_once LCFT_PATH . 'includes/class-lcft-member.php';
require_once LCFT_PATH . 'includes/class-lcft-conversations.php';
require_once LCFT_PATH . 'includes/class-lcft-attachments.php';
require_once LCFT_PATH . 'includes/class-lcft-schedule.php';
require_once LCFT_PATH . 'includes/class-lcft-chat.php';
require_once LCFT_PATH . 'includes/class-lcft-webhook.php';
require_once LCFT_PATH . 'includes/class-lcft-rest.php';
require_once LCFT_PATH . 'includes/class-lcft-widget.php';

LCFT_Widget::init();

if ( is_admin() ) {
	require_once LCFT_PATH . 'includes/class-lcft-diagnostics.php';
	require_once LCFT_PATH . 'includes/class-lcft-admin.php';

	LCFT_Admin::init();
}

register_activation_hook( __FILE__, array( 'LCFT_DB', 'install' ) );
register_deactivation_hook( __FILE__, 'lcft_deactivate' );

add_action( 'plugins_loaded', 'lcft_maybe_upgrade' );
add_action( 'rest_api_init', array( 'LCFT_Webhook', 'register_routes' ) );
add_action( 'rest_api_init', array( 'LCFT_REST', 'register_routes' ) );

// Priority 20: runs after most "REST API for logged in users only" plugins, which hook this same
// filter at the default priority of 10, so their WP_Error can be overridden for this plugin's own
// routes without touching their code.
add_filter( 'rest_authentication_errors', array( 'LCFT_Webhook', 'allow_own_routes_through_rest_lockdown' ), 20 );
add_action( 'lcft_purge_expired', array( 'LCFT_Conversations', 'purge' ) );
add_action( 'init', 'lcft_schedule_purge' );
add_action( 'init', 'lcft_load_textdomain' );
add_filter( 'plugin_row_meta', 'lcft_plugin_row_meta', 10, 2 );

/**
 * Returns the Telegram API client, configured from the stored settings.
 *
 * The token is read from the LCFT_BOT_TOKEN constant when it is defined, so a site can keep it out
 * of the database entirely.
 *
 * @since 0.1.0
 *
 * @return LCFT_Telegram_API
 */
function lcft_api() {

	static $api = null;

	if ( null !== $api ) {
		return $api;
	}

	/**
	 * Filters the Bot API base URL.
	 *
	 * Lets a site route requests through a self hosted Bot API server or a proxy, for networks
	 * where api.telegram.org is unreachable.
	 *
	 * @since 0.1.0
	 *
	 * @param string $base_url The API base URL.
	 */
	$base_url = apply_filters( 'lcft_api_base_url', LCFT_Telegram_API::DEFAULT_BASE_URL );

	$api = new LCFT_Telegram_API( LCFT_Settings::get_bot_token(), $base_url );

	return $api;
}

/**
 * Runs the installer when the stored schema version is behind the current one.
 *
 * Activation alone is not enough: a plugin updated in place is never reactivated, so the check has
 * to happen on load.
 *
 * @since 0.1.0
 */
function lcft_maybe_upgrade() {

	if ( LCFT_DB::get_installed_version() === LCFT_DB::SCHEMA_VERSION ) {
		return;
	}

	LCFT_DB::install();
}

/**
 * Keeps the daily purge scheduled only while a retention period is set.
 *
 * Scheduling it unconditionally would leave a job running on every site that keeps its history,
 * doing nothing but waking WordPress up once a day.
 *
 * @since 0.1.0
 */
function lcft_schedule_purge() {

	$wanted    = (int) LCFT_Settings::get( 'retention_days', 0 ) > 0;
	$scheduled = (bool) wp_next_scheduled( 'lcft_purge_expired' );

	if ( $wanted && ! $scheduled ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lcft_purge_expired' );
	} elseif ( ! $wanted && $scheduled ) {
		wp_clear_scheduled_hook( 'lcft_purge_expired' );
	}
}

/**
 * Clears anything scheduled by the plugin.
 *
 * The webhook registration is deliberately left alone: deactivating to debug a problem should not
 * silently sever the connection, and re-registering is one click on the settings page.
 *
 * @since 0.1.0
 */
function lcft_deactivate() {
	wp_clear_scheduled_hook( 'lcft_purge_expired' );
}

/**
 * Loads the plugin translations.
 *
 * @since 0.1.0
 */
function lcft_load_textdomain() {
	load_plugin_textdomain(
		'live-chat-for-telegram',
		false,
		dirname( LCFT_BASENAME ) . '/languages'
	);
}

/**
 * Adds the View details and Report a Bug links to the plugin's row on the Plugins screen.
 *
 * @since 0.1.0
 *
 * @param array  $links The current row meta links.
 * @param string $file  The plugin file the row belongs to.
 *
 * @return array
 */
function lcft_plugin_row_meta( $links, $file ) {

	if ( LCFT_BASENAME !== $file ) {
		return $links;
	}

	// "View details" thickbox link — same pattern as WordPress.org-hosted plugins.
	$links[] = sprintf(
		'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
		esc_url(
			self_admin_url(
				'plugin-install.php?tab=plugin-information&plugin=live-chat-for-telegram'
				. '&TB_iframe=true&width=772&height=926'
			)
		),
		esc_attr__( 'More information about Live Chat for Telegram', 'live-chat-for-telegram' ),
		esc_attr__( 'Live Chat for Telegram', 'live-chat-for-telegram' ),
		esc_html__( 'View details', 'live-chat-for-telegram' )
	);

	// Bugs are reported as GitHub issues on the plugin's own repository.
	$links[] = sprintf(
		'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
		esc_url( 'https://github.com/guilamu/live-chat-for-telegram/issues/new' ),
		esc_html__( '🐛 Report a Bug', 'live-chat-for-telegram' )
	);

	return $links;
}
