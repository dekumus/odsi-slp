<?php
/**
 * Activity feed.
 *
 * @var string                            $scope           Scope.
 * @var int                               $group_id        Group id.
 * @var int                               $user_id         Profile user id.
 * @var array<int, array<string, mixed>>  $items           Items.
 * @var string                            $next_cursor     Cursor.
 * @var int                               $viewer_id       Viewer.
 * @var bool                              $can_post        Whether to show the post box.
 * @var string[]                          $privacy_choices Privacy levels the viewer may choose (SOC-ACT-003).
 * @var string                            $default_privacy Preselected level.
 * @var bool                              $show_tabs       Whether to show the site / following switch.
 * @var int                               $max_length      Longest update allowed.
 * @var array{text: string, url: string, label: string} $empty What an empty feed says.
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_templates  = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
$default_privacy = isset( $default_privacy ) ? (string) $default_privacy : '';
$max_length      = isset( $max_length ) ? max( 1, (int) $max_length ) : 5000;
$empty           = isset( $empty ) && is_array( $empty ) ? $empty : array(
	'text'  => __( 'No updates yet.', 'odsi-social' ),
	'url'   => '',
	'label' => '',
);
$odsi_form_id    = wp_unique_id( 'odsi-social-post-' );
?>
<?php if ( ! empty( $show_tabs ) ) : ?>
	<nav class="odsi-social-feed__tabs" aria-label="<?php esc_attr_e( 'Feed', 'odsi-social' ); ?>">
		<a class="odsi-social-feed__tab<?php echo 'site' === $scope ? ' is-active' : ''; ?>" <?php echo 'site' === $scope ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( remove_query_arg( 'scope' ) ); ?>"><?php esc_html_e( 'Everyone', 'odsi-social' ); ?></a>
		<a class="odsi-social-feed__tab<?php echo 'personal' === $scope ? ' is-active' : ''; ?>" <?php echo 'personal' === $scope ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( 'scope', 'personal' ) ); ?>"><?php esc_html_e( 'Following', 'odsi-social' ); ?></a>
	</nav>
<?php endif; ?>
<div class="odsi-social-feed" data-scope="<?php echo esc_attr( $scope ); ?>" data-group-id="<?php echo esc_attr( (string) $group_id ); ?>" data-user-id="<?php echo esc_attr( (string) $user_id ); ?>" data-next-cursor="<?php echo esc_attr( $next_cursor ); ?>">
	<?php if ( $can_post ) : ?>
		<form class="odsi-social-post-form" data-group-id="<?php echo esc_attr( (string) $group_id ); ?>" aria-label="<?php esc_attr_e( 'Post an update', 'odsi-social' ); ?>">
			<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_form_id . '-content' ); ?>"><?php esc_html_e( 'What is on your mind?', 'odsi-social' ); ?></label>
			<textarea id="<?php echo esc_attr( $odsi_form_id . '-content' ); ?>" class="odsi-social-post-form__content" name="content" rows="3" required maxlength="<?php echo esc_attr( (string) $max_length ); ?>" placeholder="<?php esc_attr_e( 'What is on your mind?', 'odsi-social' ); ?>" aria-describedby="<?php echo esc_attr( $odsi_form_id . '-count' ); ?>"></textarea>
			<div class="odsi-social-post-form__controls">
				<span class="odsi-social-post-form__count" id="<?php echo esc_attr( $odsi_form_id . '-count' ); ?>" data-max="<?php echo esc_attr( (string) $max_length ); ?>" aria-hidden="true"><?php echo esc_html( sprintf( /* translators: %d: characters remaining. */ _n( '%d character left', '%d characters left', $max_length, 'odsi-social' ), $max_length ) ); ?></span>
				<span class="odsi-social-visually-hidden odsi-social-post-form__count-announce" role="status" aria-live="polite"></span>
				<?php if ( $group_id <= 0 && count( $privacy_choices ) > 0 ) : ?>
					<label class="odsi-social-visually-hidden" for="<?php echo esc_attr( $odsi_form_id . '-privacy' ); ?>"><?php esc_html_e( 'Who can see this', 'odsi-social' ); ?></label>
					<select id="<?php echo esc_attr( $odsi_form_id . '-privacy' ); ?>" class="odsi-social-post-form__privacy" name="privacy">
						<?php foreach ( $privacy_choices as $odsi_choice ) : ?>
							<option value="<?php echo esc_attr( $odsi_choice ); ?>" <?php selected( $default_privacy, $odsi_choice ); ?>><?php echo esc_html( \ODSI\Social\Support\Labels::privacy( $odsi_choice ) ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<button type="submit" class="odsi-social-button odsi-social-post-form__submit"><?php esc_html_e( 'Post', 'odsi-social' ); ?></button>
			</div>
			<p class="odsi-social-post-form__error odsi-social-error" role="alert" hidden></p>
		</form>
	<?php endif; ?>

	<div class="odsi-social-feed__status odsi-social-visually-hidden" role="status" aria-live="polite"></div>

	<ul class="odsi-social-feed__items">
		<?php foreach ( $items as $item ) : ?>
			<?php
			$odsi_html = $odsi_templates->render(
				'parts/activity-item',
				array(
					'item'      => $item,
					'viewer_id' => $viewer_id,
				)
			);
			echo $odsi_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, escaped inside.
			?>
		<?php endforeach; ?>
	</ul>

	<?php if ( empty( $items ) ) : ?>
		<div class="odsi-social-feed__empty">
			<?php echo $odsi_templates->render( 'parts/empty', $empty ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output. ?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $next_cursor ) : ?>
		<button type="button" class="odsi-social-button odsi-social-button--quiet odsi-social-feed__more"><?php esc_html_e( 'Load more', 'odsi-social' ); ?></button>
	<?php endif; ?>

	<?php if ( $viewer_id > 0 ) : ?>
		<?php echo $odsi_templates->render( 'parts/report-form', array( 'viewer_id' => $viewer_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output. ?>
	<?php endif; ?>
</div>
