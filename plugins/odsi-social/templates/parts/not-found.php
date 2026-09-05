<?php
/**
 * Not found (ADR-011: "does not exist" and "may not see" read the same).
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="odsi-social-notice odsi-social-notice--not-found">
	<p class="odsi-social-notice__text"><?php esc_html_e( 'That page does not exist.', 'odsi-social' ); ?></p>
	<a class="odsi-social-button odsi-social-button--quiet odsi-social-notice__action" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Go to the home page', 'odsi-social' ); ?></a>
</div>
