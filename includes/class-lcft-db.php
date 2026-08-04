<?php
/**
 * Schema installation and the private attachment directory.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_DB
 *
 * Owns the three custom tables and the directory uploaded files live in. Nothing here talks to
 * Telegram or to the front end; it only makes sure the storage exists and is shaped correctly.
 *
 * @since 0.1.0
 */
class LCFT_DB {

	/**
	 * The schema version.
	 *
	 * Bumped whenever a table definition changes, which is what triggers dbDelta on the next load.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1';

	/**
	 * The option storing the installed schema version.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'lcft_schema_version';

	/**
	 * The uploads subdirectory holding chat attachments.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const UPLOAD_DIR = 'lcft-private';

	/**
	 * Creates or updates the tables and the attachment directory.
	 *
	 * Safe to run repeatedly: dbDelta only applies the differences.
	 *
	 * @since 0.1.0
	 */
	public static function install() {

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// A conversation is a member. There is exactly one per user, reused forever, which is what
		// makes the Telegram topic and the member's own history line up without extra bookkeeping.
		$conversations = 'CREATE TABLE ' . self::conversations_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			chat_id bigint(20) DEFAULT NULL,
			topic_id bigint(20) DEFAULT NULL,
			entry_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			last_message_at datetime DEFAULT NULL,
			last_agent_message_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY topic (chat_id,topic_id),
			KEY status (status),
			KEY last_message_at (last_message_at)
		) $charset_collate;";

		// Messages are append only. The widget polls with "everything after id N", so the primary
		// key doubles as the cursor and no timestamp comparison is involved.
		$messages = 'CREATE TABLE ' . self::messages_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			direction varchar(3) NOT NULL,
			body longtext,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			tg_message_id bigint(20) DEFAULT NULL,
			tg_from_id bigint(20) DEFAULT NULL,
			tg_from_name varchar(255) DEFAULT NULL,
			media_group_id varchar(64) DEFAULT NULL,
			edited_at datetime DEFAULT NULL,
			read_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY cursor_lookup (conversation_id,id),
			KEY tg_message_id (tg_message_id),
			KEY unread (conversation_id,direction,read_at)
		) $charset_collate;";

		// Attachments are kept out of the media library on purpose: these are private support
		// files, and dropping them into Media would expose them to every editor on the site.
		$attachments = 'CREATE TABLE ' . self::attachments_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			kind varchar(20) NOT NULL,
			file_name varchar(255) NOT NULL,
			file_path varchar(255) NOT NULL,
			mime_type varchar(100) DEFAULT NULL,
			file_size bigint(20) unsigned DEFAULT NULL,
			width smallint(5) unsigned DEFAULT NULL,
			height smallint(5) unsigned DEFAULT NULL,
			duration int(10) unsigned DEFAULT NULL,
			tg_file_id varchar(128) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id)
		) $charset_collate;";

		dbDelta( $conversations );
		dbDelta( $messages );
		dbDelta( $attachments );

		self::protect_upload_dir();

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Returns the installed schema version, or an empty string when the plugin has never run.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_installed_version() {
		return (string) get_option( self::VERSION_OPTION, '' );
	}


	// # TABLE NAMES ---------------------------------------------------------------------------------------------------

	/**
	 * Returns the conversations table name, with the site's prefix.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function conversations_table() {
		global $wpdb;

		return $wpdb->prefix . 'lcft_conversations';
	}

	/**
	 * Returns the messages table name, with the site's prefix.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function messages_table() {
		global $wpdb;

		return $wpdb->prefix . 'lcft_messages';
	}

	/**
	 * Returns the attachments table name, with the site's prefix.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function attachments_table() {
		global $wpdb;

		return $wpdb->prefix . 'lcft_attachments';
	}


	// # ATTACHMENT STORAGE --------------------------------------------------------------------------------------------

	/**
	 * Returns the absolute path to the private attachment directory, without a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function upload_path() {

		$uploads = wp_get_upload_dir();

		return untrailingslashit( $uploads['basedir'] ) . '/' . self::UPLOAD_DIR;
	}

	/**
	 * Creates the attachment directory and blocks direct access to it.
	 *
	 * The .htaccess only helps on Apache. Under nginx nothing here is enforced, so the real
	 * protection is that file names are unguessable and every download goes through a REST route
	 * that checks ownership. Treat the rules below as defence in depth, not as the lock.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the directory exists and is writable.
	 */
	public static function protect_upload_dir() {

		$path = self::upload_path();

		if ( ! wp_mkdir_p( $path ) ) {
			return false;
		}

		$htaccess = $path . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Deny all direct access. Files are served through the plugin's REST route.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

			self::write_file( $htaccess, $rules );
		}

		$index = $path . '/index.php';

		if ( ! file_exists( $index ) ) {
			self::write_file( $index, "<?php\n// Silence is golden.\n" );
		}

		return wp_is_writable( $path );
	}

	/**
	 * Writes a file through WP_Filesystem, falling back to a direct write.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path     Absolute path to write to.
	 * @param string $contents The file contents.
	 *
	 * @return bool
	 */
	protected static function write_file( $path, $contents ) {

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( $wp_filesystem ) {
			return (bool) $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $path, $contents );
	}
}
