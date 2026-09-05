<?php
/**
 * Front-end surfaces: routing through the theme, page titles, markup
 * semantics, states, and the contracts between templates, stylesheet and
 * script. Spec: SOC-IF-001..004, ADR-011.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Admin\AdminMenu;
use ODSI\Social\Frontend\Router;
use ODSI\Social\Frontend\Shortcodes;
use ODSI\Social\Notifications\Notifications;
use ODSI\Social\Plugin;
use ODSI\Social\Support\Assets;
use ODSI\Tests\Integration\TestCase;

final class FrontendTest extends TestCase {

	private const NS = '/odsi-social/v1';

	private Shortcodes $shortcodes;

	public function set_up(): void {
		parent::set_up();
		$this->shortcodes = $this->social->service( Shortcodes::class );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		$this->route( '', '', '' );
		parent::tear_down();
	}

	/* ---------- Routing ---------- */

	public function test_routed_pages_are_singular_pages_that_resolve_the_theme_page_template(): void {
		$member = $this->social->member( 'routed-member' );
		$this->set_permalink_structure( '/%postname%/' );

		$this->go_to( '/members/routed-member/' );

		self::assertTrue( is_page(), 'A community page is a singular page for the template hierarchy.' );
		self::assertTrue( is_singular() );
		self::assertFalse( is_home() );
		self::assertFalse( is_404() );
		self::assertSame( 1, $GLOBALS['wp_query']->post_count );
		self::assertSame( '[odsi_social_page]', $GLOBALS['wp_query']->post->post_content );
		self::assertSame( get_userdata( $member )->display_name, get_the_title(), 'The page title is the member\'s name.' );
		self::assertStringStartsWith( get_userdata( $member )->display_name . ' ', wp_get_document_title() );
		self::assertSame( '', (string) $this->as_user( $this->social->admin(), static fn () => get_edit_post_link( 0 ) ), 'No "Edit Page" link to a post that does not exist.' );
		self::assertContains( 'odsi-social-page-members-single', get_body_class() );
		self::assertSame( array( 'page-odsi-social-members.php', 'page-odsi-social.php', 'page.php' ), apply_filters( 'page_template_hierarchy', array( 'page.php' ) ), 'Themes may supply community page templates.' );
		self::assertFalse( apply_filters( 'redirect_canonical', home_url( '/elsewhere/' ) ), 'No canonical redirect for a virtual page.' );

		$this->go_to( '/members/?paged=2' );
		self::assertTrue( is_page() );
		self::assertTrue( is_paged() );
		self::assertSame( 2, (int) get_query_var( 'paged' ) );

		// A page that does not exist for the viewer is an ordinary core 404.
		$this->go_to( '/members/nobody-here/' );
		self::assertTrue( is_404() );
		self::assertFalse( is_page() );
		self::assertSame( 0, $GLOBALS['wp_query']->post_count );
		self::assertSame( 404, $this->social->service( Router::class )->status_for( 'members', 'nobody-here', '', 0 ) );
	}

	public function test_block_theme_renders_community_pages_with_its_page_and_404_templates(): void {
		$theme = wp_get_theme( 'twentytwentyfive' );

		if ( ! $theme->exists() || ! $theme->is_block_theme() ) {
			self::markTestSkipped( 'No bundled block theme in this test WordPress.' );
		}

		$previous = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		// `setup_theme` ran at bootstrap for the previous theme; give this one what core gives a block theme.
		add_theme_support( 'block-templates' );
		self::assertTrue( wp_is_block_theme() );
		$this->set_permalink_structure( '/%postname%/' );

		try {
			$this->go_to( '/activity/' );
			$template = get_page_template();
			self::assertStringEndsWith( 'template-canvas.php', $template, 'Block themes render through the canvas.' );
			self::assertSame( 'twentytwentyfive//page', $GLOBALS['_wp_current_template_id'], 'The page template, not the blog index.' );
			self::assertTrue( is_page() );

			$this->go_to( '/groups/no-such-group/' );
			self::assertTrue( is_404() );
			get_404_template();
			self::assertSame( 'twentytwentyfive//404', $GLOBALS['_wp_current_template_id'], 'A missing object renders the theme\'s 404 look.' );
		} finally {
			remove_theme_support( 'block-templates' );
			switch_theme( $previous );
		}
	}

	public function test_page_titles_name_the_routed_object(): void {
		$router = $this->social->service( Router::class );
		$member = $this->social->member( 'titled-member' );
		$other  = $this->social->member( 'titled-other' );
		$group  = $this->social->group( $member, 'public', 'Titled Group' );
		$item   = $this->social->update( $member, 'hello', 'public' );
		$thread = $this->social->service( \ODSI\Social\Messages\Messages::class )->send( $member, $other, 'hi' );

		self::assertSame( 'Members', $router->title_for( 'members', '', '', 0 ) );
		self::assertSame( get_userdata( $member )->display_name, $router->title_for( 'members', 'titled-member', '', 0 ) );
		self::assertSame( 'Edit profile', $router->title_for( 'members', 'titled-member', 'edit', $member ) );
		self::assertSame( 'Titled Group', $router->title_for( 'groups', 'titled-group', '', 0 ) );
		self::assertSame( 'Manage Titled Group', $router->title_for( 'groups', 'titled-group', 'manage', $member ) );
		self::assertSame( 'Post by ' . get_userdata( $member )->display_name, $router->title_for( 'activity', (string) $item, '', 0 ) );
		self::assertSame( 'Conversation with ' . get_userdata( $other )->display_name, $router->title_for( 'messages', (string) $thread->thread_id, '', $member ) );
		self::assertSame( 'Notifications', $router->title_for( 'notifications', '', '', $member ) );
	}

	/* ---------- Feed semantics ---------- */

	public function test_feed_is_a_list_of_articles_with_labelled_accessible_controls(): void {
		$author = $this->social->member( 'feed-author' );
		$viewer = $this->social->member();
		$item   = $this->social->update( $author, 'Semantic post', 'public' );
		$this->social->comment( $viewer, $item, 'a comment' );
		$this->social->service( \ODSI\Social\Activity\Reactions::class )->set( $viewer, $item, 'like' );

		$this->route( 'activity', '', '' );
		$html = $this->as_user( $viewer, fn (): string => $this->shortcodes->render_page() );

		self::assertStringContainsString( '<nav class="odsi-social-feed__tabs" aria-label="Feed">', $html );
		self::assertStringContainsString( 'aria-current="page"', $html );
		self::assertStringContainsString( '<ul class="odsi-social-feed__items">', $html, 'The feed is a list.' );
		self::assertStringContainsString( '<li class="odsi-social-feed__item">', $html );
		self::assertMatchesRegularExpression( '#<article class="odsi-social-item" data-activity-id="' . $item . '" aria-labelledby="odsi-social-item-' . $item . '-action" tabindex="-1">#', $html, 'Each item is an article labelled by its action sentence.' );
		self::assertStringContainsString( '<a class="odsi-social-item__author" href="' . home_url( '/members/feed-author' ), $html, 'The author\'s name links to their profile.' );
		self::assertStringContainsString( '<time datetime="', $html );
		self::assertStringContainsString( 'class="odsi-social-item__react is-active" aria-pressed="true"', $html, 'Reaction state is exposed as a pressed button.' );
		self::assertStringContainsString( 'aria-expanded="false" aria-controls="odsi-social-item-' . $item . '-comment-form"', $html );
		self::assertStringContainsString( '<ul class="odsi-social-comment-list">', $html, 'Comments are a list.' );
		self::assertStringContainsString( '<li class="odsi-social-comment" data-activity-id=', $html );
		self::assertStringContainsString( 'odsi-social-comment__delete', $html, 'Comment authors can delete their comment.' );

		// The post form: label, limit announced, privacy select labelled, translated privacy labels.
		self::assertMatchesRegularExpression( '#<label class="odsi-social-visually-hidden" for="(odsi-social-post-\d+)-content">What is on your mind\?</label>\s*<textarea id="\1-content"#', $html );
		self::assertStringContainsString( 'maxlength="5000"', $html );
		self::assertStringContainsString( '5000 characters left', $html );
		self::assertStringContainsString( 'role="status" aria-live="polite"', $html );
		self::assertMatchesRegularExpression( '#<label class="odsi-social-visually-hidden" for="(odsi-social-post-\d+)-privacy">Who can see this</label>\s*<select id="\1-privacy"#', $html );
		self::assertStringContainsString( '<option value="only_me" >Only me</option>', $html, 'Privacy labels are translated, never ucwords()ed keys.' );
		self::assertStringNotContainsString( 'Only_me', $html );
		self::assertStringContainsString( '<option value="connections" >My connections</option>', $html );
		self::assertStringContainsString( '<dialog class="odsi-social-report-dialog" aria-labelledby="odsi-social-report-title">', $html, 'Reporting is a dialog.' );
		self::assertStringContainsString( '<h2 class="odsi-social-report-dialog__title" id="odsi-social-report-title">', $html );
		self::assertStringNotContainsString( '<h1', $html, 'The page heading belongs to the theme.' );

		// A visitor sees counts as text, no controls, no dialog.
		$visitor = $this->shortcodes->render_page();
		self::assertStringContainsString( '<span class="odsi-social-item__likes">1 like</span>', $visitor );
		self::assertStringNotContainsString( 'odsi-social-item__react', $visitor );
		self::assertStringNotContainsString( 'odsi-social-report-dialog', $visitor );
	}

	public function test_load_more_and_posted_items_are_identical_to_the_page(): void {
		$author = $this->social->member();
		$viewer = $this->social->member();
		$item   = $this->social->update( $author, 'Identical everywhere', 'public' );
		$this->social->comment( $viewer, $item, 'same comment' );

		$this->route( 'activity', '', '' );
		$page = $this->as_user( $viewer, fn (): string => $this->shortcodes->render_page() );
		$rest = $this->as_user(
			$viewer,
			fn () => $this->rest(
				'GET',
				self::NS . '/activity',
				array(
					'render' => '1',
				)
			)
		)->get_data()['items'][0];

		self::assertSame( $item, $rest['id'] );
		self::assertStringContainsString( $rest['html'], $page, 'A "load more" item is byte-for-byte the page\'s item.' );

		// A freshly posted update and comment can come back rendered too.
		$posted = $this->as_user(
			$viewer,
			fn () => $this->rest(
				'POST',
				self::NS . '/activity',
				array(
					'content' => 'Rendered on post',
					'privacy' => 'public',
					'render'  => '1',
				)
			)
		);
		self::assertSame( 201, $posted->get_status() );
		self::assertStringContainsString( '<li class="odsi-social-feed__item">', $posted->get_data()['html'] );
		self::assertStringContainsString( 'Rendered on post', $posted->get_data()['html'] );

		$comment = $this->as_user(
			$viewer,
			fn () => $this->rest(
				'POST',
				self::NS . "/activity/{$item}/comments",
				array(
					'content' => 'Rendered comment',
					'render'  => '1',
				)
			)
		);
		self::assertSame( 201, $comment->get_status() );
		self::assertStringStartsWith( '<li class="odsi-social-comment" data-activity-id="' . $comment->get_data()['id'] . '" data-parent-id="' . $item . '">', trim( $comment->get_data()['html'] ) );

		$this->route( 'activity', '', '' );
		$again = $this->as_user( $viewer, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( trim( $comment->get_data()['html'] ), $again, 'The rendered comment is what the page renders.' );

		self::assertArrayNotHasKey( 'html', $this->as_user( $viewer, fn () => $this->rest( 'GET', self::NS . '/activity' ) )->get_data()['items'][0], 'Without render=1 there is no markup.' );
	}

	/* ---------- States ---------- */

	public function test_empty_states_say_what_is_missing_and_what_to_do_next(): void {
		$member = $this->social->member( 'lonely' );

		$this->route( 'activity', '', '' );
		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( '<ul class="odsi-social-feed__items">', $html, 'The list exists even when empty, so the script can fill it.' );
		self::assertStringContainsString( 'No updates yet. Share the first one above.', $html );

		$visitor = $this->shortcodes->render_page();
		self::assertStringContainsString( 'No updates yet.', $visitor );
		self::assertStringContainsString( 'Log in to post', $visitor );

		$_GET['scope'] = 'personal';
		$html          = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		unset( $_GET['scope'] );
		self::assertStringContainsString( 'Nothing from the people and groups you follow yet.', $html );
		self::assertStringContainsString( 'href="' . home_url( '/members' ), $html );
		self::assertStringContainsString( 'Find members to follow', $html );

		$this->route( 'members', 'lonely', '' );
		self::assertStringContainsString( 'No updates yet.', $this->as_user( $member, fn (): string => $this->shortcodes->render_page() ) );

		$_GET['search'] = 'zzz-nobody';
		$this->route( 'members', '', '' );
		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		unset( $_GET['search'] );
		self::assertStringContainsString( 'No members match your search.', $html );
		self::assertStringContainsString( 'Clear the search', $html );
		self::assertStringContainsString( '<form class="odsi-social-directory__filters" method="get" role="search"', $html );
		self::assertStringContainsString( '<label class="odsi-social-visually-hidden" for="', $html );

		$_GET['search'] = 'zzz-nogroup';
		$this->route( 'groups', '', '' );
		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		unset( $_GET['search'] );
		self::assertStringContainsString( 'No groups match your search.', $html );
		self::assertStringContainsString( '<h2 class="odsi-social-create-group__title"', $html );

		$this->route( 'notifications', '', '' );
		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( 'You are all caught up.', $html );
		self::assertStringContainsString( 'See what is happening', $html );
		self::assertStringNotContainsString( 'odsi-social-notifications__read-all', $html );

		$this->route( 'messages', '', '' );
		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( 'No conversations yet.', $html );
		self::assertStringContainsString( 'Find members', $html );

		// Logged out: a message and the way in, on every private surface.
		foreach ( array( array( 'notifications', '' ), array( 'messages', '' ), array( 'members', 'lonely' ) ) as [ $section, $object ] ) {
			$this->social->service( \ODSI\Social\Support\Settings::class )->update( array( 'public_directory' => false ) );
			$this->route( $section, $object, '' );
			$html = $this->shortcodes->render_page();
			self::assertStringContainsString( 'odsi-social-notice--login', $html, $section );
			self::assertStringContainsString( 'Log in to see this page.', $html );
			self::assertStringContainsString( 'wp-login.php', $html );
		}
	}

	public function test_group_page_states_for_non_members_and_translated_labels(): void {
		$owner    = $this->social->member( 'grp-owner' );
		$stranger = $this->social->member( 'grp-stranger' );
		$private  = $this->social->group( $owner, 'private', 'Closed Circle' );
		$this->social->add_to_group( $private, $this->social->member(), 'moderator' );

		$this->route( 'groups', 'closed-circle', '' );
		$html = $this->as_user( $stranger, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( 'odsi-social-notice--locked', $html );
		self::assertStringContainsString( 'Join this group to see its activity and members.', $html );
		self::assertStringContainsString( 'data-membership="join"', $html );
		self::assertStringContainsString( '>Request to join<', $html );
		self::assertStringContainsString( '<span>Private</span>', $html, 'Visibility is a translated label.' );
		self::assertStringNotContainsString( '<h1', $html );

		self::assertTrue( $this->social->service( \ODSI\Social\Groups\Membership::class )->request( $stranger, $private ) );
		$html = $this->as_user( $stranger, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( 'Your request to join is awaiting approval.', $html );
		self::assertStringContainsString( '>Withdraw request<', $html );

		$html = $this->as_user( $owner, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( '<h2 class="odsi-social-group__members-title">Members</h2>', $html );
		self::assertStringContainsString( '<h2 class="odsi-social-group__feed-title">Activity</h2>', $html );
		self::assertStringContainsString( '<span class="odsi-social-member-list__role">Organiser</span>', $html, 'Roles are translated labels.' );
		self::assertStringContainsString( '<span class="odsi-social-member-list__role">Moderator</span>', $html );
		self::assertStringContainsString( 'created the group <a href="', $html, 'The creation item is in the group feed, with the author linked.' );
		self::assertStringContainsString( '<a class="odsi-social-item__author" href="' . home_url( '/members/grp-owner' ), $html );

		$visitor = $this->shortcodes->render_page();
		self::assertStringContainsString( 'odsi-social-notice--locked', $visitor );
		self::assertStringContainsString( 'wp-login.php', $visitor, 'A visitor is offered the way in.' );

		// Manage page: controls are described by the member's name.
		$this->route( 'groups', 'closed-circle', 'manage' );
		$html = $this->as_user( $owner, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( '<h2 class="odsi-social-settings__title">1 request to join</h2>', $html );
		self::assertMatchesRegularExpression( '#<span class="odsi-social-member-list__name" id="(odsi-social-member-' . $private . '-' . $stranger . ')">.*?aria-describedby="\1">Approve</button>#s', $html );

		// Hidden group: not a page at all for a stranger.
		$hidden = $this->social->group( $owner, 'hidden', 'Secret Circle' );
		self::assertGreaterThan( 0, $hidden );
		self::assertSame( 404, $this->social->service( Router::class )->status_for( 'groups', 'secret-circle', '', $stranger ) );
		self::assertSame( 200, $this->social->service( Router::class )->status_for( 'groups', 'secret-circle', '', $owner ) );
	}

	public function test_notifications_expose_unread_state_beyond_colour_with_a_per_item_read_control(): void {
		$member = $this->social->member();
		$actor  = $this->social->member();
		$notify = $this->social->service( Notifications::class );
		$notify->notify( $member, $actor, 'connections', 'requested', 1 );
		$notify->notify( $member, $actor, 'connections', 'requested', 2 );
		$ids = array_column( $notify->list( $member ), 'id' );

		$this->route( 'notifications', '', '' );
		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( '<p class="odsi-social-notifications__count" role="status">2 unread</p>', $html );
		self::assertStringContainsString( 'odsi-social-notifications__read-all', $html );
		self::assertSame( 2, substr_count( $html, 'class="odsi-social-notification is-new"' ) );
		self::assertSame( 2, substr_count( $html, 'odsi-social-notification__state odsi-social-visually-hidden">Unread:' ), 'Unread is said, not only shown.' );
		self::assertSame( 2, substr_count( $html, 'odsi-social-notification__read" data-notification-id=' ) );
		self::assertStringContainsString( 'aria-label="Mark as read: ', $html );
		self::assertStringContainsString( '<time class="odsi-social-notification__time" datetime="', $html );
		self::assertStringContainsString( ' ago</time>', $html );

		$read = $this->as_user( $member, fn () => $this->rest( 'POST', self::NS . "/notifications/{$ids[0]}/read" ) );
		self::assertSame( 200, $read->get_status() );
		self::assertSame( 1, $read->get_data()['unread_count'] );

		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		self::assertSame( 1, substr_count( $html, 'class="odsi-social-notification is-new"' ) );
		self::assertSame( 1, substr_count( $html, 'odsi-social-notification__read" data-notification-id=' ) );
	}

	public function test_thread_page_is_two_pane_with_a_log_and_the_reply_rule(): void {
		$a        = $this->social->member( 'thread-a' );
		$b        = $this->social->member( 'thread-b' );
		$c        = $this->social->member( 'thread-c' );
		$messages = $this->social->service( \ODSI\Social\Messages\Messages::class );
		$first    = $messages->send( $a, $b, 'first' );
		$messages->send( $a, $c, 'second' );

		$this->route( 'messages', (string) $first->thread_id, '' );
		$html = $this->as_user( $a, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( '<aside class="odsi-social-messages__list" aria-label="Conversations">', $html );
		self::assertSame( 2, substr_count( $html, '<li class="odsi-social-thread' ), 'The conversation list sits beside the thread.' );
		self::assertStringContainsString( 'is-current" data-thread-id="' . $first->thread_id . '"', $html );
		self::assertStringContainsString( 'aria-current="page"', $html );
		self::assertStringContainsString( '<section class="odsi-social-messages__pane" aria-label="Conversation with ' . get_userdata( $b )->display_name . '">', $html );
		self::assertStringContainsString( '<div class="odsi-social-conversation" role="log" aria-live="polite" aria-relevant="additions">', $html );
		self::assertStringContainsString( '<li class="odsi-social-message is-mine">', $html );
		self::assertStringContainsString( '<template class="odsi-social-message-form__template">', $html );
		self::assertMatchesRegularExpression( '#<label class="odsi-social-visually-hidden" for="(odsi-social-thread-\d+-content)">Write a reply</label>\s*<textarea id="\1"#', $html );

		// Inbox rows say how many are unread in words, and the badge is not the only cue.
		$this->route( 'messages', '', '' );
		$inbox = $this->as_user( $b, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( 'class="odsi-social-thread is-unread"', $inbox );
		self::assertStringContainsString( '1<span class="odsi-social-visually-hidden"> unread</span>', $inbox );

		// After a block, the thread stays readable but closed to replies (SOC-MOD-005).
		$this->social->block( $b, $a );
		$this->route( 'messages', (string) $first->thread_id, '' );
		$html = $this->as_user( $a, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( 'You can no longer reply in this conversation.', $html );
		self::assertStringNotContainsString( '<form class="odsi-social-message-form"', $html );

		// The reply REST response carries the rendered message the script appends.
		$reply = $this->as_user(
			$a,
			fn () => $this->rest(
				'POST',
				self::NS . '/messages/to/' . $c,
				array( 'content' => 'appended' )
			)
		);
		self::assertSame( 201, $reply->get_status() );
		self::assertSame( get_userdata( $a )->display_name, $reply->get_data()['message']['sender'] );
		self::assertStringContainsString( 'appended', $reply->get_data()['message']['content'] );
		self::assertSame( home_url( user_trailingslashit( '/messages/' . $reply->get_data()['thread_id'] ) ), $reply->get_data()['url'] );
	}

	public function test_inbox_compose_target_follows_the_message_rule(): void {
		$a = $this->social->member();
		$b = $this->social->member();

		$_GET['to'] = (string) $b;
		$this->route( 'messages', '', '' );
		$html = $this->as_user( $a, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( 'odsi-social-message-form--new" data-user-id="' . $b . '"', $html );
		self::assertStringContainsString( 'New message to ' . get_userdata( $b )->display_name, $html );
		self::assertStringNotContainsString( 'No conversations yet.', $html, 'The compose form is the page\'s purpose; no empty state under it.' );

		$this->social->service( \ODSI\Social\Members\Profiles::class )->set_message_setting( $b, 'no_one' );
		$html = $this->as_user( $a, fn (): string => $this->shortcodes->render_page() );
		self::assertStringNotContainsString( 'odsi-social-message-form--new', $html );
		self::assertStringContainsString( 'You cannot message ' . get_userdata( $b )->display_name . '.', $html );

		$_GET['to'] = (string) $a;
		$html       = $this->as_user( $a, fn (): string => $this->shortcodes->render_page() );
		unset( $_GET['to'] );
		self::assertStringNotContainsString( 'odsi-social-message-form--new', $html, 'No messaging yourself.' );
	}

	public function test_profile_page_has_a_hero_named_by_the_theme_heading_and_labelled_actions(): void {
		$member = $this->social->member( 'hero-member' );
		$viewer = $this->social->member();
		$this->social->follow( $viewer, $member );

		$this->route( 'members', 'hero-member', '' );
		$html = $this->as_user( $viewer, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( '<section class="odsi-social-hero odsi-social-hero--profile" aria-label="Profile summary">', $html );
		self::assertStringContainsString( 'odsi-social-hero__avatar" src="', $html );
		self::assertStringContainsString( 'alt="' . get_userdata( $member )->display_name . '"', $html, 'The hero avatar names the member.' );
		self::assertStringContainsString( '<span>1 follower</span>', $html );
		self::assertStringContainsString( 'odsi-social-hero__connect" aria-pressed="false" data-user-id="' . $member . '" data-status=""', $html );
		self::assertStringContainsString( 'odsi-social-hero__follow is-active" aria-pressed="true"', $html );
		self::assertStringContainsString( '<h2 class="odsi-social-profile__feed-title">Activity</h2>', $html );
		self::assertStringNotContainsString( '<h1', $html );
		self::assertStringNotContainsString( '<h2>', $html, 'No bare headings: every heading is a section title.' );

		$this->route( 'members', 'hero-member', 'edit' );
		$html = $this->as_user( $member, fn (): string => $this->shortcodes->render_page() );
		self::assertStringContainsString( '<legend class="odsi-social-settings__legend">Photos</legend>', $html );
		self::assertStringContainsString( '<h2 class="odsi-social-settings__title">Blocked members</h2>', $html );
		self::assertStringContainsString( 'You have not blocked anyone.', $html );
	}

	public function test_admin_settings_screen_confirms_a_save_and_labels_every_control(): void {
		$admin = $this->social->admin();
		$menu  = $this->social->service( AdminMenu::class );

		$_GET['updated'] = '1';
		$html            = $this->as_user(
			$admin,
			static function () use ( $menu ): string {
				ob_start();
				$menu->render_settings();

				return (string) ob_get_clean();
			}
		);
		unset( $_GET['updated'] );
		self::assertStringContainsString( 'notice notice-success', $html );
		self::assertStringContainsString( 'Settings saved.', $html );
		self::assertStringContainsString( 'Only me</label>', $html, 'Privacy choices use the shared labels.' );
		self::assertStringNotContainsString( 'style="', $html, 'No inline styles.' );

		$this->social->service( \ODSI\Social\Members\ProfileFields::class )->create_group( 'About' );
		$html = $this->as_user(
			$admin,
			static function () use ( $menu ): string {
				ob_start();
				$menu->render_profile_fields();

				return (string) ob_get_clean();
			}
		);
		self::assertMatchesRegularExpression( '#<label class="screen-reader-text" for="(odsi-social-field-\d+-name)">Field name</label><input type="text" id="\1"#', $html );
	}

	/* ---------- Contracts between PHP, CSS and JS ---------- */

	public function test_script_only_shows_strings_the_plugin_localizes(): void {
		$js   = (string) file_get_contents( Plugin::path() . 'assets/js/frontend.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$keys = array_keys( Assets::script_data()['i18n'] );

		preg_match_all( "/text\( '([a-zA-Z]+)'/", $js, $used );
		$used = array_unique( $used[1] );

		self::assertNotEmpty( $used );
		self::assertSame( array(), array_values( array_diff( $used, $keys ) ), 'Every string the script shows is localized.' );

		// Keys chosen at run time (`text( on ? 'unfollow' : 'follow' )`) still appear as literals.
		$dead = array_values( array_filter( $keys, static fn ( string $key ): bool => ! str_contains( $js, "'" . $key . "'" ) ) );
		self::assertSame( array(), $dead, 'No localized string is dead.' );
		self::assertStringNotContainsString( 'jQuery(', $js );
		self::assertStringNotContainsString( '$(', $js );
		self::assertStringNotContainsString( 'window.alert', $js, 'Errors are shown inline, not in alerts.' );
		self::assertArrayHasKey( 'nonce', Assets::script_data() );
	}

	public function test_every_class_the_markup_emits_has_a_stylesheet_rule_or_is_a_documented_hook(): void {
		$root    = Plugin::path();
		$emitted = array();
		$token   = 'odsi-social-[a-z0-9]+(?:-[a-z0-9]+)*(?:__[a-z0-9]+(?:-[a-z0-9]+)*)?(?:--[a-z0-9]+(?:-[a-z0-9]+)*)?';

		// Templates and PHP renderers: what `class="…"` attributes carry.
		foreach ( array_merge( glob( $root . 'templates/*/*.php' ) ?: array(), array( $root . 'src/Activity/Renderers.php', $root . 'src/Support/Sanitizer.php' ) ) as $file ) {
			preg_match_all( '/class="([^"]*)"/', (string) file_get_contents( $file ), $attrs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

			foreach ( $attrs[1] as $attr ) {
				preg_match_all( '/' . $token . '/', $attr, $m );
				$emitted = array_merge( $emitted, $m[0] );
			}
		}

		// The script: selectors it binds to and classes it creates.
		$js = (string) file_get_contents( $root . 'assets/js/frontend.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		preg_match_all( '/[.\']' . $token . '/', $js, $m );
		$emitted = array_merge( $emitted, array_map( static fn ( string $c ): string => substr( $c, 1 ), $m[0] ) );

		// Modifiers built from data at run time.
		$emitted = array_unique( array_merge( $emitted, array( 'odsi-social-notice--success', 'odsi-social-notice--error' ) ) );
		$css     = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $root . 'assets/css/frontend.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		preg_match_all( '/\.(odsi-social-[a-z0-9_-]+)/', $css, $m );
		$styled = array_unique( $m[1] );

		// Classes that exist for the script or for themes to target, styled by a
		// rule on a shared block (button, avatar) or on their parent.
		$hooks = array(
			'odsi-social-report-form',          // the dialog's form, styled as __form
			'odsi-social-hero--profile',
			'odsi-social-hero--group',
			'odsi-social-notifications__read-all',
			'odsi-social-item__avatar',
			'odsi-social-comment__avatar',
			'odsi-social-card__avatar',
			'odsi-social-hero__avatar',
			'odsi-social-hero__edit',
			'odsi-social-hero__connect',
			'odsi-social-hero__follow',
			'odsi-social-hero__message',
			'odsi-social-hero__block',
			'odsi-social-hero__report',
			'odsi-social-hero__manage',
			'odsi-social-hero__membership',
			'odsi-social-item__react',
			'odsi-social-item__comment-toggle',
			'odsi-social-item__delete',
			'odsi-social-item__report',
			'odsi-social-item__edited',
			'odsi-social-comment__delete',
			'odsi-social-comment__report',
			'odsi-social-feed__status',
			'odsi-social-feed__empty',
			'odsi-social-feed--single',
			'odsi-social-post-form__label',
			'odsi-social-post-form__submit',
			'odsi-social-post-form__count-announce',
			'odsi-social-post-form__error',
			'odsi-social-comment-form__submit',
			'odsi-social-comment-form__error',
			'odsi-social-message-form--new',
			'odsi-social-message-form__submit',
			'odsi-social-message-form__error',
			'odsi-social-message-form__template',
			'odsi-social-create-group__submit',
			'odsi-social-create-group__error',
			'odsi-social-report-dialog__cancel',
			'odsi-social-report-dialog__submit',
			'odsi-social-report-dialog__error',
			'odsi-social-directory__submit',
			'odsi-social-directory__empty',
			'odsi-social-directory--members',
			'odsi-social-directory--groups',
			'odsi-social-cards--active',
			'odsi-social-cards--pending',
			'odsi-social-cards--invited',
			'odsi-social-member-list__action',
			'odsi-social-notifications__empty',
			'odsi-social-notification__state',
			'odsi-social-notification__read',
			'odsi-social-notification__body',
			'odsi-social-notice__action',
			'odsi-social-empty__action',
			'odsi-social-settings--profile',
			'odsi-social-settings--group',
			'odsi-social-settings__preview',
			'odsi-social-settings__blocked',
			'odsi-social-settings__pending',
			'odsi-social-settings__members',
			'odsi-social-settings__banned',
			'odsi-social-settings__form',
			'odsi-social-profile__field-list',
			'odsi-social-profile__feed',
			'odsi-social-group__locked',
			'odsi-social-group__feed',
			'odsi-social-messages--inbox',
			'odsi-social-messages--thread',
			'odsi-social-messages__empty',
			'odsi-social-thread__badge',
		);

		$missing = array_values( array_diff( $emitted, $styled, $hooks ) );
		sort( $missing );
		self::assertSame( array(), $missing, 'Every emitted class is styled or listed as a hook.' );

		$dead = array_values( array_diff( $styled, $emitted, array( 'odsi-social-error', 'odsi-social-status' ) ) );
		sort( $dead );
		self::assertSame( array(), $dead, 'No stylesheet rule targets a class nothing emits.' );

		$rules = (string) preg_replace( '/:root\s*\{[^}]*\}/', '', $css );
		self::assertSame( 0, preg_match( '/var\(--odsi-(?!social-)/', $rules ), 'Rules read plugin tokens only; the shared set is read once in :root.' );
		self::assertSame( 1, preg_match( '/:root\s*\{[^}]*\}/', $css ) );
		self::assertMatchesRegularExpression( '/--odsi-social-accent: var\(--odsi-accent, #2563eb\)/', $css );
		self::assertStringContainsString( 'prefers-reduced-motion', $css );
		self::assertStringContainsString( ':focus-visible', $css );
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
