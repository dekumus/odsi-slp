<?php
/**
 * Member directory.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Members;

use ODSI\Social\Repositories\BlockRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Support\Capabilities;
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The directory query (SOC-MEM-008/009).
 */
final class Directory {

	/**
	 * Constructor.
	 *
	 * @param MemberRepository $members  Member index.
	 * @param Profiles         $profiles Profiles, for cards.
	 * @param Settings         $settings Settings.
	 * @param BlockRepository  $blocks   Blocks: a blocked pair never lists each other (SOC-MOD-005).
	 */
	public function __construct(
		private MemberRepository $members,
		private Profiles $profiles,
		private Settings $settings,
		private BlockRepository $blocks
	) {
	}

	/**
	 * Whether a viewer may see the directory.
	 *
	 * @param int $viewer_id Viewer.
	 */
	public function can_view( int $viewer_id ): bool {
		return $viewer_id > 0 || $this->settings->bool( 'public_directory' );
	}

	/**
	 * A page of members.
	 *
	 * @param int                  $viewer_id Viewer.
	 * @param array<string, mixed> $args      `search`, `orderby`, `page`, `per_page`.
	 *
	 * @return array{members: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function query( int $viewer_id, array $args = array() ): array {
		$args = array_merge(
			array(
				'search'   => '',
				'orderby'  => 'newest',
				'page'     => 1,
				'per_page' => $this->settings->int( 'directory_per_page' ),
			),
			$args
		);

		$args['search']  = sanitize_text_field( (string) $args['search'] );
		$args['orderby'] = in_array( $args['orderby'], array( 'newest', 'active', 'alphabetical' ), true ) ? (string) $args['orderby'] : 'newest';

		if ( $viewer_id > 0 && ! Capabilities::is_admin( $viewer_id ) ) {
			$args['exclude'] = array_keys( $this->blocks->ids_for( $viewer_id ) );
		}

		/**
		 * Filters the directory query arguments.
		 *
		 * @param array<string, mixed> $args      Args.
		 * @param int                  $viewer_id Viewer.
		 */
		$args = (array) apply_filters( 'odsi_social_directory_query_args', $args, $viewer_id );

		$result = $this->members->directory( $args );

		$this->profiles->prime( $viewer_id, $result['ids'] );

		$members = array();

		foreach ( $result['ids'] as $user_id ) {
			$profile = $this->profiles->view( $viewer_id, $user_id );

			if ( $profile ) {
				unset( $profile['field_groups'] );
				$members[] = $profile;
			}
		}

		return array(
			'members'  => $members,
			'total'    => $result['total'],
			'page'     => max( 1, (int) $args['page'] ),
			'per_page' => max( 1, (int) $args['per_page'] ),
		);
	}
}
