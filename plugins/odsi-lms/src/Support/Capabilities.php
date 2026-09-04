<?php
/**
 * Roles and capabilities.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the LMS role map and the capabilities each custom post type maps onto.
 *
 * Post types use `map_meta_cap` with plural/singular capability names, which lets
 * an instructor edit their own courses without gaining access to site content.
 */
final class Capabilities {

	public const MANAGE = 'manage_odsi_lms';
	public const REPORT = 'view_odsi_lms_reports';

	/**
	 * Custom roles added on activation, keyed by role slug.
	 *
	 * @return array<string, array{label: string, inherits: string}>
	 */
	public static function roles(): array {
		return array(
			'odsi_instructor' => array(
				'label'    => __( 'Instructor', 'odsi-lms' ),
				'inherits' => 'subscriber',
			),
			'odsi_student'    => array(
				'label'    => __( 'Student', 'odsi-lms' ),
				'inherits' => 'subscriber',
			),
		);
	}

	/**
	 * Capability groups for a post type, in the shape `register_post_type()` wants.
	 *
	 * @param string $singular Capability singular base, e.g. `odsi_course`.
	 * @param string $plural   Capability plural base, e.g. `odsi_courses`.
	 *
	 * @return array<string, string>
	 */
	public static function post_type_caps( string $singular, string $plural ): array {
		return array(
			'edit_post'              => "edit_{$singular}",
			'read_post'              => "read_{$singular}",
			'delete_post'            => "delete_{$singular}",
			'edit_posts'             => "edit_{$plural}",
			'edit_others_posts'      => "edit_others_{$plural}",
			'delete_posts'           => "delete_{$plural}",
			'publish_posts'          => "publish_{$plural}",
			'read_private_posts'     => "read_private_{$plural}",
			'delete_private_posts'   => "delete_private_{$plural}",
			'delete_published_posts' => "delete_published_{$plural}",
			'delete_others_posts'    => "delete_others_{$plural}",
			'edit_private_posts'     => "edit_private_{$plural}",
			'edit_published_posts'   => "edit_published_{$plural}",
			'create_posts'           => "edit_{$plural}",
		);
	}

	/**
	 * Capabilities an instructor holds: author their own LMS content and see
	 * reports. Nothing that reaches another author's content (LMS-AUT-008).
	 *
	 * @return string[]
	 */
	public static function instructor_caps(): array {
		$caps = array( self::REPORT, 'upload_files' );

		foreach ( self::capability_bases() as $singular => $plural ) {
			$caps[] = "edit_{$singular}";
			$caps[] = "read_{$singular}";
			$caps[] = "delete_{$singular}";
			$caps[] = "edit_{$plural}";
			$caps[] = "delete_{$plural}";
			$caps[] = "publish_{$plural}";
			$caps[] = "edit_published_{$plural}";
			$caps[] = "delete_published_{$plural}";
		}

		return array_values( array_unique( $caps ) );
	}

	/**
	 * Every LMS capability, for administrators and anyone managing the LMS.
	 *
	 * @return string[]
	 */
	public static function manager_caps(): array {
		$caps = array( self::MANAGE, self::REPORT );

		foreach ( self::capability_bases() as $singular => $plural ) {
			$caps = array_merge( $caps, array_values( self::post_type_caps( $singular, $plural ) ) );
		}

		return array_values( array_unique( $caps ) );
	}

	/**
	 * Capability bases for each managed post type, singular => plural.
	 *
	 * @return array<string, string>
	 */
	public static function capability_bases(): array {
		return array(
			'odsi_course'      => 'odsi_courses',
			'odsi_lesson'      => 'odsi_lessons',
			'odsi_topic'       => 'odsi_topics',
			'odsi_quiz'        => 'odsi_quizzes',
			'odsi_question'    => 'odsi_questions',
			'odsi_certificate' => 'odsi_certificates',
			'odsi_cohort'      => 'odsi_cohorts',
		);
	}

	/**
	 * Create custom roles and grant capabilities to existing ones.
	 */
	public static function install(): void {
		foreach ( self::roles() as $slug => $role ) {
			$inherited = get_role( $role['inherits'] );
			$caps      = $inherited instanceof \WP_Role ? $inherited->capabilities : array( 'read' => true );
			$existing  = get_role( $slug );

			// Re-activation must not throw away an admin's edits to the role.
			if ( $existing instanceof \WP_Role ) {
				foreach ( array_keys( array_filter( $caps ) ) as $cap ) {
					if ( ! $existing->has_cap( $cap ) ) {
						$existing->add_cap( $cap );
					}
				}

				continue;
			}

			add_role( $slug, $role['label'], $caps );
		}

		$grants = array(
			'administrator'   => self::manager_caps(),
			'odsi_instructor' => self::instructor_caps(),
		);

		foreach ( $grants as $slug => $caps ) {
			$role = get_role( $slug );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove custom roles and capabilities. Called from uninstall only.
	 */
	public static function uninstall(): void {
		foreach ( array_keys( self::roles() ) as $slug ) {
			remove_role( $slug );
		}

		$role = get_role( 'administrator' );

		if ( ! $role instanceof \WP_Role ) {
			return;
		}

		foreach ( self::manager_caps() as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}
