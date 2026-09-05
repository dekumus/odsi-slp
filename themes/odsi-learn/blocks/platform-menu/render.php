<?php
/**
 * Server render of the platform menu block.
 *
 * Variables in scope: $attributes, $content, $block.
 *
 * @package odsi-learn
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$odsi_learn_variant = isset( $attributes['variant'] ) && in_array( $attributes['variant'], array( 'header', 'footer', 'inline' ), true ) ? $attributes['variant'] : 'header';
$odsi_learn_items   = odsi_learn_platform_menu_items( ! empty( $attributes['showAccount'] ) );

if ( array() === $odsi_learn_items ) {
	return;
}

$odsi_learn_wrapper = get_block_wrapper_attributes(
	array(
		'class'      => 'odsi-learn-menu odsi-learn-menu--' . $odsi_learn_variant,
		'aria-label' => 'header' === $odsi_learn_variant ? __( 'Platform', 'odsi-learn' ) : __( 'Platform links', 'odsi-learn' ),
	)
);
?>
<nav <?php echo $odsi_learn_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by core. ?>>
	<ul class="odsi-learn-menu__list">
		<?php foreach ( $odsi_learn_items as $odsi_learn_item ) : ?>
			<li class="odsi-learn-menu__item odsi-learn-menu__item--<?php echo esc_attr( $odsi_learn_item['key'] ); ?>">
				<a class="odsi-learn-menu__link" href="<?php echo esc_url( $odsi_learn_item['url'] ); ?>"<?php echo ! empty( $odsi_learn_item['current'] ) ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $odsi_learn_item['label'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
