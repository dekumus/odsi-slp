<?php
/**
 * One comment under an activity item. Rendered by the item template and by
 * `POST /activity/{id}/comments?render=1`, so a comment the script appends
 * is identical to one the page loaded with.
 *
 * @var array<string, mixed> $comment   Presented comment.
 * @var int                  $item_id   Parent item.
 * @var int                  $viewer_id Viewer.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_cid    = (int) $comment['id'];
$odsi_author = (array) $comment['author'];
?>
<li class="odsi-social-comment" data-activity-id="<?php echo esc_attr( (string) $odsi_cid ); ?>" data-parent-id="<?php echo esc_attr( (string) $item_id ); ?>">
	<img class="odsi-social-avatar odsi-social-avatar--small odsi-social-comment__avatar" src="<?php echo esc_url( (string) $odsi_author['avatar'] ); ?>" alt="" width="32" height="32" loading="lazy" />
	<div class="odsi-social-comment__body">
		<?php if ( '' !== (string) $odsi_author['url'] ) : ?>
			<a class="odsi-social-comment__author" href="<?php echo esc_url( (string) $odsi_author['url'] ); ?>"><?php echo esc_html( (string) $odsi_author['name'] ); ?></a>
		<?php else : ?>
			<span class="odsi-social-comment__author"><?php echo esc_html( (string) $odsi_author['name'] ); ?></span>
		<?php endif; ?>
		<div class="odsi-social-comment__content"><?php echo wp_kses_post( (string) $comment['content'] ); ?></div>
		<p class="odsi-social-comment__meta">
			<time class="odsi-social-comment__time" datetime="<?php echo esc_attr( \ODSI\Social\Support\Labels::iso( (string) $comment['date'] ) ); ?>" title="<?php echo esc_attr( \ODSI\Social\Support\Labels::absolute( (string) $comment['date'] ) ); ?>"><?php echo esc_html( (string) $comment['date_relative'] ); ?></time>
			<?php if ( $viewer_id > 0 && ! empty( $comment['can_delete'] ) ) : ?>
				<button type="button" class="odsi-social-comment__delete" data-activity-id="<?php echo esc_attr( (string) $odsi_cid ); ?>"><?php esc_html_e( 'Delete', 'odsi-social' ); ?></button>
			<?php endif; ?>
			<?php if ( $viewer_id > 0 && (int) $odsi_author['id'] !== $viewer_id ) : ?>
				<button type="button" class="odsi-social-comment__report" data-object-type="comment" data-object-id="<?php echo esc_attr( (string) $odsi_cid ); ?>"><?php esc_html_e( 'Report', 'odsi-social' ); ?></button>
			<?php endif; ?>
		</p>
	</div>
</li>
