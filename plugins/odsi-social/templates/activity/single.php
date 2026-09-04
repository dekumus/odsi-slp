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
</div>
