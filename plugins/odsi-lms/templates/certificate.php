<?php
/**
 * Certificate page. A standalone document, not a theme page, so it prints
 * cleanly; the theme's stylesheet is deliberately not loaded.
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
		body { font-family: Georgia, serif; margin: 0; padding: 3rem 1rem; background: #f5f6f8; color: #1f2937; }
		.odsi-lms-certificate { background: #fff; border: 12px double #2563eb; box-sizing: border-box; margin: 0 auto; max-width: 900px; padding: clamp(1.5rem, 6vw, 4rem); text-align: center; }
		.odsi-lms-certificate__title { font-size: clamp(1.5rem, 5vw, 2.5rem); letter-spacing: 0.1em; margin-top: 0; text-transform: uppercase; }
		.odsi-lms-certificate__body { font-size: 1.125rem; line-height: 1.6; }
		.odsi-lms-certificate__code { color: #5b6470; font-family: monospace; margin-top: 3rem; overflow-wrap: anywhere; }
		.odsi-lms-certificate__actions { margin: 1.5rem auto 0; max-width: 900px; text-align: center; }
		.odsi-lms-certificate__print { background: #2563eb; border: 0; border-radius: 8px; color: #fff; cursor: pointer; font: inherit; min-height: 2.75rem; padding: 0.625rem 1.25rem; }
		.odsi-lms-certificate__print:focus-visible { outline: 3px solid #1f2937; outline-offset: 2px; }
		@page { margin: 1.5cm; }
		@media print {
			body { background: #fff; padding: 0; }
			.odsi-lms-certificate { border-color: #2563eb; max-width: none; }
			.odsi-lms-certificate__actions { display: none; }
		}
	</style>
</head>
<body>
	<main>
		<article class="odsi-lms-certificate">
			<h1 class="odsi-lms-certificate__title"><?php echo esc_html( $title ); ?></h1>
			<div class="odsi-lms-certificate__body"><?php echo wp_kses_post( $body ); ?></div>
			<p class="odsi-lms-certificate__code">
				<?php echo esc_html( sprintf( /* translators: %s: code. */ __( 'Verification code: %s', 'odsi-lms' ), (string) $row->code ) ); ?>
			</p>
		</article>
		<p class="odsi-lms-certificate__actions">
			<button type="button" class="odsi-lms-certificate__print" onclick="window.print()"><?php esc_html_e( 'Print or save as PDF', 'odsi-lms' ); ?></button>
		</p>
	</main>
</body>
</html>
