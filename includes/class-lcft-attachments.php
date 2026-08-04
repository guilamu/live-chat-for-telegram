<?php
/**
 * Private file storage for both directions of the chat.
 *
 * @package Live_Chat_For_Telegram
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LCFT_Attachments
 *
 * Files never enter the media library. These are private support attachments, and dropping them
 * into Media would list a member's documents for every editor on the site. They live in their own
 * directory under unguessable names and are only ever served through an ownership checked route.
 *
 * @since 0.1.0
 */
class LCFT_Attachments {

	/**
	 * Returns the MIME types a member is allowed to upload.
	 *
	 * Deliberately short. Anything executable, or anything the browser might be talked into
	 * running, is absent — and the list is what gets checked, not the file extension.
	 *
	 * @since 0.1.0
	 *
	 * @return array Extension pattern => MIME type, in the shape wp_check_filetype_and_ext expects.
	 */
	public static function allowed_mimes() {

		$mimes = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'heic'         => 'image/heic',
			'pdf'          => 'application/pdf',
			'txt'          => 'text/plain',
			'csv'          => 'text/csv',
			'odt'          => 'application/vnd.oasis.opendocument.text',
			'ods'          => 'application/vnd.oasis.opendocument.spreadsheet',
			'doc'          => 'application/msword',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'          => 'application/vnd.ms-excel',
			'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'          => 'application/vnd.ms-powerpoint',
			'pptx'         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		);

