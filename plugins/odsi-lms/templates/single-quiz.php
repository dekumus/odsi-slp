<?php
/**
 * Single quiz template.
 *
 * The quiz player itself is rendered by the front-end script against the REST
 * API; this template provides the mount point and the no-JavaScript fallback.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main class="odsi-lms-quiz" id="primary">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'odsi-lms-quiz__article' ); ?>>
			<h1 class="odsi-lms-quiz__title"><?php the_title(); ?></h1>
			<div class="odsi-lms-quiz__intro"><?php the_content(); ?></div>

			<div class="odsi-lms-quiz__player" data-quiz-id="<?php echo esc_attr( (string) get_the_ID() ); ?>">
				<noscript>
					<p><?php esc_html_e( 'JavaScript is required to take this quiz.', 'odsi-lms' ); ?></p>
				</noscript>
			</div>
		</article>
		<?php
	endwhile;
	?>
</main>
<?php
get_footer();
