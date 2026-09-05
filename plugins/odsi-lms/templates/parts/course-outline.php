<?php
/**
 * Course outline list.
 *
 * @var int                                                                    $course_id Course post id.
 * @var array<int, array{id:int,type:string,parent:int,depth:int,section?:bool}> $steps     Ordered steps.
 * @var int[]                                                                  $completed Completed step ids.
 * @var \ODSI\LMS\Courses\Access                                               $access    Access rules.
 * @var int                                                                    $user_id   Current user id.
 * @var int                                                                    $current   Step being read, or 0.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $steps ) ) {
	printf( '<p class="odsi-lms-notice odsi-lms-outline__empty">%s</p>', esc_html__( 'This course has no content yet.', 'odsi-lms' ) );

	return;
}
?>
<ol class="odsi-lms-outline">
	<?php foreach ( $steps as $step ) : ?>
		<?php
		$is_done    = in_array( $step['id'], $completed, true );
		$is_locked  = ! $access->can_access_step( $user_id, $step['id'] );
		$is_current = $current > 0 && $current === (int) $step['id'];
		$classes    = array(
			'odsi-lms-outline__item',
			'odsi-lms-outline__item--depth-' . (int) $step['depth'],
			'odsi-lms-outline__item--' . sanitize_html_class( str_replace( 'odsi_', '', $step['type'] ) ),
		);

		if ( ! empty( $step['section'] ) ) {
			$classes[] = 'odsi-lms-outline__item--section';
		}

		if ( $is_done ) {
			$classes[] = 'odsi-lms-outline__item--complete';
		}

		if ( $is_locked ) {
			$classes[] = 'odsi-lms-outline__item--locked';
		}

		if ( $is_current ) {
			$classes[] = 'odsi-lms-outline__item--current';
		}
		?>
		<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-step-id="<?php echo esc_attr( (string) $step['id'] ); ?>">
			<?php if ( $is_locked ) : ?>
				<span class="odsi-lms-outline__title odsi-lms-outline__title--locked"><?php echo esc_html( get_the_title( $step['id'] ) ); ?></span>
			<?php else : ?>
				<a class="odsi-lms-outline__title" href="<?php echo esc_url( (string) get_permalink( $step['id'] ) ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( get_the_title( $step['id'] ) ); ?></a>
			<?php endif; ?>

			<?php
			// State is written out, never colour alone (both badges can apply).
			if ( $is_done ) :
				?>
				<span class="odsi-lms-outline__status odsi-lms-outline__status--complete"><?php esc_html_e( 'Completed', 'odsi-lms' ); ?></span>
			<?php endif; ?>
			<?php if ( $is_locked ) : ?>
				<span class="odsi-lms-outline__status odsi-lms-outline__status--locked"><?php esc_html_e( 'Locked', 'odsi-lms' ); ?></span>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ol>