		/**
		 * Filters the file types a member may upload through the chat.
		 *
		 * @since 0.1.0
		 *
		 * @param array $mimes Extension pattern => MIME type.
		 */
		return apply_filters( 'lcft_allowed_mimes', $mimes );
	}

	/**
	 * Stores a file uploaded by a member.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $conversation_id The conversation the file belongs to.
	 * @param array $file            One entry from $_FILES.
	 *
	 * @return object|WP_Error The stored attachment row.
	 */
	public static function store_upload( $conversation_id, $file ) {

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_upload', esc_html__( 'No file was received.', 'live-chat-for-telegram' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_error', esc_html__( 'The file could not be uploaded.', 'live-chat-for-telegram' ) );
		}

		$max = (int) LCFT_Settings::get( 'max_upload_bytes', 10485760 );

		if ( (int) $file['size'] > $max ) {
			return new WP_Error(
				'too_large',
				sprintf(
					/* translators: %s: The maximum file size, already formatted. */
					esc_html__( 'That file is too large. The limit is %s.', 'live-chat-for-telegram' ),
					LCFT_Format::file_size( $max )
				)
			);
		}

		// The reported MIME type is attacker controlled, so the file's real type is what decides.
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::allowed_mimes() );

		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
			return new WP_Error( 'bad_type', esc_html__( 'That file type is not allowed.', 'live-chat-for-telegram' ) );
		}

		$destination = self::build_path( $checked['ext'] );

		if ( is_wp_error( $destination ) ) {
			return $destination;
		}

		// move_uploaded_file rather than a copy: it is the only call that verifies the source
		// really is an upload handled by this request.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
		if ( ! move_uploaded_file( $file['tmp_name'], $destination['absolute'] ) ) {
			return new WP_Error( 'move_failed', esc_html__( 'The file could not be saved.', 'live-chat-for-telegram' ) );
		}

		return self::insert(
			array(
				'conversation_id' => $conversation_id,
				'kind'            => self::kind_from_mime( $checked['type'] ),
				'file_name'       => sanitize_file_name( $file['name'] ),
				'file_path'       => $destination['relative'],
				'mime_type'       => $checked['type'],
				'file_size'       => (int) $file['size'],
			)
		);
	}

	/**
	 * Downloads a file an operator sent in Telegram and stores it.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $conversation_id The conversation.
	 * @param string $file_id         The Telegram file identifier.
	 * @param string $kind            photo, document, voice, video, video_note or audio.
	 * @param array  $meta            Optional file_name, mime_type, width, height and duration.
	 *
	 * @return object|WP_Error
	 */
	public static function store_from_telegram( $conversation_id, $file_id, $kind, $meta = array() ) {

		$api  = lcft_api();
		$file = $api->get_file( $file_id );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$size = isset( $file['file_size'] ) ? (int) $file['file_size'] : 0;

		// getFile refuses anything past 20 MB, so an operator can send the member a file they
		// cannot receive. Say so plainly rather than fail somewhere further down.
		if ( $size > LCFT_Telegram_API::MAX_DOWNLOAD_BYTES ) {
			return new WP_Error(
				'too_large_to_mirror',
				sprintf(
					/* translators: %s: The maximum size, already formatted. */
					esc_html__( 'Telegram will not hand back files over %s, so this one could not be delivered.', 'live-chat-for-telegram' ),
					LCFT_Format::file_size( LCFT_Telegram_API::MAX_DOWNLOAD_BYTES )
				)
			);
		}

		$remote_path = isset( $file['file_path'] ) ? (string) $file['file_path'] : '';
		$extension   = self::extension_for( $remote_path, $kind, isset( $meta['file_name'] ) ? $meta['file_name'] : '' );
		$destination = self::build_path( $extension );

		if ( is_wp_error( $destination ) ) {
			return $destination;
		}

		$downloaded = $api->download_file( $remote_path, $destination['absolute'] );

		if ( is_wp_error( $downloaded ) ) {
			return $downloaded;
		}

		$mime = isset( $meta['mime_type'] ) ? $meta['mime_type'] : '';

		if ( '' === $mime ) {
			$guess = wp_check_filetype( $destination['absolute'] );
			$mime  = ! empty( $guess['type'] ) ? $guess['type'] : 'application/octet-stream';
		}

		$name = isset( $meta['file_name'] ) && '' !== $meta['file_name']
			? sanitize_file_name( $meta['file_name'] )
			: basename( $destination['absolute'] );

		return self::insert(
			array(
				'conversation_id' => $conversation_id,
				'kind'            => $kind,
				'file_name'       => $name,
				'file_path'       => $destination['relative'],
				'mime_type'       => $mime,
				'file_size'       => $size ? $size : (int) filesize( $destination['absolute'] ),
				'width'           => isset( $meta['width'] ) ? (int) $meta['width'] : null,
				'height'          => isset( $meta['height'] ) ? (int) $meta['height'] : null,
				'duration'        => isset( $meta['duration'] ) ? (int) $meta['duration'] : null,
				'tg_file_id'      => $file_id,
			)
		);
	}

	/**
	 * Returns an attachment row.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id The attachment ID.
	 *
	 * @return object|null
	 */
	public static function get( $id ) {

		global $wpdb;

		$table = LCFT_DB::attachments_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Returns the absolute path of a stored attachment.
	 *
	 * @since 0.1.0
	 *
	 * @param object $attachment The attachment row.
	 *
	 * @return string
	 */
	public static function absolute_path( $attachment ) {
		return LCFT_DB::upload_path() . '/' . ltrim( $attachment->file_path, '/' );
	}

	/**
	 * Returns the URL the widget uses to fetch an attachment.
	 *
	 * The route is behind the same cookie-plus-nonce authentication as every other endpoint here,
	 * but it is loaded by the browser itself as an <img>/<audio>/<video> src or a plain download
	 * link — none of which can carry the X-WP-Nonce header a fetch() call would. WordPress's REST
	 * cookie authentication also accepts the nonce as a _wpnonce query argument for exactly this
	 * reason, so it is embedded here instead. It is generated for whichever user is viewing their
	 * own session when this is called, which is the only context this method is ever used from.
	 *
	 * @since 0.1.0
	 *
	 * @param object $attachment The attachment row.
	 *
	 * @return string
	 */
	public static function url( $attachment ) {
		return add_query_arg(
			'_wpnonce',
			wp_create_nonce( 'wp_rest' ),
			rest_url( LCFT_Settings::REST_NAMESPACE . '/file/' . (int) $attachment->id )
		);
	}

	/**
	 * Deletes an attachment and its file.
	 *
	 * @since 0.1.0
	 *
	 * @param object $attachment The attachment row.
	 */
	public static function delete( $attachment ) {

		global $wpdb;

		$path = self::absolute_path( $attachment );

		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			@unlink( $path );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( LCFT_DB::attachments_table(), array( 'id' => (int) $attachment->id ), array( '%d' ) );
	}

	/**
	 * Maps a MIME type to the way Telegram should carry it.
	 *
	 * Images go as photos so they preview inline in the group; everything else goes as a document
	 * so it survives intact.
	 *
	 * @since 0.1.0
	 *
	 * @param string $mime The MIME type.
	 *
	 * @return string
	 */
	public static function kind_from_mime( $mime ) {

		// HEIC is an image, but Telegram will not accept it as a photo and neither will most
		// browsers display it, so it travels as a document in both directions.
		if ( 0 === strpos( (string) $mime, 'image/' ) && 'image/heic' !== $mime ) {
			return 'photo';
		}

		return 'document';
	}


	// # INTERNALS -----------------------------------------------------------------------------------------------------

	/**
	 * Inserts an attachment row and returns it.
	 *
	 * @since 0.1.0
	 *
	 * @param array $values The column values.
	 *
	 * @return object|WP_Error
	 */
	protected static function insert( $values ) {

		global $wpdb;

		$values['created_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( LCFT_DB::attachments_table(), $values );

		if ( ! $inserted ) {
			return new WP_Error( 'insert_failed', esc_html__( 'The file could not be recorded.', 'live-chat-for-telegram' ) );
		}

		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Picks an unused path for a new file.
	 *
	 * The name carries no trace of the original: file names in a support chat leak their contents,
	 * and this directory may not be protected by anything but obscurity on nginx.
	 *
	 * @since 0.1.0
	 *
	 * @param string $extension The file extension, without a dot.
	 *
	 * @return array|WP_Error absolute and relative paths.
	 */
	protected static function build_path( $extension ) {

		$base = LCFT_DB::upload_path();

		// Year and month subdirectories: a busy site would otherwise end up with one directory
		// holding tens of thousands of files, which some filesystems handle poorly.
		$subdir = gmdate( 'Y/m' );
		$dir    = $base . '/' . $subdir;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mkdir_failed', esc_html__( 'The upload directory could not be created.', 'live-chat-for-telegram' ) );
		}

		$extension = preg_replace( '/[^a-z0-9]/i', '', (string) $extension );
		$extension = '' === $extension ? 'bin' : strtolower( $extension );

		do {
			$name = wp_generate_password( 32, false, false ) . '.' . $extension;
		} while ( file_exists( $dir . '/' . $name ) );

		return array(
			'absolute' => $dir . '/' . $name,
			'relative' => $subdir . '/' . $name,
		);
	}

	/**
	 * Works out the extension to save a Telegram file under.
	 *
	 * @since 0.1.0
	 *
	 * @param string $remote_path The file_path returned by getFile.
	 * @param string $kind        The kind of file.
	 * @param string $file_name   The original name, when Telegram provided one.
	 *
	 * @return string
	 */
	protected static function extension_for( $remote_path, $kind, $file_name = '' ) {

		foreach ( array( $file_name, $remote_path ) as $candidate ) {

			$extension = pathinfo( (string) $candidate, PATHINFO_EXTENSION );

			if ( $extension ) {
				return $extension;
			}
		}

		$fallbacks = array(
			'photo'      => 'jpg',
			'voice'      => 'oga',
			'audio'      => 'mp3',
			'video'      => 'mp4',
			'video_note' => 'mp4',
		);

		return isset( $fallbacks[ $kind ] ) ? $fallbacks[ $kind ] : 'bin';
	}
}
