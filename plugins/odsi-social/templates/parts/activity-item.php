<?php
/**
 * One activity item with its comments, as a feed list entry. The same
 * template serves the first page, `GET /activity?render=1` ("load more")
 * and `POST /activity?render=1`, so every item on the page is identical.
 *
 * @var array<string, mixed> $item      Presented item.
 * @var int                  $viewer_id Viewer.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_templates  = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
$odsi_id         = (int) $item['id'];
$odsi_prefix     = 'odsi-social-item-' . $odsi_id;
$odsi_comments   = (array) ( $item['comments'] ?? array() );
$odsi_max_length = max( 1, (int) apply_filters( 'odsi_social_activity_max_length', \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Support\Settings::class )->int( 'activity_max_length' ) ) );
?>
<li class="odsi-social-feed__item">
	<article class="odsi-social-item" data-activity-id="<?php echo esc_attr( (string) $odsi_id ); ?>" aria-labelledby="<?php echo esc_attr( $odsi_prefix . '-action' ); ?>" tabindex="-1">
		<header class="odsi-social-item__header">
			<img class="odsi-social-avatar odsi-social-item__avatar" src="<?php echo esc_url( (string) $item['author']['avatar'] ); ?>" alt="" width="48" height="48" loading="lazy" />
			<div class="odsi-social-item__meta">
				<p class="odsi-social-item__action" id="<?php echo esc_attr( $odsi_prefix . '-action' ); ?>"><?php echo wp_kses_post( (string) $item['action'] ); ?></p>
				<a class="odsi-social-item__time" href="<?php echo esc_url( (string) apply_filters( 'odsi_social_activity_url', '', $odsi_id ) ); ?>">
					<time datetime="<?php echo esc_attr( \ODSI\Social\Support\Labels::iso( (string) $item['date'] ) ); ?>" title="<?php echo esc_attr( \ODSI\Social\Support\Labels::absolute( (string) $item['date'] ) ); ?>"><?php echo esc_html( (string) $item['date_relative'] ); ?></time>
					<?php if ( ! empty( $item['is_edited'] ) ) : ?>
						<span class="odsi-social-item__edited"><?php esc_html_e( '(edited)', 'odsi-social' ); ?></span>
					<?php endif; ?>
				</a>
			</div>
		</header>

		<?php if ( '' !== trim( (string) $item['content'] ) ) : ?>
			<div class="odsi-social-item__content"><?php echo wp_kses_post( (string) $item['content'] ); ?></div>
		<?php endif; ?>

		<footer class="odsi-social-item__footer">
			<?php if ( $viewer_id > 0 ) : ?>
				<button type="button" class="odsi-social-item__react<?php echo '' !== (string) $item['viewer_reaction'] ? ' is-active' : ''; ?>" aria-pressed="<?php echo '' !== (string) $item['viewer_reaction'] ? 'true' : 'false'; ?>" data-activity-id="<?php echo esc_attr( (string) $odsi_id ); ?>">
					<?php esc_html_e( 'Like', 'odsi-social' ); ?>
					<span class="odsi-social-item__count" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: likes. */ _n( '%d like', '%d likes', (int) $item['reaction_count'], 'odsi-social' ), (int) $item['reaction_count'] ) ); ?>"><?php echo esc_html( (string) $item['reaction_count'] ); ?></span>
				</button>
				<button type="button" class="odsi-social-item__comment-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $odsi_prefix . '-comment-form' ); ?>" data-activity-id="<?php echo esc_attr( (string) $odsi_id ); ?>">
					<?php esc_html_e( 'Comment', 'odsi-social' ); ?>
					<span class="odsi-social-item__count" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: comments. */ _n( '%d comment', '%d comments', (int) $item['comment_count'], 'odsi-social' ), (int) $item['comment_count'] ) ); ?>"><?php echo esc_html( (string) $item['comment_count'] ); ?></span>
				</button>
				<?php if ( ! empty( $item['can_delete'] ) ) : ?>
					<button type="button" class="odsi-social-item__delete" data-activity-id="<?php echo esc_attr( (string) $odsi_id ); ?>"><?php esc_html_e( 'Delete', 'odsi-social' ); ?></button>
				<?php endif; ?>
				<?php if ( (int) $item['author']['id'] !== $viewer_id ) : ?>
					<button type="button" class="odsi-social-item__report" data-object-type="activity" data-object-id="<?php echo esc_attr( (string) $odsi_id ); ?>"><?php esc_html_e( 'Report', 'odsi-social' ); ?></button>
				<?php endif; ?>
			<?php else : ?>
				<span class="odsi-social-item__likes"><?php echo esc_html( sprintf( /* translators: %d: likes. */ _n( '%d like', '%d likes', (int) $item['reaction_count'], 'odsi-social' ), (int) $item['reaction_count'] ) ); ?></span>
				<span class="odsi-social-item__likes"><?php echo esc_html( sprintf( /* translators: %d: comments. */ _n( '%d comment', '%d comments', (int) $item['comment_count'], 'odsi-social' ), (int) $item['comment_count'] ) ); ?></span>
			<?php endif; ?>
		</footer>

		<?php if ( count( $odsi_comments ) > 0 || $viewer_id > 0 ) : ?>
			<div class="odsi-social-item__comments">
				<ul class="odsi-social-comment-list">
					<?php foreach ( $odsi_comments as $odsi_comment ) : ?>
						<?php
						$odsi_html = $odsi_templates->render(
							'parts/activity-comment',
							array(
								'comment'   => $odsi_comment,
								'item_id'   => $odsi_id,
								'viewer_id' => $viewer_id,
							)
						);
						echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
						?>
					<?php endforeach; ?>
				</ul>

				<?php if ( $viewer_id > 0 ) : ?>
					<form class="odsi-social-comment-form" id="<?php echo esc_attr( $odsi_prefix . '-comment-form' ); ?>" data-activity-id="<?php echo esc_attr( (string) $odsi_id ); ?>" hidden>
						<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_prefix . '-comment' ); ?>"><?php esc_html_e( 'Write a comment', 'odsi-social' ); ?></label>
						<textarea id="<?php echo esc_attr( $odsi_prefix . '-comment' ); ?>" class="odsi-social-comment-form__content" name="content" rows="2" required maxlength="<?php echo esc_attr( (string) $odsi_max_length ); ?>" placeholder="<?php esc_attr_e( 'Write a comment…', 'odsi-social' ); ?>"></textarea>
						<p class="odsi-social-comment-form__error odsi-social-error" role="alert" hidden></p>
						<div class="odsi-social-comment-form__controls">
							<button type="submit" class="odsi-social-button odsi-social-button--small odsi-social-comment-form__submit"><?php esc_html_e( 'Post comment', 'odsi-social' ); ?></button>
						</div>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</article>
</li>
