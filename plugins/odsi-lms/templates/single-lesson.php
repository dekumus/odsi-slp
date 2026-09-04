<?php
/**
 * Single lesson template.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main class="odsi-lms-lesson" id="primary">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'odsi-lms-lesson__article' ); ?>>
			<h1 class="odsi-lms-lesson__title"><?php the_title(); ?></h1>
			<div class="odsi-lms-lesson__content"><?php the_content(); ?></div>

			<footer class="odsi-lms-lesson__footer">
				<button type="button" class="odsi-lms-button odsi-lms-complete"
					data-step-id="<?php echo esc_attr( (string) get_the_ID() ); ?>">
					<?php esc_html_e( 'Mark complete', 'odsi-lms' ); ?>
				</button>
			</footer>
		</article>
		<?php
	endwhile;
	?>
	<aside class="odsi-lms-lesson__outline">
		<?php echo do_shortcode( '[odsi_course_outline]' ); ?>
	</aside>
</main>
<?php
get_footer();
