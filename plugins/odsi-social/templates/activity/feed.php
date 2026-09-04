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
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- sub-template output is escaped inside the sub-template.
$odsi_templates  = \ODSI\Social\Plugin::instance()->container()->get( \ODSI\Social\Frontend\Templates::class );
$default_privacy = isset( $default_privacy ) ? (string) $default_privacy : '';
?>
<?php if ( ! empty( $show_tabs ) ) : ?>
	<nav class="odsi-social-feed__tabs">
		<a class="<?php echo 'site' === $scope ? 'is-active' : ''; ?>" href="<?php echo esc_url( remove_query_arg( 'scope' ) ); ?>"><?php esc_html_e( 'Everyone', 'odsi-social' ); ?></a>
		<a class="<?php echo 'personal' === $scope ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'scope', 'personal' ) ); ?>"><?php esc_html_e( 'Following', 'odsi-social' ); ?></a>
	</nav>
<?php endif; ?>
<div class="odsi-social-feed" data-scope="<?php echo esc_attr( $scope ); ?>" data-group-id="<?php echo esc_attr( (string) $group_id ); ?>" data-user-id="<?php echo esc_attr( (string) $user_id ); ?>" data-next-cursor="<?php echo esc_attr( $next_cursor ); ?>">
	<?php if ( $can_post ) : ?>
		<form class="odsi-social-post-form" data-group-id="<?php echo esc_attr( (string) $group_id ); ?>">
			<textarea name="content" rows="3" required placeholder="<?php esc_attr_e( 'What is on your mind?', 'odsi-social' ); ?>"></textarea>
			<div class="odsi-social-post-form__controls">
				<?php if ( $group_id <= 0 && count( $privacy_choices ) > 0 ) : ?>
					<select name="privacy">
						<?php foreach ( $privacy_choices as $choice ) : ?>
							<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $default_privacy, $choice ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $choice ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<button type="submit" class="odsi-social-button"><?php esc_html_e( 'Post', 'odsi-social' ); ?></button>
			</div>
		</form>
	<?php endif; ?>

	<div class="odsi-social-feed__items">
		<?php if ( empty( $items ) ) : ?>
			<p class="odsi-social-feed__empty"><?php esc_html_e( 'Nothing here yet.', 'odsi-social' ); ?></p>
		<?php endif; ?>
		<?php foreach ( $items as $item ) : ?>
			<?php
			echo $odsi_templates->render(
				'parts/activity-item',
				array(
					'item'      => $item,
					'viewer_id' => $viewer_id,
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output. 
			?>
		<?php endforeach; ?>
	</div>

	<?php if ( '' !== $next_cursor ) : ?>
		<button type="button" class="odsi-social-button odsi-social-load-more"><?php esc_html_e( 'Load more', 'odsi-social' ); ?></button>
	<?php endif; ?>

	<?php if ( $viewer_id > 0 ) : ?>
		<?php echo $odsi_templates->render( 'parts/report-form', array( 'viewer_id' => $viewer_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output. ?>
	<?php endif; ?>
</div>
