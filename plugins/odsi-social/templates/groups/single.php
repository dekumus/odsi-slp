<?php
/**
 * Single group.
 *
 * @var array<string, mixed> $group            Group.
 * @var int                  $viewer_id        Viewer.
 * @var bool                 $can_view_content Whether the feed and member list are visible.
 * @var bool                 $is_moderator     Whether the viewer moderates.
 * @var string               $feed             Rendered feed.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_status = (string) $group['viewer']['status'];
?>
<div class="odsi-social-group" data-group-id="<?php echo esc_attr( (string) $group['id'] ); ?>">
	<?php if ( '' !== $group['cover'] ) : ?>
		<div class="odsi-social-profile__cover" style="background-image: url('<?php echo esc_url( (string) $group['cover'] ); ?>')"></div>
	<?php endif; ?>

	<header class="odsi-social-profile__header">
		<?php if ( '' !== $group['avatar'] ) : ?>
			<img class="odsi-social-avatar odsi-social-avatar--large" src="<?php echo esc_url( (string) $group['avatar'] ); ?>" alt="" width="128" height="128" />
		<?php endif; ?>
		<div>
			<h2><?php echo esc_html( (string) $group['name'] ); ?></h2>
			<p class="odsi-social-profile__counts">
				<?php echo esc_html( ucfirst( (string) $group['visibility'] ) ); ?> ·
				<?php echo esc_html( sprintf( /* translators: %d: members. */ _n( '%d member', '%d members', (int) $group['member_count'], 'odsi-social' ), (int) $group['member_count'] ) ); ?>
			</p>
			<?php if ( $viewer_id > 0 ) : ?>
				<div class="odsi-social-profile__actions">
					<?php if ( 'organiser' === $group['viewer']['role'] || \ODSI\Social\Support\Capabilities::is_admin( $viewer_id ) ) : ?>
						<a class="odsi-social-button odsi-social-group__manage" href="<?php echo esc_url( trailingslashit( (string) $group['url'] ) . 'manage/' ); ?>"><?php esc_html_e( 'Manage group', 'odsi-social' ); ?></a>
					<?php endif; ?>
					<?php if ( 'active' === $odsi_status ) : ?>
						<button type="button" class="odsi-social-button odsi-social-membership" data-group-id="<?php echo esc_attr( (string) $group['id'] ); ?>" data-action="leave"><?php esc_html_e( 'Leave group', 'odsi-social' ); ?></button>
					<?php elseif ( 'pending' === $odsi_status ) : ?>
						<button type="button" class="odsi-social-button odsi-social-membership" data-group-id="<?php echo esc_attr( (string) $group['id'] ); ?>" data-action="leave"><?php esc_html_e( 'Withdraw request', 'odsi-social' ); ?></button>
					<?php elseif ( 'invited' === $odsi_status ) : ?>
						<button type="button" class="odsi-social-button odsi-social-membership" data-group-id="<?php echo esc_attr( (string) $group['id'] ); ?>" data-action="join"><?php esc_html_e( 'Accept invitation', 'odsi-social' ); ?></button>
					<?php elseif ( 'banned' !== $odsi_status ) : ?>
						<button type="button" class="odsi-social-button odsi-social-membership" data-group-id="<?php echo esc_attr( (string) $group['id'] ); ?>" data-action="join">
							<?php echo 'public' === $group['visibility'] ? esc_html__( 'Join group', 'odsi-social' ) : esc_html__( 'Request to join', 'odsi-social' ); ?>
						</button>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</header>

	<div class="odsi-social-group__description"><?php echo wp_kses_post( (string) $group['description'] ); ?></div>

	<?php if ( $can_view_content ) : ?>
		<section class="odsi-social-group__feed"><?php echo $feed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
	<?php else : ?>
		<p class="odsi-social-notice"><?php esc_html_e( 'Join this group to see its activity.', 'odsi-social' ); ?></p>
	<?php endif; ?>
</div>
