<?php
/**
 * Notifications.
 *
 * @var array<int, array<string, mixed>> $notifications Rows.
 * @var int                              $unread_count  Unread.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="odsi-social-notifications">
	<div class="odsi-social-notifications__toolbar">
		<span><?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d unread', 'odsi-social' ), $unread_count ) ); ?></span>
		<?php if ( $unread_count > 0 ) : ?>
			<button type="button" class="odsi-social-button odsi-social-read-all"><?php esc_html_e( 'Mark all read', 'odsi-social' ); ?></button>
		<?php endif; ?>
	</div>
	<ul class="odsi-social-notifications__list">
		<?php foreach ( $notifications as $n ) : ?>
			<li class="odsi-social-notification <?php echo $n['is_new'] ? 'is-new' : ''; ?>" data-notification-id="<?php echo esc_attr( (string) $n['id'] ); ?>">
				<img class="odsi-social-avatar odsi-social-avatar--small" src="<?php echo esc_url( (string) $n['actor']['avatar'] ); ?>" alt="" width="32" height="32" />
				<a href="<?php echo esc_url( (string) $n['url'] ); ?>"><?php echo esc_html( (string) $n['text'] ); ?></a>
				<time><?php echo esc_html( human_time_diff( (int) strtotime( (string) $n['date'] ) ) ); ?></time>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( empty( $notifications ) ) : ?>
		<p class="odsi-social-feed__empty"><?php esc_html_e( 'No notifications yet.', 'odsi-social' ); ?></p>
	<?php endif; ?>
</div>
