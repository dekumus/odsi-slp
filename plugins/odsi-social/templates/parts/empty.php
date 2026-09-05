<?php
/**
 * An empty state: what is missing, and what to do next.
 *
 * @var string $text  Message.
 * @var string $url   Next action URL, or ''.
 * @var string $label Next action label.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$url   = isset( $url ) ? (string) $url : '';
$label = isset( $label ) ? (string) $label : '';
?>
<div class="odsi-social-empty">
	<p class="odsi-social-empty__text"><?php echo esc_html( (string) $text ); ?></p>
	<?php if ( '' !== $url && '' !== $label ) : ?>
		<a class="odsi-social-button odsi-social-button--quiet odsi-social-empty__action" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
	<?php endif; ?>
</div>
