/**
 * Smart Login front-end: tab switching, AJAX form handling, and the resend
 * cooldown countdown, driven by a server-supplied absolute timestamp.
 */
( function () {
	'use strict';

	if ( typeof SmartLogin === 'undefined' ) {
		return;
	}

	var resendTimer = null;

	/**
	 * Resolves to a bot-protection response token for the currently
	 * configured provider, or an empty string when none is configured or a
	 * token can't be obtained (the server re-validates regardless).
	 */
	function getBotToken( form ) {
		if ( 'recaptcha' === SmartLogin.botProvider && window.grecaptcha && SmartLogin.botSiteKey ) {
			return new Promise( function ( resolve ) {
				window.grecaptcha.ready( function () {
					window.grecaptcha.execute( SmartLogin.botSiteKey, { action: 'sml_register' } )
						.then( resolve )
						.catch( function () { resolve( '' ); } );
				} );
			} );
		}

		if ( 'turnstile' === SmartLogin.botProvider ) {
			var input = qs( '[data-sml-bot-token]', form );
			return Promise.resolve( input ? input.value : '' );
		}

		return Promise.resolve( '' );
	}

	// Cloudflare Turnstile's implicit render calls this by name (data-callback).
	// With one login form per page (the supported case) a global lookup is fine.
	window.smlTurnstileCallback = function ( token ) {
		var input = qs( '[data-sml-bot-token]' );
		if ( input ) { input.value = token; }
	};

	function qs( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function qsa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}

	function showMessage( root, text, isError ) {
		var el = qs( '[data-sml-message]', root );
		if ( ! el ) { return; }
		el.textContent = text;
		el.hidden = ! text;
		el.classList.toggle( 'sml-message--error', !! isError );
		el.classList.toggle( 'sml-message--ok', ! isError );
	}

	function showPanel( root, name ) {
		qsa( '[data-sml-panel]', root ).forEach( function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-sml-panel' ) !== name;
		} );
		qsa( '[data-sml-tab]', root ).forEach( function ( tab ) {
			var active = tab.getAttribute( 'data-sml-tab' ) === name;
			tab.classList.toggle( 'active', active );
			tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
	}

	/**
	 * Wires the one-box-per-digit verification code input: typing advances
	 * to the next box, Backspace on an empty box returns to the previous
	 * one, and pasting a full code distributes it across all boxes. The
	 * boxes themselves carry no name — their combined value is kept in a
	 * single hidden `code` field that actually submits with the form.
	 */
	function wireOtp( root ) {
		var group = qs( '[data-sml-otp]', root );
		if ( ! group ) { return; }

		var boxes  = qsa( '.sml-otp-box', group );
		var hidden = qs( '[data-sml-otp-value]', root );

		function sync() {
			hidden.value = boxes.map( function ( b ) { return b.value; } ).join( '' );
		}

		boxes.forEach( function ( box, i ) {
			box.addEventListener( 'input', function () {
				box.value = box.value.replace( /[^0-9]/g, '' ).slice( -1 );
				if ( box.value && boxes[ i + 1 ] ) {
					boxes[ i + 1 ].focus();
				}
				sync();
			} );

			box.addEventListener( 'keydown', function ( e ) {
				if ( 'Backspace' === e.key && ! box.value && boxes[ i - 1 ] ) {
					boxes[ i - 1 ].focus();
				}
			} );

			box.addEventListener( 'paste', function ( e ) {
				e.preventDefault();
				var text = ( e.clipboardData || window.clipboardData ).getData( 'text' ).replace( /[^0-9]/g, '' );
				text.split( '' ).forEach( function ( digit, idx ) {
					if ( boxes[ idx ] ) { boxes[ idx ].value = digit; }
				} );
				sync();
				var next = boxes[ Math.min( text.length, boxes.length - 1 ) ];
				if ( next ) { next.focus(); }
			} );
		} );
	}

	function resetOtp( root ) {
		var boxes  = qsa( '.sml-otp-box', root );
		var hidden = qs( '[data-sml-otp-value]', root );
		boxes.forEach( function ( b ) { b.value = ''; } );
		if ( hidden ) { hidden.value = ''; }
		if ( boxes[ 0 ] ) { boxes[ 0 ].focus(); }
	}

	function post( action, nonce, data ) {
		var body = new URLSearchParams( data );
		body.set( 'action', action );
		body.set( 'nonce', nonce );

		return fetch( SmartLogin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( r ) { return r.json(); } );
	}

	function formData( form ) {
		var data = {};
		qsa( 'input[name], select[name], textarea[name]', form ).forEach( function ( field ) {
			data[ field.name ] = field.value;
		} );
		return data;
	}

	function startResendCooldown( root, availableAtMs ) {
		var btn = qs( '[data-sml-resend]', root );
		if ( ! btn ) { return; }

		clearInterval( resendTimer );
		btn.disabled = true;

		function tick() {
			var remaining = Math.max( 0, Math.ceil( ( availableAtMs - Date.now() ) / 1000 ) );
			if ( remaining <= 0 ) {
				btn.disabled = false;
				btn.textContent = SmartLogin.i18n.resend;
				clearInterval( resendTimer );
				return;
			}
			btn.textContent = SmartLogin.i18n.resendIn.replace( '%d', remaining );
		}

		tick();
		resendTimer = setInterval( tick, 1000 );
	}

	function enterVerifyStep( root, userId, resendAvailableMs ) {
		qs( '[data-sml-user-id]', root ).value = userId;
		showPanel( root, 'verify' );
		resetOtp( root );
		startResendCooldown( root, resendAvailableMs );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		qsa( '[data-sml-root]' ).forEach( function ( root ) {

			qsa( '[data-sml-tab]', root ).forEach( function ( tab ) {
				tab.addEventListener( 'click', function ( e ) {
					// These are real links (?action=register / current URL
					// without it) so a direct visit or reload lands on the
					// right panel — intercept the click to swap panels
					// instantly instead of a full page reload.
					e.preventDefault();
					showMessage( root, '' );
					showPanel( root, tab.getAttribute( 'data-sml-tab' ) );
					if ( window.history && window.history.pushState && tab.href ) {
						window.history.pushState( {}, '', tab.href );
					}
				} );
			} );

			wireOtp( root );

			qsa( '[data-sml-password-toggle]', root ).forEach( function ( toggle ) {
				toggle.addEventListener( 'click', function () {
					var input = toggle.previousElementSibling;
					if ( ! input ) { return; }
					var isHidden = 'password' === input.type;
					input.type = isHidden ? 'text' : 'password';
					toggle.textContent = isHidden ? SmartLogin.i18n.hide : SmartLogin.i18n.show;
					toggle.setAttribute( 'aria-label', isHidden ? SmartLogin.i18n.hide : SmartLogin.i18n.show );
				} );
			} );

			var loginForm = qs( '[data-sml-form="login"]', root );
			if ( loginForm ) {
				loginForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					showMessage( root, '' );
					qs( '[data-sml-resend-prompt]', root ).hidden = true;

					var submitBtn = qs( 'button[type="submit"]', loginForm );
					submitBtn.disabled = true;
					submitBtn.dataset.originalText = submitBtn.textContent;
					submitBtn.textContent = SmartLogin.i18n.loggingIn;

					post( 'sml_login', SmartLogin.loginNonce, formData( loginForm ) )
						.then( function ( res ) {
							if ( res.success ) {
								window.location.href = res.data.redirect || window.location.href;
								return;
							}
							showMessage( root, res.data.message || SmartLogin.i18n.genericError, true );
							if ( res.data.unverified ) {
								var prompt = qs( '[data-sml-resend-prompt]', root );
								prompt.hidden = false;
								qs( '[data-sml-resend-from-login]', prompt ).dataset.userId = res.data.user_id;
							}
						} )
						.catch( function () {
							showMessage( root, SmartLogin.i18n.genericError, true );
						} )
						.finally( function () {
							submitBtn.disabled = false;
							submitBtn.textContent = submitBtn.dataset.originalText;
						} );
				} );
			}

			var resendFromLogin = qs( '[data-sml-resend-from-login]', root );
			if ( resendFromLogin ) {
				resendFromLogin.addEventListener( 'click', function () {
					var userId = resendFromLogin.dataset.userId;
					if ( ! userId ) { return; }
					post( 'sml_resend', SmartLogin.registrationNonce, { user_id: userId } )
						.then( function ( res ) {
							if ( res.success ) {
								enterVerifyStep( root, userId, res.data.resend_available );
							} else {
								showMessage( root, res.data.message || SmartLogin.i18n.genericError, true );
							}
						} );
				} );
			}

			var registerForm = qs( '[data-sml-form="register"]', root );
			if ( registerForm ) {
				registerForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					showMessage( root, '' );

					var submitBtn = qs( 'button[type="submit"]', registerForm );
					submitBtn.disabled = true;
					submitBtn.dataset.originalText = submitBtn.textContent;
					submitBtn.textContent = SmartLogin.i18n.sending;

					getBotToken( registerForm )
						.then( function ( token ) {
							var tokenField = qs( '[data-sml-bot-token]', registerForm );
							if ( tokenField ) { tokenField.value = token || ''; }
							return post( 'sml_register', SmartLogin.registrationNonce, formData( registerForm ) );
						} )
						.then( function ( res ) {
							if ( res.success ) {
								enterVerifyStep( root, res.data.user_id, res.data.resend_available );
							} else {
								showMessage( root, res.data.message || SmartLogin.i18n.genericError, true );
							}
						} )
						.catch( function () {
							showMessage( root, SmartLogin.i18n.genericError, true );
						} )
						.finally( function () {
							submitBtn.disabled = false;
							submitBtn.textContent = submitBtn.dataset.originalText;
						} );
				} );
			}

			var verifyForm = qs( '[data-sml-form="verify"]', root );
			if ( verifyForm ) {
				verifyForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					showMessage( root, '' );

					var submitBtn = qs( 'button[type="submit"]', verifyForm );
					submitBtn.disabled = true;
					submitBtn.dataset.originalText = submitBtn.textContent;
					submitBtn.textContent = SmartLogin.i18n.verifying;

					post( 'sml_verify_code', SmartLogin.registrationNonce, formData( verifyForm ) )
						.then( function ( res ) {
							if ( res.success ) {
								showMessage( root, res.data.message, false );
								window.location.reload();
							} else {
								showMessage( root, res.data.message || SmartLogin.i18n.genericError, true );
							}
						} )
						.catch( function () {
							showMessage( root, SmartLogin.i18n.genericError, true );
						} )
						.finally( function () {
							submitBtn.disabled = false;
							submitBtn.textContent = submitBtn.dataset.originalText;
						} );
				} );
			}

			var resendBtn = qs( '[data-sml-resend]', root );
			if ( resendBtn ) {
				resendBtn.addEventListener( 'click', function () {
					var userId = qs( '[data-sml-user-id]', root ).value;
					if ( ! userId ) { return; }
					post( 'sml_resend', SmartLogin.registrationNonce, { user_id: userId } )
						.then( function ( res ) {
							if ( res.success ) {
								startResendCooldown( root, res.data.resend_available );
								showMessage( root, '', false );
							} else {
								showMessage( root, res.data.message || SmartLogin.i18n.genericError, true );
							}
						} );
				} );
			}
		} );
	} );
} )();
