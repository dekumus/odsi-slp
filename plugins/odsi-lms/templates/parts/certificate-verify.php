<?php
/**
 * Certificate verification form and result.
 *
 * @var string                    $code   Submitted code.
 * @var array<string, mixed>|null $result Verification result.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="odsi-lms-verify">
	<form method="get">
		<label for="odsi-lms-verify-code"><?php esc_html_e( 'Certificate code', 'odsi-lms' ); ?></label>
		<input type="text" id="odsi-lms-verify-code" name="code" value="<?php echo esc_attr( $code ); ?>" />
		<button type="submit" class="odsi-lms-button"><?php esc_html_e( 'Verify', 'odsi-lms' ); ?></button>
	</form>
	<?php if ( '' !== $code ) : ?>
		<?php if ( $result ) : ?>
			<p class="odsi-lms-verify__ok">
				<?php echo esc_html( sprintf( /* translators: 1: name, 2: course, 3: date. */ __( 'Valid: %1$s completed %2$s on %3$s.', 'odsi-lms' ), $result['name'], $result['course'], wp_date( (string) get_option( 'date_format' ), (int) strtotime( $result['issued_at'] ) ) ) ); ?>
				<a href="<?php echo esc_url( $result['url'] ); ?>"><?php esc_html_e( 'View certificate', 'odsi-lms' ); ?></a>
			</p>
		<?php else : ?>
			<p class="odsi-lms-verify__bad"><?php esc_html_e( 'That code is not valid.', 'odsi-lms' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
