<?php
/**
 * Assignment hand-in form and history for the current learner.
 *
 * @var int                              $step_id         Step post id.
 * @var float                            $points_possible Points the assignment is worth.
 * @var array<string, mixed>|null        $latest          Latest submission, presented.
 * @var array<int, array<string, mixed>> $history         Every submission, newest first.
 * @var bool                             $can_submit      Whether a new submission is accepted.
 * @var string                           $accept          Comma-separated accepted extensions for the `accept` attribute.
 * @var string                           $accept_list     Human-readable list of accepted extensions.
 * @var int                              $max_bytes       Upload limit in bytes.
 * @var string                           $max_label       Upload limit, formatted.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

$odsi_lms_labels = array(
	'pending'  => __( 'Waiting for review', 'odsi-lms' ),
	'approved' => __( 'Approved', 'odsi-lms' ),
	'rejected' => __( 'Needs another try', 'odsi-lms' ),
);
$odsi_lms_id     = (int) $step_id;
?>
<section class="odsi-lms-assignment" data-step-id="<?php echo esc_attr( (string) $step_id ); ?>" aria-labelledby="odsi-lms-assignment-heading-<?php echo esc_attr( (string) $odsi_lms_id ); ?>">
	<h2 class="odsi-lms-assignment__heading" id="odsi-lms-assignment-heading-<?php echo esc_attr( (string) $odsi_lms_id ); ?>"><?php esc_html_e( 'Assignment', 'odsi-lms' ); ?></h2>

	<?php if ( $latest ) : ?>
		<div class="odsi-lms-notice odsi-lms-assignment__status odsi-lms-assignment__status--<?php echo esc_attr( $latest['status'] ); ?>" role="status">
			<strong class="odsi-lms-assignment__status-label"><?php echo esc_html( $odsi_lms_labels[ $latest['status'] ] ?? $latest['status'] ); ?></strong>
			<?php if ( 'approved' === $latest['status'] && $points_possible > 0 ) : ?>
				<span class="odsi-lms-assignment__points">
					<?php
					/* translators: 1: points earned, 2: points possible. */
					echo esc_html( sprintf( __( '%1$s / %2$s points', 'odsi-lms' ), $latest['points_earned'], $latest['points_possible'] ) );
					?>
				</span>
			<?php endif; ?>
			<?php if ( '' !== $latest['feedback'] ) : ?>
				<blockquote class="odsi-lms-assignment__feedback"><?php echo wp_kses_post( wpautop( $latest['feedback'] ) ); ?></blockquote>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $can_submit ) : ?>
		<form class="odsi-lms-assignment__form" method="post" enctype="multipart/form-data"
			data-max-bytes="<?php echo esc_attr( (string) $max_bytes ); ?>"
			data-max-label="<?php echo esc_attr( $max_label ); ?>"
			data-accept="<?php echo esc_attr( $accept ); ?>">
			<p class="odsi-lms-assignment__field">
				<label class="odsi-lms-assignment__label" for="odsi-lms-assignment-content-<?php echo esc_attr( (string) $odsi_lms_id ); ?>"><?php esc_html_e( 'Your answer', 'odsi-lms' ); ?></label>
				<textarea class="odsi-lms-assignment__textarea" id="odsi-lms-assignment-content-<?php echo esc_attr( (string) $odsi_lms_id ); ?>" name="content" rows="6"></textarea>
			</p>
			<p class="odsi-lms-assignment__field">
				<label class="odsi-lms-assignment__label" for="odsi-lms-assignment-file-<?php echo esc_attr( (string) $odsi_lms_id ); ?>"><?php esc_html_e( 'Attach a file (optional)', 'odsi-lms' ); ?></label>
				<input class="odsi-lms-assignment__file" id="odsi-lms-assignment-file-<?php echo esc_attr( (string) $odsi_lms_id ); ?>" type="file" name="file" accept="<?php echo esc_attr( $accept ); ?>" aria-describedby="odsi-lms-assignment-hint-<?php echo esc_attr( (string) $odsi_lms_id ); ?>" />
				<span class="odsi-lms-assignment__hint" id="odsi-lms-assignment-hint-<?php echo esc_attr( (string) $odsi_lms_id ); ?>">
					<?php
					printf(
						/* translators: 1: list of file extensions, 2: size limit. */
						esc_html__( 'Accepted types: %1$s. Maximum size: %2$s.', 'odsi-lms' ),
						esc_html( $accept_list ),
						esc_html( $max_label )
					);
					?>
				</span>
			</p>
			<p class="odsi-lms-assignment__actions">
				<button type="submit" class="odsi-lms-button odsi-lms-assignment__submit"><?php esc_html_e( 'Hand in', 'odsi-lms' ); ?></button>
			</p>
			<p class="odsi-lms-assignment__error" id="odsi-lms-assignment-error-<?php echo esc_attr( (string) $odsi_lms_id ); ?>" role="alert" hidden></p>
			<noscript><p class="odsi-lms-notice"><?php esc_html_e( 'JavaScript is required to hand in an assignment.', 'odsi-lms' ); ?></p></noscript>
		</form>
	<?php endif; ?>

	<?php if ( count( $history ) > 0 ) : ?>
		<h3 class="odsi-lms-assignment__history-heading"><?php esc_html_e( 'Your submissions', 'odsi-lms' ); ?></h3>
		<ol class="odsi-lms-assignment__history">
			<?php foreach ( $history as $odsi_lms_item ) : ?>
				<li class="odsi-lms-assignment__item odsi-lms-assignment__item--<?php echo esc_attr( $odsi_lms_item['status'] ); ?>">
					<time class="odsi-lms-assignment__date" datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( $odsi_lms_item['submitted_at'] . ' UTC' ) ) ); ?>"><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( $odsi_lms_item['submitted_at'] . ' UTC' ) ) ); ?></time>
					<span class="odsi-lms-assignment__badge odsi-lms-assignment__badge--<?php echo esc_attr( $odsi_lms_item['status'] ); ?>"><?php echo esc_html( $odsi_lms_labels[ $odsi_lms_item['status'] ] ?? $odsi_lms_item['status'] ); ?></span>
					<?php if ( '' !== $odsi_lms_item['content'] ) : ?>
						<div class="odsi-lms-assignment__content"><?php echo wp_kses_post( wpautop( $odsi_lms_item['content'] ) ); ?></div>
					<?php endif; ?>
					<?php if ( '' !== $odsi_lms_item['attachment_url'] ) : ?>
						<a class="odsi-lms-assignment__attachment" href="<?php echo esc_url( $odsi_lms_item['attachment_url'] ); ?>"><?php echo esc_html( $odsi_lms_item['attachment_name'] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</section>
