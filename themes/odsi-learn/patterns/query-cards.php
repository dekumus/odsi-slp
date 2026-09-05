<?php
/**
 * Title: Post cards
 * Slug: odsi-learn/query-cards
 * Categories: odsi-learn
 * Inserter: no
 * Description: The archive and search result loop as a card grid.
 *
 * @package odsi-learn
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:query {"queryId":3,"query":{"inherit":true},"align":"wide","layout":{"type":"default"}} -->
<div class="wp-block-query alignwide">
	<!-- wp:post-template {"className":"odsi-learn-cards","layout":{"type":"grid","columnCount":3}} -->
		<!-- wp:group {"className":"odsi-learn-card","layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
		<div class="wp-block-group odsi-learn-card">
			<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"16/9"} /-->
			<!-- wp:group {"className":"odsi-learn-card__body","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|40","left":"var:preset|spacing|40","right":"var:preset|spacing|40"}}},"layout":{"type":"flex","orientation":"vertical"}} -->
			<div class="wp-block-group odsi-learn-card__body" style="padding-top:var(--wp--preset--spacing--30);padding-right:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--40)">
				<!-- wp:post-title {"level":2,"isLink":true,"fontSize":"large"} /-->
				<!-- wp:post-excerpt {"excerptLength":20,"fontSize":"small","textColor":"muted"} /-->
				<!-- wp:post-date {"fontSize":"small","textColor":"muted"} /-->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:group -->
	<!-- /wp:post-template -->
	<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->
		<!-- wp:query-pagination-previous /-->
		<!-- wp:query-pagination-numbers /-->
		<!-- wp:query-pagination-next /-->
	<!-- /wp:query-pagination -->
	<!-- wp:query-no-results -->
		<!-- wp:paragraph {"textColor":"muted"} -->
		<p class="has-muted-color has-text-color"><?php esc_html_e( 'Nothing matched. Try different words.', 'odsi-learn' ); ?></p>
		<!-- /wp:paragraph -->
	<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
