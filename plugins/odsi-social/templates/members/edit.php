<?php
/**
 * Profile settings form. The page heading ("Edit profile") comes from the
 * theme; sections here start at h2 (fieldset legends read as headings).
 *
 * @var array<string, mixed>                      $profile             Profile as the member sees it.
 * @var array<int, array<string, mixed>>          $form                Field groups with values.
 * @var string                                    $message_setting     Current message setting.
 * @var bool                                      $email_notifications Whether the member wants emails.
 * @var array<string, string>                     $visibilities        Visibility key => label.
 * @var string                                    $accept              Accepted image extensions.
 * @var array{type: string, text: string}|null    $notice              Feedback after a save.
 * @var array<int, array<string, mixed>>          $blocked             Members this member has blocked (SOC-MOD-001).
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_templates = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
$odsi_uid       = (int) $profile['id'];
$blocked        = isset( $blocked ) && is_array( $blocked ) ? $blocked : array();
?>
<div class="odsi-social-settings odsi-social-settings--profile" data-user-id="<?php echo esc_attr( (string) $odsi_uid ); ?>">
	<p class="odsi-social-settings__back"><a href="<?php echo esc_url( (string) $profile['url'] ); ?>">&larr; <?php esc_html_e( 'Back to profile', 'odsi-social' ); ?></a></p>

	<?php if ( $notice ) : ?>
		<p class="odsi-social-notice odsi-social-notice--<?php echo esc_attr( $notice['type'] ); ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><?php echo esc_html( $notice['text'] ); ?></p>
	<?php endif; ?>

	<form class="odsi-social-settings__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( \ODSI\Social\Frontend\Forms::NONCE_PROFILE ); ?>
		<input type="hidden" name="action" value="odsi_social_profile_save" />
		<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $odsi_uid ); ?>" />

		<fieldset class="odsi-social-settings__section">
			<legend class="odsi-social-settings__legend"><?php esc_html_e( 'Photos', 'odsi-social' ); ?></legend>
			<div class="odsi-social-settings__image">
				<img class="odsi-social-avatar odsi-social-avatar--large odsi-social-settings__preview" src="<?php echo esc_url( (string) $profile['avatar'] ); ?>" alt="<?php esc_attr_e( 'Your current profile photo', 'odsi-social' ); ?>" width="96" height="96" />
				<div class="odsi-social-settings__field">
					<label for="odsi-avatar"><?php esc_html_e( 'Profile photo', 'odsi-social' ); ?></label>
					<input id="odsi-avatar" type="file" name="avatar" accept="<?php echo esc_attr( $accept ); ?>" />
					<label class="odsi-social-settings__choice"><input type="checkbox" name="remove_avatar" value="1" /> <?php esc_html_e( 'Remove photo and use Gravatar', 'odsi-social' ); ?></label>
				</div>
			</div>
			<div class="odsi-social-settings__image">
				<?php if ( '' !== $profile['cover'] ) : ?>
					<div class="odsi-social-hero__cover odsi-social-hero__cover--small odsi-social-settings__preview" style="background-image: url('<?php echo esc_url( (string) $profile['cover'] ); ?>')" role="img" aria-label="<?php esc_attr_e( 'Your current cover image', 'odsi-social' ); ?>"></div>
				<?php endif; ?>
				<div class="odsi-social-settings__field">
					<label for="odsi-cover"><?php esc_html_e( 'Cover image', 'odsi-social' ); ?></label>
					<input id="odsi-cover" type="file" name="cover" accept="<?php echo esc_attr( $accept ); ?>" />
					<label class="odsi-social-settings__choice"><input type="checkbox" name="remove_cover" value="1" /> <?php esc_html_e( 'Remove cover image', 'odsi-social' ); ?></label>
				</div>
			</div>
		</fieldset>

		<?php foreach ( $form as $odsi_group ) : ?>
			<fieldset class="odsi-social-settings__section">
				<legend class="odsi-social-settings__legend"><?php echo esc_html( (string) $odsi_group['group'] ); ?></legend>
				<?php foreach ( $odsi_group['fields'] as $odsi_field ) : ?>
					<?php
					$odsi_fid  = (int) $odsi_field['id'];
					$odsi_name = 'fields[' . $odsi_fid . '][value]';
					$odsi_id   = 'odsi-field-' . $odsi_fid;
					$odsi_req  = ! empty( $odsi_field['required'] );
					?>
					<div class="odsi-social-settings__field">
						<label for="<?php echo esc_attr( $odsi_id ); ?>">
							<?php echo esc_html( (string) $odsi_field['name'] ); ?>
							<?php if ( $odsi_req ) : ?>
								<span class="odsi-social-settings__required" aria-hidden="true">*</span>
								<span class="odsi-social-visually-hidden"><?php esc_html_e( '(required)', 'odsi-social' ); ?></span>
							<?php endif; ?>
						</label>
						<?php
						switch ( (string) $odsi_field['type'] ) {
							case 'textarea':
								printf( '<textarea id="%1$s" name="%2$s" rows="4"%3$s>%4$s</textarea>', esc_attr( $odsi_id ), esc_attr( $odsi_name ), $odsi_req ? ' required' : '', esc_textarea( (string) $odsi_field['value'] ) );
								break;

							case 'select':
								printf( '<select id="%1$s" name="%2$s"%3$s><option value="">%4$s</option>', esc_attr( $odsi_id ), esc_attr( $odsi_name ), $odsi_req ? ' required' : '', esc_html__( '— Choose —', 'odsi-social' ) );
								foreach ( (array) $odsi_field['options'] as $odsi_option ) {
									printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( (string) $odsi_option ), selected( (string) $odsi_field['value'], (string) $odsi_option, false ) );
								}
								echo '</select>';
								break;

							case 'multiselect':
								printf( '<input type="hidden" name="%s" value="" />', esc_attr( $odsi_name ) );
								foreach ( (array) $odsi_field['options'] as $odsi_option ) {
									printf( '<label class="odsi-social-settings__choice"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %2$s</label>', esc_attr( $odsi_name ), esc_attr( (string) $odsi_option ), checked( in_array( (string) $odsi_option, (array) $odsi_field['value'], true ), true, false ) );
								}
								break;

							case 'checkbox':
								printf( '<input type="hidden" name="%s" value="" />', esc_attr( $odsi_name ) );
								printf( '<input id="%1$s" type="checkbox" name="%2$s" value="1"%3$s />', esc_attr( $odsi_id ), esc_attr( $odsi_name ), checked( (bool) $odsi_field['value'], true, false ) );
								break;

							case 'date':
								printf( '<input id="%1$s" type="date" name="%2$s" value="%3$s"%4$s />', esc_attr( $odsi_id ), esc_attr( $odsi_name ), esc_attr( (string) $odsi_field['value'] ), $odsi_req ? ' required' : '' );
								break;

							case 'url':
								printf( '<input id="%1$s" type="url" name="%2$s" value="%3$s"%4$s />', esc_attr( $odsi_id ), esc_attr( $odsi_name ), esc_attr( (string) $odsi_field['value'] ), $odsi_req ? ' required' : '' );
								break;

							default:
								printf( '<input id="%1$s" type="text" name="%2$s" value="%3$s"%4$s />', esc_attr( $odsi_id ), esc_attr( $odsi_name ), esc_attr( (string) $odsi_field['value'] ), $odsi_req ? ' required' : '' );
						}//end switch
						?>
						<?php if ( $odsi_field['allow_visibility_change'] ) : ?>
							<label class="odsi-social-settings__visibility">
								<?php esc_html_e( 'Visible to', 'odsi-social' ); ?>
								<select name="fields[<?php echo esc_attr( (string) $odsi_fid ); ?>][visibility]">
									<?php foreach ( $visibilities as $odsi_key => $odsi_label ) : ?>
										<option value="<?php echo esc_attr( $odsi_key ); ?>" <?php selected( (string) $odsi_field['visibility'], $odsi_key ); ?>><?php echo esc_html( $odsi_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php else : ?>
							<span class="odsi-social-settings__visibility"><?php echo esc_html( sprintf( /* translators: %s: visibility label. */ __( 'Visible to: %s', 'odsi-social' ), $visibilities[ $odsi_field['visibility'] ] ?? \ODSI\Social\Support\Labels::privacy( (string) $odsi_field['visibility'] ) ) ); ?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</fieldset>
		<?php endforeach; ?>

		<fieldset class="odsi-social-settings__section">
			<legend class="odsi-social-settings__legend"><?php esc_html_e( 'Notifications', 'odsi-social' ); ?></legend>
			<input type="hidden" name="email_notifications" value="0" />
			<label class="odsi-social-settings__choice"><input type="checkbox" name="email_notifications" value="1" <?php checked( $email_notifications ); ?> /> <?php esc_html_e( 'Email me when something happens that I would want to know about', 'odsi-social' ); ?></label>
		</fieldset>

		<fieldset class="odsi-social-settings__section">
			<legend class="odsi-social-settings__legend"><?php esc_html_e( 'Messages', 'odsi-social' ); ?></legend>
			<div class="odsi-social-settings__field">
				<label for="odsi-message-setting"><?php esc_html_e( 'Who may message me', 'odsi-social' ); ?></label>
				<select id="odsi-message-setting" name="message_setting">
					<option value="anyone" <?php selected( $message_setting, 'anyone' ); ?>><?php esc_html_e( 'Anyone', 'odsi-social' ); ?></option>
					<option value="connections" <?php selected( $message_setting, 'connections' ); ?>><?php esc_html_e( 'My connections', 'odsi-social' ); ?></option>
					<option value="no_one" <?php selected( $message_setting, 'no_one' ); ?>><?php esc_html_e( 'No one', 'odsi-social' ); ?></option>
				</select>
			</div>
		</fieldset>

		<p class="odsi-social-settings__submit"><button type="submit" class="odsi-social-button"><?php esc_html_e( 'Save changes', 'odsi-social' ); ?></button></p>
	</form>

	<section class="odsi-social-settings__section odsi-social-settings__blocked">
		<h2 class="odsi-social-settings__title"><?php esc_html_e( 'Blocked members', 'odsi-social' ); ?></h2>
		<?php if ( array() === $blocked ) : ?>
			<?php
			$odsi_html = $odsi_templates->render(
				'parts/empty',
				array(
					'text'  => __( 'You have not blocked anyone. Blocked members cannot see you, message you or connect with you.', 'odsi-social' ),
					'url'   => '',
					'label' => '',
				)
			);
			echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
			?>
		<?php else : ?>
			<ul class="odsi-social-member-list">
				<?php foreach ( $blocked as $odsi_member ) : ?>
					<?php $odsi_row_id = 'odsi-social-blocked-' . (int) $odsi_member['id']; ?>
					<li class="odsi-social-member-list__item">
						<img class="odsi-social-avatar odsi-social-avatar--small" src="<?php echo esc_url( (string) $odsi_member['avatar'] ); ?>" alt="" width="32" height="32" loading="lazy" />
						<span class="odsi-social-member-list__name" id="<?php echo esc_attr( $odsi_row_id ); ?>"><?php echo esc_html( (string) $odsi_member['name'] ); ?></span>
						<span class="odsi-social-member-list__role"><?php echo esc_html( sprintf( /* translators: %s: human time difference, e.g. "3 days ago". */ __( 'blocked %s', 'odsi-social' ), \ODSI\Social\Support\Labels::ago( (string) $odsi_member['since'] ) ) ); ?></span>
						<span class="odsi-social-member-list__actions">
							<form class="odsi-social-member-list__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( \ODSI\Social\Frontend\Forms::NONCE_UNBLOCK ); ?>
								<input type="hidden" name="action" value="odsi_social_unblock" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $odsi_uid ); ?>" />
								<input type="hidden" name="member_id" value="<?php echo esc_attr( (string) $odsi_member['id'] ); ?>" />
								<button type="submit" class="odsi-social-button odsi-social-button--quiet odsi-social-button--small odsi-social-member-list__action" aria-describedby="<?php echo esc_attr( $odsi_row_id ); ?>"><?php esc_html_e( 'Unblock', 'odsi-social' ); ?></button>
							</form>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
</div>
