<?php
/**
 * Image uploads for avatars and covers.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Members;

use ODSI\Social\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a browser upload into an attachment that is an image of an allowed
 * type, no larger than the admin's maximum, owned by the member (SOC-MEM-003).
 */
final class Uploads {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Allowed extensions => mime types, from the `avatar_types` setting.
	 *
	 * @return array<string, string>
	 */
	public function allowed_mimes(): array {
		$known = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		);
		$types = array_map( 'strtolower', array_map( 'strval', (array) $this->settings->get( 'avatar_types' ) ) );
		$mimes = array();

		foreach ( $types as $ext ) {
			if ( isset( $known[ $ext ] ) ) {
				$mimes[ $ext ] = $known[ $ext ];
			}
		}

		/**
		 * Filters the image types a member may upload as an avatar or cover.
		 *
		 * @param array<string, string> $mimes Extension => mime type.
		 */
		return (array) apply_filters( 'odsi_social_image_mime_types', $mimes ?: array( 'jpg' => 'image/jpeg' ) );
	}

	/**
	 * Whether a member may use an attachment as their image: they uploaded
	 * it, or they can edit it (SOC-MEM-004a).
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $user_id       Member.
	 */
	public static function owned_by( int $attachment_id, int $user_id ): bool {
		if ( $attachment_id <= 0 || $user_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}

		return (int) get_post_field( 'post_author', $attachment_id ) === $user_id || user_can( $user_id, 'edit_post', $attachment_id );
	}

	/**
	 * Delete an image this plugin stored once it is no longer referenced.
	 *
	 * @param int $attachment_id Attachment, or 0.
	 */
	public static function reclaim( int $attachment_id ): void {
		if ( $attachment_id > 0 && '' !== (string) get_post_meta( $attachment_id, '_odsi_social_image', true ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Store an uploaded image.
	 *
	 * @param int                  $owner_id Member who owns the file.
	 * @param array<string, mixed> $file     One `$_FILES` entry.
	 * @param string               $kind     `avatar` or `cover`, kept as meta.
	 * @param int                  $parent_id Post to attach to (a group), or 0.
	 *
	 * @return int|WP_Error Attachment id.
	 */
	public function store( int $owner_id, array $file, string $kind, int $parent_id = 0 ): int|WP_Error {
		if ( empty( $file['name'] ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'odsi_social_no_file', __( 'Choose an image first.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		if ( (int) ( $file['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'odsi_social_upload_failed', __( 'The upload did not complete.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		/**
		 * Filters the maximum image upload size in bytes.
		 *
		 * @param int $bytes Bytes; 5 MB by default, never above the site limit.
		 */
		$max_bytes = min( (int) apply_filters( 'odsi_social_image_max_bytes', 5 * MB_IN_BYTES ), wp_max_upload_size() );

		if ( (int) ( $file['size'] ?? 0 ) > $max_bytes ) {
			return new WP_Error(
				'odsi_social_upload_too_large',
				/* translators: %s: size. */
				sprintf( __( 'Images must be smaller than %s.', 'odsi-social' ), size_format( $max_bytes ) ),
				array( 'status' => 400 )
			);
		}

		// Check the real type from the file's bytes before touching it as an
		// image, then read only the header: a tiny file can declare enormous
		// dimensions and exhaust memory in the image editor.
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], $this->allowed_mimes() );

		if ( empty( $check['type'] ) || ! str_starts_with( (string) $check['type'], 'image/' ) ) {
			return new WP_Error( 'odsi_social_upload_failed', __( 'Sorry, this file type is not permitted for security reasons.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		$declared = wp_getimagesize( (string) $file['tmp_name'] );

		if ( ! is_array( $declared ) || (int) $declared[0] > 8192 || (int) $declared[1] > 8192 ) {
			return new WP_Error( 'odsi_social_not_an_image', __( 'That file is not an image we can use.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		$limited = \ODSI\Social\Support\RateLimiter::check( 'image_upload', $owner_id );

		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		/**
		 * Filters the WordPress upload handler used for member images.
		 *
		 * `wp_handle_upload` insists on a real browser upload; a test harness
		 * that stages files itself can switch to `wp_handle_sideload`.
		 *
		 * @param string $handler Function name.
		 */
		$handler  = (string) apply_filters( 'odsi_social_upload_handler', 'wp_handle_upload' );
		$handler  = in_array( $handler, array( 'wp_handle_upload', 'wp_handle_sideload' ), true ) ? $handler : 'wp_handle_upload';
		$uploaded = $handler(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $this->allowed_mimes(),
			)
		);

		if ( isset( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
			$message = isset( $uploaded['error'] ) && is_string( $uploaded['error'] ) ? $uploaded['error'] : __( 'The image could not be uploaded.', 'odsi-social' );

			return new WP_Error( 'odsi_social_upload_failed', $message, array( 'status' => 400 ) );
		}

		$path = (string) $uploaded['file'];

		if ( ! str_starts_with( (string) $uploaded['type'], 'image/' ) || false === wp_getimagesize( $path ) ) {
			wp_delete_file( $path );

			return new WP_Error( 'odsi_social_not_an_image', __( 'That file is not an image.', 'odsi-social' ), array( 'status' => 400 ) );
		}

		$this->constrain( $path );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $uploaded['type'],
				'post_title'     => sanitize_text_field( wp_basename( $path ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => $owner_id,
			),
			$path,
			$parent_id,
			true
		);

		if ( $attachment_id instanceof WP_Error ) {
			$attachment_id->add_data( array( 'status' => 500 ) );

			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_odsi_social_image', sanitize_key( $kind ) );
		wp_update_attachment_metadata( $attachment_id, (array) wp_generate_attachment_metadata( $attachment_id, $path ) );

		return $attachment_id;
	}

	/**
	 * Shrink an image in place so neither side exceeds `avatar_max_px`.
	 *
	 * @param string $path File.
	 */
	private function constrain( string $path ): void {
		$max  = max( 64, $this->settings->int( 'avatar_max_px' ) );
		$size = wp_getimagesize( $path );

		if ( ! is_array( $size ) || ( (int) $size[0] <= $max && (int) $size[1] <= $max ) ) {
			return;
		}

		$editor = wp_get_image_editor( $path );

		if ( $editor instanceof WP_Error ) {
			return;
		}

		$editor->resize( $max, $max, false );
		$editor->save( $path );
	}
}
