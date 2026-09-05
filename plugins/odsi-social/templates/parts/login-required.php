<?php
/**
 * Logged-out notice with the way in.
 *
 * @var string $message Optional message; the default says the page needs an account.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$message  = isset( $message ) && '' !== (string) $message ? (string) $message : __( 'Log in to see this page.', 'odsi-social' );
$odsi_uri = esc_url_raw( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised inline.
?>
<div class="odsi-social-notice odsi-social-notice--login">
	<p class="odsi-social-notice__text"><?php echo esc_html( $message ); ?></p>
	<a class="odsi-social-button odsi-social-notice__action" href="<?php echo esc_url( wp_login_url( '' !== $odsi_uri ? home_url( $odsi_uri ) : home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log in', 'odsi-social' ); ?></a>
</div>
