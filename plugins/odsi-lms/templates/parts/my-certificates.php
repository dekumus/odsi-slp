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
	printf( '<p class="odsi-lms-my-certificates__empty">%s</p>', esc_html__( 'No certificates yet.', 'odsi-lms' ) );

	return;
}
?>
<ul class="odsi-lms-my-certificates">
	<?php foreach ( $rows as $row ) : ?>
		<li>
			<a href="<?php echo esc_url( $certificates->url( (string) $row->code ) ); ?>" target="_blank" rel="noopener">
				<?php echo esc_html( get_the_title( (int) $row->course_id ) ); ?>
			</a>
			<span class="odsi-lms-my-certificates__date"><?php echo esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( (string) $row->issued_at ) ) ); ?></span>
		</li>
	<?php endforeach; ?>
</ul>
