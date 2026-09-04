<?php
/**
 * Group directory.
 *
 * @var array<int, array<string, mixed>> $groups     Groups.
 * @var int                              $total      Total.
 * @var array<string, mixed>             $args       Args.
 * @var bool                             $can_create Whether the viewer may create a group.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="odsi-social-directory odsi-social-directory--groups">
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
		<?php endforeach; ?>
	</ul>

	<?php if ( empty( $groups ) ) : ?>
		<p class="odsi-social-feed__empty"><?php esc_html_e( 'No groups found.', 'odsi-social' ); ?></p>
	<?php endif; ?>
</div>
