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
			var current = b.getAttribute( 'data-prefill' ) || b.getAttribute( 'data-url' ) || '';
			var next = window.prompt( i18n.newUrl, current );
			if ( ! next || next === b.getAttribute( 'data-url' ) ) {
				return;
			}
			body = { url: next };
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
	} );
}() );
