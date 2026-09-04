<?php
/**
 * Single activity item.
 *
 * @var array<string, mixed> $item      Item with every comment.
 * @var int                  $viewer_id Viewer.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- sub-template output is escaped inside the sub-template.
$odsi_templates = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
?>
<div class="odsi-social-feed odsi-social-feed--single">
	<?php
	echo $odsi_templates->render(
		'parts/activity-item',
		array(
			'item'      => $item,
			'viewer_id' => $viewer_id,
		)
	); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<?php if ( $viewer_id > 0 ) : ?>
		<?php echo $odsi_templates->render( 'parts/report-form', array( 'viewer_id' => $viewer_id ) ); ?>
	<?php endif; ?>
	<?php if ( ! empty( $item['reactors'] ) ) : ?>
		<section class="odsi-social-reactors">
			<h3><?php esc_html_e( 'Liked by', 'odsi-social' ); ?></h3>
			<ul class="odsi-social-member-list">
				<?php foreach ( (array) $item['reactors'] as $odsi_reactor ) : ?>
					<li class="odsi-social-member-list__item">
						<img class="odsi-social-avatar" src="<?php echo esc_url( (string) $odsi_reactor['avatar'] ); ?>" alt="" width="32" height="32" />
						<a class="odsi-social-member-list__name" href="<?php echo esc_url( (string) $odsi_reactor['url'] ); ?>"><?php echo esc_html( (string) $odsi_reactor['name'] ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>
</div>
