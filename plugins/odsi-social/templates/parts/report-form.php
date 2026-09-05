<?php
/**
 * The report dialog (SOC-MOD-010). Rendered once per page, closed; the
 * script opens it as a modal dialog from whichever "Report" control was
 * activated and fills in the object from that control's data attributes.
 *
 * @var int $viewer_id Viewer.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;
?>
<dialog class="odsi-social-report-dialog" aria-labelledby="odsi-social-report-title">
	<form class="odsi-social-report-dialog__form odsi-social-report-form" method="dialog">
		<h2 class="odsi-social-report-dialog__title" id="odsi-social-report-title"><?php esc_html_e( 'Report this content', 'odsi-social' ); ?></h2>
		<input type="hidden" name="object_type" value="" />
		<input type="hidden" name="object_id" value="" />
		<div class="odsi-social-report-dialog__field">
			<label for="odsi-social-report-reason"><?php esc_html_e( 'Why are you reporting this?', 'odsi-social' ); ?></label>
			<select id="odsi-social-report-reason" class="odsi-social-report-dialog__reason" name="reason" required>
				<?php foreach ( \ODSI\Social\Moderation\Reports::reason_labels() as $odsi_key => $odsi_label ) : ?>
					<option value="<?php echo esc_attr( $odsi_key ); ?>"><?php echo esc_html( $odsi_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="odsi-social-report-dialog__field">
			<label for="odsi-social-report-details"><?php esc_html_e( 'Anything a moderator should know (optional)', 'odsi-social' ); ?></label>
			<textarea id="odsi-social-report-details" class="odsi-social-report-dialog__details" name="details" rows="3" maxlength="<?php echo esc_attr( (string) \ODSI\Social\Moderation\Reports::DETAILS_MAX ); ?>"></textarea>
		</div>
		<p class="odsi-social-report-dialog__error odsi-social-error" role="alert" hidden></p>
		<div class="odsi-social-report-dialog__controls">
			<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-report-dialog__cancel"><?php esc_html_e( 'Cancel', 'odsi-social' ); ?></button>
			<button type="submit" class="odsi-social-button odsi-social-report-dialog__submit"><?php esc_html_e( 'Send report', 'odsi-social' ); ?></button>
		</div>
	</form>
</dialog>
