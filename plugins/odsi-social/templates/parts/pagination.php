<?php
/**
 * Pagination links for a listing, as a landmark.
 *
 * @var int    $total    Total rows.
 * @var int    $per_page Rows per page.
 * @var int    $page     Current page.
 * @var string $label    Landmark label, e.g. "Members pages".
 *
 * @package ODSI\Social
 */

defined( 'ABSPATH' ) || exit;

$odsi_pages = (int) ceil( (int) $total / max( 1, (int) $per_page ) );

if ( $odsi_pages < 2 ) {
	return;
}
?>
<nav class="odsi-social-pagination" aria-label="<?php echo esc_attr( (string) $label ); ?>">
	<?php
	echo wp_kses_post(
		(string) paginate_links(
			array(
				'total'   => $odsi_pages,
				'current' => max( 1, (int) $page ),
				'base'    => add_query_arg( 'paged', '%#%' ),
			)
		)
	);
	?>
</nav>
