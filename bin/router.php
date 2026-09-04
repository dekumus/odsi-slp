<?php
/**
 * Router for PHP's built-in server so pretty permalinks work without Apache.
 *
 * Serves existing files directly and hands everything else to index.php.
 * Paths are checked lexically: any `..` segment is refused outright, so a
 * request can never name something outside the document root, while
 * symlinked plugin directories (the normal dev layout) still serve.
 *
 * @package ODSI
 */

declare( strict_types = 1 );

$odsi_root = rtrim( (string) $_SERVER['DOCUMENT_ROOT'], '/' );
$odsi_path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
$odsi_path = '/' . ltrim( rawurldecode( $odsi_path ), '/' );

if ( str_contains( $odsi_path, "\0" ) || in_array( '..', explode( '/', $odsi_path ), true ) ) {
	http_response_code( 400 );

	return true;
}

$odsi_file = $odsi_root . $odsi_path;

if ( '/' !== $odsi_path && is_file( $odsi_file ) ) {
	return false;
}

if ( is_dir( $odsi_file ) && is_file( rtrim( $odsi_file, '/' ) . '/index.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $odsi_path, '/' ) . '/index.php';
	require rtrim( $odsi_file, '/' ) . '/index.php';

	return true;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require $odsi_root . '/index.php';
