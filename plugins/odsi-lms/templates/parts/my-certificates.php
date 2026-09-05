<?php
/**
 * A learner's certificates.
 *
 * @var object[]                            $rows         Award rows.
 * @var \ODSI\LMS\Certificates\Certificates $certificates Service.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $rows ) ) {
	printf( '<p class="odsi-lms-notice odsi-lms-my-certificates__empty">%s</p>', esc_html__( 'No certificates yet. Complete a course that awards one and it will appear here.', 'odsi-lms' ) );

	return;
}
?>
<ul class="odsi-lms-my-certificates">
	<?php foreach ( $rows as $row ) : ?>
		<li class="odsi-lms-my-certificates__item">
			<a class="odsi-lms-my-certificates__link" href="<?php echo esc_url( $certificates->url( (string) $row->code ) ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html( get_the_title( (int) $row->course_id ) ); ?>
				<span class="odsi-lms-visually-hidden"><?php esc_html_e( '(opens in a new tab)', 'odsi-lms' ); ?></span>
			</a>
			<span class="odsi-lms-my-certificates__date"><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( (string) $row->issued_at . ' UTC' ) ) ); ?></span>
		</li>
	<?php endforeach; ?>
</ul>
