<?php
/**
 * Single course template.
 *
 * Copy this file to `wp-content/themes/<theme>/odsi-lms/single-course.php` to
 * customise it without touching the plugin.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main class="odsi-lms-course" id="primary">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'odsi-lms-course__article' ); ?>>
			<header class="odsi-lms-course__header">
				<h1 class="odsi-lms-course__title"><?php the_title(); ?></h1>
				<?php echo do_shortcode( '[odsi_course_progress]' ); ?>
			</header>

			<div class="odsi-lms-course__content"><?php the_content(); ?></div>

			<?php echo do_shortcode( '[odsi_enroll_button]' ); ?>

			<section class="odsi-lms-course__outline">
				<h2><?php esc_html_e( 'Course content', 'odsi-lms' ); ?></h2>
				<?php echo do_shortcode( '[odsi_course_outline]' ); ?>
			</section>
		</article>
		<?php
	endwhile;
	?>
</main>
<?php
get_footer();
