/**
 * ODSI LMS quiz player.
 *
 * Renders a quiz from GET /quizzes/{id}/questions, opens an attempt, submits
 * answers, and shows the result. The page shows a start button without it;
 * the whole interaction lives here. Every string goes through wp.i18n and
 * every state change is announced through one polite live region.
 */
( function() {
	'use strict';

	const config = window.odsiLms || {};
	const api = config.api;
	const mount = document.querySelector( '.odsi-lms-quiz__player' );

	if ( ! mount || ! config.restUrl || ! api ) {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	const quizId = mount.getAttribute( 'data-quiz-id' );
	const state = { attemptId: 0, questions: [], deadline: 0, timer: null, announced: {} };

	// One live region for the life of the player, outside the re-rendered
	// area, so announcements are never lost to a DOM replacement.
	const live = el( 'p', { class: 'odsi-lms-quiz__live odsi-lms-visually-hidden', role: 'status', 'aria-live': 'polite' } );
	mount.insertAdjacentElement( 'afterend', live );

	function announce( message ) {
		live.textContent = '';
		window.setTimeout( function() {
			live.textContent = message;
		}, 50 );
	}

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key === 'html' ) {
				node.innerHTML = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function( child ) {
			node.appendChild( child );
		} );
		return node;
	}

	function render( nodes ) {
		mount.innerHTML = '';
		nodes.forEach( function( node ) {
			mount.appendChild( node );
		} );
		mount.focus();
	}

	function errorNode( message ) {
		return el( 'p', { class: 'odsi-lms-quiz__error', role: 'alert', text: message } );
	}

	function formatClock( seconds ) {
		return Math.floor( seconds / 60 ) + ':' + ( '0' + ( seconds % 60 ) ).slice( -2 );
	}

	function bestNode( best ) {
		if ( ! best ) {
			return null;
		}

		let text;

		if ( best.passed ) {
			/* translators: %s: percentage. */
			text = sprintf( __( 'Your best result so far: %s%% (passed).', 'odsi-lms' ), best.percentage );
		} else {
			/* translators: %s: percentage. */
			text = sprintf( __( 'Your best result so far: %s%% (not passed).', 'odsi-lms' ), best.percentage );
		}

		return el( 'p', { class: 'odsi-lms-quiz__best', text } );
	}

	function renderIntro( quiz ) {
		const nodes = [];
		const best = bestNode( quiz.best );

		if ( ! quiz.questions.length ) {
			nodes.push( el( 'p', { class: 'odsi-lms-notice odsi-lms-quiz__notice', text: __( 'This quiz has no questions yet. Check back later.', 'odsi-lms' ) } ) );
			render( nodes );
			return;
		}

		const info = [];
		if ( quiz.time_limit > 0 ) {
			info.push( el( 'li', {
				class: 'odsi-lms-quiz__intro-list',
				/* translators: %d: number of minutes. */
				text: sprintf( _n( 'Time limit: %d minute', 'Time limit: %d minutes', quiz.time_limit, 'odsi-lms' ), quiz.time_limit ),
			} ) );
		}
		if ( quiz.attempts_remaining !== null ) {
			info.push( el( 'li', {
				class: 'odsi-lms-quiz__intro-list',
				/* translators: %d: number of attempts. */
				text: sprintf( _n( '%d attempt remaining', '%d attempts remaining', quiz.attempts_remaining, 'odsi-lms' ), quiz.attempts_remaining ),
			} ) );
		}
		info.push( el( 'li', {
			class: 'odsi-lms-quiz__intro-list',
			/* translators: %s: pass mark percentage. */
			text: sprintf( __( 'Pass mark: %s%%', 'odsi-lms' ), quiz.pass_mark ),
		} ) );
		info.push( el( 'li', {
			class: 'odsi-lms-quiz__intro-list',
			/* translators: %d: number of questions. */
			text: sprintf( _n( '%d question', '%d questions', quiz.questions.length, 'odsi-lms' ), quiz.questions.length ),
		} ) );

		nodes.push( el( 'ul', { class: 'odsi-lms-quiz__info' }, info ) );

		if ( best ) {
			nodes.push( best );
		}

		if ( quiz.attempts_remaining === 0 && ! quiz.has_open_attempt ) {
			nodes.push( el( 'p', { class: 'odsi-lms-notice odsi-lms-quiz__notice', text: __( 'You have used all your attempts at this quiz.', 'odsi-lms' ) } ) );
			render( nodes );
			return;
		}

		if ( quiz.has_open_attempt ) {
			nodes.push( el( 'p', { class: 'odsi-lms-notice odsi-lms-quiz__notice', text: __( 'You have an attempt in progress. Resuming keeps its original time limit.', 'odsi-lms' ) } ) );
		}

		const button = el( 'button', {
			type: 'button',
			class: 'odsi-lms-button odsi-lms-quiz__start',
			text: quiz.has_open_attempt ? __( 'Resume quiz', 'odsi-lms' ) : __( 'Start quiz', 'odsi-lms' ),
		} );
		const error = el( 'p', { class: 'odsi-lms-quiz__error', role: 'alert', hidden: '' } );

		button.addEventListener( 'click', function() {
			api.clearError( error );
			api.setBusy( button, __( 'Starting…', 'odsi-lms' ) );
			api.request( '/quizzes/' + quizId + '/attempts', { body: {} } )
				.then( function( attempt ) {
					state.attemptId = attempt.attempt_id;
					if ( attempt.time_limit > 0 ) {
						const seconds = typeof attempt.seconds_remaining === 'number' ? attempt.seconds_remaining : attempt.time_limit * 60;
						state.deadline = Date.now() + ( seconds * 1000 );
					}
					announce( attempt.resumed ? __( 'Attempt resumed.', 'odsi-lms' ) : __( 'Quiz started.', 'odsi-lms' ) );
					renderQuestions();
				} )
				.catch( function( failure ) {
					api.setBusy( button, null );
					api.showError( error, failure.message );
				} );
		} );

		nodes.push( el( 'div', { class: 'odsi-lms-quiz__intro-actions' }, [ button ] ) );
		nodes.push( error );
		render( nodes );
	}

	function questionField( question, index ) {
		const name = 'q' + question.id;
		const fields = [];

		if ( question.type === 'fill_blank' ) {
			fields.push( el( 'input', { type: 'text', class: 'odsi-lms-quiz__field', name, 'aria-label': question.title, autocomplete: 'off' } ) );
		} else if ( question.type === 'essay' ) {
			fields.push( el( 'textarea', { class: 'odsi-lms-quiz__field', name, rows: '5', 'aria-label': question.title } ) );
		} else {
			const multiple = question.type === 'multiple';
			question.options.forEach( function( option ) {
				const id = name + '-' + option.index;
				const input = el( 'input', { type: multiple ? 'checkbox' : 'radio', class: 'odsi-lms-quiz__option-input', name, value: String( option.index ), id } );
				const label = el( 'label', { class: 'odsi-lms-quiz__option-label', for: id }, [ input, el( 'span', { text: option.text } ) ] );
				fields.push( el( 'div', { class: 'odsi-lms-quiz__option' }, [ label ] ) );
			} );
		}

		const legend = el( 'legend', { class: 'odsi-lms-quiz__legend' }, [
			el( 'span', {
				/* translators: 1: question number, 2: question title. */
				text: sprintf( __( '%1$d. %2$s', 'odsi-lms' ), index + 1, question.title ) + ' ',
			} ),
			el( 'span', {
				class: 'odsi-lms-quiz__points',
				/* translators: %s: number of points. */
				text: sprintf( _n( '(%s point)', '(%s points)', Number( question.points ) === 1 ? 1 : 2, 'odsi-lms' ), question.points ),
			} ),
		] );

		return el( 'fieldset', { class: 'odsi-lms-quiz__question', 'data-question-id': question.id }, [
			legend,
			el( 'div', { class: 'odsi-lms-quiz__prompt', html: question.content || '' } ),
		].concat( fields ) );
	}

	function collectAnswers( form ) {
		const answers = {};
		state.questions.forEach( function( question ) {
			const name = 'q' + question.id;
			if ( question.type === 'multiple' ) {
				answers[ question.id ] = Array.prototype.map.call( form.querySelectorAll( '[name="' + name + '"]:checked' ), function( input ) {
					return parseInt( input.value, 10 );
				} );
			} else if ( question.type === 'fill_blank' || question.type === 'essay' ) {
				const field = form.querySelector( '[name="' + name + '"]' );
				answers[ question.id ] = field ? field.value : '';
			} else {
				const checked = form.querySelector( '[name="' + name + '"]:checked' );
				answers[ question.id ] = checked ? parseInt( checked.value, 10 ) : null;
			}
		} );
		return answers;
	}

	function stopTimer() {
		if ( state.timer ) {
			window.clearInterval( state.timer );
			state.timer = null;
		}
	}

	function renderQuestions() {
		const form = el( 'form', { class: 'odsi-lms-quiz__form', novalidate: '' } );
		const clock = el( 'p', { class: 'odsi-lms-quiz__clock', role: 'timer', 'aria-live': 'off' } );
		const error = el( 'p', { class: 'odsi-lms-quiz__error', role: 'alert', hidden: '' } );
		const submit = el( 'button', { type: 'submit', class: 'odsi-lms-button odsi-lms-quiz__submit', text: __( 'Submit answers', 'odsi-lms' ) } );

		state.questions.forEach( function( question, index ) {
			form.appendChild( questionField( question, index ) );
		} );

		form.appendChild( el( 'div', { class: 'odsi-lms-quiz__actions' }, [ submit ] ) );
		form.appendChild( error );

		form.addEventListener( 'submit', function( event ) {
			event.preventDefault();

			if ( form.dataset.submitting === '1' ) {
				return;
			}

			form.dataset.submitting = '1';
			api.clearError( error );
			api.setBusy( submit, __( 'Submitting…', 'odsi-lms' ) );
			announce( __( 'Submitting your answers.', 'odsi-lms' ) );

			api.request( '/attempts/' + state.attemptId + '/submit', { body: { answers: collectAnswers( form ) } } )
				.then( renderResult )
				.catch( function( failure ) {
					form.dataset.submitting = '';
					stopTimer();

					// A closed or timed-out attempt cannot be resubmitted; offer the way back.
					if ( failure.code === 'odsi_lms_attempt_timed_out' || failure.code === 'odsi_lms_attempt_closed' || failure.code === 'odsi_lms_quiz_locked' ) {
						renderClosed( failure.message );
						return;
					}

					api.setBusy( submit, null );
					api.showError( error, failure.message );
				} );
		} );

		render( state.deadline ? [ clock, form ] : [ form ] );

		if ( state.deadline ) {
			stopTimer();
			state.announced = {};
			const tick = function() {
				const left = Math.max( 0, Math.round( ( state.deadline - Date.now() ) / 1000 ) );
				/* translators: %s: time as minutes:seconds. */
				clock.textContent = sprintf( __( 'Time left: %s', 'odsi-lms' ), formatClock( left ) );
				clock.classList.toggle( 'odsi-lms-quiz__clock--urgent', left <= 60 );

				// Polite milestones rather than a stream of every second.
				[ 300, 60, 30 ].forEach( function( milestone ) {
					if ( left <= milestone && ! state.announced[ milestone ] ) {
						state.announced[ milestone ] = true;
						/* translators: %s: time as minutes:seconds. */
						announce( sprintf( __( '%s left.', 'odsi-lms' ), formatClock( left ) ) );
					}
				} );

				if ( left === 0 ) {
					stopTimer();
					announce( __( 'Time is up. Submitting your answers.', 'odsi-lms' ) );
					form.requestSubmit();
				}
			};
			tick();
			state.timer = window.setInterval( tick, 500 );
		}
	}

	function renderClosed( message ) {
		const again = el( 'button', { type: 'button', class: 'odsi-lms-button odsi-lms-quiz__again', text: __( 'Back to the quiz', 'odsi-lms' ) } );
		again.addEventListener( 'click', function() {
			window.location.reload();
		} );
		announce( message );
		render( [ errorNode( message ), el( 'div', { class: 'odsi-lms-quiz__actions' }, [ again ] ) ] );
	}

	function renderResult( result ) {
		stopTimer();

		let status = __( 'Not this time.', 'odsi-lms' );
		let modifier = 'failed';
		if ( result.needs_grading ) {
			status = __( 'Submitted. Some answers are awaiting grading.', 'odsi-lms' );
			modifier = 'pending';
		} else if ( result.passed ) {
			status = __( 'You passed!', 'odsi-lms' );
			modifier = 'passed';
		}

		const nodes = [
			el( 'p', { class: 'odsi-lms-quiz__status odsi-lms-quiz__status--' + modifier, text: status } ),
			el( 'p', {
				class: 'odsi-lms-quiz__score',
				/* translators: 1: points earned, 2: points possible, 3: percentage. */
				text: sprintf( __( '%1$s of %2$s points (%3$s%%)', 'odsi-lms' ), result.points_earned, result.points_possible, result.percentage ),
			} ),
		];

		const list = el( 'ol', { class: 'odsi-lms-quiz__breakdown' } );
		state.questions.forEach( function( question ) {
			const q = result.questions[ question.id ] || {};
			let label = __( 'Incorrect', 'odsi-lms' );
			let verdict = 'incorrect';
			if ( q.needs_grading ) {
				label = __( 'Awaiting grading', 'odsi-lms' );
				verdict = 'pending';
			} else if ( q.is_correct ) {
				label = __( 'Correct', 'odsi-lms' );
				verdict = 'correct';
			}
			list.appendChild( el( 'li', { class: 'odsi-lms-quiz__breakdown-item' }, [
				el( 'span', { class: 'odsi-lms-quiz__breakdown-title', text: question.title + ' — ' } ),
				el( 'span', { class: 'odsi-lms-quiz__verdict odsi-lms-quiz__verdict--' + verdict, text: label } ),
			] ) );
		} );
		nodes.push( list );

		const actions = [];

		if ( result.next_url ) {
			actions.push( el( 'a', { class: 'odsi-lms-button odsi-lms-quiz__next', href: result.next_url, text: __( 'Continue to the next step', 'odsi-lms' ) } ) );
		}

		if ( ! result.passed && result.attempts_remaining !== 0 ) {
			const again = el( 'button', { type: 'button', class: 'odsi-lms-button odsi-lms-button--secondary odsi-lms-quiz__again', text: __( 'Try again', 'odsi-lms' ) } );
			again.addEventListener( 'click', function() {
				window.location.reload();
			} );
			actions.push( again );
		}

		if ( result.course_url ) {
			actions.push( el( 'a', { class: 'odsi-lms-button odsi-lms-button--secondary odsi-lms-quiz__course', href: result.course_url, text: __( 'Back to the course', 'odsi-lms' ) } ) );
		}

		if ( ! result.passed && result.attempts_remaining === 0 ) {
			nodes.push( el( 'p', { class: 'odsi-lms-notice odsi-lms-quiz__notice', text: __( 'You have used all your attempts at this quiz.', 'odsi-lms' ) } ) );
		}

		nodes.push( el( 'div', { class: 'odsi-lms-quiz__actions' }, actions ) );

		announce( status );
		render( [ el( 'section', { class: 'odsi-lms-quiz__result', 'aria-label': __( 'Quiz result', 'odsi-lms' ) }, nodes ) ] );
	}

	api.request( '/quizzes/' + quizId + '/questions' )
		.then( function( quiz ) {
			state.questions = quiz.questions;
			renderIntro( quiz );
		} )
		.catch( function( failure ) {
			render( [ errorNode( failure.message ) ] );
		} );
}() );
