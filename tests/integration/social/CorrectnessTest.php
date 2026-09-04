<?php
/**
 * Regressions for the correctness review: rendering, routing, priming,
 * maintenance and uninstall. Spec: SOC-ACT-003/007/035, SOC-GRP-010, SOC-IF-003.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Activity\Feed;
use ODSI\Social\Admin\AdminMenu;
use ODSI\Social\Frontend\Router;
use ODSI\Social\Frontend\Shortcodes;
use ODSI\Social\Frontend\Templates;
use ODSI\Social\Groups\Groups;
use ODSI\Social\Installer;
use ODSI\Social\Maintenance;
use ODSI\Social\Members\ProfileFields;
use ODSI\Social\Members\Profiles;
use ODSI\Social\Messages\Messages;
use ODSI\Social\Notifications\Emails;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\PostTypes\GroupPostType;
use ODSI\Social\Repositories\ActivityRepository;
use ODSI\Social\Repositories\GroupRepository;
use ODSI\Social\Repositories\MemberRepository;
use ODSI\Social\Support\Settings;
use ODSI\Social\Uninstaller;
use ODSI\Tests\Integration\TestCase;

final class CorrectnessTest extends TestCase {

	private Shortcodes $shortcodes;
	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->shortcodes = $this->social->service( Shortcodes::class );
		$this->settings   = $this->social->service( Settings::class );
	}

	public function tear_down(): void {
		unset( $_GET['scope'] );
		$this->route( '', '', '' );
		parent::tear_down();
	}

	public function test_act_003_post_form_offers_only_allowed_privacy_and_preselects_the_default(): void {
		$u = $this->social->member();
		$this->settings->update(
			array(
				'allowed_privacy' => array( 'public', 'connections' ),
				'default_privacy' => 'connections',
			)
		);

		try {
			$html = $this->as_user( $u, fn (): string => $this->shortcodes->render_feed( array() ) );
		} finally {
			$this->settings->update( array_intersect_key( Settings::defaults(), array_flip( array( 'allowed_privacy', 'default_privacy' ) ) ) );
		}

		self::assertStringContainsString( "<option value=\"connections\"  selected='selected'>", $html );
		self::assertStringContainsString( '<option value="public" >', $html );
		self::assertStringNotContainsString( 'value="only_me"', $html );
		self::assertStringNotContainsString( 'value="members"', $html );
	}

	public function test_following_tab_switches_the_feed_scope_only_with_tabs_and_a_member(): void {
		$u            = $this->social->member();
		$_GET['scope'] = 'personal';

		self::assertStringContainsString( 'data-scope="personal"', $this->as_user( $u, fn (): string => $this->shortcodes->render_feed( array( 'show_tabs' => 1 ) ) ) );
		self::assertStringContainsString( 'data-scope="site"', $this->as_user( $u, fn (): string => $this->shortcodes->render_feed( array() ) ), 'Without tabs the block keeps its configured scope.' );
		self::assertStringContainsString( 'data-scope="site"', $this->shortcodes->render_feed( array( 'show_tabs' => 1 ) ), 'Visitors have no personal feed.' );
	}

	public function test_if_003_router_decides_404_before_output(): void {
		$router   = $this->social->service( Router::class );
		$owner    = $this->social->member( 'router-owner' );
		$stranger = $this->social->member( 'router-stranger' );
		$hidden   = $this->social->group( $owner, 'hidden', 'Vault' );
		$slug     = (string) get_post( $hidden )->post_name;
		$secret   = $this->social->update( $owner, 'mine only', 'only_me' );
		$thread   = (int) $this->social->service( Messages::class )->send( $owner, $stranger, 'psst' )->thread_id;
		$outsider = $this->social->member();

		self::assertSame( 200, $router->status_for( 'members', '', '', 0 ) );
		self::assertSame( 200, $router->status_for( 'members', 'router-owner', '', 0 ) );
		self::assertSame( 404, $router->status_for( 'members', 'nobody-here', '', 0 ) );
		self::assertSame( 200, $router->status_for( 'members', 'router-owner', 'edit', 0 ), 'A visitor is asked to log in, not told the page is missing.' );
		self::assertSame( 404, $router->status_for( 'members', 'router-owner', 'edit', $stranger ) );
		self::assertSame( 200, $router->status_for( 'members', 'router-owner', 'edit', $owner ) );

		self::assertSame( 404, $router->status_for( 'groups', $slug, '', $stranger ), 'SOC-GRP-005: hidden is 404 to non-members.' );
		self::assertSame( 200, $router->status_for( 'groups', $slug, '', $owner ) );
		self::assertSame( 404, $router->status_for( 'groups', 'no-such-group', '', $owner ) );
		self::assertSame( 200, $router->status_for( 'groups', $slug, 'manage', $owner ) );

		self::assertSame( 404, $router->status_for( 'activity', (string) $secret, '', $stranger ) );
		self::assertSame( 200, $router->status_for( 'activity', (string) $secret, '', $owner ) );
		self::assertSame( 404, $router->status_for( 'activity', '999999', '', $owner ) );

		self::assertSame( 404, $router->status_for( 'messages', (string) $thread, '', $outsider ) );
		self::assertSame( 200, $router->status_for( 'messages', (string) $thread, '', $stranger ) );

		self::assertFalse( apply_filters( 'odsi_social_page_exists', true, 'groups', $slug, '', $stranger ) );
	}

	public function test_page_url_filter_follows_the_configured_slugs(): void {
		$this->settings->update( array( 'slug_messages' => 'inbox' ) );

		try {
			self::assertSame( home_url( user_trailingslashit( 'inbox' ) ), apply_filters( 'odsi_social_page_url', home_url( '/messages/' ), 'messages', '', '' ) );
			self::assertSame( home_url( user_trailingslashit( 'groups/readers/manage' ) ), apply_filters( 'odsi_social_page_url', '', 'groups', 'readers', 'manage' ) );
		} finally {
			$this->settings->update( array( 'slug_messages' => 'messages' ) );
		}

		$owner   = $this->social->member( 'linked' );
		$viewer  = $this->social->member();
		$profile = $this->social->service( Profiles::class )->view( $viewer, $owner );
		$html    = $this->social->service( Templates::class )->render(
			'members/profile',
			array(
				'profile'      => $profile,
				'viewer_id'    => $viewer,
				'feed'         => '',
				'is_following' => false,
			)
		);

		self::assertStringContainsString( esc_url( add_query_arg( 'to', $owner, home_url( user_trailingslashit( 'messages' ) ) ) ), $html );
	}

	public function test_act_007_mentions_of_members_who_cannot_see_the_item_stay_plain_text(): void {
		$author   = $this->social->member( 'render-author' );
		$friend   = $this->social->member( 'render-friend' );
		$this->social->member( 'render-outsider' );
		$this->social->connect( $author, $friend );

		$item = $this->social->update( $author, 'hi @render-friend and @render-outsider', 'connections' );
		$html = (string) $this->social->service( Feed::class )->item( $author, $item )['content'];

		self::assertSame( 1, substr_count( $html, 'odsi-social-mention' ) );
		self::assertStringContainsString( '>@render-friend</a>', $html );
		self::assertStringContainsString( ' @render-outsider', $html );
		self::assertStringNotContainsString( '>@render-outsider</a>', $html );
	}

	public function test_mem_008_member_directory_costs_a_fixed_number_of_queries(): void {
		$viewer = $this->social->member();
		$fields = $this->social->service( ProfileFields::class );
		$group  = $fields->create_group( 'About' );
		$city   = $fields->create( $group, 'City', 'text' );
		$ids    = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $this->social->member();
		}

		foreach ( $ids as $i => $id ) {
			$this->social->service( Profiles::class )->update_fields( $id, array( $city => array( 'value' => "Town {$i}" ) ) );
		}

		$this->social->connect( $viewer, $ids[0] );
		$this->social->service( MemberRepository::class )->flush();
		$this->social->service( \ODSI\Social\Repositories\ProfileDataRepository::class )->flush();
		$this->social->service( \ODSI\Social\Repositories\ConnectionRepository::class )->flush();
		$fields->flush();

		$directory = $this->social->service( \ODSI\Social\Members\Directory::class );
		$result    = null;
		$used      = $this->social->count_queries(
			function () use ( &$result, $directory, $viewer ): void {
				$result = $directory->query( $viewer, array( 'per_page' => 20 ) );
			}
		);

		self::assertGreaterThanOrEqual( 13, count( $result['members'] ) );
		self::assertSame( 'accepted', array_column( $result['members'], 'viewer', 'id' )[ $ids[0] ]['connection'] );
		self::assertLessThanOrEqual( 10, $used, "{$used} queries for a directory page." );
	}

	public function test_grp_004_groups_directory_costs_a_fixed_number_of_queries(): void {
		$viewer = $this->social->member();
		$owner  = $this->social->member();
		$ids    = array();

		for ( $i = 0; $i < 10; $i++ ) {
			$ids[] = $this->social->group( $owner, $i % 2 ? 'private' : 'public', "Circle {$i}" );
		}

		$this->social->add_to_group( $ids[1], $viewer );
		$this->social->service( GroupRepository::class )->flush();
		$this->social->service( \ODSI\Social\Repositories\GroupMemberRepository::class )->flush();
		wp_cache_flush();

		$groups = $this->social->service( Groups::class );
		$cards  = array();
		$used   = $this->social->count_queries(
			function () use ( &$cards, $groups, $viewer, $ids ): void {
				$groups->prime( $viewer, $ids );

				foreach ( $ids as $id ) {
					$cards[] = $groups->present( $viewer, $id );
				}
			}
		);

		self::assertCount( 10, array_filter( $cards ) );
		self::assertSame( 'active', array_column( $cards, 'viewer', 'id' )[ $ids[1] ]['status'] );
		self::assertLessThanOrEqual( 8, $used, "{$used} queries to present ten groups." );

		$html = $this->as_user( $viewer, fn (): string => $this->shortcodes->render_groups() );
		self::assertStringContainsString( 'Your groups', $html, 'SOC-GRP-010' );
		self::assertStringContainsString( 'Circle 1', $html );
	}

	public function test_not_005_notifications_list_costs_a_fixed_number_of_queries(): void {
		$u             = $this->social->member();
		$notifications = $this->social->service( Notifications::class );

		for ( $i = 0; $i < 15; $i++ ) {
			$notifications->notify( $u, $this->social->member(), 'connections', 'requested', $i + 1 );
		}

		$this->social->service( MemberRepository::class )->flush();
		wp_cache_flush();

		$list = array();
		$used = $this->social->count_queries(
			function () use ( &$list, $notifications, $u ): void {
				$list = $notifications->list( $u );
			}
		);

		self::assertCount( 15, $list );
		self::assertNotSame( '', $list[0]['actor']['avatar'] );
		self::assertLessThanOrEqual( 6, $used, "{$used} queries for a notifications page." );
	}

	public function test_msg_006_inbox_costs_a_fixed_number_of_queries_and_paginates(): void {
		$u        = $this->social->member();
		$messages = $this->social->service( Messages::class );

		for ( $i = 0; $i < 8; $i++ ) {
			$messages->send( $this->social->member(), $u, "hello {$i}" );
		}

		$this->social->service( MemberRepository::class )->flush();
		wp_cache_flush();

		$inbox = array();
		$used  = $this->social->count_queries(
			function () use ( &$inbox, $messages, $u ): void {
				$inbox = $messages->inbox( $u );
			}
		);

		self::assertCount( 8, $inbox );
		self::assertSame( 'hello 7', $inbox[0]['last_message'] );
		self::assertNotSame( 'A former member', $inbox[0]['other']['name'] );
		self::assertLessThanOrEqual( 8, $used, "{$used} queries for an inbox page." );
		self::assertSame( 8, $messages->inbox_count( $u ) );
		self::assertCount( 3, $messages->inbox( $u, 2, 5 ) );
	}

	public function test_maintenance_recounts_denormalised_counters(): void {
		global $wpdb;

		$owner = $this->social->member();
		$other = $this->social->member();
		$group = $this->social->group( $owner, 'public' );
		$this->social->add_to_group( $group, $other );
		$item = $this->social->update( $owner, 'count me', 'public', $group );
		$this->social->comment( $other, $item );
		$this->social->service( \ODSI\Social\Activity\Reactions::class )->set( $other, $item );

		$activity = $this->social->service( ActivityRepository::class );
		$groups   = $this->social->service( GroupRepository::class );
		$members  = $this->social->service( MemberRepository::class );

		$wpdb->update(
			$activity->table(),
			array(
				'comment_count'  => 9,
				'reaction_count' => 0,
			),
			array( 'id' => $item )
		);
		$wpdb->update( $groups->table(), array( 'member_count' => 40 ), array( 'post_id' => $group ) );
		$wpdb->update( $members->table(), array( 'activity_count' => 7 ), array( 'user_id' => $owner ) );
		$groups->flush();
		$members->flush();

		$this->social->service( Maintenance::class )->run();

		self::assertSame( 1, (int) $activity->find( $item )->comment_count );
		self::assertSame( 1, (int) $activity->find( $item )->reaction_count );
		self::assertSame( 2, (int) $groups->find( $group )->member_count );
		self::assertSame( 2, (int) $members->find( $owner )->activity_count, 'The update and the "created a group" item.' );
	}

	public function test_uninstall_purge_removes_content_outside_the_tables(): void {
		$owner = $this->social->member();
		$group = $this->social->group( $owner, 'public', 'Doomed' );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $image, '_odsi_social_image', 'avatar' );
		Emails::set_wants_email( $owner, false );
		set_transient( 'odsi_social_conn_cooldown_1_2', 1, HOUR_IN_SECONDS );
		update_option( Router::FLUSH_OPTION, '1', false );

		if ( ! wp_next_scheduled( Installer::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Installer::CRON_HOOK );
		}

		Uninstaller::purge_content();

		self::assertNull( get_post( $group ) );
		self::assertNull( get_post( $image ) );
		self::assertSame( '', get_user_meta( $owner, Emails::USER_META, true ) );
		self::assertFalse( get_transient( 'odsi_social_conn_cooldown_1_2' ) );
		self::assertFalse( get_option( Router::FLUSH_OPTION ) );
		self::assertFalse( wp_next_scheduled( Installer::CRON_HOOK ) );
		self::assertSame(
			array(),
			get_posts(
				array(
					'post_type' => GroupPostType::NAME,
					'post_status' => 'any',
					'fields' => 'ids',
				)
			)
		);
	}

	public function test_profile_renders_textarea_fields_with_line_breaks(): void {
		$owner  = $this->social->member( 'multiline' );
		$fields = $this->social->service( ProfileFields::class );
		$group  = $fields->create_group( 'About' );
		$bio    = $fields->create( $group, 'Bio', 'textarea' );
		$this->social->service( Profiles::class )->update_fields( $owner, array( $bio => array( 'value' => "First line\n\nSecond <b>para</b>" ) ) );

		$html = $this->social->service( Templates::class )->render(
			'members/profile',
			array(
				'profile'      => $this->social->service( Profiles::class )->view( 0, $owner ),
				'viewer_id'    => 0,
				'feed'         => '',
				'is_following' => false,
			)
		);

		self::assertStringContainsString( '<p>First line</p>', $html );
		self::assertStringContainsString( 'Second para', $html );
		self::assertStringNotContainsString( '<b>para</b>', $html, 'Textarea values are text, not markup.' );
	}

	public function test_admin_settings_expose_every_setting_and_validate_them(): void {
		$menu = $this->social->service( AdminMenu::class );

		ob_start();
		$menu->render_settings();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="allowed_privacy[]"', $html );
		self::assertStringContainsString( '<select id="default_privacy"', $html );
		self::assertStringContainsString( 'name="directory_per_page"', $html );
		self::assertStringContainsString( 'name="message_max_length"', $html );
		self::assertStringContainsString( 'name="avatar_max_px"', $html );
		self::assertStringContainsString( 'name="avatar_types"', $html );

		$values = $menu->sanitize_settings(
			array(
				'allowed_privacy'    => array( 'public', 'bogus', 'connections' ),
				'default_privacy'    => 'only_me',
				'feed_per_page'      => '0',
				'directory_per_page' => '0',
				'avatar_types'       => 'PNG, exe, jpg',
				'avatar_max_px'      => '10',
			)
		);

		self::assertSame( array( 'public', 'connections' ), $values['allowed_privacy'] );
		self::assertSame( 'public', $values['default_privacy'], 'The default is always an allowed level.' );
		self::assertSame( 1, $values['feed_per_page'] );
		self::assertSame( 1, $values['directory_per_page'] );
		self::assertSame( array( 'jpg', 'png' ), $values['avatar_types'] );
		self::assertSame( 64, $values['avatar_max_px'] );

		$empty = $menu->sanitize_settings( array( 'allowed_privacy' => array() ) );
		self::assertSame( \ODSI\Social\Activity\Privacy::choices(), $empty['allowed_privacy'], 'An empty set falls back to every level.' );
	}

	public function test_group_page_lists_members_and_directory_pages_paginate(): void {
		$owner  = $this->social->member( 'listed-owner' );
		$member = $this->social->member( 'listed-member' );
		$group  = $this->social->group( $owner, 'public', 'Listed' );
		$this->social->add_to_group( $group, $member );

		$this->route( 'groups', 'listed', '' );
		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );

		self::assertStringContainsString( 'odsi-social-group__members', $html );
		self::assertStringContainsString( get_userdata( $owner )->display_name, $html );
		self::assertStringContainsString( get_userdata( $member )->display_name, $html );

		$private = $this->social->group( $owner, 'private', 'Closed' );
		$this->route( 'groups', 'closed', '' );
		self::assertStringNotContainsString( 'odsi-social-group__members', $this->as_user( $member, fn (): string => $this->shortcodes->render_page() ), 'Non-members see no member list of a private group.' );
		self::assertGreaterThan( 0, $private );

		$notifications = $this->social->service( Notifications::class );

		for ( $i = 0; $i < 25; $i++ ) {
			$notifications->notify( $member, $owner, 'connections', 'requested', $i + 1 );
		}

		$this->route( 'notifications', '', '' );
		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		self::assertSame( 20, substr_count( $html, 'odsi-social-notification ' ) );
		self::assertStringContainsString( 'page-numbers', $html );

		$this->route( 'messages', '', '' );
		self::assertStringNotContainsString( 'page-numbers', $this->as_user( $member, fn (): string => $this->shortcodes->render_page() ), 'One page needs no links.' );
	}

	/**
	 * Point the router at a community page.
	 */
	private function route( string $section, string $object_slug, string $action ): void {
		set_query_var( Router::QV_PAGE, $section );
		set_query_var( Router::QV_OBJECT, $object_slug );
		set_query_var( Router::QV_ACTION, $action );
	}
}
