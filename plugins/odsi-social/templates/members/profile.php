<?php
/**
 * Member profile. The page's heading (the member's name) is printed by the
 * theme from the page title; this template starts its own sections at h2.
 *
 * @var array<string, mixed> $profile      Profile.
 * @var int                  $viewer_id    Viewer.
 * @var string               $feed         Rendered profile feed.
 * @var bool                 $is_following Whether the viewer follows this member.
 * @var bool                 $can_moderate Whether the viewer may block or report this member (never an admin).
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_uid     = (int) $profile['id'];
$odsi_name    = (string) $profile['name'];
$can_moderate = ! empty( $can_moderate );
$odsi_self    = ! empty( $profile['viewer']['is_self'] );
?>
<div class="odsi-social-profile" data-user-id="<?php echo esc_attr( (string) $odsi_uid ); ?>">
	<section class="odsi-social-hero odsi-social-hero--profile" aria-label="<?php esc_attr_e( 'Profile summary', 'odsi-social' ); ?>">
		<?php if ( '' !== $profile['cover'] ) : ?>
			<div class="odsi-social-hero__cover" style="background-image: url('<?php echo esc_url( (string) $profile['cover'] ); ?>')"></div>
		<?php endif; ?>
		<div class="odsi-social-hero__header">
			<img class="odsi-social-avatar odsi-social-avatar--large odsi-social-hero__avatar" src="<?php echo esc_url( (string) $profile['avatar'] ); ?>" alt="<?php echo esc_attr( $odsi_name ); ?>" width="128" height="128" />
			<div class="odsi-social-hero__body">
				<p class="odsi-social-hero__name"><?php echo esc_html( $odsi_name ); ?></p>
				<p class="odsi-social-hero__counts">
					<span><?php echo esc_html( sprintf( /* translators: %d: count. */ _n( '%d connection', '%d connections', (int) $profile['counts']['connections'], 'odsi-social' ), (int) $profile['counts']['connections'] ) ); ?></span>
					<span><?php echo esc_html( sprintf( /* translators: %d: count. */ _n( '%d follower', '%d followers', (int) $profile['counts']['followers'], 'odsi-social' ), (int) $profile['counts']['followers'] ) ); ?></span>
				</p>

				<?php if ( $viewer_id > 0 && ( $odsi_self || \ODSI\Social\Support\Capabilities::is_admin( $viewer_id ) ) ) : ?>
					<div class="odsi-social-hero__actions">
						<a class="odsi-social-button odsi-social-hero__edit" href="<?php echo esc_url( (string) apply_filters( 'odsi_social_page_url', trailingslashit( (string) $profile['url'] ) . 'edit/', 'members', (string) $profile['nicename'], 'edit' ) ); ?>"><?php esc_html_e( 'Edit profile', 'odsi-social' ); ?></a>
					</div>
				<?php endif; ?>

				<?php if ( $viewer_id > 0 && ! $odsi_self ) : ?>
					<div class="odsi-social-hero__actions">
						<?php
						$odsi_status = (string) $profile['viewer']['connection'];
						$odsi_label  = match ( $odsi_status ) {
							'accepted'         => __( 'Remove connection', 'odsi-social' ),
							'pending_sent'     => __( 'Withdraw request', 'odsi-social' ),
							'pending_received' => __( 'Accept request', 'odsi-social' ),
							default            => __( 'Connect', 'odsi-social' ),
						};
	?>
						<button type="button" class="odsi-social-button odsi-social-hero__connect" aria-pressed="<?php echo 'accepted' === $odsi_status ? 'true' : 'false'; ?>" data-user-id="<?php echo esc_attr( (string) $odsi_uid ); ?>" data-status="<?php echo esc_attr( $odsi_status ); ?>"><?php echo esc_html( $odsi_label ); ?></button>
						<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-hero__follow<?php echo $is_following ? ' is-active' : ''; ?>" aria-pressed="<?php echo $is_following ? 'true' : 'false'; ?>" data-user-id="<?php echo esc_attr( (string) $odsi_uid ); ?>"><?php echo $is_following ? esc_html__( 'Unfollow', 'odsi-social' ) : esc_html__( 'Follow', 'odsi-social' ); ?></button>
						<a class="odsi-social-button odsi-social-button--quiet odsi-social-hero__message" href="<?php echo esc_url( add_query_arg( 'to', $odsi_uid, (string) apply_filters( 'odsi_social_page_url', home_url( '/messages/' ), 'messages', '', '' ) ) ); ?>"><?php esc_html_e( 'Message', 'odsi-social' ); ?></a>
						<?php if ( $can_moderate ) : ?>
							<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-hero__block" data-user-id="<?php echo esc_attr( (string) $odsi_uid ); ?>"><?php esc_html_e( 'Block', 'odsi-social' ); ?></button>
							<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-hero__report" data-object-type="member" data-object-id="<?php echo esc_attr( (string) $odsi_uid ); ?>"><?php esc_html_e( 'Report', 'odsi-social' ); ?></button>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<?php foreach ( (array) $profile['field_groups'] as $odsi_group ) : ?>
		<section class="odsi-social-profile__fields">
			<h2 class="odsi-social-profile__fields-title"><?php echo esc_html( (string) $odsi_group['group'] ); ?></h2>
			<dl class="odsi-social-profile__field-list">
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
		<h2 class="odsi-social-profile__feed-title"><?php esc_html_e( 'Activity', 'odsi-social' ); ?></h2>
		<?php echo $feed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output. ?>
	</section>
</div>
