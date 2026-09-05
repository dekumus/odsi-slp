<?php
/**
 * Title: Community
 * Slug: odsi-learn/community
 * Categories: odsi-learn
 * Description: Recent activity beside the member and group directories.
 *
 * @package odsi-learn
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$odsi_learn_activity = (string) apply_filters( 'odsi_social_page_url', '', 'activity' );
$odsi_learn_members  = (string) apply_filters( 'odsi_social_page_url', '', 'members' );
$odsi_learn_groups   = (string) apply_filters( 'odsi_social_page_url', '', 'groups' );

if ( '' === $odsi_learn_activity ) {
	return;
}
?>
<!-- wp:group {"className":"odsi-learn-section","align":"wide","style":{"spacing":{"margin":{"top":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group odsi-learn-section alignwide" style="margin-top:var(--wp--preset--spacing--70)">
	<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between","verticalAlignment":"bottom","flexWrap":"wrap"}} -->
	<div class="wp-block-group">
		<!-- wp:heading {"level":2} -->
		<h2 class="wp-block-heading"><?php esc_html_e( 'Community', 'odsi-learn' ); ?></h2>
		<!-- /wp:heading -->
		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><a href="<?php echo esc_url( $odsi_learn_members ); ?>"><?php esc_html_e( 'Members', 'odsi-learn' ); ?></a> · <a href="<?php echo esc_url( $odsi_learn_groups ); ?>"><?php esc_html_e( 'Groups', 'odsi-learn' ); ?></a> · <a href="<?php echo esc_url( $odsi_learn_activity ); ?>"><?php esc_html_e( 'All activity', 'odsi-learn' ); ?></a></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
	<!-- wp:columns {"align":"wide"} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"60%"} -->
		<div class="wp-block-column" style="flex-basis:60%">
			<!-- wp:odsi-social/activity-feed {"perPage":5} /-->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"width":"40%"} -->
		<div class="wp-block-column" style="flex-basis:40%">
			<!-- wp:heading {"level":3,"fontSize":"medium"} -->
			<h3 class="wp-block-heading has-medium-font-size"><?php esc_html_e( 'Groups', 'odsi-learn' ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:odsi-social/group-directory {"perPage":4} /-->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
