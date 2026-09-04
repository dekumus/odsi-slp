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

		$author = get_userdata( (int) $item->user_id );
		$name   = $author ? $author->display_name : __( 'A former member', 'odsi-social' );

		if ( Activity::TYPE_COMMENT === (string) $item->type ) {
			/* translators: %s: member name. */
			return sprintf( esc_html__( '%s commented', 'odsi-social' ), esc_html( $name ) );
		}

		/* translators: %s: member name. */
		return sprintf( esc_html__( '%s posted an update', 'odsi-social' ), esc_html( $name ) );
	}

	/**
	 * Body markup for a row.
	 *
	 * @param object $item Activity row.
	 */
	public function body( object $item ): string {
		$renderer = $this->for( $item );
		$html     = $renderer ? $renderer->body( $item ) : Sanitizer::render( (string) $item->content );

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
