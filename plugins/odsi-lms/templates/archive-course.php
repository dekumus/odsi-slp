<?php
/**
 * Course archive template.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main class="odsi-lms-archive" id="primary">
	<header class="odsi-lms-archive__header">
		<h1 class="odsi-lms-archive__title"><?php post_type_archive_title(); ?></h1>
	</header>

	<?php echo do_shortcode( '[odsi_course_grid per_page="12"]' ); ?>
</main>
<?php
get_footer();
