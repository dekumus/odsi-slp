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
