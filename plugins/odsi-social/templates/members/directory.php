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

$odsi_templates = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
$odsi_id        = wp_unique_id( 'odsi-social-members-' );
$odsi_search    = (string) $args['search'];
?>
<div class="odsi-social-directory odsi-social-directory--members">
	<form class="odsi-social-directory__filters" method="get" role="search" aria-label="<?php esc_attr_e( 'Search members', 'odsi-social' ); ?>">
		<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_id . '-search' ); ?>"><?php esc_html_e( 'Search members', 'odsi-social' ); ?></label>
		<input id="<?php echo esc_attr( $odsi_id . '-search' ); ?>" class="odsi-social-directory__search" type="search" name="search" value="<?php echo esc_attr( $odsi_search ); ?>" placeholder="<?php esc_attr_e( 'Search members', 'odsi-social' ); ?>" />
		<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_id . '-orderby' ); ?>"><?php esc_html_e( 'Sort by', 'odsi-social' ); ?></label>
		<select id="<?php echo esc_attr( $odsi_id . '-orderby' ); ?>" class="odsi-social-directory__orderby" name="orderby">
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
		<button type="submit" class="odsi-social-button odsi-social-directory__submit"><?php esc_html_e( 'Filter', 'odsi-social' ); ?></button>
	</form>

	<p class="odsi-social-directory__count">
		<?php echo esc_html( sprintf( /* translators: %d: member count. */ _n( '%d member', '%d members', $total, 'odsi-social' ), $total ) ); ?>
	</p>

	<?php if ( count( $members ) > 0 ) : ?>
		<ul class="odsi-social-cards">
			<?php foreach ( $members as $member ) : ?>
				<li class="odsi-social-card">
					<a class="odsi-social-card__link" href="<?php echo esc_url( (string) $member['url'] ); ?>">
						<img class="odsi-social-avatar odsi-social-card__avatar" src="<?php echo esc_url( (string) $member['avatar'] ); ?>" alt="" width="64" height="64" loading="lazy" />
						<span class="odsi-social-card__name"><?php echo esc_html( (string) $member['name'] ); ?></span>
					</a>
					<span class="odsi-social-card__meta">
						<?php echo esc_html( sprintf( /* translators: %d: connections. */ _n( '%d connection', '%d connections', (int) $member['counts']['connections'], 'odsi-social' ), (int) $member['counts']['connections'] ) ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<div class="odsi-social-directory__empty">
			<?php
			$odsi_html = $odsi_templates->render(
				'parts/empty',
				'' !== $odsi_search ? array(
					'text'  => __( 'No members match your search.', 'odsi-social' ),
					'url'   => remove_query_arg( array( 'search', 'paged' ) ),
					'label' => __( 'Clear the search', 'odsi-social' ),
				) : array(
					'text'  => __( 'No members yet.', 'odsi-social' ),
					'url'   => '',
					'label' => '',
				)
			);
			echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
			?>
		</div>
	<?php endif; ?>

	<?php
	$odsi_html = $odsi_templates->render(
		'parts/pagination',
		array(
			'total'    => (int) $total,
			'per_page' => (int) $per_page,
			'page'     => (int) $page,
			'label'    => __( 'Members pages', 'odsi-social' ),
		)
	);
	echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
	?>
</div>
