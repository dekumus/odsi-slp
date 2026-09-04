<?php
/**
 * Member directory.
 *
 * @var array<int, array<string, mixed>> $members  Members.
 * @var int                              $total    Total.
 * @var int                              $page     Page.
 * @var int                              $per_page Page size.
 * @var array<string, mixed>             $args     Query args.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="odsi-social-directory">
	<form class="odsi-social-directory__filters" method="get">
		<input type="search" name="search" value="<?php echo esc_attr( (string) $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search members', 'odsi-social' ); ?>" />
		<select name="orderby">
			<?php
			foreach ( array(
				'newest'       => __( 'Newest', 'odsi-social' ),
				'active'       => __( 'Recently active', 'odsi-social' ),
				'alphabetical' => __( 'Alphabetical', 'odsi-social' ),
			) as $odsi_key => $odsi_label ) :
				?>
				<option value="<?php echo esc_attr( $odsi_key ); ?>" <?php selected( $args['orderby'], $odsi_key ); ?>><?php echo esc_html( $odsi_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="odsi-social-button"><?php esc_html_e( 'Filter', 'odsi-social' ); ?></button>
	</form>

	<p class="odsi-social-directory__count">
		<?php echo esc_html( sprintf( /* translators: %d: member count. */ _n( '%d member', '%d members', $total, 'odsi-social' ), $total ) ); ?>
	</p>

	<ul class="odsi-social-cards">
		<?php foreach ( $members as $member ) : ?>
			<li class="odsi-social-card">
				<a href="<?php echo esc_url( (string) $member['url'] ); ?>">
					<img class="odsi-social-avatar" src="<?php echo esc_url( (string) $member['avatar'] ); ?>" alt="" width="64" height="64" />
					<strong><?php echo esc_html( (string) $member['name'] ); ?></strong>
				</a>
				<span class="odsi-social-card__meta">
					<?php echo esc_html( sprintf( /* translators: %d: connections. */ _n( '%d connection', '%d connections', (int) $member['counts']['connections'], 'odsi-social' ), (int) $member['counts']['connections'] ) ); ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php
	echo wp_kses_post(
		(string) paginate_links(
			array(
				'total'   => (int) ceil( $total / max( 1, $per_page ) ),
				'current' => $page,
				'base'    => add_query_arg( 'paged', '%#%' ),
			)
		)
	);
	?>
</div>
