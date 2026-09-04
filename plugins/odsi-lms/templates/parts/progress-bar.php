<?php
/**
 * Course progress bar.
 *
 * @var int   $course_id  Course post id.
 * @var float $percentage Completion percentage.
 * @var int   $completed  Steps completed.
 * @var int   $total      Steps in the course.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="odsi-lms-progress" data-course-id="<?php echo esc_attr( (string) $course_id ); ?>">
	<div class="odsi-lms-progress__track" role="progressbar"
		aria-valuenow="<?php echo esc_attr( (string) $percentage ); ?>"
		aria-valuemin="0" aria-valuemax="100"
		aria-label="<?php esc_attr_e( 'Course progress', 'odsi-lms' ); ?>">
		<div class="odsi-lms-progress__fill" style="width: <?php echo esc_attr( (string) $percentage ); ?>%"></div>
	</div>
	<p class="odsi-lms-progress__label">
		<?php
		printf(
			/* translators: 1: steps completed, 2: total steps, 3: percentage. */
			esc_html__( '%1$d of %2$d steps complete (%3$s%%)', 'odsi-lms' ),
			(int) $completed,
			(int) $total,
			esc_html( (string) $percentage )
		);
		?>
	</p>
</div>
