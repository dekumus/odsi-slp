<?php
/**
 * Inbox.
 *
 * @var array<int, array<string, mixed>> $threads      Threads.
 * @var int                              $unread_total Unread.
 * @var int                              $total        Total threads.
 * @var int                              $page         Page.
 * @var int                              $per_page     Page size.
 * @var array{id: int, name: string, allowed: bool}|null $compose Member a new message is addressed to (`?to=`).
 * @var int                              $max_length   Longest message allowed.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_templates = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
$compose        = isset( $compose ) && is_array( $compose ) ? $compose : null;
$max_length     = isset( $max_length ) ? max( 1, (int) $max_length ) : 10000;
$odsi_id        = wp_unique_id( 'odsi-social-compose-' );
?>
<div class="odsi-social-messages odsi-social-messages--inbox">
	<?php if ( $compose && $compose['allowed'] ) : ?>
		<form class="odsi-social-message-form odsi-social-message-form--new" data-user-id="<?php echo esc_attr( (string) $compose['id'] ); ?>" aria-labelledby="<?php echo esc_attr( $odsi_id . '-to' ); ?>">
			<p class="odsi-social-message-form__to" id="<?php echo esc_attr( $odsi_id . '-to' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: name. */ __( 'New message to %s', 'odsi-social' ), $compose['name'] ) ); ?></p>
			<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_id . '-content' ); ?>"><?php esc_html_e( 'Message', 'odsi-social' ); ?></label>
			<textarea id="<?php echo esc_attr( $odsi_id . '-content' ); ?>" class="odsi-social-message-form__content" name="content" rows="3" required maxlength="<?php echo esc_attr( (string) $max_length ); ?>" placeholder="<?php esc_attr_e( 'Write a message…', 'odsi-social' ); ?>"></textarea>
			<p class="odsi-social-message-form__error odsi-social-error" role="alert" hidden></p>
			<div class="odsi-social-message-form__controls">
				<button type="submit" class="odsi-social-button odsi-social-message-form__submit"><?php esc_html_e( 'Send', 'odsi-social' ); ?></button>
			</div>
		</form>
	<?php elseif ( $compose ) : ?>
		<div class="odsi-social-notice odsi-social-notice--locked" role="status">
			<p class="odsi-social-notice__text"><?php echo esc_html( sprintf( /* translators: %s: name. */ __( 'You cannot message %s.', 'odsi-social' ), $compose['name'] ) ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( count( $threads ) > 0 ) : ?>
		<ul class="odsi-social-threads">
			<?php foreach ( $threads as $thread ) : ?>
				<li class="odsi-social-thread<?php echo $thread['unread_count'] > 0 ? ' is-unread' : ''; ?>" data-thread-id="<?php echo esc_attr( (string) $thread['thread_id'] ); ?>">
					<a class="odsi-social-thread__link" href="<?php echo esc_url( (string) apply_filters( 'odsi_social_thread_url', '', (int) $thread['thread_id'] ) ); ?>">
						<img class="odsi-social-avatar odsi-social-avatar--small" src="<?php echo esc_url( (string) $thread['other']['avatar'] ); ?>" alt="" width="32" height="32" loading="lazy" />
						<span class="odsi-social-thread__body">
							<span class="odsi-social-thread__name"><?php echo esc_html( (string) $thread['other']['name'] ); ?></span>
							<span class="odsi-social-thread__excerpt"><?php echo esc_html( (string) $thread['last_message'] ); ?></span>
						</span>
						<?php if ( $thread['unread_count'] > 0 ) : ?>
							<span class="odsi-social-badge odsi-social-thread__badge"><?php echo esc_html( (string) $thread['unread_count'] ); ?><span class="odsi-social-visually-hidden"> <?php esc_html_e( 'unread', 'odsi-social' ); ?></span></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( null === $compose ) : ?>
		<div class="odsi-social-messages__empty">
			<?php
			$odsi_html = $odsi_templates->render(
				'parts/empty',
				array(
					'text'  => __( 'No conversations yet. Start one from a member’s profile.', 'odsi-social' ),
					'url'   => (string) apply_filters( 'odsi_social_page_url', '', 'members', '', '' ),
					'label' => __( 'Find members', 'odsi-social' ),
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
			'label'    => __( 'Conversation pages', 'odsi-social' ),
		)
	);
	echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
	?>
</div>
