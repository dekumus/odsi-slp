<?php
/**
 * Notifications.
 *
 * @var array<int, array<string, mixed>> $notifications Rows.
 * @var int                              $unread_count  Unread.
 * @var int                              $total         Total rows.
 * @var int                              $page          Page.
 * @var int                              $per_page      Page size.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_templates = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
?>
<div class="odsi-social-notifications">
	<div class="odsi-social-notifications__toolbar">
		<p class="odsi-social-notifications__count" role="status"><?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d unread', 'odsi-social' ), $unread_count ) ); ?></p>
		<?php if ( $unread_count > 0 ) : ?>
			<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-notifications__read-all"><?php esc_html_e( 'Mark all read', 'odsi-social' ); ?></button>
		<?php endif; ?>
	</div>

	<?php if ( count( $notifications ) > 0 ) : ?>
		<ul class="odsi-social-notifications__list">
			<?php foreach ( $notifications as $n ) : ?>
				<li class="odsi-social-notification<?php echo $n['is_new'] ? ' is-new' : ''; ?>" data-notification-id="<?php echo esc_attr( (string) $n['id'] ); ?>">
					<img class="odsi-social-avatar odsi-social-avatar--small" src="<?php echo esc_url( (string) $n['actor']['avatar'] ); ?>" alt="" width="32" height="32" loading="lazy" />
					<div class="odsi-social-notification__body">
						<a class="odsi-social-notification__link" href="<?php echo esc_url( (string) $n['url'] ); ?>">
							<?php if ( $n['is_new'] ) : ?>
								<span class="odsi-social-notification__state odsi-social-visually-hidden"><?php esc_html_e( 'Unread:', 'odsi-social' ); ?></span>
							<?php endif; ?>
							<?php echo esc_html( (string) $n['text'] ); ?>
						</a>
						<time class="odsi-social-notification__time" datetime="<?php echo esc_attr( \ODSI\Social\Support\Labels::iso( (string) $n['date'] ) ); ?>" title="<?php echo esc_attr( \ODSI\Social\Support\Labels::absolute( (string) $n['date'] ) ); ?>"><?php echo esc_html( \ODSI\Social\Support\Labels::ago( (string) $n['date'] ) ); ?></time>
					</div>
					<?php if ( $n['is_new'] ) : ?>
						<?php /* translators: %s: notification text. */ $odsi_read_label = sprintf( __( 'Mark as read: %s', 'odsi-social' ), (string) $n['text'] ); ?>
						<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-button--small odsi-social-notification__read" data-notification-id="<?php echo esc_attr( (string) $n['id'] ); ?>" aria-label="<?php echo esc_attr( $odsi_read_label ); ?>"><?php esc_html_e( 'Mark read', 'odsi-social' ); ?></button>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<div class="odsi-social-notifications__empty">
			<?php
			$odsi_html = $odsi_templates->render(
				'parts/empty',
				array(
					'text'  => __( 'You are all caught up. Likes, comments, mentions, requests and messages will show up here.', 'odsi-social' ),
					'url'   => (string) apply_filters( 'odsi_social_page_url', '', 'activity', '', '' ),
					'label' => __( 'See what is happening', 'odsi-social' ),
				)
			);
			echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
			?>
		</div>
	<?php endif; ?>

	<?php
	$odsi_html = $odsi_templates->render(
		'parts/pagination',
		array(
			'total'    => (int) ( $total ?? 0 ),
			'per_page' => (int) ( $per_page ?? 20 ),
			'page'     => (int) ( $page ?? 1 ),
			'label'    => __( 'Notification pages', 'odsi-social' ),
		)
	);
	echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
	?>
</div>
