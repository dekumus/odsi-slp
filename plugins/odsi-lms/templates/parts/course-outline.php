<?php
/**
 * Course outline list.
 *
 * @var int                                                             $course_id Course post id.
 * @var array<int, array{id:int,type:string,parent:int,depth:int}>      $steps     Ordered steps.
 * @var int[]                                                           $completed Completed step ids.
 * @var \ODSI\LMS\Courses\Access                                        $access    Access rules.
 * @var int                                                             $user_id   Current user id.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $steps ) ) {
	printf( '<p class="odsi-lms-outline__empty">%s</p>', esc_html__( 'This course has no content yet.', 'odsi-lms' ) );

	return;
}
?>
<ol class="odsi-lms-outline">
	<?php foreach ( $steps as $step ) : ?>
		<?php
		$is_done   = in_array( $step['id'], $completed, true );
		$is_locked = ! $access->can_access_step( $user_id, $step['id'] );
		$classes   = array(
			'odsi-lms-outline__item',
			'odsi-lms-outline__item--depth-' . (int) $step['depth'],
			'odsi-lms-outline__item--' . sanitize_html_class( str_replace( 'odsi_', '', $step['type'] ) ),
		);

		if ( $is_done ) {
			$classes[] = 'is-complete';
		}

		if ( $is_locked ) {
			$classes[] = 'is-locked';
		}
		?>
		<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<?php if ( $is_locked ) : ?>
				<span class="odsi-lms-outline__title" aria-disabled="true">
					<?php echo esc_html( get_the_title( $step['id'] ) ); ?>
				</span>
				<span class="screen-reader-text"><?php esc_html_e( 'Locked', 'odsi-lms' ); ?></span>
			<?php else : ?>
				<a class="odsi-lms-outline__title" href="<?php echo esc_url( (string) get_permalink( $step['id'] ) ); ?>">
					<?php echo esc_html( get_the_title( $step['id'] ) ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $is_done ) : ?>
				<span class="odsi-lms-outline__status"><?php esc_html_e( 'Completed', 'odsi-lms' ); ?></span>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ol>
