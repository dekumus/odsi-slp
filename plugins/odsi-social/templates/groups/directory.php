<?php
/**
 * Group directory.
 *
 * @var array<int, array<string, mixed>> $groups     Groups.
 * @var int                              $total      Total.
 * @var array<string, mixed>             $args       Args.
 * @var bool                             $can_create Whether the viewer may create a group.
 * @var array<string, array<int, array<string, mixed>>> $mine The viewer's own groups: `active`, `pending`, `invited` (SOC-GRP-010).
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$mine = isset( $mine ) && is_array( $mine ) ? $mine : array();

/**
 * One card in a list of groups.
 *
 * @param array<string, mixed> $group Presented group.
 */
$odsi_group_card = static function ( array $group ): void {
	?>
	<li class="odsi-social-card">
		<a href="<?php echo esc_url( (string) $group['url'] ); ?>">
			<?php if ( '' !== $group['avatar'] ) : ?>
				<img class="odsi-social-avatar" src="<?php echo esc_url( (string) $group['avatar'] ); ?>" alt="" width="64" height="64" />
			<?php endif; ?>
			<strong><?php echo esc_html( (string) $group['name'] ); ?></strong>
		</a>
		<span class="odsi-social-card__meta">
			<?php echo esc_html( ucfirst( (string) $group['visibility'] ) ); ?> ·
			<?php echo esc_html( sprintf( /* translators: %d: members. */ _n( '%d member', '%d members', (int) $group['member_count'], 'odsi-social' ), (int) $group['member_count'] ) ); ?>
		</span>
	</li>
	<?php
};
?>
<div class="odsi-social-directory odsi-social-directory--groups">
	<?php if ( array_filter( $mine ) ) : ?>
		<section class="odsi-social-directory__mine">
			<h3><?php esc_html_e( 'Your groups', 'odsi-social' ); ?></h3>
			<?php
			foreach ( array(
				'active'  => __( 'Member of', 'odsi-social' ),
				'pending' => __( 'Requests awaiting approval', 'odsi-social' ),
				'invited' => __( 'Invitations', 'odsi-social' ),
			) as $odsi_status => $odsi_label ) :
				if ( empty( $mine[ $odsi_status ] ) ) {
					continue;
				}
				?>
				<h4><?php echo esc_html( $odsi_label ); ?></h4>
				<ul class="odsi-social-cards odsi-social-cards--<?php echo esc_attr( $odsi_status ); ?>">
					<?php foreach ( $mine[ $odsi_status ] as $odsi_group ) : ?>
						<?php $odsi_group_card( $odsi_group ); ?>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<form class="odsi-social-directory__filters" method="get">
		<input type="search" name="search" value="<?php echo esc_attr( (string) $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search groups', 'odsi-social' ); ?>" />
		<select name="orderby">
			<?php
			foreach ( array(
				'newest'  => __( 'Newest', 'odsi-social' ),
				'members' => __( 'Most members', 'odsi-social' ),
				'active'  => __( 'Recently active', 'odsi-social' ),
			) as $odsi_key => $odsi_label ) :
				?>
				<option value="<?php echo esc_attr( $odsi_key ); ?>" <?php selected( $args['orderby'], $odsi_key ); ?>><?php echo esc_html( $odsi_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="odsi-social-button"><?php esc_html_e( 'Filter', 'odsi-social' ); ?></button>
	</form>

	<?php if ( $can_create ) : ?>
		<form class="odsi-social-create-group">
			<input type="text" name="name" required placeholder="<?php esc_attr_e( 'New group name', 'odsi-social' ); ?>" />
			<select name="visibility">
				<option value="public"><?php esc_html_e( 'Public', 'odsi-social' ); ?></option>
				<option value="private"><?php esc_html_e( 'Private', 'odsi-social' ); ?></option>
				<option value="hidden"><?php esc_html_e( 'Hidden', 'odsi-social' ); ?></option>
			</select>
			<button type="submit" class="odsi-social-button"><?php esc_html_e( 'Create group', 'odsi-social' ); ?></button>
		</form>
	<?php endif; ?>

	<ul class="odsi-social-cards">
		<?php foreach ( $groups as $group ) : ?>
			<?php $odsi_group_card( $group ); ?>
		<?php endforeach; ?>
	</ul>

	<?php if ( empty( $groups ) ) : ?>
		<p class="odsi-social-feed__empty"><?php esc_html_e( 'No groups found.', 'odsi-social' ); ?></p>
	<?php endif; ?>

	<?php
	echo wp_kses_post(
		(string) paginate_links(
			array(
				'total'   => (int) ceil( (int) $total / max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
				'current' => max( 1, (int) ( $args['page'] ?? 1 ) ),
				'base'    => add_query_arg( 'paged', '%#%' ),
			)
		)
	);
	?>
</div>
