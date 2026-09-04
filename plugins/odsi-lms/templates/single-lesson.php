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
		</article>
		<?php
	endwhile;
	?>
</main>
<?php
get_footer();
