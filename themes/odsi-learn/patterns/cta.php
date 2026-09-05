<?php
/**
 * Title: Call to action
 * Slug: odsi-learn/cta
 * Categories: odsi-learn, call-to-action
 * Description: A surface band inviting visitors to sign up.
 *
 * @package odsi-learn
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"odsi-learn-cta","align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50","right":"var:preset|spacing|50"},"margin":{"top":"var:preset|spacing|70"}},"border":{"radius":"14px"}},"backgroundColor":"surface","layout":{"type":"constrained","contentSize":"640px"}} -->
<div class="wp-block-group odsi-learn-cta alignwide has-surface-background-color has-background" style="border-radius:14px;margin-top:var(--wp--preset--spacing--70);padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)">
	<!-- wp:heading {"level":2,"textAlign":"center"} -->
	<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e( 'Ready to start?', 'odsi-learn' ); ?></h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"align":"center","textColor":"muted"} -->
	<p class="has-text-align-center has-muted-color has-text-color"><?php esc_html_e( 'Create a free account to enroll, track your progress and join the conversation.', 'odsi-learn' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button -->
		<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Create an account', 'odsi-learn' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
