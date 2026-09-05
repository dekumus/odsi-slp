<?php
/**
 * Single group. The page heading (the group's name) is printed by the
 * theme; sections here start at h2.
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

$odsi_status    = (string) $group['viewer']['status'];
$odsi_gid       = (int) $group['id'];
$members        = isset( $members ) && is_array( $members ) ? $members : array();
$odsi_templates = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
$odsi_locked    = match ( $odsi_status ) {
	'pending' => __( 'Your request to join is awaiting approval. You will see the group’s activity once an organiser approves it.', 'odsi-social' ),
	'invited' => __( 'You have been invited to this group. Accept the invitation to see its activity.', 'odsi-social' ),
	'banned'  => __( 'You cannot see this group’s activity.', 'odsi-social' ),
	default   => __( 'Join this group to see its activity and members.', 'odsi-social' ),
};
?>
<div class="odsi-social-group" data-group-id="<?php echo esc_attr( (string) $odsi_gid ); ?>">
	<section class="odsi-social-hero odsi-social-hero--group" aria-label="<?php esc_attr_e( 'Group summary', 'odsi-social' ); ?>">
		<?php if ( '' !== $group['cover'] ) : ?>
			<div class="odsi-social-hero__cover" style="background-image: url('<?php echo esc_url( (string) $group['cover'] ); ?>')"></div>
		<?php endif; ?>
		<div class="odsi-social-hero__header">
			<?php if ( '' !== $group['avatar'] ) : ?>
				<img class="odsi-social-avatar odsi-social-avatar--large odsi-social-hero__avatar" src="<?php echo esc_url( (string) $group['avatar'] ); ?>" alt="<?php echo esc_attr( (string) $group['name'] ); ?>" width="128" height="128" />
			<?php endif; ?>
			<div class="odsi-social-hero__body">
				<p class="odsi-social-hero__name"><?php echo esc_html( (string) $group['name'] ); ?></p>
				<p class="odsi-social-hero__counts">
					<span><?php echo esc_html( \ODSI\Social\Support\Labels::visibility( (string) $group['visibility'] ) ); ?></span>
					<span><?php echo esc_html( sprintf( /* translators: %d: members. */ _n( '%d member', '%d members', (int) $group['member_count'], 'odsi-social' ), (int) $group['member_count'] ) ); ?></span>
				</p>
				<?php if ( $viewer_id > 0 ) : ?>
					<div class="odsi-social-hero__actions">
						<?php if ( 'organiser' === $group['viewer']['role'] || \ODSI\Social\Support\Capabilities::is_admin( $viewer_id ) ) : ?>
							<a class="odsi-social-button odsi-social-hero__manage" href="<?php echo esc_url( (string) apply_filters( 'odsi_social_page_url', trailingslashit( (string) $group['url'] ) . 'manage/', 'groups', (string) $group['slug'], 'manage' ) ); ?>"><?php esc_html_e( 'Manage group', 'odsi-social' ); ?></a>
						<?php endif; ?>
						<?php if ( 'active' === $odsi_status ) : ?>
							<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-hero__membership" data-group-id="<?php echo esc_attr( (string) $odsi_gid ); ?>" data-membership="leave"><?php esc_html_e( 'Leave group', 'odsi-social' ); ?></button>
						<?php elseif ( 'pending' === $odsi_status ) : ?>
							<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-hero__membership" data-group-id="<?php echo esc_attr( (string) $odsi_gid ); ?>" data-membership="leave"><?php esc_html_e( 'Withdraw request', 'odsi-social' ); ?></button>
						<?php elseif ( 'invited' === $odsi_status ) : ?>
							<button type="button" class="odsi-social-button odsi-social-hero__membership" data-group-id="<?php echo esc_attr( (string) $odsi_gid ); ?>" data-membership="join"><?php esc_html_e( 'Accept invitation', 'odsi-social' ); ?></button>
							<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-hero__membership" data-group-id="<?php echo esc_attr( (string) $odsi_gid ); ?>" data-membership="leave"><?php esc_html_e( 'Decline', 'odsi-social' ); ?></button>
						<?php elseif ( 'banned' !== $odsi_status ) : ?>
							<button type="button" class="odsi-social-button odsi-social-hero__membership" data-group-id="<?php echo esc_attr( (string) $odsi_gid ); ?>" data-membership="join"><?php echo 'public' === $group['visibility'] ? esc_html__( 'Join group', 'odsi-social' ) : esc_html__( 'Request to join', 'odsi-social' ); ?></button>
						<?php endif; ?>
						<?php if ( 'organiser' !== $group['viewer']['role'] ) : ?>
							<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-hero__report" data-object-type="group" data-object-id="<?php echo esc_attr( (string) $odsi_gid ); ?>"><?php esc_html_e( 'Report', 'odsi-social' ); ?></button>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<?php if ( '' !== trim( (string) $group['description'] ) ) : ?>
		<div class="odsi-social-group__description"><?php echo wp_kses_post( (string) $group['description'] ); ?></div>
	<?php endif; ?>

	<?php if ( $can_view_content ) : ?>
		<?php if ( count( $members ) > 0 ) : ?>
			<section class="odsi-social-group__members">
				<h2 class="odsi-social-group__members-title"><?php esc_html_e( 'Members', 'odsi-social' ); ?></h2>
				<ul class="odsi-social-member-list">
					<?php foreach ( $members as $odsi_member ) : ?>
						<li class="odsi-social-member-list__item">
							<img class="odsi-social-avatar odsi-social-avatar--small" src="<?php echo esc_url( (string) $odsi_member['avatar'] ); ?>" alt="" width="32" height="32" loading="lazy" />
							<a class="odsi-social-member-list__name" href="<?php echo esc_url( (string) $odsi_member['url'] ); ?>"><?php echo esc_html( (string) $odsi_member['name'] ); ?></a>
							<?php if ( 'member' !== $odsi_member['role'] ) : ?>
								<span class="odsi-social-member-list__role"><?php echo esc_html( \ODSI\Social\Support\Labels::role( (string) $odsi_member['role'] ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		<section class="odsi-social-group__feed">
			<h2 class="odsi-social-group__feed-title"><?php esc_html_e( 'Activity', 'odsi-social' ); ?></h2>
			<?php echo $feed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output. ?>
		</section>
	<?php else : ?>
		<div class="odsi-social-notice odsi-social-notice--locked odsi-social-group__locked">
			<p class="odsi-social-notice__text"><?php echo esc_html( $odsi_locked ); ?></p>
			<?php if ( $viewer_id <= 0 ) : ?>
				<a class="odsi-social-button odsi-social-notice__action" href="<?php echo esc_url( wp_login_url( (string) $group['url'] ) ); ?>"><?php esc_html_e( 'Log in', 'odsi-social' ); ?></a>
			<?php endif; ?>
		</div>
		<?php if ( $viewer_id > 0 ) : ?>
			<?php echo $odsi_templates->render( 'parts/report-form', array( 'viewer_id' => $viewer_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output. ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
