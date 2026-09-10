/* global LinkSentinel, wp */
( function () {
	'use strict';
	var apiFetch = wp.apiFetch;
	var i18n = LinkSentinel.i18n;
	var panel = document.getElementById( 'lsn-panel' );
	if ( ! panel ) {
		return;
	}
	var bar = document.getElementById( 'lsn-progress-bar' );
	var msg = document.getElementById( 'lsn-message' );
	var btnStart = document.getElementById( 'lsn-start' );
	var btnFull = document.getElementById( 'lsn-start-full' );
	var btnStop = document.getElementById( 'lsn-stop' );
	var polling = false;
	var stopped = false;

	function setRunning( running ) {
		panel.setAttribute( 'data-running', running ? '1' : '0' );
		btnStart.disabled = running;
		btnFull.disabled = running;
		btnStop.disabled = ! running;
	}

	function render( data ) {
		bar.style.width = ( data.percent || 0 ) + '%';
		msg.textContent = data.message || '';
		setRunning( data.running );
		if ( ! data.running && data.state && data.state.phase === 'done' && ! stopped ) {
			msg.textContent = i18n.done;
			window.setTimeout( function () { window.location.reload(); }, 800 );
		}
	}

	function post( path, body ) {
		return apiFetch( { path: '/link-sentinel/v1' + path, method: 'POST', data: body || {} } );
	}

	function loop() {
		if ( polling || stopped ) {
			return;
		}
		polling = true;
		post( '/scan/step' ).then( function ( data ) {
			polling = false;
			render( data );
			if ( data.running ) {
				window.setTimeout( loop, 250 );
			}
		} ).catch( function ( err ) {
			polling = false;
			msg.textContent = i18n.failed + ' ' + ( err && err.message ? err.message : '' );
			window.setTimeout( loop, 5000 );
		} );
	}

	function start( full ) {
		stopped = false;
		setRunning( true );
		msg.textContent = '…';
		post( '/scan/start', { force_all: !! full } ).then( function ( data ) {
			render( data );
			if ( data.running ) {
				loop();
			}
		} ).catch( function ( err ) {
			setRunning( false );
			msg.textContent = i18n.failed + ' ' + ( err && err.message ? err.message : '' );
		} );
	}

	btnStart.addEventListener( 'click', function () { start( false ); } );
	btnFull.addEventListener( 'click', function () { start( true ); } );
	btnStop.addEventListener( 'click', function () {
		stopped = true;
		msg.textContent = i18n.stopping;
		post( '/scan/stop' ).then( function ( data ) { render( data ); } );
	} );

	setRunning( panel.getAttribute( 'data-running' ) === '1' );
	if ( panel.getAttribute( 'data-running' ) === '1' ) {
		loop();
	}

	// Row actions.
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '.lsn-action' );
		if ( ! b ) {
			return;
		}
		var id = b.getAttribute( 'data-id' );
		var action = b.getAttribute( 'data-action' );
		var body = {};
		var path = '/links/' + id + '/' + action;
		if ( action === 'url' ) {
			askUrl( b.getAttribute( 'data-prefill' ) || b.getAttribute( 'data-url' ) || '', function ( next ) {
				if ( ! next || next === b.getAttribute( 'data-url' ) ) {
					return;
				}
				run( b, path, { url: next } );
			} );
			return;
		} else if ( action === 'unlink' ) {
			if ( ! window.confirm( i18n.unlink ) ) {
				return;
			}
		} else if ( action === 'restore' ) {
			path = '/links/' + id + '/dismiss';
			body = { dismissed: false };
		} else if ( action === 'dismiss' ) {
			body = { dismissed: true };
		}
		run( b, path, body );
	} );

	function run( b, path, body ) {
		var row = b.closest( 'tr' );
		if ( row ) {
			row.style.opacity = '0.5';
		}
		post( path, body ).then( function () {
			window.location.reload();
		} ).catch( function ( err ) {
			if ( row ) {
				row.style.opacity = '';
			}
			window.alert( i18n.failed + ' ' + ( err && err.message ? err.message : '' ) );
		} );
	}

	// A small dialog instead of window.prompt: the URL can be long, and the
	// user should see it whole before saving.
	function askUrl( current, done ) {
		var dlg = document.getElementById( 'lsn-url-dialog' );
		if ( ! dlg ) {
			dlg = document.createElement( 'dialog' );
			dlg.id = 'lsn-url-dialog';
			dlg.className = 'lsn-dialog';
			dlg.innerHTML = '<form method="dialog"><label for="lsn-url-input"></label><input type="url" id="lsn-url-input" class="large-text code" required><p class="lsn-dialog-actions"><button type="button" class="button" value="cancel"></button> <button type="submit" class="button button-primary" value="ok"></button></p></form>';
			document.body.appendChild( dlg );
			dlg.querySelector( 'label' ).textContent = i18n.newUrl;
			dlg.querySelector( 'button[value="cancel"]' ).textContent = i18n.cancel;
			dlg.querySelector( 'button[value="ok"]' ).textContent = i18n.save;
			dlg.querySelector( 'button[value="cancel"]' ).addEventListener( 'click', function () { dlg.close( 'cancel' ); } );
		}
		var input = dlg.querySelector( 'input' );
		input.value = current;
		dlg.onclose = function () {
			if ( dlg.returnValue === 'ok' ) {
				done( input.value.trim() );
			}
		};
		if ( typeof dlg.showModal === 'function' ) {
			dlg.showModal();
			input.focus();
			input.select();
		} else {
			var next = window.prompt( i18n.newUrl, current );
			done( next ? next.trim() : '' );
		}
	}
}() );
