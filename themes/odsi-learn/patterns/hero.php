<?php
/**
 * Title: Hero
 * Slug: odsi-learn/hero
 * Categories: odsi-learn, featured
 * Description: A welcome banner with two calls to action.
 *
 * @package odsi-learn
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$odsi_learn_courses = post_type_exists( 'odsi_course' ) ? (string) get_post_type_archive_link( 'odsi_course' ) : '';
$odsi_learn_members = (string) apply_filters( 'odsi_social_page_url', '', 'members' );
?>
<!-- wp:group {"className":"odsi-learn-hero","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained","contentSize":"760px"}} -->
<div class="wp-block-group odsi-learn-hero alignfull has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">
	<!-- wp:heading {"level":1,"textAlign":"center","textColor":"base","fontSize":"xx-large"} -->
	<h1 class="wp-block-heading has-text-align-center has-base-color has-text-color has-xx-large-font-size"><?php esc_html_e( 'Learn together, at your own pace', 'odsi-learn' ); ?></h1>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"align":"center","fontSize":"large"} -->
	<p class="has-text-align-center has-large-font-size"><?php esc_html_e( 'Structured courses with quizzes and certificates, and a community of learners to share the journey with.', 'odsi-learn' ); ?></p>
	<!-- /wp:paragraph -->
	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"backgroundColor":"accent","textColor":"base"} -->
		<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-accent-background-color has-text-color has-background wp-element-button" href="<?php echo esc_url( '' !== $odsi_learn_courses ? $odsi_learn_courses : home_url( '/' ) ); ?>"><?php esc_html_e( 'Browse courses', 'odsi-learn' ); ?></a></div>
		<!-- /wp:button -->
		<!-- wp:button {"className":"is-style-odsi-quiet"} -->
		<div class="wp-block-button is-style-odsi-quiet"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( '' !== $odsi_learn_members ? $odsi_learn_members : wp_registration_url() ); ?>"><?php esc_html_e( 'Meet the community', 'odsi-learn' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
