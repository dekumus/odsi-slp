<?php
/**
 * Activity type renderers.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Activity;

use ODSI\Social\Contracts\ActivityRenderer;
use ODSI\Social\Support\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Registry mapping `component/type` to a renderer, with a content-only fallback
 * so a deactivated component never breaks a feed (SOC-ACT-011).
 */
final class Renderers {

	/**
	 * Registered renderers keyed by "component/type", with a wildcard component of "*".
	 *
	 * @var array<string, ActivityRenderer>
	 */
	private array $renderers = array();

	/**
	 * Constructor.
	 *
	 * @param Privacy $privacy Privacy rule, so mentions of members who cannot see an item stay plain text.
	 */
	public function __construct( private Privacy $privacy ) {
	}

	/**
	 * Register a renderer.
	 *
	 * @param string           $type      Activity type.
	 * @param ActivityRenderer $renderer  Renderer.
	 * @param string           $component Component, or `*` for any.
	 */
	public function register( string $type, ActivityRenderer $renderer, string $component = '*' ): void {
		$this->renderers[ $component . '/' . $type ] = $renderer;
	}

	/**
	 * Action sentence for a row.
	 *
	 * @param object $item Activity row.
	 */
	public function action( object $item ): string {
		$renderer = $this->for( $item );

		if ( $renderer ) {
			return $renderer->action( $item );
		}

		$name = self::author_link( (int) $item->user_id );

		if ( Activity::TYPE_COMMENT === (string) $item->type ) {
			/* translators: %s: member name, linked to their profile. */
			return sprintf( esc_html__( '%s commented', 'odsi-social' ), $name );
		}

		/* translators: %s: member name, linked to their profile. */
		return sprintf( esc_html__( '%s posted an update', 'odsi-social' ), $name );
	}

	/**
	 * A member's name linked to their profile, escaped, or "A former member"
	 * once the account is gone (SOC-MEM-010). For action sentences.
	 *
	 * @param int $user_id Member.
	 */
	public static function author_link( int $user_id ): string {
		$author = get_userdata( $user_id );

		if ( ! $author ) {
			return esc_html__( 'A former member', 'odsi-social' );
		}

		$url = (string) apply_filters( 'odsi_social_member_url', '', $user_id );

		if ( '' === $url ) {
			return esc_html( $author->display_name );
		}

		return sprintf( '<a class="odsi-social-item__author" href="%s">%s</a>', esc_url( $url ), esc_html( $author->display_name ) );
	}

	/**
	 * Body markup for a row.
	 *
	 * @param object $item Activity row.
	 */
	public function body( object $item ): string {
		$renderer = $this->for( $item );
		$html     = $renderer ? $renderer->body( $item ) : Sanitizer::render( (string) $item->content, fn ( int $user_id ): bool => $this->privacy->can_view( $user_id, $item ) );

		/**
		 * Filters rendered activity content.
		 *
		 * @param string $html Rendered markup.
		 * @param object $item Activity row.
		 */
		return (string) apply_filters( 'odsi_social_activity_content', $html, $item );
	}

	/**
	 * Resolve the renderer for a row.
	 *
	 * @param object $item Activity row.
	 */
	private function for( object $item ): ?ActivityRenderer {
		return $this->renderers[ $item->component . '/' . $item->type ]
			?? $this->renderers[ '*/' . $item->type ]
			?? null;
	}
}
