/**
 * ODSI LMS front-end behaviour.
 *
 * Progressive enhancement only: every control it binds to is a real element
 * rendered by PHP, so the pages remain readable without this file.
 */
( function () {
	'use strict';

	var config = window.odsiLms || {};

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
			.then( function ( response ) {
				return response.json().then( function ( data ) {
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
		var wrapper = document.querySelector(
			'.odsi-lms-progress[data-course-id="' + courseId + '"]'
		);

		if ( ! wrapper ) {
			return;
		}

		var track = wrapper.querySelector( '.odsi-lms-progress__track' );
		var fill = wrapper.querySelector( '.odsi-lms-progress__fill' );

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
		var stepId = button.getAttribute( 'data-step-id' );
		var original = button.textContent;

		button.disabled = true;
		button.textContent = config.i18n.markingComplete;

		request( '/steps/' + stepId + '/complete', {} )
			.then( function ( data ) {
				button.textContent = config.i18n.completed;
				paintProgress( data.course_id, data.percentage );
			} )
			.catch( function ( error ) {
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
		var courseId = button.getAttribute( 'data-course-id' );

		button.disabled = true;

		request( '/courses/' + courseId + '/enroll', {} )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( error ) {
				button.disabled = false;
				window.alert( error.message );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

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
} )();
