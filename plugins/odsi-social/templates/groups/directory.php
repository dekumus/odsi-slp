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

$odsi_templates = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
$mine           = isset( $mine ) && is_array( $mine ) ? $mine : array();
$odsi_has_mine  = count( array_filter( $mine ) ) > 0;
$odsi_id        = wp_unique_id( 'odsi-social-groups-' );
$odsi_search    = (string) $args['search'];

/**
 * One card in a list of groups.
 *
 * @param array<string, mixed> $group Presented group.
 */
$odsi_group_card = static function ( array $group ): void {
	?>
	<li class="odsi-social-card">
		<a class="odsi-social-card__link" href="<?php echo esc_url( (string) $group['url'] ); ?>">
			<?php if ( '' !== $group['avatar'] ) : ?>
				<img class="odsi-social-avatar odsi-social-card__avatar" src="<?php echo esc_url( (string) $group['avatar'] ); ?>" alt="" width="64" height="64" loading="lazy" />
			<?php endif; ?>
			<span class="odsi-social-card__name"><?php echo esc_html( (string) $group['name'] ); ?></span>
		</a>
		<span class="odsi-social-card__meta">
			<?php echo esc_html( \ODSI\Social\Support\Labels::visibility( (string) $group['visibility'] ) ); ?> &middot;
			<?php echo esc_html( sprintf( /* translators: %d: members. */ _n( '%d member', '%d members', (int) $group['member_count'], 'odsi-social' ), (int) $group['member_count'] ) ); ?>
		</span>
	</li>
	<?php
};
?>
<div class="odsi-social-directory odsi-social-directory--groups">
	<?php if ( $odsi_has_mine ) : ?>
		<section class="odsi-social-directory__mine">
			<h2 class="odsi-social-directory__title"><?php esc_html_e( 'Your groups', 'odsi-social' ); ?></h2>
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
				<h3 class="odsi-social-directory__subtitle"><?php echo esc_html( $odsi_label ); ?></h3>
				<ul class="odsi-social-cards odsi-social-cards--<?php echo esc_attr( $odsi_status ); ?>">
					<?php foreach ( $mine[ $odsi_status ] as $odsi_group ) : ?>
						<?php $odsi_group_card( $odsi_group ); ?>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<?php if ( $can_create ) : ?>
		<form class="odsi-social-create-group" aria-labelledby="<?php echo esc_attr( $odsi_id . '-create' ); ?>">
			<h2 class="odsi-social-create-group__title" id="<?php echo esc_attr( $odsi_id . '-create' ); ?>"><?php esc_html_e( 'Create a group', 'odsi-social' ); ?></h2>
			<div class="odsi-social-create-group__fields">
				<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_id . '-name' ); ?>"><?php esc_html_e( 'Group name', 'odsi-social' ); ?></label>
				<input id="<?php echo esc_attr( $odsi_id . '-name' ); ?>" class="odsi-social-create-group__name" type="text" name="name" required maxlength="200" placeholder="<?php esc_attr_e( 'New group name', 'odsi-social' ); ?>" />
				<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_id . '-visibility' ); ?>"><?php esc_html_e( 'Visibility', 'odsi-social' ); ?></label>
				<select id="<?php echo esc_attr( $odsi_id . '-visibility' ); ?>" class="odsi-social-create-group__visibility" name="visibility">
					<option value="public"><?php esc_html_e( 'Public — anyone can see and join', 'odsi-social' ); ?></option>
					<option value="private"><?php esc_html_e( 'Private — listed, members approved', 'odsi-social' ); ?></option>
					<option value="hidden"><?php esc_html_e( 'Hidden — invitation only', 'odsi-social' ); ?></option>
				</select>
				<button type="submit" class="odsi-social-button odsi-social-create-group__submit"><?php esc_html_e( 'Create group', 'odsi-social' ); ?></button>
			</div>
			<p class="odsi-social-create-group__error odsi-social-error" role="alert" hidden></p>
		</form>
	<?php endif; ?>

	<?php if ( $odsi_has_mine || $can_create ) : ?>
		<h2 class="odsi-social-directory__title"><?php esc_html_e( 'All groups', 'odsi-social' ); ?></h2>
	<?php endif; ?>

	<form class="odsi-social-directory__filters" method="get" role="search" aria-label="<?php esc_attr_e( 'Search groups', 'odsi-social' ); ?>">
		<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_id . '-search' ); ?>"><?php esc_html_e( 'Search groups', 'odsi-social' ); ?></label>
		<input id="<?php echo esc_attr( $odsi_id . '-search' ); ?>" class="odsi-social-directory__search" type="search" name="search" value="<?php echo esc_attr( $odsi_search ); ?>" placeholder="<?php esc_attr_e( 'Search groups', 'odsi-social' ); ?>" />
		<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_id . '-orderby' ); ?>"><?php esc_html_e( 'Sort by', 'odsi-social' ); ?></label>
		<select id="<?php echo esc_attr( $odsi_id . '-orderby' ); ?>" class="odsi-social-directory__orderby" name="orderby">
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
		<button type="submit" class="odsi-social-button odsi-social-directory__submit"><?php esc_html_e( 'Filter', 'odsi-social' ); ?></button>
	</form>

	<p class="odsi-social-directory__count">
		<?php echo esc_html( sprintf( /* translators: %d: group count. */ _n( '%d group', '%d groups', (int) $total, 'odsi-social' ), (int) $total ) ); ?>
	</p>

	<?php if ( count( $groups ) > 0 ) : ?>
		<ul class="odsi-social-cards">
			<?php foreach ( $groups as $group ) : ?>
				<?php $odsi_group_card( $group ); ?>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<div class="odsi-social-directory__empty">
			<?php
			if ( '' !== $odsi_search ) {
				$odsi_empty = array(
					'text'  => __( 'No groups match your search.', 'odsi-social' ),
					'url'   => remove_query_arg( array( 'search', 'paged' ) ),
					'label' => __( 'Clear the search', 'odsi-social' ),
				);
			} elseif ( $can_create ) {
				$odsi_empty = array(
					'text'  => __( 'No groups yet. Create the first one above.', 'odsi-social' ),
					'url'   => '',
					'label' => '',
				);
			} else {
				$odsi_empty = array(
					'text'  => __( 'No groups yet.', 'odsi-social' ),
					'url'   => '',
					'label' => '',
				);
			}

			echo $odsi_templates->render( 'parts/empty', $odsi_empty ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output.
			?>
		</div>
	<?php endif; ?>

	<?php
	$odsi_html = $odsi_templates->render(
		'parts/pagination',
		array(
			'total'    => (int) $total,
			'per_page' => (int) ( $args['per_page'] ?? 20 ),
			'page'     => (int) ( $args['page'] ?? 1 ),
			'label'    => __( 'Groups pages', 'odsi-social' ),
		)
	);
	echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
	?>
</div>
