<?php
/**
 * Member profile.
 *
 * @var array<string, mixed> $profile      Profile.
 * @var int                  $viewer_id    Viewer.
 * @var string               $feed         Rendered profile feed.
 * @var bool                 $is_following Whether the viewer follows this member.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_uid = (int) $profile['id'];
?>
<div class="odsi-social-profile" data-user-id="<?php echo esc_attr( (string) $odsi_uid ); ?>">
	<?php if ( '' !== $profile['cover'] ) : ?>
		<div class="odsi-social-profile__cover" style="background-image: url('<?php echo esc_url( (string) $profile['cover'] ); ?>')"></div>
	<?php endif; ?>

	<header class="odsi-social-profile__header">
		<img class="odsi-social-avatar odsi-social-avatar--large" src="<?php echo esc_url( (string) $profile['avatar'] ); ?>" alt="" width="128" height="128" />
		<div>
			<h2><?php echo esc_html( (string) $profile['name'] ); ?></h2>
			<p class="odsi-social-profile__counts">
				<?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d connections', 'odsi-social' ), (int) $profile['counts']['connections'] ) ); ?> ·
				<?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d followers', 'odsi-social' ), (int) $profile['counts']['followers'] ) ); ?>
			</p>

			<?php if ( $viewer_id > 0 && ( $profile['viewer']['is_self'] || \ODSI\Social\Support\Capabilities::is_admin( $viewer_id ) ) ) : ?>
				<div class="odsi-social-profile__actions">
					<a class="odsi-social-button odsi-social-profile__edit" href="<?php echo esc_url( (string) apply_filters( 'odsi_social_page_url', trailingslashit( (string) $profile['url'] ) . 'edit/', 'members', (string) $profile['nicename'], 'edit' ) ); ?>"><?php esc_html_e( 'Edit profile', 'odsi-social' ); ?></a>
				</div>
			<?php endif; ?>

			<?php if ( $viewer_id > 0 && ! $profile['viewer']['is_self'] ) : ?>
				<div class="odsi-social-profile__actions">
					<?php
					$odsi_status = (string) $profile['viewer']['connection'];
					$odsi_label  = match ( $odsi_status ) {
						'accepted'         => __( 'Remove connection', 'odsi-social' ),
						'pending_sent'     => __( 'Withdraw request', 'odsi-social' ),
						'pending_received' => __( 'Accept request', 'odsi-social' ),
						default            => __( 'Connect', 'odsi-social' ),
					};
	?>
					<button type="button" class="odsi-social-button odsi-social-connect" data-user-id="<?php echo esc_attr( (string) $odsi_uid ); ?>" data-status="<?php echo esc_attr( $odsi_status ); ?>"><?php echo esc_html( $odsi_label ); ?></button>
					<button type="button" class="odsi-social-button odsi-social-follow <?php echo $is_following ? 'is-active' : ''; ?>" data-user-id="<?php echo esc_attr( (string) $odsi_uid ); ?>"><?php echo $is_following ? esc_html__( 'Unfollow', 'odsi-social' ) : esc_html__( 'Follow', 'odsi-social' ); ?></button>
					<a class="odsi-social-button" href="<?php echo esc_url( add_query_arg( 'to', $odsi_uid, (string) apply_filters( 'odsi_social_page_url', home_url( '/messages/' ), 'messages', '', '' ) ) ); ?>"><?php esc_html_e( 'Message', 'odsi-social' ); ?></a>
				</div>
			<?php endif; ?>
		</div>
	</header>

	<?php foreach ( (array) $profile['field_groups'] as $odsi_group ) : ?>
		<section class="odsi-social-profile__fields">
			<h3><?php echo esc_html( (string) $odsi_group['group'] ); ?></h3>
			<dl>
				<?php foreach ( $odsi_group['fields'] as $odsi_field ) : ?>
					<dt><?php echo esc_html( (string) $odsi_field['name'] ); ?></dt>
					<dd>
						<?php
						$odsi_value = $odsi_field['value'];
						if ( is_array( $odsi_value ) ) {
							echo esc_html( implode( ', ', $odsi_value ) );
						} elseif ( true === $odsi_value ) {
							esc_html_e( 'Yes', 'odsi-social' );
						} elseif ( 'url' === $odsi_field['type'] ) {
							printf( '<a href="%1$s" rel="nofollow">%1$s</a>', esc_url( (string) $odsi_value ) );
						} elseif ( 'textarea' === $odsi_field['type'] ) {
							// Escaped first; wpautop only adds paragraph and break tags.
							echo wp_kses_post( wpautop( esc_html( (string) $odsi_value ) ) );
						} else {
							echo esc_html( (string) $odsi_value );
						}
						?>
					</dd>
				<?php endforeach; ?>
			</dl>
		</section>
	<?php endforeach; ?>

	<section class="odsi-social-profile__feed">
		<?php echo $feed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output. ?>
	</section>
</div>
