<?php
/**
 * Enrolled course list.
 *
 * @var int[]                        $course_ids Course post ids.
 * @var \ODSI\LMS\Courses\Progress   $progress   Progress service.
 * @var int                          $user_id    Current user id.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $course_ids ) ) {
	printf( '<p class="odsi-lms-my-courses__empty">%s</p>', esc_html__( 'You are not enrolled on any courses yet.', 'odsi-lms' ) );

	return;
}
?>
<ul class="odsi-lms-my-courses">
	<?php foreach ( $course_ids as $course_id ) : ?>
		<?php $percentage = $progress->course_percentage( $user_id, (int) $course_id ); ?>
		<li class="odsi-lms-my-courses__item">
			<a class="odsi-lms-my-courses__link" href="<?php echo esc_url( (string) get_permalink( $course_id ) ); ?>">
				<?php echo esc_html( get_the_title( $course_id ) ); ?>
			</a>
			<div class="odsi-lms-progress__track">
				<div class="odsi-lms-progress__fill" style="width: <?php echo esc_attr( (string) $percentage ); ?>%"></div>
			</div>
			<span class="odsi-lms-my-courses__percentage">
				<?php echo esc_html( sprintf( '%s%%', $percentage ) ); ?>
			</span>
		</li>
	<?php endforeach; ?>
</ul>
