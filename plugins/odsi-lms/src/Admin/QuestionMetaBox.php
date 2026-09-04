<?php
/**
 * Question editor: type, points and answers.
 *
 * @package ODSI\LMS
 */

declare( strict_types = 1 );

namespace ODSI\LMS\Admin;

defined( 'ABSPATH' ) || exit;

use ODSI\LMS\Contracts\Bootable;
use ODSI\LMS\PostTypes\PostTypes;
use ODSI\LMS\Quizzes\Grader;
use ODSI\LMS\Support\Meta;
use WP_Post;

/**
 * Answers are stored as the Grader reads them: a list of
 * `{ text, correct }` rows. The editor is plain text so it works without
 * JavaScript: one option per line, a leading `*` marks a correct one.
 */
final class QuestionMetaBox implements Bootable {

	private const NONCE_ACTION = 'odsi_lms_question';
	private const NONCE_FIELD  = 'odsi_lms_question_nonce';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post_' . PostTypes::QUESTION, array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Add the box.
	 */
	public function register(): void {
		add_meta_box( 'odsi-lms-question', __( 'Answers', 'odsi-lms' ), array( $this, 'render' ), PostTypes::QUESTION, 'normal', 'high' );
	}

	/**
	 * Question types and labels.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			Grader::TYPE_SINGLE     => __( 'Single choice', 'odsi-lms' ),
			Grader::TYPE_MULTIPLE   => __( 'Multiple choice', 'odsi-lms' ),
			Grader::TYPE_TRUE_FALSE => __( 'True or false', 'odsi-lms' ),
			Grader::TYPE_FILL_BLANK => __( 'Fill in the blank', 'odsi-lms' ),
			Grader::TYPE_ESSAY      => __( 'Essay (graded by hand)', 'odsi-lms' ),
		);
	}

	/**
	 * Render.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$type    = (string) get_post_meta( $post->ID, Meta::QUESTION_TYPE, true ) ?: Grader::TYPE_SINGLE;
		$points  = get_post_meta( $post->ID, Meta::QUESTION_POINTS, true );
		$answers = (array) get_post_meta( $post->ID, Meta::QUESTION_ANSWERS, true );

		echo '<p><label for="odsi-question-type"><strong>' . esc_html__( 'Type', 'odsi-lms' ) . '</strong></label> ';
		echo '<select id="odsi-question-type" name="' . esc_attr( Meta::QUESTION_TYPE ) . '">';
		foreach ( self::types() as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $type, $value, false ), esc_html( $label ) );
		}
		echo '</select> ';
		printf(
			'<label for="odsi-question-points">%1$s</label> <input type="number" id="odsi-question-points" name="%2$s" value="%3$s" min="0" step="0.5" class="small-text" /></p>',
			esc_html__( 'Points', 'odsi-lms' ),
			esc_attr( Meta::QUESTION_POINTS ),
			esc_attr( '' === $points ? '1' : (string) (float) $points )
		);

		$true_correct = ! empty( $answers[0]['correct'] );

		echo '<p><label for="odsi-question-truth">' . esc_html__( 'True/false answer', 'odsi-lms' ) . '</label> ';
		echo '<select id="odsi-question-truth" name="odsi_question_truth">';
		printf( '<option value="1" %1$s>%2$s</option>', selected( $true_correct, true, false ), esc_html__( 'True', 'odsi-lms' ) );
		printf( '<option value="0" %1$s>%2$s</option>', selected( $true_correct, false, false ), esc_html__( 'False', 'odsi-lms' ) );
		echo '</select> <span class="description">' . esc_html__( 'Used only by true/false questions.', 'odsi-lms' ) . '</span></p>';

		printf(
			'<p><label for="odsi-question-options">%1$s</label><br /><textarea id="odsi-question-options" name="odsi_question_options" rows="8" class="large-text code">%2$s</textarea><br /><span class="description">%3$s</span></p>',
			esc_html__( 'Options', 'odsi-lms' ),
			esc_textarea( self::to_text( $answers ) ),
			esc_html__( 'One option per line. Start a line with * to mark it correct (single or multiple choice). For fill-in-the-blank, list every accepted answer, one per line. Leave empty for essays.', 'odsi-lms' )
		);
	}

	/**
	 * Persist.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public function save( int $post_id, WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$type = sanitize_key( wp_unslash( (string) ( $_POST[ Meta::QUESTION_TYPE ] ?? '' ) ) );
		$type = array_key_exists( $type, self::types() ) ? $type : Grader::TYPE_SINGLE;

		update_post_meta( $post_id, Meta::QUESTION_TYPE, $type );
		update_post_meta( $post_id, Meta::QUESTION_POINTS, max( 0, (float) sanitize_text_field( wp_unslash( (string) ( $_POST[ Meta::QUESTION_POINTS ] ?? '1' ) ) ) ) );
		update_post_meta(
			$post_id,
			Meta::QUESTION_ANSWERS,
			self::parse(
				$type,
				sanitize_textarea_field( wp_unslash( (string) ( $_POST['odsi_question_options'] ?? '' ) ) ),
				! empty( $_POST['odsi_question_truth'] )
			)
		);
	}

	/**
	 * Build the stored answer rows from the editor's plain text.
	 *
	 * @param string $type    Question type.
	 * @param string $text    Options, one per line.
	 * @param bool   $truth   Correct answer for true/false.
	 * @return array<int, array{text: string, correct: bool}>
	 */
	public static function parse( string $type, string $text, bool $truth ): array {
		if ( Grader::TYPE_TRUE_FALSE === $type ) {
			return array(
				array(
					'text'    => __( 'True', 'odsi-lms' ),
					'correct' => $truth,
				),
				array(
					'text'    => __( 'False', 'odsi-lms' ),
					'correct' => ! $truth,
				),
			);
		}

		if ( Grader::TYPE_ESSAY === $type ) {
			return array();
		}

		$rows = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$correct = Grader::TYPE_FILL_BLANK === $type || str_starts_with( $line, '*' );
			$rows[]  = array(
				'text'    => trim( ltrim( $line, '*' ) ),
				'correct' => $correct,
			);
		}

		// A single-choice question keeps exactly one correct option: the first marked.
		if ( Grader::TYPE_SINGLE === $type ) {
			$seen = false;

			foreach ( $rows as &$row ) {
				if ( $row['correct'] && $seen ) {
					$row['correct'] = false;
				}
				$seen = $seen || $row['correct'];
			}
			unset( $row );
		}

		return $rows;
	}

	/**
	 * The inverse of parse(), for the textarea.
	 *
	 * @param array<int, mixed> $answers Stored rows.
	 */
	private static function to_text( array $answers ): string {
		$lines = array();

		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}

			$lines[] = ( ! empty( $answer['correct'] ) ? '*' : '' ) . (string) ( $answer['text'] ?? '' );
		}

		return implode( "\n", $lines );
	}
}
