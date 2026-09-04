<?php
/**
 * Notification emails. Spec: SOC-NOT-008.
 *
 * @package ODSI\Tests
 */

declare( strict_types = 1 );

namespace ODSI\Tests\Integration\Social;

use ODSI\Social\Activity\Reactions;
use ODSI\Social\Connections\Connections;
use ODSI\Social\Frontend\Forms;
use ODSI\Social\Notifications\Emails;
use ODSI\Tests\Integration\TestCase;

final class EmailsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	public function test_one_email_per_notification_unless_folded_or_opted_out(): void {
		$a = $this->social->member();
		$b = $this->social->member();
		$c = $this->social->member();

		$this->social->service( Connections::class )->request( $a, $b );

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		self::assertCount( 1, $sent );
		self::assertSame( get_userdata( $b )->user_email, $sent[0]['to'][0][0] );
		self::assertStringContainsString( get_userdata( $a )->display_name, $sent[0]['subject'] );
		self::assertStringContainsString( 'http', $sent[0]['body'] );

		// Two likes on one post fold into one notification and one email.
		$post = $this->social->update( $b, 'Popular' );
		$this->social->service( Reactions::class )->set( $a, $post );
		$this->social->service( Reactions::class )->set( $c, $post );
		self::assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent, 'A folded notification does not email again.' );

		// Opt out through the profile form.
		self::assertTrue( $this->social->service( Forms::class )->process_profile( $b, $b, array( 'email_notifications' => '0' ), array() ) );
		self::assertFalse( Emails::wants_email( $b ) );
		$this->social->service( Connections::class )->request( $c, $b );
		self::assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent, 'No email after opting out.' );

		add_filter( 'odsi_social_notification_email', '__return_empty_array' );
		$this->social->service( Connections::class )->request( $b, $a );
		self::assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent, 'The filter suppresses.' );
	}
}
