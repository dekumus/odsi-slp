<?php
/**
 * Enroll / continue button and the enrollment state notices around it.
 *
 * @var int                                                 $course_id    Course post id.
 * @var int                                                 $user_id      Current user id.
 * @var bool                                                $is_enrolled  Whether the user currently has access through an enrollment.
 * @var string                                              $access_mode  open, free, paid or closed.
 * @var int[]                                               $missing      Prerequisite courses not yet completed.
 * @var array{id:int,type?:string,parent?:int,depth?:int}|null $next_step    Step to continue at, or the first step.
 * @var string                                              $status       Effective enrollment status ('' when none): active, completed, expired, pending, cancelled.
 * @var string                                              $expires_at   Formatted expiry date, or ''.
 * @var string                                              $completed_at Formatted completion date, or ''.
 * @var string                                              $login_url    Login URL returning to the course.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

$odsi_lms_continue_url = $next_step ? (string) get_permalink( $next_step['id'] ) : (string) get_permalink( $course_id );
?>
<div class="odsi-lms-enroll" data-course-id="<?php echo esc_attr( (string) $course_id ); ?>">
	<?php if ( $user_id <= 0 ) : ?>
		<?php if ( 'open' === $access_mode && $next_step ) : ?>
			<a class="odsi-lms-button odsi-lms-enroll__start" href="<?php echo esc_url( $odsi_lms_continue_url ); ?>">
				<?php esc_html_e( 'Start course', 'odsi-lms' ); ?>
			</a>
		<?php else : ?>
			<a class="odsi-lms-button odsi-lms-enroll__login" href="<?php echo esc_url( $login_url ); ?>">
				<?php esc_html_e( 'Log in to enroll', 'odsi-lms' ); ?>
			</a>
		<?php endif; ?>
	<?php elseif ( array() !== $missing ) : ?>
		<p class="odsi-lms-notice odsi-lms-enroll__notice odsi-lms-enroll__notice--prerequisites"><?php esc_html_e( 'Complete these courses first:', 'odsi-lms' ); ?></p>
		<ul class="odsi-lms-enroll__prerequisites">
			<?php foreach ( $missing as $required ) : ?>
				<li class="odsi-lms-enroll__prerequisite"><a href="<?php echo esc_url( (string) get_permalink( $required ) ); ?>"><?php echo esc_html( (string) get_the_title( $required ) ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( 'completed' === $status ) : ?>
		<p class="odsi-lms-notice odsi-lms-notice--success odsi-lms-enroll__notice odsi-lms-enroll__notice--completed">
			<?php
			if ( '' !== $completed_at ) {
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'You completed this course on %s.', 'odsi-lms' ), $completed_at ) );
			} else {
				esc_html_e( 'You completed this course.', 'odsi-lms' );
			}
			?>
		</p>
		<a class="odsi-lms-button odsi-lms-enroll__continue" href="<?php echo esc_url( $odsi_lms_continue_url ); ?>">
			<?php esc_html_e( 'Review course', 'odsi-lms' ); ?>
		</a>
	<?php elseif ( $is_enrolled ) : ?>
		<a class="odsi-lms-button odsi-lms-enroll__continue" href="<?php echo esc_url( $odsi_lms_continue_url ); ?>">
			<?php esc_html_e( 'Continue course', 'odsi-lms' ); ?>
		</a>
	<?php elseif ( 'pending' === $status ) : ?>
		<p class="odsi-lms-notice odsi-lms-enroll__notice odsi-lms-enroll__notice--pending"><?php esc_html_e( 'Your enrollment is awaiting confirmation.', 'odsi-lms' ); ?></p>
	<?php else : ?>
		<?php if ( 'expired' === $status ) : ?>
			<p class="odsi-lms-notice odsi-lms-notice--error odsi-lms-enroll__notice odsi-lms-enroll__notice--expired">
				<?php
				if ( '' !== $expires_at ) {
					/* translators: %s: date. */
					echo esc_html( sprintf( __( 'Your access to this course ended on %s. Your progress has been kept.', 'odsi-lms' ), $expires_at ) );
				} else {
					esc_html_e( 'Your access to this course has ended. Your progress has been kept.', 'odsi-lms' );
				}
				?>
			</p>
		<?php endif; ?>

		<?php if ( 'paid' === $access_mode ) : ?>
			<?php
			/**
			 * Filters the markup shown in place of the enroll button on a paid
			 * course; a commerce integration renders its buy button here.
			 *
			 * @param string $html      Markup.
			 * @param int    $course_id Course.
			 */
			echo wp_kses_post( (string) apply_filters( 'odsi_lms_paid_enroll_markup', '<p class="odsi-lms-notice odsi-lms-enroll__notice odsi-lms-enroll__notice--paid">' . esc_html__( 'This course requires a purchase.', 'odsi-lms' ) . '</p>', $course_id ) );
			?>
		<?php elseif ( 'closed' === $access_mode ) : ?>
			<p class="odsi-lms-notice odsi-lms-enroll__notice odsi-lms-enroll__notice--closed"><?php esc_html_e( 'Enrollment on this course is by invitation.', 'odsi-lms' ); ?></p>
		<?php else : ?>
			<button type="button" class="odsi-lms-button odsi-lms-enroll__button" data-course-id="<?php echo esc_attr( (string) $course_id ); ?>">
				<?php
				if ( 'expired' === $status || 'cancelled' === $status ) {
					esc_html_e( 'Enroll again', 'odsi-lms' );
				} else {
					esc_html_e( 'Enroll on this course', 'odsi-lms' );
				}
				?>
			</button>
			<p class="odsi-lms-enroll__error" role="alert" hidden></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
