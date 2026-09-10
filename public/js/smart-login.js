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

	// Turnstile widget id per panel name ('login' | 'register' | 'forgot' |
	// 'reset'). Each form gets its own widget, rendered only once it's on
	// screen; recaptcha v3 is invisible and needs none of this.
	var turnstileWidgets = {};
	// Whether a valid, unspent Turnstile token is currently held per panel.
	var turnstileReady = {};

	/**
	 * True when this panel's form must not be submittable yet because its
	 * Turnstile challenge hasn't produced a token (or the token expired).
	 */
	function botGatePending( name ) {
		return 'turnstile' === SmartLogin.botProvider
			&& !! SmartLogin.botSiteKey
			&& 'undefined' !== typeof turnstileWidgets[ name ]
			&& ! turnstileReady[ name ];
	}

	function setSubmitDisabled( form, disabled ) {
		var btn = form && qs( 'button[type="submit"]', form );
		if ( btn ) { btn.disabled = !! disabled; }
	}

	/**
	 * Renders a Turnstile widget into the given panel's form, once. Safe to
	 * call repeatedly and before the Turnstile API has loaded. While the
	 * widget has no token the form's submit button stays disabled, so an
	 * account/login/reset request can't be made until Cloudflare passes.
	 */
	function renderTurnstileFor( name, root ) {
		if ( 'turnstile' !== SmartLogin.botProvider || ! window.turnstile || ! SmartLogin.botSiteKey ) {
			return;
		}
		var form = qs( '[data-sml-form="' + name + '"]', root );
		if ( ! form ) { return; }
		var holder = qs( '[data-sml-turnstile]', form );
		if ( ! holder || holder.getAttribute( 'data-rendered' ) ) { return; }

		var tokenInput = qs( '[data-sml-bot-token]', form );
		var onToken = function ( value ) {
			if ( tokenInput ) { tokenInput.value = value || ''; }
			turnstileReady[ name ] = !! value;
			setSubmitDisabled( form, ! value );
		};

		try {
			var id = window.turnstile.render( holder, {
				sitekey: SmartLogin.botSiteKey,
				callback: onToken,
				'error-callback': function () { onToken( '' ); },
				'expired-callback': function () { onToken( '' ); },
				'timeout-callback': function () { onToken( '' ); }
			} );
			holder.setAttribute( 'data-rendered', '1' );
			turnstileWidgets[ name ] = id;
			turnstileReady[ name ] = false;
			setSubmitDisabled( form, true );
		} catch ( e ) {}
	}

	// Turnstile's api.js?onload= calls this once the API object exists.
	window.smlTurnstileOnload = function () {
		qsa( '[data-sml-root]' ).forEach( function ( root ) {
			var visible = qs( '[data-sml-panel]:not([hidden])', root );
			if ( visible ) {
				renderTurnstileFor( visible.getAttribute( 'data-sml-panel' ), root );
			}
		} );
	};

	/**
	 * Discards the current Turnstile token for a panel and asks the widget
	 * for a fresh one — call after a failed submit, since a Turnstile token
	 * is single-use and the widget won't reissue on its own.
	 */
	function resetTurnstile( name ) {
		if ( 'turnstile' !== SmartLogin.botProvider || ! window.turnstile ) { return; }
		var id = turnstileWidgets[ name ];
		if ( 'undefined' === typeof id ) { return; }
		turnstileReady[ name ] = false;
		try { window.turnstile.reset( id ); } catch ( e ) {}
	}

	/**
	 * Resolves to a bot-protection response token for the currently
	 * configured provider, or an empty string when none is configured or a
	 * token can't be obtained (the server re-validates regardless).
	 *
	 * @param {HTMLElement} form  The form being submitted.
	 * @param {string}      name  Panel name — 'login' | 'register' | 'forgot' | 'reset'.
	 */
	function getBotToken( form, name ) {
		if ( 'recaptcha' === SmartLogin.botProvider && window.grecaptcha && SmartLogin.botSiteKey ) {
			return new Promise( function ( resolve ) {
				window.grecaptcha.ready( function () {
					window.grecaptcha.execute( SmartLogin.botSiteKey, { action: 'sml_' + name } )
						.then( resolve )
						.catch( function () { resolve( '' ); } );
				} );
			} );
		}

		if ( 'turnstile' === SmartLogin.botProvider && window.turnstile ) {
			var id    = turnstileWidgets[ name ];
			var token = 'undefined' !== typeof id ? ( window.turnstile.getResponse( id ) || '' ) : '';
			if ( ! token ) {
				// Fallback to whatever the callback last wrote, in case the
				// widget id lookup is unavailable for any reason.
				var input = qs( '[data-sml-bot-token]', form );
				token = input ? input.value : '';
			}
			return Promise.resolve( token );
		}

		return Promise.resolve( '' );
	}

	function qs( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function qsa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}

	// Generic "group digits in 3s" display formatting (e.g. "555 123 4567") —
	// not a claim of any country's official number format, just a readable
	// grouping while the visitor types. Digits beyond the selected country's
	// max are dropped rather than accepted and rejected later server-side.
	function formatPhoneDigits( value, maxDigits ) {
		var digits = ( value || '' ).replace( /\D/g, '' ).slice( 0, maxDigits );
		var groups = [];
		var remaining = digits;
		while ( remaining.length > 4 ) {
			groups.push( remaining.slice( 0, 3 ) );
			remaining = remaining.slice( 3 );
		}
		groups.push( remaining );
		return groups.join( ' ' ).trim();
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
		// Render this panel's Turnstile widget the first time it's shown.
		renderTurnstileFor( name, root );
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

			// The <select> can't render an SVG inside its own closed state,
			// so a real flag icon sits next to it and is swapped by hand
			// whenever the selection changes.
			qsa( '[data-sml-flag-select]', root ).forEach( function ( select ) {
				var phoneInput = qs( '[data-sml-phone-input]', root );

				var applyMaxForSelected = function () {
					if ( ! phoneInput ) { return; }
					var opt = select.options[ select.selectedIndex ];
					var max = opt ? parseInt( opt.getAttribute( 'data-max' ), 10 ) || 14 : 14;
					// +4 allows for the display spaces inserted by formatPhoneDigits().
					phoneInput.setAttribute( 'maxlength', max + 4 );
					phoneInput.value = formatPhoneDigits( phoneInput.value, max );
				};

				select.addEventListener( 'change', function () {
					// Swapped as markup (not a sprite <use> href) so the
					// neutral fallback badge for countries without a hand
					// drawn flag also displays correctly.
					var wrap = select.parentElement && select.parentElement.querySelector( '[data-sml-flag-wrap]' );
					var opt = select.options[ select.selectedIndex ];
					if ( wrap && opt && opt.getAttribute( 'data-flag' ) ) {
						try {
							wrap.innerHTML = atob( opt.getAttribute( 'data-flag' ) );
						} catch ( err ) { /* leave existing flag in place */ }
					}
					applyMaxForSelected();
				} );

				if ( phoneInput ) {
					applyMaxForSelected();
					phoneInput.addEventListener( 'input', function () {
						var opt = select.options[ select.selectedIndex ];
						var max = opt ? parseInt( opt.getAttribute( 'data-max' ), 10 ) || 14 : 14;
						var caretAtEnd = phoneInput.selectionStart === phoneInput.value.length;
						phoneInput.value = formatPhoneDigits( phoneInput.value, max );
						if ( caretAtEnd ) {
							phoneInput.setSelectionRange( phoneInput.value.length, phoneInput.value.length );
						}
					} );
				}
			} );

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
					if ( botGatePending( 'login' ) ) { return; }
					showMessage( root, '' );

					var submitBtn = qs( 'button[type="submit"]', loginForm );
					submitBtn.disabled = true;
					submitBtn.dataset.originalText = submitBtn.textContent;
					submitBtn.textContent = SmartLogin.i18n.loggingIn;

					getBotToken( loginForm, 'login' )
						.then( function ( token ) {
							var tokenField = qs( '[data-sml-bot-token]', loginForm );
							if ( tokenField ) { tokenField.value = token || ''; }
							return post( 'sml_login', SmartLogin.loginNonce, formData( loginForm ) );
						} )
						.then( function ( res ) {
							if ( res.success ) {
								var redirect = function () {
									window.location.href = res.data.redirect || window.location.href;
								};
								// A pre-existing (pre-plugin) account that still
								// isn't verified gets a quiet reminder email
								// instead of being blocked — give them a moment
								// to actually see that before redirecting away.
								if ( res.data.notice ) {
									showMessage( root, res.data.notice, false );
									setTimeout( redirect, 2200 );
								} else {
									redirect();
								}
								return;
							}
							resetTurnstile( 'login' );
							if ( res.data.unverified ) {
								// Rather than leaving them on the login form
								// with an error, drop straight into the
								// verify-code screen — the server already
								// issued (or is honoring the cooldown on) a
								// fresh code, so there's something valid
								// waiting there.
								enterVerifyStep( root, res.data.user_id, res.data.resend_available );
								showMessage( root, res.data.message || SmartLogin.i18n.genericError, false );
								return;
							}
							showMessage( root, res.data.message || SmartLogin.i18n.genericError, true );
						} )
						.catch( function () {
							resetTurnstile( 'login' );
							showMessage( root, SmartLogin.i18n.genericError, true );
						} )
						.finally( function () {
							submitBtn.disabled = botGatePending( 'login' );
							submitBtn.textContent = submitBtn.dataset.originalText;
						} );
				} );
			}

			var registerForm = qs( '[data-sml-form="register"]', root );
			if ( registerForm ) {
				registerForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					if ( botGatePending( 'register' ) ) { return; }
					showMessage( root, '' );

					var submitBtn = qs( 'button[type="submit"]', registerForm );
					submitBtn.disabled = true;
					submitBtn.dataset.originalText = submitBtn.textContent;
					submitBtn.textContent = SmartLogin.i18n.sending;

					getBotToken( registerForm, 'register' )
						.then( function ( token ) {
							var tokenField = qs( '[data-sml-bot-token]', registerForm );
							if ( tokenField ) { tokenField.value = token || ''; }
							return post( 'sml_register', SmartLogin.registrationNonce, formData( registerForm ) );
						} )
						.then( function ( res ) {
							if ( res.success ) {
								enterVerifyStep( root, res.data.user_id, res.data.resend_available );
							} else {
								resetTurnstile( 'register' );
								showMessage( root, res.data.message || SmartLogin.i18n.genericError, true );
							}
						} )
						.catch( function () {
							resetTurnstile( 'register' );
							showMessage( root, SmartLogin.i18n.genericError, true );
						} )
						.finally( function () {
							submitBtn.disabled = botGatePending( 'register' );
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
								// Goes back to Cart/Checkout (or wherever sent
								// the visitor here) when that's set, otherwise
								// just reloads this page as a logged-in user.
								if ( res.data.redirect ) {
									window.location.href = res.data.redirect;
								} else {
									window.location.reload();
								}
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

			var forgotForm = qs( '[data-sml-form="forgot"]', root );
			if ( forgotForm ) {
				forgotForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					if ( botGatePending( 'forgot' ) ) { return; }
					showMessage( root, '' );

					var submitBtn = qs( 'button[type="submit"]', forgotForm );
					submitBtn.disabled = true;
					submitBtn.dataset.originalText = submitBtn.textContent;
					submitBtn.textContent = SmartLogin.i18n.sending;

					getBotToken( forgotForm, 'forgot' )
						.then( function ( token ) {
							var tokenField = qs( '[data-sml-bot-token]', forgotForm );
							if ( tokenField ) { tokenField.value = token || ''; }
							return post( 'sml_forgot_password', SmartLogin.passwordResetNonce, formData( forgotForm ) );
						} )
						.then( function ( res ) {
							showMessage( root, ( res.data && res.data.message ) || SmartLogin.i18n.genericError, ! res.success );
							if ( res.success ) { forgotForm.reset(); } else { resetTurnstile( 'forgot' ); }
						} )
						.catch( function () {
							resetTurnstile( 'forgot' );
							showMessage( root, SmartLogin.i18n.genericError, true );
						} )
						.finally( function () {
							submitBtn.disabled = botGatePending( 'forgot' );
							submitBtn.textContent = submitBtn.dataset.originalText;
						} );
				} );
			}

			var resetForm = qs( '[data-sml-form="reset"]', root );
			if ( resetForm ) {
				resetForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					if ( botGatePending( 'reset' ) ) { return; }
					showMessage( root, '' );

					var submitBtn = qs( 'button[type="submit"]', resetForm );
					submitBtn.disabled = true;
					submitBtn.dataset.originalText = submitBtn.textContent;
					submitBtn.textContent = SmartLogin.i18n.resetting;

					getBotToken( resetForm, 'reset' )
						.then( function ( token ) {
							var tokenField = qs( '[data-sml-bot-token]', resetForm );
							if ( tokenField ) { tokenField.value = token || ''; }
							return post( 'sml_reset_password', SmartLogin.passwordResetNonce, formData( resetForm ) );
						} )
						.then( function ( res ) {
							if ( res.success ) {
								showMessage( root, res.data.message, false );
								setTimeout( function () {
									window.location.href = res.data.redirect || window.location.href;
								}, 1500 );
							} else {
								resetTurnstile( 'reset' );
								showMessage( root, res.data.message || SmartLogin.i18n.genericError, true );
							}
						} )
						.catch( function () {
							resetTurnstile( 'reset' );
							showMessage( root, SmartLogin.i18n.genericError, true );
						} )
						.finally( function () {
							submitBtn.disabled = botGatePending( 'reset' );
							submitBtn.textContent = submitBtn.dataset.originalText;
						} );
				} );
			}

			var resendBtn = qs( '[data-sml-resend]', root );
			if ( resendBtn ) {
				resendBtn.addEventListener( 'click', function () {
					var userId = qs( '[data-sml-user-id]', root ).value;
					if ( ! userId ) { return; }
					var redirectField = qs( '[name="redirect_to"]', root );
					post( 'sml_resend', SmartLogin.registrationNonce, { user_id: userId, redirect_to: redirectField ? redirectField.value : '' } )
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
