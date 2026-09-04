<?php
/**
 * The report form (SOC-MOD-010). Rendered once per page, hidden; the script
 * moves it next to whichever "Report" control was clicked and fills in the
 * object from that control's data attributes.
 *
 * @var int $viewer_id Viewer.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;
?>
<form class="odsi-social-report-form" hidden>
	<input type="hidden" name="object_type" value="" />
	<input type="hidden" name="object_id" value="" />
	<label>
		<?php esc_html_e( 'Why are you reporting this?', 'odsi-social' ); ?>
		<select name="reason" required>
			<?php foreach ( \ODSI\Social\Moderation\Reports::reason_labels() as $odsi_key => $odsi_label ) : ?>
				<option value="<?php echo esc_attr( $odsi_key ); ?>"><?php echo esc_html( $odsi_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<textarea name="details" rows="2" maxlength="<?php echo esc_attr( (string) \ODSI\Social\Moderation\Reports::DETAILS_MAX ); ?>" placeholder="<?php esc_attr_e( 'Anything a moderator should know (optional)', 'odsi-social' ); ?>"></textarea>
	<div class="odsi-social-post-form__controls">
		<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-report-cancel"><?php esc_html_e( 'Cancel', 'odsi-social' ); ?></button>
		<button type="submit" class="odsi-social-button"><?php esc_html_e( 'Send report', 'odsi-social' ); ?></button>
	</div>
</form>
