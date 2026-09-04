<?php
/**
 * Certificate page. A standalone document, not a theme page.
 *
 * @var string $body  Rendered body with placeholders substituted.
 * @var object $row   Award row.
 * @var string $title Certificate title.
 *
 * @package ODSI\LMS
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex" />
	<title><?php echo esc_html( $title ); ?></title>
	<style>
		body { font-family: Georgia, serif; margin: 0; padding: 3rem; background: #f5f6f8; color: #1f2937; }
		.odsi-lms-certificate { background: #fff; border: 12px double #2563eb; margin: 0 auto; max-width: 900px; padding: 4rem; text-align: center; }
		.odsi-lms-certificate h1 { font-size: 2.5rem; letter-spacing: 0.1em; text-transform: uppercase; }
		.odsi-lms-certificate__code { color: #5b6470; font-family: monospace; margin-top: 3rem; }
		@media print { body { background: #fff; padding: 0; } }
	</style>
</head>
<body>
	<article class="odsi-lms-certificate">
		<h1><?php echo esc_html( $title ); ?></h1>
		<div class="odsi-lms-certificate__body"><?php echo wp_kses_post( $body ); ?></div>
		<p class="odsi-lms-certificate__code">
			<?php echo esc_html( sprintf( /* translators: %s: code. */ __( 'Verification code: %s', 'odsi-lms' ), (string) $row->code ) ); ?>
		</p>
	</article>
</body>
</html>
