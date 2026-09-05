/**
 * ODSI Social front-end behaviour.
 *
 * Progressive enhancement over server-rendered pages: posting, commenting,
 * reacting, membership, connection, follow, block and report actions,
 * notification read state, message sending and cursor-based "load more".
 *
 * Every control is a real element PHP rendered. Nothing here shows a string
 * that did not come from `odsiSocial.i18n`, and every request failure is
 * reported next to the control that caused it, never swallowed. No jQuery.
 */
( function() {
	'use strict';

	const config = window.odsiSocial || {};
	const i18n = config.i18n || {};

	/**
	 * Elements with a request in flight, so a second activation is ignored.
	 */
	const busy = new WeakSet();

	/**
	 * A localized string, with `%d` / `%s` replaced.
	 *
	 * @param {string} key   Key in `odsiSocial.i18n`.
	 * @param {*}      value Placeholder value.
	 * @return {string} Text.
	 */
	function text( key, value ) {
		const template = String( i18n[ key ] || '' );

		return value === undefined ? template : template.replace( /%[ds]/, String( value ) );
	}

	/**
	 * Call the plugin's REST API with the page's nonce.
	 *
	 * @param {string} method HTTP method.
	 * @param {string} path   Path under the namespace.
	 * @param {Object} [body] JSON body.
	 * @return {Promise<Object>} Parsed response, or a rejection carrying the server's message.
	 */
	function request( method, path, body ) {
		return window
			.fetch( config.restUrl + path, {
				method,
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
				body: body ? JSON.stringify( body ) : undefined,
			} )
			.then( function( response ) {
				return response.text().then( function( raw ) {
					let data = {};

					try {
						data = raw ? JSON.parse( raw ) : {};
					} catch ( e ) {
						data = {};
					}

					if ( ! response.ok ) {
						const expired = 'rest_cookie_invalid_nonce' === data.code || ( 'rest_forbidden' === data.code && 403 === response.status && ! data.message );
						const error = new Error( expired ? text( 'sessionExpired' ) : data.message || text( 'error' ) );
						error.code = data.code || '';
						error.status = response.status;
						throw error;
					}

					return data;
				} );
			} );
	}

	/**
	 * Closest ancestor matching a selector, for anything that might not be an element.
	 *
	 * @param {EventTarget|null} el       Start.
	 * @param {string}           selector Selector.
	 * @return {Element|null} Match.
	 */
	function closest( el, selector ) {
		return el instanceof Element ? el.closest( selector ) : null;
	}

	/**
	 * Show an error next to a control, or in a form's own alert slot.
	 *
	 * @param {Element} anchor  Form or control.
	 * @param {string}  message Message.
	 */
	function showError( anchor, message ) {
		let slot = anchor instanceof HTMLFormElement ? anchor.querySelector( '.odsi-social-error' ) : null;

		if ( ! slot ) {
			slot = anchor.nextElementSibling && anchor.nextElementSibling.classList.contains( 'odsi-social-error' ) ? anchor.nextElementSibling : null;
		}

		if ( ! slot ) {
			slot = document.createElement( 'p' );
			slot.className = 'odsi-social-error';
			slot.setAttribute( 'role', 'alert' );
			anchor.insertAdjacentElement( 'afterend', slot );
		}

		slot.textContent = message || text( 'error' );
		slot.hidden = false;
	}

	/**
	 * Remove an earlier error for a control or form.
	 *
	 * @param {Element} anchor Form or control.
	 */
	function clearError( anchor ) {
		const slot = anchor instanceof HTMLFormElement ? anchor.querySelector( '.odsi-social-error' ) : anchor.nextElementSibling;

		if ( slot && slot.classList.contains( 'odsi-social-error' ) ) {
			slot.textContent = '';
			slot.hidden = true;

			if ( ! ( anchor instanceof HTMLFormElement ) ) {
				slot.remove();
			}
		}
	}

	/**
	 * Announce a status to assistive technology through the feed's live region.
	 *
	 * @param {Element|null} scope   Element inside a feed, or null for the first feed.
	 * @param {string}       message Message.
	 */
	function announce( scope, message ) {
		const feed = closest( scope, '.odsi-social-feed' ) || document.querySelector( '.odsi-social-feed' );
		const region = feed ? feed.querySelector( '.odsi-social-feed__status' ) : null;

		if ( region ) {
			region.textContent = '';
			window.setTimeout( function() {
				region.textContent = message;
			}, 50 );
		}
	}

	/**
	 * Mark an element busy for the length of a request; false when it already is.
	 *
	 * @param {Element} el Control or form.
	 * @return {boolean} Whether the caller may proceed.
	 */
	function start( el ) {
		if ( busy.has( el ) ) {
			return false;
		}

		busy.add( el );
		el.setAttribute( 'aria-busy', 'true' );
		el.classList.add( 'is-busy' );

		const submit = el instanceof HTMLFormElement ? el.querySelector( '[type="submit"]' ) : el;

		if ( submit ) {
			submit.setAttribute( 'aria-disabled', 'true' );
			submit.classList.add( 'is-busy' );
		}

		return true;
	}

	/**
	 * Release an element after its request.
	 *
	 * @param {Element} el Control or form.
	 */
	function finish( el ) {
		busy.delete( el );
		el.removeAttribute( 'aria-busy' );
		el.classList.remove( 'is-busy' );

		const submit = el instanceof HTMLFormElement ? el.querySelector( '[type="submit"]' ) : el;

		if ( submit ) {
			submit.removeAttribute( 'aria-disabled' );
			submit.classList.remove( 'is-busy' );
		}
	}

	/**
	 * Run a request from a control: one at a time, errors shown inline.
	 *
	 * @param {Element}  el     Control or form.
	 * @param {Function} action Returns the request promise.
	 * @param {Function} [done] Success handler.
	 * @param {Function} [undo] Called with the error before it is shown (to revert optimistic UI).
	 */
	function perform( el, action, done, undo ) {
		if ( ! start( el ) ) {
			return;
		}

		clearError( el );

		action()
			.then( function( data ) {
				if ( done ) {
					done( data );
				}
			} )
			.catch( function( error ) {
				if ( undo ) {
					undo( error );
				}

				showError( el, error.message );
			} )
			.then( function() {
				finish( el );
			} );
	}

	/**
	 * Parse an HTML fragment into its first element.
	 *
	 * @param {string} html Markup.
	 * @return {Element|null} Element.
	 */
	function fragment( html ) {
		const template = document.createElement( 'template' );
		template.innerHTML = String( html || '' ).trim();

		return template.content.firstElementChild;
	}

	/**
	 * Adjust the number inside a footer button.
	 *
	 * @param {Element|null} control Button holding an `.odsi-social-item__count`.
	 * @param {number}       delta   Change.
	 */
	function bump( control, delta ) {
		const count = control ? control.querySelector( '.odsi-social-item__count' ) : null;

		if ( count ) {
			count.textContent = String( Math.max( 0, ( parseInt( count.textContent, 10 ) || 0 ) + delta ) );
		}
	}

	/**
	 * Keep the "characters left" counter of a textarea current.
	 *
	 * @param {HTMLTextAreaElement} field Textarea with `maxlength` and `aria-describedby`.
	 */
	function updateCounter( field ) {
		const id = field.getAttribute( 'aria-describedby' );
		const counter = id ? document.getElementById( id ) : null;

		if ( ! counter ) {
			return;
		}

		const max = parseInt( counter.getAttribute( 'data-max' ) || field.getAttribute( 'maxlength' ) || '0', 10 );
		const left = Math.max( 0, max - field.value.length );
		const label = text( 1 === left ? 'characterLeft' : 'charactersLeft', left );

		counter.textContent = label;
		counter.classList.toggle( 'is-low', left <= Math.max( 20, Math.floor( max / 20 ) ) );

		// The live copy only speaks when the limit is near, so typing is not narrated.
		const live = counter.parentElement ? counter.parentElement.querySelector( '.odsi-social-post-form__count-announce' ) : null;

		if ( live ) {
			live.textContent = left <= 50 ? label : '';
		}
	}

	document.addEventListener( 'input', function( event ) {
		if ( event.target instanceof HTMLTextAreaElement && event.target.hasAttribute( 'aria-describedby' ) ) {
			updateCounter( event.target );
		}
	} );

	/* ---------- Forms ---------- */

	document.addEventListener( 'submit', function( event ) {
		const form = event.target;

		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}

		if ( form.classList.contains( 'odsi-social-post-form' ) ) {
			event.preventDefault();
			const privacy = form.querySelector( '[name="privacy"]' );

			perform(
				form,
				function() {
					return request( 'POST', '/activity', {
						content: form.content.value,
						privacy: privacy ? privacy.value : '',
						group_id: parseInt( form.getAttribute( 'data-group-id' ) || '0', 10 ),
						render: true,
					} );
				},
				function( item ) {
					const feed = closest( form, '.odsi-social-feed' );
					const list = feed ? feed.querySelector( '.odsi-social-feed__items' ) : null;
					const entry = fragment( item.html );

					if ( list && entry ) {
						list.insertAdjacentElement( 'afterbegin', entry );
						const empty = feed.querySelector( '.odsi-social-feed__empty' );

						if ( empty ) {
							empty.remove();
						}
					}

					form.content.value = '';
					updateCounter( form.content );
					announce( form, text( 'posted' ) );
				},
			);
		}

		if ( form.classList.contains( 'odsi-social-comment-form' ) ) {
			event.preventDefault();
			const article = closest( form, '.odsi-social-item' );

			perform(
				form,
				function() {
					return request( 'POST', '/activity/' + form.getAttribute( 'data-activity-id' ) + '/comments', { content: form.content.value, render: true } );
				},
				function( comment ) {
					const list = article ? article.querySelector( '.odsi-social-comment-list' ) : null;
					const entry = fragment( comment.html );

					if ( list && entry ) {
						list.appendChild( entry );
					}

					bump( article ? article.querySelector( '.odsi-social-item__comment-toggle' ) : null, 1 );
					form.content.value = '';
					announce( form, text( 'commented' ) );
					form.content.focus();
				},
			);
		}

		if ( form.classList.contains( 'odsi-social-create-group' ) ) {
			event.preventDefault();

			perform(
				form,
				function() {
					return request( 'POST', '/groups', { name: form.name.value, visibility: form.visibility.value } );
				},
				function( group ) {
					window.location.href = group.url;
				},
			);
		}

		if ( form.classList.contains( 'odsi-social-message-form' ) ) {
			event.preventDefault();
			const thread = form.getAttribute( 'data-thread-id' );
			const user = form.getAttribute( 'data-user-id' );

			perform(
				form,
				function() {
					return request( 'POST', thread ? '/messages/' + thread : '/messages/to/' + user, { content: form.content.value } );
				},
				function( sent ) {
					const listId = form.getAttribute( 'data-list' );
					const list = listId ? document.getElementById( listId ) : null;
					const template = form.querySelector( 'template' );

					if ( ! list || ! template || ! sent.message ) {
						// A new conversation: it now has a page of its own.
						window.location.href = sent.url || window.location.href;
						return;
					}

					const entry = template.content.firstElementChild.cloneNode( true );
					entry.querySelector( '.odsi-social-message__sender' ).textContent = sent.message.sender || '';
					entry.querySelector( '.odsi-social-message__content' ).innerHTML = sent.message.content || '';
					list.appendChild( entry );
					form.content.value = '';
					form.content.focus();
				},
			);
		}

		if ( form.classList.contains( 'odsi-social-report-form' ) ) {
			event.preventDefault();

			perform(
				form,
				function() {
					return request( 'POST', '/reports', {
						object_type: form.object_type.value,
						object_id: parseInt( form.object_id.value, 10 ),
						reason: form.reason.value,
						details: form.details.value,
					} );
				},
				function() {
					form.details.value = '';
					closeReport( form, true );
				},
			);
		}
	} );

	/* ---------- Report dialog ---------- */

	let reportTrigger = null;

	/**
	 * Open the page's report dialog for the control that asked for it.
	 *
	 * @param {HTMLElement} control The "Report" button, carrying the object's type and id.
	 */
	function openReport( control ) {
		const dialog = document.querySelector( '.odsi-social-report-dialog' );
		const form = dialog ? dialog.querySelector( '.odsi-social-report-form' ) : null;

		if ( ! dialog || ! form ) {
			return;
		}

		reportTrigger = control;
		form.object_type.value = control.getAttribute( 'data-object-type' ) || '';
		form.object_id.value = control.getAttribute( 'data-object-id' ) || '';
		clearError( form );

		if ( typeof dialog.showModal === 'function' ) {
			if ( ! dialog.open ) {
				dialog.showModal();
			}
		} else {
			dialog.setAttribute( 'open', '' );
		}

		form.reason.focus();
	}

	/**
	 * Close the report dialog, returning focus to the control that opened it
	 * and, after a sent report, telling the member what happens next.
	 *
	 * @param {HTMLFormElement} form The dialog's form.
	 * @param {boolean}         sent Whether a report was filed.
	 */
	function closeReport( form, sent ) {
		const dialog = closest( form, '.odsi-social-report-dialog' );

		if ( dialog ) {
			if ( typeof dialog.close === 'function' && dialog.open ) {
				dialog.close();
			} else {
				dialog.removeAttribute( 'open' );
			}
		}

		if ( reportTrigger ) {
			if ( sent ) {
				let status = reportTrigger.nextElementSibling;

				if ( ! status || ! status.classList.contains( 'odsi-social-status' ) ) {
					status = document.createElement( 'span' );
					status.className = 'odsi-social-status';
					status.setAttribute( 'role', 'status' );
					reportTrigger.insertAdjacentElement( 'afterend', status );
				}

				status.textContent = text( 'reported' );
			}

			reportTrigger.focus();
			reportTrigger = null;
		}
	}

	document.addEventListener( 'cancel', function( event ) {
		// Escape on the native dialog.
		const dialog = event.target;

		if ( dialog instanceof Element && dialog.classList.contains( 'odsi-social-report-dialog' ) ) {
			event.preventDefault();
			const form = dialog.querySelector( '.odsi-social-report-form' );

			if ( form ) {
				closeReport( form, false );
			}
		}
	}, true );

	/* ---------- Clicks ---------- */

	document.addEventListener( 'click', function( event ) {
		const target = event.target;

		if ( ! ( target instanceof Element ) ) {
			return;
		}

		const react = closest( target, '.odsi-social-item__react' );
		if ( react ) {
			const id = react.getAttribute( 'data-activity-id' );
			const active = react.classList.contains( 'is-active' );

			// Optimistic: flip now, put it back if the server disagrees.
			react.classList.toggle( 'is-active', ! active );
			react.setAttribute( 'aria-pressed', active ? 'false' : 'true' );
			bump( react, active ? -1 : 1 );

			perform(
				react,
				function() {
					return request( active ? 'DELETE' : 'PUT', '/activity/' + id + '/reaction', active ? undefined : { type: 'like' } );
				},
				null,
				function() {
					react.classList.toggle( 'is-active', active );
					react.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
					bump( react, active ? 1 : -1 );
				},
			);
			return;
		}

		const toggle = closest( target, '.odsi-social-item__comment-toggle' );
		if ( toggle ) {
			const form = document.getElementById( toggle.getAttribute( 'aria-controls' ) || '' );

			if ( form ) {
				form.hidden = ! form.hidden;
				toggle.setAttribute( 'aria-expanded', form.hidden ? 'false' : 'true' );

				if ( ! form.hidden ) {
					form.content.focus();
				}
			}
			return;
		}

		const del = closest( target, '.odsi-social-item__delete, .odsi-social-comment__delete' );
		if ( del ) {
			if ( ! window.confirm( text( 'confirmDelete' ) ) ) { // eslint-disable-line no-alert
				return;
			}

			const isComment = del.classList.contains( 'odsi-social-comment__delete' );
			const article = closest( del, '.odsi-social-item' );

			perform(
				del,
				function() {
					return request( 'DELETE', '/activity/' + del.getAttribute( 'data-activity-id' ) );
				},
				function() {
					announce( del, text( 'deleted' ) );

					if ( isComment ) {
						const comment = closest( del, '.odsi-social-comment' );

						if ( comment ) {
							comment.remove();
						}

						bump( article ? article.querySelector( '.odsi-social-item__comment-toggle' ) : null, -1 );
						return;
					}

					const entry = closest( del, '.odsi-social-feed__item' ) || article;
					const feed = closest( del, '.odsi-social-feed' );

					if ( entry ) {
						entry.remove();
					}

					if ( feed && feed.classList.contains( 'odsi-social-feed--single' ) ) {
						window.location.href = config.homeUrl || '/';
					}
				},
			);
			return;
		}

		const more = closest( target, '.odsi-social-feed__more' );
		if ( more ) {
			const feed = closest( more, '.odsi-social-feed' );

			if ( ! feed ) {
				return;
			}

			const params = new URLSearchParams( {
				scope: feed.getAttribute( 'data-scope' ) || 'site',
				group_id: feed.getAttribute( 'data-group-id' ) || '0',
				user_id: feed.getAttribute( 'data-user-id' ) || '0',
				cursor: feed.getAttribute( 'data-next-cursor' ) || '',
				render: '1',
			} );
			const label = more.textContent;

			more.textContent = text( 'loading' );
			announce( more, text( 'loading' ) );

			perform(
				more,
				function() {
					return request( 'GET', '/activity?' + params.toString() );
				},
				function( page ) {
					// Each item arrives server-rendered through the same template
					// as the first page, so it carries its buttons, counts and
					// comments and every handler above applies to it.
					const list = feed.querySelector( '.odsi-social-feed__items' );
					let first = null;

					( page.items || [] ).forEach( function( item ) {
						const entry = fragment( item.html );

						if ( list && entry ) {
							list.appendChild( entry );
							first = first || entry.querySelector( '.odsi-social-item' );
						}
					} );

					feed.setAttribute( 'data-next-cursor', page.next_cursor || '' );
					more.textContent = label;
					announce( more, text( 'loaded' ) );

					if ( first ) {
						first.focus();
					}

					if ( ! page.next_cursor ) {
						more.remove();
					}
				},
				function() {
					more.textContent = label;
				},
			);
			return;
		}

		const membership = closest( target, '.odsi-social-hero__membership' );
		if ( membership ) {
			const gid = membership.getAttribute( 'data-group-id' );
			const leave = 'leave' === membership.getAttribute( 'data-membership' );

			perform(
				membership,
				function() {
					return request( leave ? 'DELETE' : 'POST', '/groups/' + gid + '/membership' );
				},
				function() {
					// What the viewer may see of the group changes with their membership.
					window.location.reload();
				},
			);
			return;
		}

		const connect = closest( target, '.odsi-social-hero__connect' );
		if ( connect ) {
			const uid = connect.getAttribute( 'data-user-id' );
			const status = connect.getAttribute( 'data-status' ) || '';
			let call;
			let next;

			if ( 'pending_received' === status ) {
				call = function() {
					return request( 'POST', '/connections/' + uid + '/accept' );
				};
				next = 'accepted';
			} else if ( '' === status ) {
				call = function() {
					return request( 'POST', '/connections/' + uid );
				};
				next = 'pending_sent';
			} else {
				call = function() {
					return request( 'DELETE', '/connections/' + uid );
				};
				next = '';
			}

			perform( connect, call, function() {
				const labels = { accepted: 'removeConnection', pending_sent: 'withdraw', pending_received: 'accept', '': 'connect' };

				connect.setAttribute( 'data-status', next );
				connect.setAttribute( 'aria-pressed', 'accepted' === next ? 'true' : 'false' );
				connect.textContent = text( labels[ next ] );
			} );
			return;
		}

		const follow = closest( target, '.odsi-social-hero__follow' );
		if ( follow ) {
			const fid = follow.getAttribute( 'data-user-id' );
			const following = follow.classList.contains( 'is-active' );

			/**
			 * Show a follow state on the button.
			 *
			 * @param {boolean} on Whether the viewer follows.
			 */
			const paint = function( on ) {
				follow.classList.toggle( 'is-active', on );
				follow.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				follow.textContent = text( on ? 'unfollow' : 'follow' );
			};

			paint( ! following );

			perform(
				follow,
				function() {
					return request( following ? 'DELETE' : 'PUT', '/follows/' + fid );
				},
				null,
				function() {
					paint( following );
				},
			);
			return;
		}

		const readAll = closest( target, '.odsi-social-notifications__read-all' );
		if ( readAll ) {
			perform(
				readAll,
				function() {
					return request( 'POST', '/notifications/read' );
				},
				function( data ) {
					document.querySelectorAll( '.odsi-social-notification.is-new' ).forEach( markRead );
					updateUnread( data.unread_count || 0 );
					readAll.remove();
				},
			);
			return;
		}

		const readOne = closest( target, '.odsi-social-notification__read' );
		if ( readOne ) {
			const row = closest( readOne, '.odsi-social-notification' );

			perform(
				readOne,
				function() {
					return request( 'POST', '/notifications/' + readOne.getAttribute( 'data-notification-id' ) + '/read' );
				},
				function( data ) {
					if ( row ) {
						markRead( row );
					}

					updateUnread( data.unread_count || 0 );
				},
			);
			return;
		}

		const block = closest( target, '.odsi-social-hero__block' );
		if ( block ) {
			if ( ! window.confirm( text( 'confirmBlock' ) ) ) { // eslint-disable-line no-alert
				return;
			}

			perform(
				block,
				function() {
					return request( 'PUT', '/members/' + block.getAttribute( 'data-user-id' ) + '/block' );
				},
				function() {
					// The profile no longer exists for the viewer; go home.
					window.location.href = config.homeUrl || '/';
				},
			);
			return;
		}

		const report = closest( target, '.odsi-social-item__report, .odsi-social-comment__report, .odsi-social-hero__report' );
		if ( report ) {
			openReport( report );
			return;
		}

		const cancel = closest( target, '.odsi-social-report-dialog__cancel' );
		if ( cancel ) {
			const form = closest( cancel, '.odsi-social-report-form' );

			if ( form ) {
				closeReport( form, false );
			}
		}
	} );

	/**
	 * Show one notification as read.
	 *
	 * @param {Element} row The notification list item.
	 */
	function markRead( row ) {
		row.classList.remove( 'is-new' );
		row.querySelectorAll( '.odsi-social-notification__state, .odsi-social-notification__read' ).forEach( function( el ) {
			el.remove();
		} );
	}

	/**
	 * Refresh the unread count above the list.
	 *
	 * @param {number} count Unread notifications.
	 */
	function updateUnread( count ) {
		const counter = document.querySelector( '.odsi-social-notifications__count' );

		if ( counter ) {
			counter.textContent = text( 'unread', count );
		}

		if ( 0 === count ) {
			const button = document.querySelector( '.odsi-social-notifications__read-all' );

			if ( button ) {
				button.remove();
			}
		}
	}
}() );
