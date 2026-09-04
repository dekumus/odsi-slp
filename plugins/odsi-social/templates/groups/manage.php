<?php
/**
 * Group management: settings and members.
 *
 * @var array<string, mixed>                   $group   Group.
 * @var array<int, array<string, mixed>>       $members Active members.
 * @var array<int, array<string, mixed>>       $pending Pending requests.
 * @var array<int, array<string, mixed>>       $banned  Banned members.
 * @var string                                 $accept  Accepted image extensions.
 * @var array{type: string, text: string}|null $notice  Feedback after a save.
 * @var int                                    $viewer_id Viewer.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_gid = (int) $group['id'];

/**
 * One member action button.
 *
 * @param int    $group_id Group.
 * @param int    $user_id  Member.
 * @param string $action   Action.
 * @param string $label    Label.
 */
$odsi_member_button = static function ( int $group_id, int $user_id, string $action, string $label ): void {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="odsi-social-inline-form">';
	wp_nonce_field( \ODSI\Social\Frontend\Forms::NONCE_MEMBER );
	printf( '<input type="hidden" name="action" value="odsi_social_group_member" /><input type="hidden" name="group_id" value="%d" /><input type="hidden" name="member_id" value="%d" /><input type="hidden" name="member_action" value="%s" />', (int) $group_id, (int) $user_id, esc_attr( $action ) );
	printf( '<button type="submit" class="odsi-social-button odsi-social-button--small">%s</button>', esc_html( $label ) );
	echo '</form>';
};
?>
<div class="odsi-social-settings odsi-social-settings--group" data-group-id="<?php echo esc_attr( (string) $odsi_gid ); ?>">
	<h2><?php echo esc_html( sprintf( /* translators: %s: group name. */ __( 'Manage %s', 'odsi-social' ), (string) $group['name'] ) ); ?></h2>
	<p><a href="<?php echo esc_url( (string) $group['url'] ); ?>">&larr; <?php esc_html_e( 'Back to group', 'odsi-social' ); ?></a></p>

	<?php if ( $notice ) : ?>
		<p class="odsi-social-notice odsi-social-notice--<?php echo esc_attr( $notice['type'] ); ?>" role="status"><?php echo esc_html( $notice['text'] ); ?></p>
	<?php endif; ?>

	<form class="odsi-social-settings__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( \ODSI\Social\Frontend\Forms::NONCE_GROUP ); ?>
		<input type="hidden" name="action" value="odsi_social_group_save" />
		<input type="hidden" name="group_id" value="<?php echo esc_attr( (string) $odsi_gid ); ?>" />

		<fieldset class="odsi-social-settings__section">
			<legend><?php esc_html_e( 'Settings', 'odsi-social' ); ?></legend>
			<div class="odsi-social-settings__field">
				<label for="odsi-group-name"><?php esc_html_e( 'Name', 'odsi-social' ); ?></label>
				<input id="odsi-group-name" type="text" name="name" value="<?php echo esc_attr( (string) $group['name'] ); ?>" required />
			</div>
			<div class="odsi-social-settings__field">
				<label for="odsi-group-description"><?php esc_html_e( 'Description', 'odsi-social' ); ?></label>
				<textarea id="odsi-group-description" name="description" rows="5"><?php echo esc_textarea( (string) get_post_field( 'post_content', $odsi_gid ) ); ?></textarea>
			</div>
			<div class="odsi-social-settings__field">
				<label for="odsi-group-visibility"><?php esc_html_e( 'Visibility', 'odsi-social' ); ?></label>
				<select id="odsi-group-visibility" name="visibility">
					<option value="public" <?php selected( (string) $group['visibility'], 'public' ); ?>><?php esc_html_e( 'Public — anyone can see and join', 'odsi-social' ); ?></option>
					<option value="private" <?php selected( (string) $group['visibility'], 'private' ); ?>><?php esc_html_e( 'Private — listed, members approved', 'odsi-social' ); ?></option>
					<option value="hidden" <?php selected( (string) $group['visibility'], 'hidden' ); ?>><?php esc_html_e( 'Hidden — invitation only', 'odsi-social' ); ?></option>
				</select>
			</div>
		</fieldset>

		<fieldset class="odsi-social-settings__section">
			<legend><?php esc_html_e( 'Images', 'odsi-social' ); ?></legend>
			<div class="odsi-social-settings__image">
				<?php if ( '' !== $group['avatar'] ) : ?>
					<img class="odsi-social-avatar odsi-social-avatar--large" src="<?php echo esc_url( (string) $group['avatar'] ); ?>" alt="" width="96" height="96" />
				<?php endif; ?>
				<p>
					<label for="odsi-group-avatar"><?php esc_html_e( 'Group photo', 'odsi-social' ); ?></label>
					<input id="odsi-group-avatar" type="file" name="avatar" accept="<?php echo esc_attr( $accept ); ?>" />
				</p>
				<p><label><input type="checkbox" name="remove_avatar" value="1" /> <?php esc_html_e( 'Remove group photo', 'odsi-social' ); ?></label></p>
			</div>
			<div class="odsi-social-settings__image">
				<?php if ( '' !== $group['cover'] ) : ?>
					<div class="odsi-social-profile__cover odsi-social-profile__cover--small" style="background-image: url('<?php echo esc_url( (string) $group['cover'] ); ?>')"></div>
				<?php endif; ?>
				<p>
					<label for="odsi-group-cover"><?php esc_html_e( 'Cover image', 'odsi-social' ); ?></label>
					<input id="odsi-group-cover" type="file" name="cover" accept="<?php echo esc_attr( $accept ); ?>" />
				</p>
				<p><label><input type="checkbox" name="remove_cover" value="1" /> <?php esc_html_e( 'Remove cover image', 'odsi-social' ); ?></label></p>
			</div>
		</fieldset>

		<p><button type="submit" class="odsi-social-button"><?php esc_html_e( 'Save settings', 'odsi-social' ); ?></button></p>
	</form>

	<?php if ( count( $pending ) > 0 ) : ?>
		<section class="odsi-social-settings__section">
			<h3><?php echo esc_html( sprintf( /* translators: %d: count. */ _n( '%d request to join', '%d requests to join', count( $pending ), 'odsi-social' ), count( $pending ) ) ); ?></h3>
			<ul class="odsi-social-member-list">
				<?php foreach ( $pending as $odsi_member ) : ?>
					<li class="odsi-social-member-list__item">
						<img class="odsi-social-avatar" src="<?php echo esc_url( (string) $odsi_member['avatar'] ); ?>" alt="" width="32" height="32" />
						<span class="odsi-social-member-list__name"><?php echo esc_html( (string) $odsi_member['name'] ); ?></span>
						<?php $odsi_member_button( $odsi_gid, (int) $odsi_member['id'], 'approve', __( 'Approve', 'odsi-social' ) ); ?>
						<?php $odsi_member_button( $odsi_gid, (int) $odsi_member['id'], 'reject', __( 'Decline', 'odsi-social' ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<section class="odsi-social-settings__section">
		<h3><?php echo esc_html( sprintf( /* translators: %d: count. */ _n( '%d member', '%d members', count( $members ), 'odsi-social' ), count( $members ) ) ); ?></h3>
		<ul class="odsi-social-member-list">
			<?php foreach ( $members as $odsi_member ) : ?>
				<li class="odsi-social-member-list__item">
					<img class="odsi-social-avatar" src="<?php echo esc_url( (string) $odsi_member['avatar'] ); ?>" alt="" width="32" height="32" />
					<span class="odsi-social-member-list__name"><?php echo esc_html( (string) $odsi_member['name'] ); ?></span>
					<span class="odsi-social-member-list__role"><?php echo esc_html( ucfirst( (string) $odsi_member['role'] ) ); ?></span>
					<?php if ( 'organiser' !== $odsi_member['role'] && (int) $odsi_member['id'] !== $viewer_id ) : ?>
						<?php if ( 'moderator' === $odsi_member['role'] ) : ?>
							<?php $odsi_member_button( $odsi_gid, (int) $odsi_member['id'], 'demote', __( 'Make member', 'odsi-social' ) ); ?>
						<?php else : ?>
							<?php $odsi_member_button( $odsi_gid, (int) $odsi_member['id'], 'promote', __( 'Make moderator', 'odsi-social' ) ); ?>
						<?php endif; ?>
						<?php $odsi_member_button( $odsi_gid, (int) $odsi_member['id'], 'remove', __( 'Remove', 'odsi-social' ) ); ?>
						<?php $odsi_member_button( $odsi_gid, (int) $odsi_member['id'], 'ban', __( 'Ban', 'odsi-social' ) ); ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<?php if ( count( $banned ) > 0 ) : ?>
		<section class="odsi-social-settings__section">
			<h3><?php esc_html_e( 'Banned', 'odsi-social' ); ?></h3>
			<ul class="odsi-social-member-list">
				<?php foreach ( $banned as $odsi_member ) : ?>
					<li class="odsi-social-member-list__item">
						<span class="odsi-social-member-list__name"><?php echo esc_html( (string) $odsi_member['name'] ); ?></span>
						<?php $odsi_member_button( $odsi_gid, (int) $odsi_member['id'], 'unban', __( 'Unban', 'odsi-social' ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>
