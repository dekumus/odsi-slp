/**
 * ODSI Social front-end behaviour.
 *
 * Progressive enhancement over server-rendered pages: posting, commenting,
 * reacting, membership and connection actions, and cursor-based load-more.
 */
( function() {
	'use strict';

	const config = window.odsiSocial || {};

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

	function fail( error ) {
		window.alert( error.message || config.i18n.error );
	}

	function closest( el, selector ) {
		return el instanceof Element ? el.closest( selector ) : null;
	}

	document.addEventListener( 'submit', function( event ) {
		const form = event.target;

		if ( ! ( form instanceof HTMLFormElement ) ) {
			return;
		}

		if ( form.classList.contains( 'odsi-social-post-form' ) ) {
			event.preventDefault();
			const privacy = form.querySelector( '[name="privacy"]' );
			request( 'POST', '/activity', {
				content: form.content.value,
				privacy: privacy ? privacy.value : '',
				group_id: parseInt( form.getAttribute( 'data-group-id' ) || '0', 10 ),
			} )
				.then( function() {
					window.location.reload();
				} )
				.catch( fail );
		}

		if ( form.classList.contains( 'odsi-social-comment-form' ) ) {
			event.preventDefault();
			request( 'POST', '/activity/' + form.getAttribute( 'data-activity-id' ) + '/comments', { content: form.content.value } )
				.then( function() {
					window.location.reload();
				} )
				.catch( fail );
		}

		if ( form.classList.contains( 'odsi-social-create-group' ) ) {
			event.preventDefault();
			request( 'POST', '/groups', { name: form.name.value, visibility: form.visibility.value } )
				.then( function( group ) {
					window.location.href = group.url;
				} )
				.catch( fail );
		}

		if ( form.classList.contains( 'odsi-social-message-form' ) ) {
			event.preventDefault();
			const thread = form.getAttribute( 'data-thread-id' );
			const user = form.getAttribute( 'data-user-id' );
			const path = thread ? '/messages/' + thread : '/messages/to/' + user;
			request( 'POST', path, { content: form.content.value } )
				.then( function() {
					window.location.reload();
				} )
				.catch( fail );
		}
	} );

	document.addEventListener( 'click', function( event ) {
		const target = event.target;

		if ( ! ( target instanceof HTMLElement ) ) {
			return;
		}

		const react = closest( target, '.odsi-social-react' );
		if ( react ) {
			const id = react.getAttribute( 'data-activity-id' );
			const active = react.classList.contains( 'is-active' );
			request( active ? 'DELETE' : 'PUT', '/activity/' + id + '/reaction', active ? undefined : { type: 'like' } )
				.then( function() {
					const count = react.querySelector( '.odsi-social-count' );
					const n = parseInt( count.textContent, 10 ) || 0;
					count.textContent = String( active ? Math.max( 0, n - 1 ) : n + 1 );
					react.classList.toggle( 'is-active', ! active );
				} )
				.catch( fail );
			return;
		}

		const toggle = closest( target, '.odsi-social-comment-toggle' );
		if ( toggle ) {
			const form = document.querySelector( '.odsi-social-comment-form[data-activity-id="' + toggle.getAttribute( 'data-activity-id' ) + '"]' );
			if ( form ) {
				form.hidden = ! form.hidden;
				if ( ! form.hidden ) {
					form.content.focus();
				}
			}
			return;
		}

		const del = closest( target, '.odsi-social-delete' );
		if ( del && window.confirm( config.i18n.confirm ) ) {
			request( 'DELETE', '/activity/' + del.getAttribute( 'data-activity-id' ) )
				.then( function() {
					const item = closest( del, '.odsi-social-item' );
					if ( item ) {
						item.remove();
					}
				} )
				.catch( fail );
			return;
		}

		const more = closest( target, '.odsi-social-load-more' );
		if ( more ) {
			const feed = closest( more, '.odsi-social-feed' );
			const params = new URLSearchParams( {
				scope: feed.getAttribute( 'data-scope' ),
				group_id: feed.getAttribute( 'data-group-id' ),
				user_id: feed.getAttribute( 'data-user-id' ),
				cursor: feed.getAttribute( 'data-next-cursor' ),
			} );
			more.disabled = true;
			request( 'GET', '/activity?' + params.toString() )
				.then( function( page ) {
					const container = feed.querySelector( '.odsi-social-feed__items' );
					page.items.forEach( function( item ) {
						const article = document.createElement( 'article' );
						article.className = 'odsi-social-item';
						article.innerHTML = '<header class="odsi-social-item__header"><img class="odsi-social-avatar" width="48" height="48" alt="" /><div><div class="odsi-social-item__action"></div><span class="odsi-social-item__time"></span></div></header><div class="odsi-social-item__content"></div>';
						article.querySelector( 'img' ).src = item.author.avatar;
						article.querySelector( '.odsi-social-item__action' ).innerHTML = item.action;
						article.querySelector( '.odsi-social-item__time' ).textContent = item.date_relative;
						article.querySelector( '.odsi-social-item__content' ).innerHTML = item.content;
						container.appendChild( article );
					} );
					feed.setAttribute( 'data-next-cursor', page.next_cursor );
					more.disabled = false;
					if ( ! page.next_cursor ) {
						more.remove();
					}
				} )
				.catch( function( error ) {
					more.disabled = false;
					fail( error );
				} );
			return;
		}

		const membership = closest( target, '.odsi-social-membership' );
		if ( membership ) {
			const gid = membership.getAttribute( 'data-group-id' );
			const action = membership.getAttribute( 'data-action' );
			request( action === 'leave' ? 'DELETE' : 'POST', '/groups/' + gid + '/membership' )
				.then( function() {
					window.location.reload();
				} )
				.catch( fail );
			return;
		}

		const connect = closest( target, '.odsi-social-connect' );
		if ( connect ) {
			const uid = connect.getAttribute( 'data-user-id' );
			const status = connect.getAttribute( 'data-status' );
			let call;
			if ( status === 'pending_received' ) {
				call = request( 'POST', '/connections/' + uid + '/accept' );
			} else if ( status === '' ) {
				call = request( 'POST', '/connections/' + uid );
			} else {
				call = request( 'DELETE', '/connections/' + uid );
			}
			call.then( function() {
				window.location.reload();
			} ).catch( fail );
			return;
		}

		const follow = closest( target, '.odsi-social-follow' );
		if ( follow ) {
			const fid = follow.getAttribute( 'data-user-id' );
			request( follow.classList.contains( 'is-active' ) ? 'DELETE' : 'PUT', '/follows/' + fid )
				.then( function() {
					window.location.reload();
				} )
				.catch( fail );
			return;
		}

		const readAll = closest( target, '.odsi-social-read-all' );
		if ( readAll ) {
			request( 'POST', '/notifications/read' )
				.then( function() {
					window.location.reload();
				} )
				.catch( fail );
		}
	} );
}() );
