<?php
/**
 * Title: Latest courses
 * Slug: odsi-learn/courses
 * Categories: odsi-learn
 * Description: A heading, a short introduction and the course grid.
 *
 * @package odsi-learn
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$odsi_learn_courses = post_type_exists( 'odsi_course' ) ? (string) get_post_type_archive_link( 'odsi_course' ) : '';
?>
<!-- wp:group {"className":"odsi-learn-section","align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group odsi-learn-section alignwide" style="margin-top:var(--wp--preset--spacing--70)">
	<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between","verticalAlignment":"bottom","flexWrap":"wrap"}} -->
	<div class="wp-block-group">
		<!-- wp:heading {"level":2} -->
		<h2 class="wp-block-heading"><?php esc_html_e( 'Courses', 'odsi-learn' ); ?></h2>
		<!-- /wp:heading -->
		<?php if ( '' !== $odsi_learn_courses ) : ?>
		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><a href="<?php echo esc_url( $odsi_learn_courses ); ?>"><?php esc_html_e( 'See all courses', 'odsi-learn' ); ?></a></p>
		<!-- /wp:paragraph -->
		<?php endif; ?>
	</div>
	<!-- /wp:group -->
	<?php if ( '' !== $odsi_learn_courses ) : ?>
	<!-- wp:odsi-lms/course-grid {"perPage":6,"align":"wide"} /-->
	<?php else : ?>
	<!-- wp:paragraph {"textColor":"muted"} -->
	<p class="has-muted-color has-text-color"><?php esc_html_e( 'Activate the ODSI Learning plugin to list courses here.', 'odsi-learn' ); ?></p>
	<!-- /wp:paragraph -->
	<?php endif; ?>
</div>
<!-- /wp:group -->
