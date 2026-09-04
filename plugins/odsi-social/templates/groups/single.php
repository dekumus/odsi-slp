<?php
/**
 * Single group.
 *
 * @var array<string, mixed> $group            Group.
 * @var int                  $viewer_id        Viewer.
 * @var bool                 $can_view_content Whether the feed and member list are visible.
 * @var bool                 $is_moderator     Whether the viewer moderates.
 * @var array<int, array<string, mixed>> $members First active members (empty when content is hidden).
 * @var string               $feed             Rendered feed.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_status = (string) $group['viewer']['status'];
$members     = isset( $members ) && is_array( $members ) ? $members : array();
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
						<a class="odsi-social-button odsi-social-group__manage" href="<?php echo esc_url( (string) apply_filters( 'odsi_social_page_url', trailingslashit( (string) $group['url'] ) . 'manage/', 'groups', (string) $group['slug'], 'manage' ) ); ?>"><?php esc_html_e( 'Manage group', 'odsi-social' ); ?></a>
					<?php endif; ?>
					<?php if ( 'active' === $odsi_status ) : ?>
						<button type="button" class="odsi-social-button odsi-social-membership" data-group-id="<?php echo esc_attr( (string) $group['id'] ); ?>" data-action="leave"><?php esc_html_e( 'Leave group', 'odsi-social' ); ?></button>
					<?php elseif ( 'pending' === $odsi_status ) : ?>
						<button type="button" class="odsi-social-button odsi-social-membership" data-group-id="<?php echo esc_attr( (string) $group['id'] ); ?>" data-action="leave"><?php esc_html_e( 'Withdraw request', 'odsi-social' ); ?></button>
					<?php elseif ( 'invited' === $odsi_status ) : ?>
						<button type="button" class="odsi-social-button odsi-social-membership" data-group-id="<?php echo esc_attr( (string) $group['id'] ); ?>" data-action="join"><?php esc_html_e( 'Accept invitation', 'odsi-social' ); ?></button>
						<button type="button" class="odsi-social-button odsi-social-membership" data-group-id="<?php echo esc_attr( (string) $group['id'] ); ?>" data-action="leave"><?php esc_html_e( 'Decline', 'odsi-social' ); ?></button>
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
		<?php if ( count( $members ) > 0 ) : ?>
			<section class="odsi-social-group__members">
				<h3><?php esc_html_e( 'Members', 'odsi-social' ); ?></h3>
				<ul class="odsi-social-member-list">
					<?php foreach ( $members as $odsi_member ) : ?>
						<li class="odsi-social-member-list__item">
							<img class="odsi-social-avatar" src="<?php echo esc_url( (string) $odsi_member['avatar'] ); ?>" alt="" width="32" height="32" />
							<a class="odsi-social-member-list__name" href="<?php echo esc_url( (string) $odsi_member['url'] ); ?>"><?php echo esc_html( (string) $odsi_member['name'] ); ?></a>
							<?php if ( 'member' !== $odsi_member['role'] ) : ?>
								<span class="odsi-social-member-list__role"><?php echo esc_html( ucfirst( (string) $odsi_member['role'] ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		<section class="odsi-social-group__feed"><?php echo $feed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
	<?php else : ?>
		<p class="odsi-social-notice"><?php esc_html_e( 'Join this group to see its activity.', 'odsi-social' ); ?></p>
	<?php endif; ?>
</div>
