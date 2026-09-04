<?php
/**
 * Content sanitisation.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One place for how member-authored text is cleaned and rendered.
 */
final class Sanitizer {

	/**
	 * Clean submitted text for storage.
	 *
	 * Callers pass unslashed text (REST parameters already are; the form
	 * handlers unslash `$_POST` themselves), so nothing is unslashed here: a
	 * second pass would eat the member's own backslashes.
	 *
	 * @param string $content    Raw, unslashed content.
	 * @param int    $max_length Maximum characters after cleaning.
	 *
	 * @return string Cleaned content; empty when nothing meaningful remains.
	 */
	public static function content( string $content, int $max_length ): string {
		$clean = trim( wp_kses( $content, wp_kses_allowed_html( 'post' ) ) );

		if ( '' === wp_strip_all_tags( $clean ) ) {
			return '';
		}

		if ( $max_length > 0 && mb_strlen( $clean ) > $max_length ) {
			$clean = mb_substr( $clean, 0, $max_length );
		}

		return $clean;
	}

	/**
	 * Render stored content for display: paragraphs, autolinks, mention links.
	 *
	 * A mentioned member who cannot see the content stays plain text
	 * (SOC-ACT-007); the caller says who can through `$can_see`.
	 *
	 * @param string                   $content Stored content.
	 * @param callable(int): bool|null $can_see Whether a mentioned member may see this content; null links everyone.
	 */
	public static function render( string $content, ?callable $can_see = null ): string {
		$html = wpautop( make_clickable( $content ) );

		// Rewrite mentions in text nodes only. Inside a tag an @nick would sit
		// in an attribute value, and a link injected there breaks the markup.
		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return $html;
		}

		foreach ( $parts as $i => $part ) {
			if ( '' === $part || '<' === $part[0] ) {
				continue;
			}

			$parts[ $i ] = (string) preg_replace_callback(
				'/(?<![\w\/])@([A-Za-z0-9_\-\.]+)/u',
				static function ( array $m ) use ( $can_see ): string {
					$user = get_user_by( 'slug', $m[1] );

					if ( ! $user || ( null !== $can_see && ! $can_see( (int) $user->ID ) ) ) {
						return $m[0];
					}

					$url = (string) apply_filters( 'odsi_social_member_url', '', (int) $user->ID );

					return sprintf( '<a class="odsi-social-mention" href="%s">@%s</a>', esc_url( $url ), esc_html( $m[1] ) );
				},
				$part
			);
		}//end foreach

		return implode( '', $parts );
	}
}
