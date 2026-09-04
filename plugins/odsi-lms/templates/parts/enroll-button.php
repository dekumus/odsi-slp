<?php
/**
 * Enroll / continue button.
 *
 * @var int                                                        $course_id   Course post id.
 * @var int                                                        $user_id     Current user id.
 * @var bool                                                       $is_enrolled Whether the user is enrolled.
 * @var string                                                     $access_mode open, free, paid or closed.
 * @var int[]                                                      $missing     Prerequisite courses not yet completed.
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
	<?php elseif ( array() !== $missing ) : ?>
		<p class="odsi-lms-enroll__notice"><?php esc_html_e( 'Complete these courses first:', 'odsi-lms' ); ?></p>
		<ul class="odsi-lms-enroll__prerequisites">
			<?php foreach ( $missing as $required ) : ?>
				<li><a href="<?php echo esc_url( (string) get_permalink( $required ) ); ?>"><?php echo esc_html( (string) get_the_title( $required ) ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( $is_enrolled ) : ?>
		<a class="odsi-lms-button" href="<?php echo esc_url( $next_step ? (string) get_permalink( $next_step['id'] ) : (string) get_permalink( $course_id ) ); ?>">
			<?php esc_html_e( 'Continue course', 'odsi-lms' ); ?>
		</a>
	<?php elseif ( 'paid' === $access_mode ) : ?>
		<?php
		/**
		 * Filters the markup shown in place of the enroll button on a paid
		 * course; a commerce integration renders its buy button here.
		 *
		 * @param string $html      Markup.
		 * @param int    $course_id Course.
		 */
		echo wp_kses_post( (string) apply_filters( 'odsi_lms_paid_enroll_markup', '<p class="odsi-lms-enroll__notice">' . esc_html__( 'This course requires a purchase.', 'odsi-lms' ) . '</p>', $course_id ) );
		?>
	<?php elseif ( 'closed' === $access_mode ) : ?>
		<p class="odsi-lms-enroll__notice"><?php esc_html_e( 'Enrollment on this course is by invitation.', 'odsi-lms' ); ?></p>
	<?php else : ?>
		<button type="button" class="odsi-lms-button odsi-lms-enroll__button"
			data-course-id="<?php echo esc_attr( (string) $course_id ); ?>">
			<?php esc_html_e( 'Enroll on this course', 'odsi-lms' ); ?>
		</button>
	<?php endif; ?>
</div>
