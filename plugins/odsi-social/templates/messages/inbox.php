<?php
/**
 * Inbox.
 *
 * @var array<int, array<string, mixed>> $threads      Threads.
 * @var int                              $unread_total Unread.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_to = absint( $_GET['to'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill only.
?>
<div class="odsi-social-messages">
	<?php if ( $odsi_to > 0 && get_userdata( $odsi_to ) ) : ?>
		<form class="odsi-social-message-form odsi-social-message-form--new" data-user-id="<?php echo esc_attr( (string) $odsi_to ); ?>">
			<p><?php echo esc_html( sprintf( /* translators: %s: name. */ __( 'New message to %s', 'odsi-social' ), get_userdata( $odsi_to )->display_name ) ); ?></p>
			<textarea name="content" rows="3" required></textarea>
			<button type="submit" class="odsi-social-button"><?php esc_html_e( 'Send', 'odsi-social' ); ?></button>
		</form>
	<?php endif; ?>

	<ul class="odsi-social-threads">
		<?php foreach ( $threads as $thread ) : ?>
			<li class="odsi-social-thread <?php echo $thread['unread_count'] > 0 ? 'is-unread' : ''; ?>">
				<a href="<?php echo esc_url( (string) apply_filters( 'odsi_social_thread_url', '', (int) $thread['thread_id'] ) ); ?>">
					<img class="odsi-social-avatar odsi-social-avatar--small" src="<?php echo esc_url( (string) $thread['other']['avatar'] ); ?>" alt="" width="32" height="32" />
					<strong><?php echo esc_html( (string) $thread['other']['name'] ); ?></strong>
					<span class="odsi-social-thread__excerpt"><?php echo esc_html( (string) $thread['last_message'] ); ?></span>
					<?php if ( $thread['unread_count'] > 0 ) : ?>
						<span class="odsi-social-badge"><?php echo esc_html( (string) $thread['unread_count'] ); ?></span>
					<?php endif; ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( empty( $threads ) && 0 === $odsi_to ) : ?>
		<p class="odsi-social-feed__empty"><?php esc_html_e( 'No conversations yet.', 'odsi-social' ); ?></p>
	<?php endif; ?>
</div>
