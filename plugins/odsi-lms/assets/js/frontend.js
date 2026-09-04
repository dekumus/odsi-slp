/**
 * ODSI LMS front-end behaviour.
 *
 * Progressive enhancement only: every control it binds to is a real element
 * rendered by PHP, so the pages remain readable without this file.
 */
( function() {
	'use strict';

	const config = window.odsiLms || {};

	/**
	 * Call a plugin REST route.
	 *
	 * @param {string} path   Route path relative to the plugin namespace.
	 * @param {Object} [body] Optional JSON body; sends POST when present.
	 * @return {Promise<Object>} Parsed response.
	 */
	function request( path, body ) {
		return window
			.fetch( config.restUrl + path, {
				method: body ? 'POST' : 'GET',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
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

	/**
	 * Update every progress bar on the page for a course.
	 *
	 * @param {number} courseId   Course post id.
	 * @param {number} percentage Completion percentage.
	 */
	function paintProgress( courseId, percentage ) {
		const wrapper = document.querySelector(
			'.odsi-lms-progress[data-course-id="' + courseId + '"]',
		);

		if ( ! wrapper ) {
			return;
		}

		const track = wrapper.querySelector( '.odsi-lms-progress__track' );
		const fill = wrapper.querySelector( '.odsi-lms-progress__fill' );

		if ( fill ) {
			fill.style.width = percentage + '%';
		}

		if ( track ) {
			track.setAttribute( 'aria-valuenow', String( percentage ) );
		}
	}

	/**
	 * Mark a lesson or topic complete.
	 *
	 * @param {HTMLElement} button Clicked button.
	 */
	function completeStep( button ) {
		const stepId = button.getAttribute( 'data-step-id' );
		const original = button.textContent;

		button.disabled = true;
		button.textContent = config.i18n.markingComplete;

		request( '/steps/' + stepId + '/complete', {} )
			.then( function( data ) {
				button.textContent = config.i18n.completed;
				paintProgress( data.course_id, data.percentage );
			} )
			.catch( function( error ) {
				button.disabled = false;
				button.textContent = original;
				window.alert( error.message );
			} );
	}

	/**
	 * Enroll the current user on a course.
	 *
	 * @param {HTMLElement} button Clicked button.
	 */
	function enroll( button ) {
		const courseId = button.getAttribute( 'data-course-id' );

		button.disabled = true;

		request( '/courses/' + courseId + '/enroll', {} )
			.then( function() {
				window.location.reload();
			} )
			.catch( function( error ) {
				button.disabled = false;
				window.alert( error.message );
			} );
	}

	/**
	 * Hand in an assignment form as multipart, then reload to show the state.
	 *
	 * @param {HTMLFormElement} form Form.
	 */
	function submitAssignment( form ) {
		const section = form.closest( '.odsi-lms-assignment' );
		const stepId = section ? section.getAttribute( 'data-step-id' ) : '';
		const button = form.querySelector( '.odsi-lms-assignment__submit' );
		const error = form.querySelector( '.odsi-lms-assignment__error' );

		if ( button ) {
			button.disabled = true;
		}

		if ( error ) {
			error.hidden = true;
		}

		window
			.fetch( config.restUrl + '/steps/' + stepId + '/submissions', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce },
				body: new window.FormData( form ),
			} )
			.then( function( response ) {
				return response.json().then( function( data ) {
					if ( ! response.ok ) {
						throw new Error( data.message || config.i18n.error );
					}

					window.location.reload();
				} );
			} )
			.catch( function( failure ) {
				if ( button ) {
					button.disabled = false;
				}

				if ( error ) {
					error.textContent = failure.message;
					error.hidden = false;
				} else {
					window.alert( failure.message );
				}
			} );
	}

	document.addEventListener( 'submit', function( event ) {
		const form = event.target;

		if ( form instanceof HTMLFormElement && form.classList.contains( 'odsi-lms-assignment__form' ) ) {
			event.preventDefault();
			submitAssignment( form );
		}
	} );

	document.addEventListener( 'click', function( event ) {
		const target = event.target;

		if ( ! ( target instanceof HTMLElement ) ) {
			return;
		}

		if ( target.classList.contains( 'odsi-lms-complete' ) ) {
			event.preventDefault();
			completeStep( target );
		}

		if ( target.classList.contains( 'odsi-lms-enroll__button' ) ) {
			event.preventDefault();
			enroll( target );
		}
	} );
}() );
