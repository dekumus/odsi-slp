<?php
/**
 * One activity item with its comments.
 *
 * @var array<string, mixed> $item      Presented item.
 * @var int                  $viewer_id Viewer.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;
?>
<article class="odsi-social-item" data-activity-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
	<header class="odsi-social-item__header">
		<img class="odsi-social-avatar" src="<?php echo esc_url( (string) $item['author']['avatar'] ); ?>" alt="" width="48" height="48" />
		<div>
			<div class="odsi-social-item__action"><?php echo wp_kses_post( (string) $item['action'] ); ?></div>
			<a class="odsi-social-item__time" href="<?php echo esc_url( (string) apply_filters( 'odsi_social_activity_url', '', (int) $item['id'] ) ); ?>">
				<?php echo esc_html( (string) $item['date_relative'] ); ?>
				<?php if ( ! empty( $item['is_edited'] ) ) : ?>
					<span class="odsi-social-item__edited"><?php esc_html_e( '(edited)', 'odsi-social' ); ?></span>
				<?php endif; ?>
			</a>
		</div>
	</header>

	<div class="odsi-social-item__content"><?php echo wp_kses_post( (string) $item['content'] ); ?></div>

	<footer class="odsi-social-item__footer">
		<?php if ( $viewer_id > 0 ) : ?>
			<button type="button" class="odsi-social-react <?php echo '' !== $item['viewer_reaction'] ? 'is-active' : ''; ?>" data-activity-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
				<?php esc_html_e( 'Like', 'odsi-social' ); ?>
				<span class="odsi-social-count"><?php echo esc_html( (string) $item['reaction_count'] ); ?></span>
			</button>
			<button type="button" class="odsi-social-comment-toggle" data-activity-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
				<?php esc_html_e( 'Comment', 'odsi-social' ); ?>
				<span class="odsi-social-count"><?php echo esc_html( (string) $item['comment_count'] ); ?></span>
			</button>
			<?php if ( ! empty( $item['can_delete'] ) ) : ?>
				<button type="button" class="odsi-social-delete" data-activity-id="<?php echo esc_attr( (string) $item['id'] ); ?>"><?php esc_html_e( 'Delete', 'odsi-social' ); ?></button>
			<?php endif; ?>
			<?php if ( (int) $item['author']['id'] !== $viewer_id ) : ?>
				<button type="button" class="odsi-social-report" data-object-type="activity" data-object-id="<?php echo esc_attr( (string) $item['id'] ); ?>"><?php esc_html_e( 'Report', 'odsi-social' ); ?></button>
			<?php endif; ?>
		<?php else : ?>
			<span class="odsi-social-count"><?php echo esc_html( sprintf( /* translators: %d: likes. */ _n( '%d like', '%d likes', (int) $item['reaction_count'], 'odsi-social' ), (int) $item['reaction_count'] ) ); ?></span>
		<?php endif; ?>
	</footer>

	<?php if ( ! empty( $item['comments'] ) || $viewer_id > 0 ) : ?>
		<div class="odsi-social-comments">
			<?php foreach ( (array) $item['comments'] as $odsi_comment ) : ?>
				<div class="odsi-social-comment" data-activity-id="<?php echo esc_attr( (string) $odsi_comment['id'] ); ?>">
					<img class="odsi-social-avatar odsi-social-avatar--small" src="<?php echo esc_url( (string) $odsi_comment['author']['avatar'] ); ?>" alt="" width="32" height="32" />
					<div>
						<strong><?php echo esc_html( (string) $odsi_comment['author']['name'] ); ?></strong>
						<div class="odsi-social-comment__content"><?php echo wp_kses_post( (string) $odsi_comment['content'] ); ?></div>
						<span class="odsi-social-item__time"><?php echo esc_html( (string) $odsi_comment['date_relative'] ); ?></span>
						<?php if ( $viewer_id > 0 && (int) $odsi_comment['author']['id'] !== $viewer_id ) : ?>
							<button type="button" class="odsi-social-report odsi-social-report--comment" data-object-type="comment" data-object-id="<?php echo esc_attr( (string) $odsi_comment['id'] ); ?>"><?php esc_html_e( 'Report', 'odsi-social' ); ?></button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<?php if ( $viewer_id > 0 ) : ?>
				<form class="odsi-social-comment-form" data-activity-id="<?php echo esc_attr( (string) $item['id'] ); ?>" hidden>
					<textarea name="content" rows="2" required placeholder="<?php esc_attr_e( 'Write a comment…', 'odsi-social' ); ?>"></textarea>
					<button type="submit" class="odsi-social-button"><?php esc_html_e( 'Post comment', 'odsi-social' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</article>
