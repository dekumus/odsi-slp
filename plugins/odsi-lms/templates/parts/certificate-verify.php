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
	<form class="odsi-lms-verify__form" method="get" role="search" aria-label="<?php esc_attr_e( 'Verify a certificate', 'odsi-lms' ); ?>">
		<label class="odsi-lms-verify__label" for="odsi-lms-verify-code"><?php esc_html_e( 'Certificate code', 'odsi-lms' ); ?></label>
		<input class="odsi-lms-verify__input" type="text" id="odsi-lms-verify-code" name="code" value="<?php echo esc_attr( $code ); ?>" required autocomplete="off" spellcheck="false" />
		<button type="submit" class="odsi-lms-button odsi-lms-verify__submit"><?php esc_html_e( 'Verify', 'odsi-lms' ); ?></button>
	</form>
	<?php if ( '' !== $code ) : ?>
		<?php if ( $result ) : ?>
			<p class="odsi-lms-notice odsi-lms-notice--success odsi-lms-verify__result odsi-lms-verify__result--valid" role="status">
				<?php echo esc_html( sprintf( /* translators: 1: name, 2: course, 3: date. */ __( 'Valid: %1$s completed %2$s on %3$s.', 'odsi-lms' ), $result['name'], $result['course'], wp_date( (string) get_option( 'date_format' ), (int) strtotime( $result['issued_at'] . ' UTC' ) ) ) ); ?>
				<a href="<?php echo esc_url( $result['url'] ); ?>"><?php esc_html_e( 'View certificate', 'odsi-lms' ); ?></a>
			</p>
		<?php else : ?>
			<p class="odsi-lms-notice odsi-lms-notice--error odsi-lms-verify__result odsi-lms-verify__result--invalid" role="status"><?php esc_html_e( 'That code is not valid.', 'odsi-lms' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
