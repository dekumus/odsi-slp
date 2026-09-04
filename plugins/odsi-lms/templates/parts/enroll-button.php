<?php
/**
 * Enroll / continue button.
 *
 * @var int                                                        $course_id   Course post id.
 * @var int                                                        $user_id     Current user id.
 * @var bool                                                       $is_enrolled Whether the user is enrolled.
 * @var array{id:int,type:string,parent:int,depth:int}|null        $next_step   First step of the course.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="odsi-lms-enroll">
	<?php if ( $user_id <= 0 ) : ?>
		<a class="odsi-lms-button" href="<?php echo esc_url( wp_login_url( (string) get_permalink( $course_id ) ) ); ?>">
			<?php esc_html_e( 'Log in to enroll', 'odsi-lms' ); ?>
		</a>
	<?php elseif ( $is_enrolled ) : ?>
		<a class="odsi-lms-button" href="<?php echo esc_url( $next_step ? (string) get_permalink( $next_step['id'] ) : (string) get_permalink( $course_id ) ); ?>">
			<?php esc_html_e( 'Continue course', 'odsi-lms' ); ?>
		</a>
	<?php else : ?>
		<button type="button" class="odsi-lms-button odsi-lms-enroll__button"
			data-course-id="<?php echo esc_attr( (string) $course_id ); ?>">
			<?php esc_html_e( 'Enroll on this course', 'odsi-lms' ); ?>
		</button>
	<?php endif; ?>
</div>
