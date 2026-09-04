<?php
/**
 * Logged-out notice.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="odsi-lms-login-required">
	<a href="<?php echo esc_url( wp_login_url( (string) get_permalink() ) ); ?>">
		<?php esc_html_e( 'Log in to see your courses.', 'odsi-lms' ); ?>
	</a>
</p>
