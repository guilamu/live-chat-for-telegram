<?php
/**
 * Removes everything the plugin created.
 *
 * Only runs when the plugin is deleted, never on deactivation. Conversations, attachments and
 * settings all go. The Telegram side is untouched: the topics and their history stay in the group,
 * which is usually where the copy that matters lives anyway.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Options.
delete_option( 'lcft_settings' );
delete_option( 'lcft_schema_version' );

// The cached Gravity Forms entry lookup, one row per member who ever opened the chat.
delete_metadata( 'user', 0, '_lcft_entry_id', '', true );

// Scheduled work.
wp_clear_scheduled_hook( 'lcft_purge_expired' );

// Attachments. The directory is removed with its contents, so the year and month subdirectories
// have to go first.
$uploads = wp_get_upload_dir();
$private = untrailingslashit( $uploads['basedir'] ) . '/lcft-private';

if ( is_dir( $private ) ) {

	require_once ABSPATH . 'wp-admin/includes/file.php';

	global $wp_filesystem;

	if ( ! $wp_filesystem ) {
		WP_Filesystem();
	}

	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $private, true );
	}
}

// Tables.
foreach ( array( 'lcft_messages', 'lcft_attachments', 'lcft_conversations' ) as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table );
}
