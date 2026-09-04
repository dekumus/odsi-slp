<?php
/**
 * Certificates.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Certificates;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\Frontend\Templates;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Repositories\CertificateRepository;
use ODSI\LMS\Support\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Issues a certificate when a course that has one is completed, renders it,
 * and verifies codes publicly.
 */
final class Certificates implements Bootable {

	public const QUERY_VAR = 'odsi_certificate';

	/**
	 * Constructor.
	 *
	 * @param CertificateRepository $certificates Storage.
	 * @param Templates             $templates    Template loader.
	 */
	public function __construct(
		private CertificateRepository $certificates,
		private Templates $templates
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'odsi_lms_course_completed', array( $this, 'on_course_completed' ), 10, 2 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'init', array( $this, 'rewrites' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_shortcode( 'odsi_certificate_verify', array( $this, 'verify_shortcode' ) );
		add_shortcode( 'odsi_my_certificates', array( $this, 'my_certificates_shortcode' ) );
	}

	/**
	 * Issue on completion, once.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 */
	public function on_course_completed( int $user_id, int $course_id ): void {
		$this->issue( $user_id, $course_id );
	}

	/**
	 * Issue a certificate for a course that has a template. Idempotent.
	 *
	 * @param int $user_id   Learner.
	 * @param int $course_id Course.
	 *
	 * @return string The code, or '' when the course has no certificate.
	 */
	public function issue( int $user_id, int $course_id ): string {
		$template_id = (int) get_post_meta( $course_id, Meta::CERTIFICATE_ID, true );

		if ( $template_id <= 0 || PostTypes::CERTIFICATE !== get_post_type( $template_id ) ) {
			return '';
		}

		$existing = $this->certificates->find_for( $user_id, $course_id );

		if ( $existing ) {
			return (string) $existing->code;
		}

		$code = $this->generate_code();

		$this->certificates->issue( $user_id, $course_id, $template_id, $code, 100.0 );

		/**
		 * Fires when a certificate is issued.
		 *
		 * @param int    $user_id   Learner.
		 * @param int    $course_id Course.
		 * @param string $code      Public code.
		 */
		do_action( 'odsi_lms_certificate_issued', $user_id, $course_id, $code );

		return $code;
	}

	/**
	 * Verify a code.
	 *
	 * @param string $code Code.
	 *
	 * @return array<string, mixed>|null Details, or null when unknown or revoked.
	 */
	public function verify( string $code ): ?array {
		$row = $this->certificates->find_by_code( strtoupper( trim( $code ) ) );

		if ( ! $row || ! empty( $row->revoked_at ) ) {
			return null;
		}

		$user = get_userdata( (int) $row->user_id );

		return array(
			'code'      => (string) $row->code,
			'name'      => $user ? $user->display_name : __( 'A former member', 'odsi-lms' ),
			'course'    => html_entity_decode( (string) get_the_title( (int) $row->course_id ), ENT_QUOTES, 'UTF-8' ),
			'issued_at' => (string) $row->issued_at,
			'url'       => $this->url( (string) $row->code ),
		);
	}

	/**
	 * Public URL of a certificate.
	 *
	 * @param string $code Code.
	 */
	public function url( string $code ): string {
		return home_url( user_trailingslashit( 'certificate/' . rawurlencode( $code ) ) );
	}

	/**
	 * Render the certificate template with placeholders substituted.
	 *
	 * @param object $row Award row.
	 */
	public function render( object $row ): string {
		$user     = get_userdata( (int) $row->user_id );
		$template = get_post( (int) $row->certificate_id );
		$content  = $template ? $template->post_content : '{name} completed {course} on {date}.';

		$replacements = array(
			'{name}'   => esc_html( $user ? $user->display_name : '' ),
			'{course}' => esc_html( (string) get_the_title( (int) $row->course_id ) ),
			'{date}'   => esc_html( wp_date( (string) get_option( 'date_format' ), (int) strtotime( (string) $row->issued_at ) ) ),
			'{code}'   => esc_html( (string) $row->code ),
		);

		/**
		 * Filters the certificate placeholder map.
		 *
		 * @param array<string, string> $replacements Placeholder => value.
		 * @param object                $row          Award row.
		 */
		$replacements = (array) apply_filters( 'odsi_lms_certificate_placeholders', $replacements, $row );

		$body = strtr( wp_kses_post( $content ), $replacements );

		return $this->templates->render(
			'certificate',
			array(
				'body'  => wpautop( $body ),
				'row'   => $row,
				'title' => $template ? $template->post_title : __( 'Certificate', 'odsi-lms' ),
			)
		);
	}

	/**
	 * Query var.
	 *
	 * @param string[] $vars Vars.
	 *
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * `/certificate/{code}/`.
	 */
	public function rewrites(): void {
		if ( isset( $GLOBALS['wp_rewrite'] ) ) {
			add_rewrite_rule( '^certificate/([A-Za-z0-9\-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		}
	}

	/**
	 * Serve a certificate page. Anyone with the code may view it (the code is
	 * the credential); revoked codes 404.
	 */
	public function maybe_render(): void {
		$code = (string) get_query_var( self::QUERY_VAR, '' );

		if ( '' === $code ) {
			return;
		}

		$row = $this->certificates->find_by_code( strtoupper( $code ) );

		if ( ! $row || ! empty( $row->revoked_at ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );

			return;
		}

		echo $this->render( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output.
		exit;
	}

	/**
	 * `[odsi_certificate_verify]`
	 */
	public function verify_shortcode(): string {
		$code   = sanitize_text_field( wp_unslash( (string) ( $_GET['code'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public lookup.
		$result = '' !== $code ? $this->verify( $code ) : null;

		return $this->templates->render(
			'parts/certificate-verify',
			array(
				'code'   => $code,
				'result' => $result,
			)
		);
	}

	/**
	 * `[odsi_my_certificates]`
	 */
	public function my_certificates_shortcode(): string {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return $this->templates->render( 'parts/login-required' );
		}

		return $this->templates->render(
			'parts/my-certificates',
			array(
				'rows'         => array_filter( $this->certificates->for_user( $user_id ), static fn ( object $r ): bool => empty( $r->revoked_at ) ),
				'certificates' => $this,
			)
		);
	}

	/**
	 * A readable, unguessable code.
	 */
	private function generate_code(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

		do {
			$raw = '';

			for ( $i = 0; $i < 12; $i++ ) {
				$raw .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
			}

			$code = substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 4 ) . '-' . substr( $raw, 8, 4 );
		} while ( $this->certificates->find_by_code( $code ) );

		return $code;
	}
}
