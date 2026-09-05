<?php
/**
 * Message thread, beside the conversation list: two panes from tablet
 * width up, stacked on small screens.
 *
 * @var array<string, mixed>             $thread     Thread.
 * @var int                              $viewer_id  Viewer.
 * @var string                           $other_name The other participant's name.
 * @var bool                             $can_reply  Whether the viewer may still write here (SOC-MSG-002, SOC-MOD-005).
 * @var array<int, array<string, mixed>> $threads    The viewer's first page of conversations.
 * @var int                              $max_length Longest message allowed.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_tid   = (int) $thread['thread_id'];
$other_name = isset( $other_name ) ? (string) $other_name : '';
$can_reply  = ! isset( $can_reply ) || (bool) $can_reply;
$threads    = isset( $threads ) && is_array( $threads ) ? $threads : array();
$max_length = isset( $max_length ) ? max( 1, (int) $max_length ) : 10000;
$odsi_id    = 'odsi-social-thread-' . $odsi_tid;
?>
<div class="odsi-social-messages odsi-social-messages--thread" data-thread-id="<?php echo esc_attr( (string) $odsi_tid ); ?>">
	<div class="odsi-social-messages__layout">
		<aside class="odsi-social-messages__list" aria-label="<?php esc_attr_e( 'Conversations', 'odsi-social' ); ?>">
			<?php if ( count( $threads ) > 0 ) : ?>
				<ul class="odsi-social-threads">
					<?php foreach ( $threads as $odsi_thread ) : ?>
						<?php $odsi_current = (int) $odsi_thread['thread_id'] === $odsi_tid; ?>
						<li class="odsi-social-thread<?php echo $odsi_thread['unread_count'] > 0 ? ' is-unread' : ''; ?><?php echo $odsi_current ? ' is-current' : ''; ?>" data-thread-id="<?php echo esc_attr( (string) $odsi_thread['thread_id'] ); ?>">
							<a class="odsi-social-thread__link" href="<?php echo esc_url( (string) apply_filters( 'odsi_social_thread_url', '', (int) $odsi_thread['thread_id'] ) ); ?>" <?php echo $odsi_current ? 'aria-current="page"' : ''; ?>>
								<img class="odsi-social-avatar odsi-social-avatar--small" src="<?php echo esc_url( (string) $odsi_thread['other']['avatar'] ); ?>" alt="" width="32" height="32" loading="lazy" />
								<span class="odsi-social-thread__body">
									<span class="odsi-social-thread__name"><?php echo esc_html( (string) $odsi_thread['other']['name'] ); ?></span>
									<span class="odsi-social-thread__excerpt"><?php echo esc_html( (string) $odsi_thread['last_message'] ); ?></span>
								</span>
								<?php if ( $odsi_thread['unread_count'] > 0 ) : ?>
									<span class="odsi-social-badge odsi-social-thread__badge"><?php echo esc_html( (string) $odsi_thread['unread_count'] ); ?><span class="odsi-social-visually-hidden"> <?php esc_html_e( 'unread', 'odsi-social' ); ?></span></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="odsi-social-messages__all"><a href="<?php echo esc_url( (string) apply_filters( 'odsi_social_page_url', '', 'messages', '', '' ) ); ?>"><?php esc_html_e( 'All conversations', 'odsi-social' ); ?></a></p>
		</aside>

		<section class="odsi-social-messages__pane" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: member name. */ __( 'Conversation with %s', 'odsi-social' ), $other_name ) ); ?>">
			<div class="odsi-social-conversation" role="log" aria-live="polite" aria-relevant="additions">
				<ul class="odsi-social-conversation__list" id="<?php echo esc_attr( $odsi_id . '-list' ); ?>">
					<?php foreach ( (array) $thread['messages'] as $message ) : ?>
						<li class="odsi-social-message<?php echo (int) $message['sender_id'] === $viewer_id ? ' is-mine' : ''; ?>">
							<span class="odsi-social-message__sender"><?php echo esc_html( (string) $message['sender'] ); ?></span>
							<div class="odsi-social-message__content"><?php echo wp_kses_post( (string) $message['content'] ); ?></div>
							<time class="odsi-social-message__time" datetime="<?php echo esc_attr( \ODSI\Social\Support\Labels::iso( (string) $message['date'] ) ); ?>" title="<?php echo esc_attr( \ODSI\Social\Support\Labels::absolute( (string) $message['date'] ) ); ?>"><?php echo esc_html( \ODSI\Social\Support\Labels::ago( (string) $message['date'] ) ); ?></time>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<?php if ( $can_reply ) : ?>
				<form class="odsi-social-message-form" data-thread-id="<?php echo esc_attr( (string) $odsi_tid ); ?>" data-list="<?php echo esc_attr( $odsi_id . '-list' ); ?>">
					<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_id . '-content' ); ?>"><?php esc_html_e( 'Write a reply', 'odsi-social' ); ?></label>
					<textarea id="<?php echo esc_attr( $odsi_id . '-content' ); ?>" class="odsi-social-message-form__content" name="content" rows="3" required maxlength="<?php echo esc_attr( (string) $max_length ); ?>" placeholder="<?php esc_attr_e( 'Write a reply…', 'odsi-social' ); ?>"></textarea>
					<p class="odsi-social-message-form__error odsi-social-error" role="alert" hidden></p>
					<div class="odsi-social-message-form__controls">
						<button type="submit" class="odsi-social-button odsi-social-message-form__submit"><?php esc_html_e( 'Send', 'odsi-social' ); ?></button>
					</div>
					<template class="odsi-social-message-form__template">
						<li class="odsi-social-message is-mine">
							<span class="odsi-social-message__sender"></span>
							<div class="odsi-social-message__content"></div>
							<time class="odsi-social-message__time"><?php esc_html_e( 'just now', 'odsi-social' ); ?></time>
						</li>
					</template>
				</form>
			<?php else : ?>
				<div class="odsi-social-notice odsi-social-notice--locked" role="status">
					<p class="odsi-social-notice__text"><?php esc_html_e( 'You can no longer reply in this conversation.', 'odsi-social' ); ?></p>
				</div>
			<?php endif; ?>
		</section>
	</div>
</div>
