<?php
/**
 * Message thread.
 *
 * @var array<string, mixed> $thread    Thread.
 * @var int                  $viewer_id Viewer.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="odsi-social-messages odsi-social-messages--thread" data-thread-id="<?php echo esc_attr( (string) $thread['thread_id'] ); ?>">
	<div class="odsi-social-thread__messages">
		<?php foreach ( (array) $thread['messages'] as $message ) : ?>
			<div class="odsi-social-message <?php echo (int) $message['sender_id'] === $viewer_id ? 'is-mine' : ''; ?>">
				<strong><?php echo esc_html( (string) $message['sender'] ); ?></strong>
				<div><?php echo wp_kses_post( (string) $message['content'] ); ?></div>
				<time><?php echo esc_html( human_time_diff( (int) strtotime( (string) $message['date'] ) ) ); ?></time>
			</div>
		<?php endforeach; ?>
	</div>
	<form class="odsi-social-message-form" data-thread-id="<?php echo esc_attr( (string) $thread['thread_id'] ); ?>">
		<textarea name="content" rows="3" required placeholder="<?php esc_attr_e( 'Write a reply…', 'odsi-social' ); ?>"></textarea>
		<button type="submit" class="odsi-social-button"><?php esc_html_e( 'Send', 'odsi-social' ); ?></button>
	</form>
</div>
