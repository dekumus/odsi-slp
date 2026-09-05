<?php
/**
 * Enrolled course list.
 *
 * @var int[]                      $course_ids Course post ids.
 * @var array<int, string>         $statuses   Effective enrollment status per course id.
 * @var \ODSI\LMS\Courses\Progress $progress   Progress service.
 * @var int                        $user_id    Current user id.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $course_ids ) ) {
	printf(
		'<p class="odsi-lms-notice odsi-lms-my-courses__empty">%1$s <a href="%2$s">%3$s</a></p>',
		esc_html__( 'You are not enrolled on any courses yet.', 'odsi-lms' ),
		esc_url( (string) get_post_type_archive_link( 'odsi_course' ) ),
		esc_html__( 'Browse courses', 'odsi-lms' )
	);

	return;
}

$odsi_lms_status_labels = array(
	'active'    => __( 'In progress', 'odsi-lms' ),
	'completed' => __( 'Completed', 'odsi-lms' ),
	'expired'   => __( 'Expired', 'odsi-lms' ),
	'pending'   => __( 'Pending', 'odsi-lms' ),
	'cancelled' => __( 'Cancelled', 'odsi-lms' ),
);
?>
<ul class="odsi-lms-my-courses">
	<?php foreach ( $course_ids as $course_id ) : ?>
		<?php
		$percentage      = $progress->course_percentage( $user_id, (int) $course_id );
		$odsi_lms_status = sanitize_html_class( (string) ( $statuses[ $course_id ] ?? 'active' ) );
		$odsi_lms_title  = (string) get_the_title( $course_id );
		?>
		<li class="odsi-lms-my-courses__item odsi-lms-my-courses__item--<?php echo esc_attr( $odsi_lms_status ); ?>">
			<a class="odsi-lms-my-courses__link" href="<?php echo esc_url( (string) get_permalink( $course_id ) ); ?>"><?php echo esc_html( $odsi_lms_title ); ?></a>
			<span class="odsi-lms-my-courses__status odsi-lms-my-courses__status--<?php echo esc_attr( $odsi_lms_status ); ?>"><?php echo esc_html( $odsi_lms_status_labels[ $odsi_lms_status ] ?? $odsi_lms_status ); ?></span>
			<div class="odsi-lms-progress odsi-lms-my-courses__progress" data-course-id="<?php echo esc_attr( (string) $course_id ); ?>">
				<div class="odsi-lms-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $percentage ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: course title. */ __( 'Progress on %s', 'odsi-lms' ), $odsi_lms_title ) ); ?>">
					<div class="odsi-lms-progress__fill" style="width: <?php echo esc_attr( (string) $percentage ); ?>%"></div>
				</div>
				<span class="odsi-lms-progress__label odsi-lms-my-courses__percentage"><?php echo esc_html( sprintf( '%s%%', $percentage ) ); ?></span>
			</div>
		</li>
	<?php endforeach; ?>
</ul>
