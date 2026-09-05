<?php
/**
 * Single activity item with every comment and who liked it.
 *
 * @var array<string, mixed> $item      Item with every comment.
 * @var int                  $viewer_id Viewer.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_templates = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
?>
<div class="odsi-social-feed odsi-social-feed--single" data-scope="single">
	<div class="odsi-social-feed__status odsi-social-visually-hidden" role="status" aria-live="polite"></div>
	<ul class="odsi-social-feed__items">
		<?php
		$odsi_html = $odsi_templates->render(
			'parts/activity-item',
			array(
				'item'      => $item,
				'viewer_id' => $viewer_id,
			)
		);
		echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
		?>
	</ul>
	<?php if ( ! empty( $item['reactors'] ) ) : ?>
		<section class="odsi-social-reactors">
			<h2 class="odsi-social-reactors__title"><?php esc_html_e( 'Liked by', 'odsi-social' ); ?></h2>
			<ul class="odsi-social-member-list">
				<?php foreach ( (array) $item['reactors'] as $odsi_reactor ) : ?>
					<li class="odsi-social-member-list__item">
						<img class="odsi-social-avatar odsi-social-avatar--small" src="<?php echo esc_url( (string) $odsi_reactor['avatar'] ); ?>" alt="" width="32" height="32" loading="lazy" />
						<a class="odsi-social-member-list__name" href="<?php echo esc_url( (string) $odsi_reactor['url'] ); ?>"><?php echo esc_html( (string) $odsi_reactor['name'] ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
	<?php if ( $viewer_id > 0 ) : ?>
		<?php echo $odsi_templates->render( 'parts/report-form', array( 'viewer_id' => $viewer_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output. ?>
	<?php endif; ?>
</div>
