<?php
/**
 * Course catalogue grid.
 *
 * @var \WP_Query $query Course query.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

if ( ! $query->have_posts() ) {
	printf( '<p class="odsi-lms-grid__empty">%s</p>', esc_html__( 'No courses found.', 'odsi-lms' ) );

	return;
}
?>
<div class="odsi-lms-grid">
	<?php
	while ( $query->have_posts() ) :
		$query->the_post();
		?>
		<article class="odsi-lms-card">
			<?php if ( has_post_thumbnail() ) : ?>
				<a class="odsi-lms-card__media" href="<?php the_permalink(); ?>">
					<?php the_post_thumbnail( 'medium_large' ); ?>
				</a>
			<?php endif; ?>
			<h3 class="odsi-lms-card__title">
				<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
			</h3>
			<p class="odsi-lms-card__excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
		</article>
		<?php
	endwhile;

	wp_reset_postdata();
	?>
</div>
