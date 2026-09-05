/**
 * ODSI LMS front-end behaviour.
 *
 * Progressive enhancement only: every control it binds to is a real element
 * rendered by PHP, so the pages remain readable without this file. Every
 * user-visible string goes through wp.i18n; every failure is written into a
 * live region next to the control, never into alert().
 */
( function() {
	'use strict';

	const config = window.odsiLms || {};
	const { __, sprintf } = window.wp.i18n;

	/**
	 * Message for a failed request, from the REST error when it has one.
	 *
	 * @param {Object|null} data   Parsed error body, if any.
	 * @param {number}      status HTTP status.
	 * @return {string} Translated message.
	 */
	function errorMessage( data, status ) {
		if ( data && ( data.code === 'rest_cookie_invalid_nonce' || ( status === 403 && data.code === 'rest_forbidden' ) ) ) {
			return __( 'Your session has expired. Reload the page and try again.', 'odsi-lms' );
		}

		if ( data && typeof data.message === 'string' && data.message !== '' ) {
			return data.message;
		}

		return __( 'Something went wrong. Please try again.', 'odsi-lms' );
	}

	/**
	 * Call a plugin REST route.
	 *
	 * @param {string} path    Route path relative to the plugin namespace.
	 * @param {Object} options `method`, `body` (object, JSON encoded) or `form` (FormData).
	 * @return {Promise<Object>} Parsed response. Rejects with an Error carrying `code` and `status`.
	 */
	function request( path, options ) {
		const opts = options || {};
		const headers = { 'X-WP-Nonce': config.nonce };
		let body;

		if ( opts.form ) {
			body = opts.form;
		} else if ( opts.body ) {
			headers[ 'Content-Type' ] = 'application/json';
			body = JSON.stringify( opts.body );
		}

		return window
			.fetch( config.restUrl + path, {
				method: opts.method || ( body ? 'POST' : 'GET' ),
				credentials: 'same-origin',
				headers,
				body,
			} )
			.then(
				function( response ) {
					return response
						.json()
						.catch( function() {
							return null;
						} )
						.then( function( data ) {
							if ( ! response.ok || data === null ) {
								const error = new Error( errorMessage( data, response.status ) );
								error.code = data && data.code ? data.code : 'odsi_lms_http_' + response.status;
								error.status = response.status;
								throw error;
							}

							return data;
						} );
				},
				function() {
					const error = new Error( __( 'Could not reach the server. Check your connection and try again.', 'odsi-lms' ) );
					error.code = 'odsi_lms_network';
					error.status = 0;
					throw error;
				},
			);
	}

	/**
	 * Show a message in an error region (role="alert") and, optionally, mark
	 * the field it concerns.
	 *
	 * @param {HTMLElement|null} region  Error element.
	 * @param {string}           message Message.
	 * @param {HTMLElement}      [field] Field to mark invalid.
	 */
	function showError( region, message, field ) {
		if ( ! region ) {
			return;
		}

		region.textContent = message;
		region.hidden = false;

		if ( field ) {
			field.setAttribute( 'aria-invalid', 'true' );

			if ( region.id ) {
				field.setAttribute( 'aria-describedby', region.id );
			}

			field.focus();
		}
	}

	/**
	 * Clear an error region and any field it marked.
	 *
	 * @param {HTMLElement|null} region Error element.
	 * @param {HTMLElement[]}    fields Fields to reset.
	 */
	function clearError( region, fields ) {
		if ( region ) {
			region.textContent = '';
			region.hidden = true;
		}

		( fields || [] ).forEach( function( field ) {
			field.removeAttribute( 'aria-invalid' );

			if ( region && region.id && field.getAttribute( 'aria-describedby' ) === region.id ) {
				field.removeAttribute( 'aria-describedby' );
			}
		} );
	}

	/**
	 * Put a button into or out of its busy state.
	 *
	 * @param {HTMLButtonElement} button Button.
	 * @param {string|null}       label  Busy label, or null to restore.
	 */
	function setBusy( button, label ) {
		if ( label !== null ) {
			if ( ! button.dataset.idleLabel ) {
				button.dataset.idleLabel = button.textContent;
			}

			button.disabled = true;
			button.setAttribute( 'aria-busy', 'true' );
			button.textContent = label;

			return;
		}

		button.disabled = false;
		button.removeAttribute( 'aria-busy' );

		if ( button.dataset.idleLabel ) {
			button.textContent = button.dataset.idleLabel;
		}
	}

	/**
	 * Update every progress bar on the page for a course.
	 *
	 * @param {number} courseId    Course post id.
	 * @param {number} percentage  Completion percentage.
	 * @param {number} [completed] Steps completed.
	 * @param {number} [total]     Steps in the course.
	 */
	function paintProgress( courseId, percentage, completed, total ) {
		document.querySelectorAll( '.odsi-lms-progress[data-course-id="' + courseId + '"]' ).forEach( function( wrapper ) {
			const track = wrapper.querySelector( '.odsi-lms-progress__track' );
			const fill = wrapper.querySelector( '.odsi-lms-progress__fill' );
			const label = wrapper.querySelector( '.odsi-lms-progress__label' );

			if ( fill ) {
				fill.style.width = percentage + '%';
			}

			if ( track ) {
				track.setAttribute( 'aria-valuenow', String( percentage ) );
			}

			if ( label && typeof completed === 'number' && typeof total === 'number' ) {
				label.textContent = sprintf(
					/* translators: 1: steps completed, 2: total steps, 3: percentage. */
					__( '%1$d of %2$d steps complete (%3$s%%)', 'odsi-lms' ),
					completed,
					total,
					percentage,
				);
			}
		} );
	}

	/**
	 * Mark a step complete in every outline on the page.
	 *
	 * @param {number} stepId Step post id.
	 */
	function paintOutlineComplete( stepId ) {
		document.querySelectorAll( '.odsi-lms-outline__item[data-step-id="' + stepId + '"]' ).forEach( function( item ) {
			if ( item.classList.contains( 'odsi-lms-outline__item--complete' ) ) {
				return;
			}

			item.classList.add( 'odsi-lms-outline__item--complete' );

			const badge = document.createElement( 'span' );
			badge.className = 'odsi-lms-outline__status odsi-lms-outline__status--complete';
			badge.textContent = __( 'Completed', 'odsi-lms' );
			item.appendChild( badge );
		} );
	}

	/**
	 * Turn a locked "next" entry into a link once the step it waited on is done.
	 *
	 * @param {number} nextId  Step that just unlocked.
	 * @param {string} nextUrl Its permalink.
	 */
	function unlockNext( nextId, nextUrl ) {
		if ( ! nextId || ! nextUrl ) {
			return;
		}

		document.querySelectorAll( '.odsi-lms-step-nav__link--locked[data-step-id="' + nextId + '"]' ).forEach( function( locked ) {
			const link = document.createElement( 'a' );
			link.className = 'odsi-lms-step-nav__link odsi-lms-step-nav__link--next';
			link.setAttribute( 'rel', 'next' );
			link.href = nextUrl;

			Array.prototype.forEach.call( locked.children, function( child ) {
				if ( ! child.classList.contains( 'odsi-lms-step-nav__lock' ) ) {
					link.appendChild( child.cloneNode( true ) );
				}
			} );

			locked.replaceWith( link );
		} );

		document.querySelectorAll( '.odsi-lms-outline__item--locked[data-step-id="' + nextId + '"]' ).forEach( function( item ) {
			const title = item.querySelector( '.odsi-lms-outline__title' );
			const lock = item.querySelector( '.odsi-lms-outline__status--locked' );

			if ( title && title.tagName !== 'A' ) {
				const link = document.createElement( 'a' );
				link.className = 'odsi-lms-outline__title';
				link.href = nextUrl;
				link.textContent = title.textContent;
				title.replaceWith( link );
			}

			if ( lock ) {
				lock.remove();
			}

			item.classList.remove( 'odsi-lms-outline__item--locked' );
		} );
	}

	/**
	 * Mark a lesson or topic complete.
	 *
	 * @param {HTMLButtonElement} button Clicked button.
	 */
	function completeStep( button ) {
		const stepId = parseInt( button.getAttribute( 'data-step-id' ), 10 );
		const footer = button.closest( '.odsi-lms-lesson__footer' );
		const status = footer ? footer.querySelector( '.odsi-lms-lesson__status' ) : null;
		const error = footer ? footer.querySelector( '.odsi-lms-lesson__error' ) : null;

		clearError( error );
		setBusy( button, __( 'Saving…', 'odsi-lms' ) );

		request( '/steps/' + stepId + '/complete', { body: {} } )
			.then( function( data ) {
				button.textContent = __( 'Completed', 'odsi-lms' );
				button.classList.add( 'odsi-lms-complete--done' );
				button.removeAttribute( 'aria-busy' );
				button.disabled = true;

				paintProgress( data.course_id, data.percentage, data.completed_count, data.total );
				paintOutlineComplete( stepId );
				unlockNext( data.next_id, data.next_url );

				if ( status ) {
					status.textContent = data.course_complete
						? __( 'Step completed. You have finished the course.', 'odsi-lms' )
						: sprintf(
							/* translators: %s: percentage. */
							__( 'Step completed. Course progress: %s%%.', 'odsi-lms' ),
							data.percentage,
						);
				}
			} )
			.catch( function( failure ) {
				setBusy( button, null );
				showError( error, failure.message );
			} );
	}

	/**
	 * Enroll the current user on a course. The page reloads on success so the
	 * outline, progress bar and button all reflect the new state.
	 *
	 * @param {HTMLButtonElement} button Clicked button.
	 */
	function enroll( button ) {
		const courseId = button.getAttribute( 'data-course-id' );
		const wrapper = button.closest( '.odsi-lms-enroll' );
		const error = wrapper ? wrapper.querySelector( '.odsi-lms-enroll__error' ) : null;

		clearError( error );
		setBusy( button, __( 'Enrolling…', 'odsi-lms' ) );

		request( '/courses/' + courseId + '/enroll', { body: {} } )
			.then( function() {
				button.textContent = __( 'Enrolled. Loading the course…', 'odsi-lms' );
				window.location.reload();
			} )
			.catch( function( failure ) {
				setBusy( button, null );
				showError( error, failure.message );
			} );
	}

	/**
	 * Check an assignment form against the same limits the server applies.
	 *
	 * @param {HTMLFormElement} form Form.
	 * @return {{message: string, field: HTMLElement}|null} The first problem, or null.
	 */
	function validateAssignment( form ) {
		const textarea = form.querySelector( 'textarea[name="content"]' );
		const file = form.querySelector( 'input[type="file"][name="file"]' );
		const chosen = file && file.files && file.files.length ? file.files[ 0 ] : null;
		const text = textarea ? textarea.value.trim() : '';

		if ( text === '' && ! chosen ) {
			return { message: __( 'Write an answer or attach a file before handing in.', 'odsi-lms' ), field: textarea || file };
		}

		if ( ! chosen ) {
			return null;
		}

		const limits = config.assignment || {};
		const maxBytes = parseInt( form.getAttribute( 'data-max-bytes' ) || limits.maxBytes || 0, 10 );
		const maxLabel = form.getAttribute( 'data-max-label' ) || limits.maxLabel || '';

		if ( maxBytes > 0 && chosen.size > maxBytes ) {
			/* translators: %s: size limit. */
			return { message: sprintf( __( 'The file is larger than the %s limit.', 'odsi-lms' ), maxLabel ), field: file };
		}

		const accept = ( form.getAttribute( 'data-accept' ) || ( file && file.getAttribute( 'accept' ) ) || '' )
			.split( ',' )
			.map( function( ext ) {
				return ext.trim().toLowerCase();
			} )
			.filter( Boolean );
		const extension = '.' + chosen.name.split( '.' ).pop().toLowerCase();

		if ( accept.length && chosen.name.indexOf( '.' ) !== -1 && accept.indexOf( extension ) === -1 ) {
			return {
				message: sprintf(
					/* translators: %s: list of accepted file extensions. */
					__( 'That file type is not accepted. Accepted types: %s.', 'odsi-lms' ),
					accept.join( ', ' ).replace( /\./g, '' ),
				),
				field: file,
			};
		}

		return null;
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
		const fields = Array.prototype.slice.call( form.querySelectorAll( 'textarea, input[type="file"]' ) );

		if ( form.dataset.submitting === '1' ) {
			return;
		}

		clearError( error, fields );

		const problem = validateAssignment( form );

		if ( problem ) {
			showError( error, problem.message, problem.field );

			return;
		}

		form.dataset.submitting = '1';

		if ( button ) {
			setBusy( button, __( 'Handing in…', 'odsi-lms' ) );
		}

		request( '/steps/' + stepId + '/submissions', { method: 'POST', form: new window.FormData( form ) } )
			.then( function() {
				if ( button ) {
					button.textContent = __( 'Handed in. Loading…', 'odsi-lms' );
				}

				window.location.reload();
			} )
			.catch( function( failure ) {
				form.dataset.submitting = '';

				if ( button ) {
					setBusy( button, null );
				}

				showError( error, failure.message );
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
		const target = event.target instanceof Element ? event.target.closest( 'button' ) : null;

		if ( ! target || target.disabled ) {
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

	// Shared with the quiz player, which loads after this file.
	config.api = { request, showError, clearError, setBusy, paintProgress, paintOutlineComplete, unlockNext };
	window.odsiLms = config;
}() );
