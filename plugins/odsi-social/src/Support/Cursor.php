<?php
/**
 * Opaque pagination cursors.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes a (timestamp, id) position so that pages never repeat or skip rows
 * when new ones arrive (SOC-ACT-034).
 */
final class Cursor {

	/**
	 * Encode a position.
	 *
	 * @param string $timestamp MySQL datetime.
	 * @param int    $id        Row id.
	 */
	public static function encode( string $timestamp, int $id ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of a cursor, not obfuscation.
		return rtrim( strtr( base64_encode( $timestamp . '|' . $id ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode a position.
	 *
	 * @param string $cursor Encoded cursor.
	 *
	 * @return array{timestamp: string, id: int}|null Null for an empty or malformed cursor.
	 */
	public static function decode( string $cursor ): ?array {
		if ( '' === $cursor ) {
			return null;
		}

		$decoded = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $decoded || ! str_contains( $decoded, '|' ) ) {
			return null;
		}

		[ $timestamp, $id ] = explode( '|', $decoded, 2 );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp ) || ! ctype_digit( $id ) ) {
			return null;
		}

		return array(
			'timestamp' => $timestamp,
			'id'        => (int) $id,
		);
	}
}
