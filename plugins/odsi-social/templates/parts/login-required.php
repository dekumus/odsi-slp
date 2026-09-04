<?php
/**
 * Logged-out notice.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="odsi-social-notice">
	<a href="<?php echo esc_url( wp_login_url( (string) ( $_SERVER['REQUEST_URI'] ?? home_url() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput ?>">
		<?php esc_html_e( 'Log in to see this page.', 'odsi-social' ); ?>
	</a>
</p>
