<?php
/**
 * Admin menu and screens.
 *
 * @package ODSI\Social
 */

declare( strict_types = 1 );

namespace ODSI\Social\Admin;

use ODSI\Social\Activity\Privacy;
use ODSI\Social\Contracts\Bootable;
use ODSI\Social\Members\ProfileFields;
use ODSI\Social\Support\Capabilities;
use ODSI\Social\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The Community menu: settings and profile field management.
 */
final class AdminMenu implements Bootable {

	public const SLUG = 'odsi-social';

	private const NONCE = 'odsi_social_admin';

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings.
	 * @param ProfileFields $fields   Profile fields.
	 */
	public function __construct(
		private Settings $settings,
		private ProfileFields $fields
	) {
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register' ), 9 );
		add_action( 'admin_post_odsi_social_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_odsi_social_profile_fields', array( $this, 'handle_profile_fields' ) );
	}

	/**
	 * Register the menu.
	 */
	public function register(): void {
		add_menu_page(
			__( 'Community', 'odsi-social' ),
			__( 'Community', 'odsi-social' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render_settings' ),
			'dashicons-groups',
			31
		);

		add_submenu_page( self::SLUG, __( 'Settings', 'odsi-social' ), __( 'Settings', 'odsi-social' ), Capabilities::MANAGE, self::SLUG, array( $this, 'render_settings' ) );
		add_submenu_page( self::SLUG, __( 'Profile Fields', 'odsi-social' ), __( 'Profile Fields', 'odsi-social' ), Capabilities::MANAGE, 'odsi-social-profile-fields', array( $this, 'render_profile_fields' ) );
	}

	/**
	 * Settings the screen edits: key => [label, control]. Controls are
	 * `text`, `number`, `checkbox`, `privacy_set`, `privacy_select`, `list`.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private function fields(): array {
		return array(
			'slug_members'                => array( __( 'Members base slug', 'odsi-social' ), 'text' ),
			'slug_groups'                 => array( __( 'Groups base slug', 'odsi-social' ), 'text' ),
			'slug_activity'               => array( __( 'Activity base slug', 'odsi-social' ), 'text' ),
			'slug_notifications'          => array( __( 'Notifications base slug', 'odsi-social' ), 'text' ),
			'slug_messages'               => array( __( 'Messages base slug', 'odsi-social' ), 'text' ),
			'public_directory'            => array( __( 'Visitors may see the member directory', 'odsi-social' ), 'checkbox' ),
			'members_can_create_groups'   => array( __( 'Members may create groups', 'odsi-social' ), 'checkbox' ),
			'allowed_privacy'             => array( __( 'Privacy levels members may choose', 'odsi-social' ), 'privacy_set' ),
			'default_privacy'             => array( __( 'Default post privacy', 'odsi-social' ), 'privacy_select' ),
			'activity_max_length'         => array( __( 'Maximum post length', 'odsi-social' ), 'number' ),
			'message_max_length'          => array( __( 'Maximum message length', 'odsi-social' ), 'number' ),
			'edit_window_minutes'         => array( __( 'Edit window (minutes, 0 disables)', 'odsi-social' ), 'number' ),
			'feed_per_page'               => array( __( 'Feed items per page', 'odsi-social' ), 'number' ),
			'directory_per_page'          => array( __( 'Members per directory page', 'odsi-social' ), 'number' ),
			'avatar_max_px'               => array( __( 'Maximum image size (pixels a side)', 'odsi-social' ), 'number' ),
			'avatar_types'                => array( __( 'Allowed image types (comma separated: jpg, jpeg, png, gif, webp)', 'odsi-social' ), 'list' ),
			'notification_retention_days' => array( __( 'Keep read notifications for (days)', 'odsi-social' ), 'number' ),
			'delete_content_with_user'    => array( __( 'Delete a member\'s posts when their account is deleted', 'odsi-social' ), 'checkbox' ),
			'reset_data_on_uninstall'     => array( __( 'Delete all community data when the plugin is uninstalled', 'odsi-social' ), 'checkbox' ),
		);
	}

	/**
	 * Settings screen.
	 */
	public function render_settings(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Community settings', 'odsi-social' ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="odsi_social_save_settings" /><table class="form-table">';

		foreach ( $this->fields() as $key => [ $label, $type ] ) {
			$value = $this->settings->get( $key );
			echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';

			switch ( $type ) {
				case 'checkbox':
					echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( (bool) $value, true, false ) . ' />';
					break;

				case 'privacy_set':
					foreach ( Privacy::choices() as $choice ) {
						echo '<label style="margin-right:1em"><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $choice ) . '" ' . checked( in_array( $choice, (array) $value, true ), true, false ) . ' /> ' . esc_html( self::privacy_label( $choice ) ) . '</label>';
					}
					break;

				case 'privacy_select':
					echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';

					foreach ( Privacy::choices() as $choice ) {
						echo '<option value="' . esc_attr( $choice ) . '" ' . selected( (string) $value, $choice, false ) . '>' . esc_html( self::privacy_label( $choice ) ) . '</option>';
					}

					echo '</select>';
					break;

				case 'list':
					echo '<input type="text" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( implode( ', ', array_map( 'strval', (array) $value ) ) ) . '" />';
					break;

				default:
					echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '"' . ( 'number' === $type ? ' min="0"' : '' ) . ' />';
			}//end switch

			echo '</td></tr>';
		}//end foreach

		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Human label for a privacy level.
	 *
	 * @param string $choice Level.
	 */
	private static function privacy_label( string $choice ): string {
		return match ( $choice ) {
			Privacy::PUBLIC      => __( 'Everyone', 'odsi-social' ),
			Privacy::MEMBERS     => __( 'Members', 'odsi-social' ),
			Privacy::CONNECTIONS => __( 'Connections', 'odsi-social' ),
			Privacy::ONLY_ME     => __( 'Only me', 'odsi-social' ),
			default              => $choice,
		};
	}

	/**
	 * Validate a submitted settings form into values to store. Anything not
	 * submitted keeps its stored value; every value is coerced into range.
	 *
	 * @param array<string, mixed> $post Submitted fields, unslashed.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $post ): array {
		$values = array();

		foreach ( array( 'slug_members', 'slug_groups', 'slug_activity', 'slug_notifications', 'slug_messages' ) as $key ) {
			if ( isset( $post[ $key ] ) ) {
				$values[ $key ] = sanitize_title( (string) $post[ $key ] ) ?: (string) Settings::defaults()[ $key ];
			}
		}

		$minimums = array(
			'activity_max_length'         => 1,
			'message_max_length'          => 1,
			'edit_window_minutes'         => 0,
			'feed_per_page'               => 1,
			'directory_per_page'          => 1,
			'avatar_max_px'               => 64,
			'notification_retention_days' => 1,
		);

		foreach ( $minimums as $key => $minimum ) {
			if ( isset( $post[ $key ] ) ) {
				$values[ $key ] = max( $minimum, absint( $post[ $key ] ) );
			}
		}

		foreach ( array( 'public_directory', 'members_can_create_groups', 'delete_content_with_user', 'reset_data_on_uninstall' ) as $key ) {
			$values[ $key ] = ! empty( $post[ $key ] );
		}

		// The allowed set is never empty, and the default is always one of it.
		$allowed = array_values( array_intersect( Privacy::choices(), array_map( 'sanitize_key', array_map( 'strval', (array) ( $post['allowed_privacy'] ?? array() ) ) ) ) );

		if ( array() === $allowed && ! array_key_exists( 'allowed_privacy', $post ) ) {
			$allowed = array_values( array_intersect( Privacy::choices(), array_map( 'strval', (array) $this->settings->get( 'allowed_privacy' ) ) ) );
		}

		if ( array() === $allowed ) {
			$allowed = Privacy::choices();
		}

		$values['allowed_privacy'] = $allowed;

		$default = sanitize_key( (string) ( $post['default_privacy'] ?? $this->settings->string( 'default_privacy' ) ) );

		$values['default_privacy'] = in_array( $default, $allowed, true ) ? $default : $allowed[0];

		if ( isset( $post['avatar_types'] ) ) {
			$known = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
			$types = array_values( array_intersect( $known, array_map( 'strtolower', array_map( 'trim', explode( ',', (string) $post['avatar_types'] ) ) ) ) );

			$values['avatar_types'] = $types ?: (array) Settings::defaults()['avatar_types'];
		}

		return $values;
	}

	/**
	 * Persist settings.
	 */
	public function save_settings(): void {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You cannot change these settings.', 'odsi-social' ) );
		}

		$this->settings->update( $this->sanitize_settings( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field in sanitize_settings.
		flush_rewrite_rules();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Profile fields screen.
	 */
	public function render_profile_fields(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Profile fields', 'odsi-social' ) . '</h1>';

		foreach ( $this->fields->structure() as $section ) {
			echo '<h2>' . esc_html( (string) $section['group']->name ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Field', 'odsi-social' ) . '</th><th>' . esc_html__( 'Type', 'odsi-social' ) . '</th><th>' . esc_html__( 'Required', 'odsi-social' ) . '</th><th>' . esc_html__( 'Default visibility', 'odsi-social' ) . '</th><th></th></tr></thead><tbody>';

			foreach ( $section['fields'] as $field ) {
				echo '<tr><td>' . esc_html( (string) $field->name ) . '</td><td>' . esc_html( (string) $field->type ) . '</td><td>' . ( (int) $field->required ? esc_html__( 'Yes', 'odsi-social' ) : esc_html__( 'No', 'odsi-social' ) ) . '</td><td>' . esc_html( (string) $field->default_visibility ) . '</td><td>';
				$this->action_form( 'delete_field', array( 'field_id' => (int) $field->id ), __( 'Delete', 'odsi-social' ) );
				echo '</td></tr>';
			}

			echo '</tbody></table>';
			$this->add_field_form( (int) $section['group']->id );
			$this->action_form( 'delete_group', array( 'group_id' => (int) $section['group']->id ), __( 'Delete group', 'odsi-social' ) );
		}

		echo '<h2>' . esc_html__( 'Add a field group', 'odsi-social' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="odsi_social_profile_fields" /><input type="hidden" name="do" value="add_group" />';
		echo '<input type="text" name="name" required placeholder="' . esc_attr__( 'Group name', 'odsi-social' ) . '" /> ';
		submit_button( __( 'Add group', 'odsi-social' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Add-field form for a group.
	 *
	 * @param int $group_id Group.
	 */
	private function add_field_form( int $group_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:1em 0">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="odsi_social_profile_fields" /><input type="hidden" name="do" value="add_field" /><input type="hidden" name="group_id" value="' . esc_attr( (string) $group_id ) . '" />';
		echo '<input type="text" name="name" required placeholder="' . esc_attr__( 'Field name', 'odsi-social' ) . '" /> <select name="type">';

		foreach ( ProfileFields::TYPES as $type ) {
			echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $type ) . '</option>';
		}

		echo '</select> <select name="default_visibility">';

		foreach ( ProfileFields::VISIBILITIES as $visibility ) {
			echo '<option value="' . esc_attr( $visibility ) . '">' . esc_html( $visibility ) . '</option>';
		}

		echo '</select> <label><input type="checkbox" name="required" value="1" /> ' . esc_html__( 'Required', 'odsi-social' ) . '</label> ';
		echo '<input type="text" name="options" placeholder="' . esc_attr__( 'Options, comma separated', 'odsi-social' ) . '" /> ';
		submit_button( __( 'Add field', 'odsi-social' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Tiny inline action form.
	 *
	 * @param string             $operation Action.
	 * @param array<string, int> $hidden Hidden fields.
	 * @param string             $label  Button label.
	 */
	private function action_form( string $operation, array $hidden, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="odsi_social_profile_fields" /><input type="hidden" name="do" value="' . esc_attr( $operation ) . '" />';

		foreach ( $hidden as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}

		submit_button( $label, 'link-delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle profile field actions.
	 */
	public function handle_profile_fields(): void {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You cannot manage profile fields.', 'odsi-social' ) );
		}

		$operation = sanitize_key( (string) ( $_POST['do'] ?? '' ) );

		switch ( $operation ) {
			case 'add_group':
				$this->fields->create_group( sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ) );
				break;

			case 'delete_group':
				$this->fields->delete_group( absint( $_POST['group_id'] ?? 0 ) );
				break;

			case 'add_field':
				$options = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( (string) ( $_POST['options'] ?? '' ) ) ) ) ) ) );
				$this->fields->create(
					absint( $_POST['group_id'] ?? 0 ),
					sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ),
					sanitize_key( (string) ( $_POST['type'] ?? 'text' ) ),
					array(
						'options'            => $options,
						'required'           => ! empty( $_POST['required'] ),
						'default_visibility' => sanitize_key( (string) ( $_POST['default_visibility'] ?? 'public' ) ),
					)
				);
				break;

			case 'delete_field':
				$this->fields->delete( absint( $_POST['field_id'] ?? 0 ) );
				break;
		}//end switch

		wp_safe_redirect( add_query_arg( array( 'page' => 'odsi-social-profile-fields' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
