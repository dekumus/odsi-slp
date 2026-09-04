/**
 * ODSI LMS quiz player.
 *
 * Renders a quiz from GET /quizzes/{id}/questions, opens an attempt, submits
 * answers, and shows the result. The page shows a start button without it;
 * the whole interaction lives here.
 */
( function() {
	'use strict';

	const config = window.odsiLms || {};
	const mount = document.querySelector( '.odsi-lms-quiz__player' );

	if ( ! mount || ! config.restUrl ) {
		return;
	}

	const quizId = mount.getAttribute( 'data-quiz-id' );
	const state = { attemptId: 0, questions: [], deadline: 0, timer: null };

	function request( method, path, body ) {
		return window
			.fetch( config.restUrl + path, {
				method,
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
				body: body ? JSON.stringify( body ) : undefined,
			} )
			.then( function( response ) {
				return response.json().then( function( data ) {
					if ( ! response.ok ) {
						throw new Error( data.message || config.i18n.error );
					}
					return data;
				} );
			} );
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
	}

	function renderIntro( quiz ) {
		const info = [];
		if ( quiz.time_limit > 0 ) {
			info.push( el( 'li', { text: quiz.time_limit + ' ' + config.i18n.minutes } ) );
		}
		if ( quiz.attempts_remaining !== null ) {
			info.push( el( 'li', { text: config.i18n.attemptsRemaining + ' ' + quiz.attempts_remaining } ) );
		}
		info.push( el( 'li', { text: config.i18n.passMark + ' ' + quiz.pass_mark + '%' } ) );

		const button = el( 'button', { type: 'button', class: 'odsi-lms-button', text: config.i18n.start } );
		button.addEventListener( 'click', function() {
			button.disabled = true;
			request( 'POST', '/quizzes/' + quizId + '/attempts', {} )
				.then( function( attempt ) {
					state.attemptId = attempt.attempt_id;
					if ( attempt.time_limit > 0 ) {
						state.deadline = Date.now() + ( attempt.time_limit * 60000 );
					}
					renderQuestions();
				} )
				.catch( function( error ) {
					button.disabled = false;
					render( [ el( 'p', { class: 'odsi-lms-quiz__error', text: error.message } ) ] );
				} );
		} );

		render( [ el( 'ul', { class: 'odsi-lms-quiz__info' }, info ), button ] );
	}

	function questionField( question ) {
		const name = 'q' + question.id;
		const fields = [];

		if ( question.type === 'fill_blank' ) {
			fields.push( el( 'input', { type: 'text', name, 'aria-label': question.title } ) );
		} else if ( question.type === 'essay' ) {
			fields.push( el( 'textarea', { name, rows: '5', 'aria-label': question.title } ) );
		} else {
			const multiple = question.type === 'multiple';
			question.options.forEach( function( option ) {
				const id = name + '-' + option.index;
				const input = el( 'input', { type: multiple ? 'checkbox' : 'radio', name, value: String( option.index ), id } );
				const label = el( 'label', { for: id, text: option.text } );
				fields.push( el( 'div', { class: 'odsi-lms-quiz__option' }, [ input, label ] ) );
			} );
		}

		return el( 'fieldset', { class: 'odsi-lms-quiz__question', 'data-question-id': question.id }, [
			el( 'legend', { text: question.title + ( question.points ? ' (' + question.points + ')' : '' ) } ),
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

	function renderQuestions() {
		const form = el( 'form', { class: 'odsi-lms-quiz__form' } );
		const clock = el( 'p', { class: 'odsi-lms-quiz__clock' } );

		state.questions.forEach( function( question ) {
			form.appendChild( questionField( question ) );
		} );

		const submit = el( 'button', { type: 'submit', class: 'odsi-lms-button', text: config.i18n.submit } );
		form.appendChild( submit );

		form.addEventListener( 'submit', function( event ) {
			event.preventDefault();
			submit.disabled = true;
			request( 'POST', '/attempts/' + state.attemptId + '/submit', { answers: collectAnswers( form ) } )
				.then( renderResult )
				.catch( function( error ) {
					submit.disabled = false;
					window.alert( error.message );
				} );
		} );

		render( state.deadline ? [ clock, form ] : [ form ] );

		if ( state.deadline ) {
			window.clearInterval( state.timer );
			state.timer = window.setInterval( function() {
				const left = Math.max( 0, Math.round( ( state.deadline - Date.now() ) / 1000 ) );
				clock.textContent = config.i18n.timeLeft + ' ' + Math.floor( left / 60 ) + ':' + ( '0' + ( left % 60 ) ).slice( -2 );
				if ( left === 0 ) {
					window.clearInterval( state.timer );
					form.requestSubmit();
				}
			}, 500 );
		}
	}

	function renderResult( result ) {
		window.clearInterval( state.timer );

		let status = config.i18n.failed;
		if ( result.needs_grading ) {
			status = config.i18n.needsGrading;
		} else if ( result.passed ) {
			status = config.i18n.passed;
		}
		const nodes = [
			el( 'p', { class: 'odsi-lms-quiz__status ' + ( result.passed ? 'is-passed' : 'is-failed' ), text: status } ),
			el( 'p', { text: result.points_earned + ' / ' + result.points_possible + ' (' + result.percentage + '%)' } ),
		];

		const list = el( 'ol', { class: 'odsi-lms-quiz__breakdown' } );
		state.questions.forEach( function( question ) {
			const q = result.questions[ question.id ] || {};
			let label = config.i18n.incorrect;
			if ( q.needs_grading ) {
				label = config.i18n.needsGrading;
			} else if ( q.is_correct ) {
				label = config.i18n.correct;
			}
			list.appendChild( el( 'li', { class: q.is_correct ? 'is-correct' : 'is-incorrect', text: question.title + ' — ' + label } ) );
		} );
		nodes.push( list );

		const again = el( 'button', { type: 'button', class: 'odsi-lms-button', text: config.i18n.tryAgain } );
		again.addEventListener( 'click', function() {
			window.location.reload();
		} );
		nodes.push( again );

		render( nodes );
	}

	request( 'GET', '/quizzes/' + quizId + '/questions' )
		.then( function( quiz ) {
			state.questions = quiz.questions;
			renderIntro( quiz );
		} )
		.catch( function( error ) {
			render( [ el( 'p', { class: 'odsi-lms-quiz__error', text: error.message } ) ] );
		} );
}() );
