/**
 * @file
 * Cybersource REST / Flex Microform v2 checkout behaviour.
 *
 * 1. Loads the Microform client library named in the capture context and mounts
 *    the hosted card-number and CVV fields (the PAN/CVV stay inside Cybersource's
 *    iframes and never touch this site).
 * 2. Card UX modelled on the Secure Acceptance form: live brand icon, accepted
 *    brand enforcement, and tick/cross status on the number, expiry and CVV.
 * 3. On submit, validates the expiry, tokenises the card into a single-use
 *    transient token, writes it to the hidden field and lets the form submit.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  // Microform card names we have an icon for (others fall back to generic).
  var KNOWN_BRANDS = {
    visa: 1, mastercard: 1, maestro: 1, amex: 1, discover: 1, dinersclub: 1, jcb: 1
  };

  function setStatus(el, state) {
    if (!el) { return; }
    el.classList.remove('valid', 'invalid', 'unsupported');
    if (state) { el.classList.add(state); }
  }

  function attach(form) {
    var settings = (drupalSettings && drupalSettings.cybersourceRest) || {};
    if (!settings.captureContext || !settings.clientLibrary) {
      return;
    }
    var supported = settings.supportedCardTypes || [];

    var numberMount = form.querySelector('#cybersource-card-number');
    var cvvMount = form.querySelector('#cybersource-card-cvv');
    var expiry = form.querySelector('.cybersource-rest-expiry');
    var tokenField = form.querySelector('.cybersource-rest-token');
    if (!numberMount || !cvvMount || !expiry || !tokenField) {
      return;
    }
    var brandEl = form.querySelector('.cc-field--number .cc-brand');
    var numStatus = form.querySelector('.cc-field--number .cc-status');
    var expStatus = form.querySelector('.cc-field--expiry .cc-status');
    var cvcStatus = form.querySelector('.cc-field--cvc .cc-status');
    var errorEl = form.querySelector('#cybersource-rest-error');

    var currentBrand = null;
    var numberValid = false;
    var cvvValid = false;
    var cvvField = null;

    function showError(message) {
      if (errorEl) { errorEl.textContent = message || ''; }
    }

    function brandLabel(name) {
      // "dinersclub" -> "Diners Club"; otherwise title-case the single word.
      if (name === 'dinersclub') { return 'Diners Club'; }
      return name ? name.charAt(0).toUpperCase() + name.slice(1) : '';
    }

    function isSupported(name) {
      if (!name) { return true; }
      if (!supported.length) { return true; }
      return supported.indexOf(name) !== -1;
    }

    // Parse "mm / yy", "mmyy" or "mm/yyyy" into {month, year} (year 4-digit).
    function parseExpiry() {
      var digits = expiry.value.replace(/\D/g, '');
      if (digits.length < 4) { return null; }
      var mm = digits.substr(0, 2);
      var rest = digits.substr(2);
      var year = rest.length >= 4 ? rest.substr(0, 4) : '20' + rest.substr(0, 2);
      var m = parseInt(mm, 10);
      var y = parseInt(year, 10);
      if (m < 1 || m > 12) { return null; }
      var now = new Date();
      var curY = now.getFullYear();
      var curM = now.getMonth() + 1;
      if (y < curY || (y === curY && m < curM) || y > curY + 20) { return null; }
      return { month: mm, year: String(y) };
    }

    function syncExpiry() {
      var ok = parseExpiry() !== null;
      setStatus(expStatus, expiry.value ? (ok ? 'valid' : 'invalid') : null);
      return ok;
    }
    expiry.addEventListener('input', syncExpiry);

    function onNumberChange(data) {
      var card = (data && data.card && data.card[0]) || null;
      currentBrand = card ? card.name : null;
      var allowed = isSupported(currentBrand);
      brandEl.className = 'cc-brand' + (currentBrand && KNOWN_BRANDS[currentBrand] ? ' ' + currentBrand : '');
      // Amex uses a 4-digit security code; everything else (and an undetected
      // card) uses 3. Microform already enforces the correct length in its own
      // validation; this just keeps the placeholder in step.
      if (cvvField && cvvField.update) {
        cvvField.update({ placeholder: currentBrand === 'amex' ? '••••' : '•••' });
      }
      numberValid = !!(data && data.valid) && allowed;
      if (currentBrand && !allowed) {
        setStatus(numStatus, 'unsupported');
        showError(Drupal.t('Sorry, we do not accept @brand. Please use another card.', { '@brand': brandLabel(currentBrand) }));
      }
      else {
        setStatus(numStatus, (data && data.empty) ? null : (numberValid ? 'valid' : 'invalid'));
        if (allowed) { showError(''); }
      }
    }

    function onCvvChange(data) {
      cvvValid = !!(data && data.valid);
      setStatus(cvcStatus, (data && data.empty) ? null : (cvvValid ? 'valid' : 'invalid'));
    }

    function initMicroform() {
      /* global Flex */
      // The Microform inputs live in Cybersource iframes, so "inherit" cannot
      // reach the host page's font. Pass the page's resolved font-family (and
      // text colour) explicitly so the hosted PAN/CVV match our own fields and
      // the surrounding theme.
      var pageStyle = window.getComputedStyle(form);
      var flex = new Flex(settings.captureContext);
      var microform = flex.microform('card', {
        styles: {
          input: {
            'font-size': '16px',
            'font-family': pageStyle.fontFamily || 'sans-serif',
            color: pageStyle.color || '#1a1a1a'
          },
          '::placeholder': { color: '#9aa0a6' },
          ':focus': { color: pageStyle.color || '#1a1a1a' },
          valid: { color: '#1a7f37' },
          invalid: { color: '#b3261e' }
        }
      });

      var numberField = microform.createField('number', { placeholder: '1234 5678 9012 3456' });
      numberField.load(numberMount);
      numberField.on('change', onNumberChange);
      numberField.on('focus', function () { showError(''); });

      cvvField = microform.createField('securityCode', { placeholder: '•••' });
      cvvField.load(cvvMount);
      cvvField.on('change', onCvvChange);

      bindSubmit(microform);
    }

    function bindSubmit(microform) {
      var lastSubmitter = null;
      form.addEventListener('click', function (e) {
        var t = e.target;
        if (t && t.matches && t.matches('[type="submit"], button[type="submit"], input[type="submit"]')) {
          lastSubmitter = t;
        }
      }, true);

      form.addEventListener('submit', function (e) {
        if (form.dataset.cybersourceTokenized === '1') {
          // Second pass: our token is set, let the form submit normally.
          return;
        }
        e.preventDefault();
        e.stopPropagation();

        var expiryValues = parseExpiry();
        var unsupported = currentBrand && !isSupported(currentBrand);
        if (!expiryValues) { setStatus(expStatus, 'invalid'); }
        if (unsupported || !expiryValues || !numberValid || !cvvValid) {
          if (unsupported) {
            showError(Drupal.t('Sorry, we do not accept @brand. Please use another card.', { '@brand': brandLabel(currentBrand) }));
          }
          else {
            showError(Drupal.t('Please check your card details and try again.'));
          }
          return;
        }

        var submitter = e.submitter || lastSubmitter;
        if (submitter) { submitter.setAttribute('disabled', 'disabled'); }

        microform.createToken({
          expirationMonth: expiryValues.month,
          expirationYear: expiryValues.year
        }, function (err, token) {
          if (err || !token) {
            if (submitter) { submitter.removeAttribute('disabled'); }
            showError((err && err.message) || Drupal.t('Your card could not be processed. Please check your details and try again.'));
            return;
          }
          var finish = function () {
            tokenField.value = token;
            form.dataset.cybersourceTokenized = '1';
            if (submitter) {
              submitter.removeAttribute('disabled');
              submitter.click();
            }
            else if (typeof form.requestSubmit === 'function') {
              form.requestSubmit();
            }
            else {
              form.submit();
            }
          };
          var fail = function (message) {
            if (submitter) { submitter.removeAttribute('disabled'); }
            showError(message || Drupal.t('Your card could not be processed. Please check your details and try again.'));
          };
          if (!settings.payerAuth) {
            finish();
            return;
          }
          runPayerAuth(token, finish, fail);
        });
      });
    }

    /**
     * 3-D Secure: setup -> device data collection -> enrollment -> challenge.
     *
     * The server keeps the authentication RESULT (CAVV etc.) to itself; the
     * browser only ferries the Cardinal UI steps. If anything here is skipped
     * or fails, the server refuses the payment (fail closed).
     */
    function runPayerAuth(token, done, fail) {
      var unavailable = Drupal.t('Card authentication is temporarily unavailable. Please try again.');
      getCsrfToken().then(function (csrf) {
        return postJson(settings.paSetupUrl, { token: token }, csrf).then(function (setup) {
          if (!setup || !setup.accessToken || !setup.deviceDataCollectionUrl) {
            throw new Error('setup');
          }
          return runDeviceDataCollection(setup).then(function () {
            return postJson(settings.paEnrollUrl, {
              token: token,
              referenceId: setup.referenceId,
              browser: collectBrowserData(),
              billing: collectBillingData()
            }, csrf);
          });
        });
      }).then(function (result) {
        if (!result || !result.status) { throw new Error('enroll'); }
        if (result.status === 'authenticated' || result.status === 'unavailable') {
          done();
        }
        else if (result.status === 'challenge') {
          runChallenge(result).then(done, function () {
            fail(Drupal.t('Card authentication was not completed. Please try again.'));
          });
        }
        else {
          fail(Drupal.t('Your bank could not authenticate this card. Please use another card or contact your bank.'));
        }
      }).catch(function () {
        fail(unavailable);
      });
    }

    function getCsrfToken() {
      return fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
        .then(function (r) { return r.text(); });
    }

    function postJson(url, body, csrf) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(body)
      }).then(function (r) {
        if (!r.ok) { throw new Error('http ' + r.status); }
        return r.json();
      });
    }

    /**
     * Cardinal device data collection: hidden iframe POST, completion by
     * postMessage from the Cardinal origin. Proceeds after 10s regardless
     * (per the Cybersource guidance) so a blocked profiler cannot wedge
     * checkout.
     */
    function runDeviceDataCollection(setup) {
      return new Promise(function (resolve) {
        var cleanupDone = false;
        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'display:none;width:10px;height:10px;';
        iframe.name = 'cybersource-rest-ddc';
        var ddcForm = document.createElement('form');
        ddcForm.method = 'POST';
        ddcForm.target = iframe.name;
        ddcForm.action = setup.deviceDataCollectionUrl;
        ddcForm.style.display = 'none';
        var jwtInput = document.createElement('input');
        jwtInput.type = 'hidden';
        jwtInput.name = 'JWT';
        jwtInput.value = setup.accessToken;
        ddcForm.appendChild(jwtInput);
        document.body.appendChild(iframe);
        document.body.appendChild(ddcForm);

        function cleanup() {
          if (cleanupDone) { return; }
          cleanupDone = true;
          window.removeEventListener('message', onMessage);
          iframe.remove();
          ddcForm.remove();
          resolve();
        }
        function onMessage(event) {
          if (event.origin !== settings.paDdcOrigin) { return; }
          cleanup();
        }
        window.addEventListener('message', onMessage);
        setTimeout(cleanup, 10000);
        ddcForm.submit();
      });
    }

    /**
     * The issuer challenge, in a modal iframe. Completion is signalled by our
     * own challenge-return page (same origin) posting a message to the parent.
     */
    function runChallenge(challenge) {
      return new Promise(function (resolve, reject) {
        var settled = false;
        var overlay = document.createElement('div');
        overlay.className = 'cybersource-pa-overlay';
        var frameWrap = document.createElement('div');
        frameWrap.className = 'cybersource-pa-window';
        frameWrap.style.width = (challenge.width || 500) + 'px';
        frameWrap.style.height = (challenge.height || 600) + 'px';
        var iframe = document.createElement('iframe');
        iframe.name = 'cybersource-rest-challenge';
        iframe.className = 'cybersource-pa-frame';
        frameWrap.appendChild(iframe);
        overlay.appendChild(frameWrap);
        // Two challenge shapes: Cardinal step-up (stepUpUrl + JWT) or the raw
        // EMV 3DS CReq flow (acsUrl + pareq). Fail closed on neither.
        var action;
        var fields;
        if (challenge.stepUpUrl && challenge.accessToken) {
          action = challenge.stepUpUrl;
          fields = { JWT: challenge.accessToken };
        }
        else if (challenge.acsUrl && challenge.pareq) {
          action = challenge.acsUrl;
          fields = { creq: challenge.pareq };
        }
        else {
          reject(new Error('challenge-params'));
          return;
        }
        var challengeForm = document.createElement('form');
        challengeForm.method = 'POST';
        challengeForm.target = iframe.name;
        challengeForm.action = action;
        challengeForm.style.display = 'none';
        Object.keys(fields).forEach(function (name) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = name;
          input.value = fields[name];
          challengeForm.appendChild(input);
        });
        document.body.appendChild(overlay);
        document.body.appendChild(challengeForm);

        function settle(ok) {
          if (settled) { return; }
          settled = true;
          window.removeEventListener('message', onMessage);
          overlay.remove();
          challengeForm.remove();
          if (ok) { resolve(); } else { reject(new Error('challenge')); }
        }
        function onMessage(event) {
          // Completion comes from OUR challenge-return page (same origin).
          if (event.origin !== window.location.origin) { return; }
          if (event.data && event.data.cybersourceRestPaComplete) { settle(true); }
        }
        window.addEventListener('message', onMessage);
        // Fallback completion detection: some account configurations (the raw
        // acsUrl/pareq shape) never navigate the iframe back to our return
        // page. The iframe's SECOND load means the ACS moved past the
        // challenge screen (the CRes round), so proceed then — safely: the
        // authorization validates the real challenge outcome server-to-server
        // and refuses the payment if authentication did not succeed.
        var loads = 0;
        iframe.addEventListener('load', function () {
          loads++;
          if (loads >= 2) {
            setTimeout(function () { settle(true); }, 1500);
          }
        });
        // The customer gets 10 minutes before we give up.
        setTimeout(function () { settle(false); }, 600000);
        challengeForm.submit();
      });
    }

    /** Browser fingerprint fields for the 3DS enrollment check. */
    function collectBrowserData() {
      var javaEnabled = false;
      try { javaEnabled = !!(navigator.javaEnabled && navigator.javaEnabled()); }
      catch (e) { javaEnabled = false; }
      return {
        colorDepth: window.screen ? window.screen.colorDepth : 24,
        screenHeight: window.screen ? window.screen.height : 0,
        screenWidth: window.screen ? window.screen.width : 0,
        timeDifference: new Date().getTimezoneOffset(),
        language: navigator.language || 'en',
        javaEnabled: javaEnabled
      };
    }

    /** The customer-typed billing fields (the server caps and re-checks). */
    function collectBillingData() {
      var read = function (selector) {
        var el = document.querySelector(selector);
        return el && typeof el.value === 'string' ? el.value : '';
      };
      return {
        firstName: read('[name*="[given_name]"]'),
        lastName: read('[name*="[family_name]"]'),
        address1: read('[name*="[address_line1]"]'),
        address2: read('[name*="[address_line2]"]'),
        locality: read('[name*="[locality]"]'),
        administrativeArea: read('[name*="[administrative_area]"]'),
        postalCode: read('[name*="[postal_code]"]'),
        country: read('select[name*="[country_code]"]'),
        email: read('input[name*="[email]"]')
      };
    }

    // Load the Microform client library (once), then initialise.
    var existing = document.querySelector('script[data-cybersource-flex]');
    if (window.Flex) {
      initMicroform();
    }
    else if (existing) {
      existing.addEventListener('load', initMicroform);
    }
    else if (!settings.clientLibraryIntegrity) {
      // Fail closed: never inject the third-party script without Subresource
      // Integrity (the server already enforces this, so this should be unreachable).
      showError(Drupal.t('Could not load the secure card fields. Please refresh and try again.'));
    }
    else {
      var script = document.createElement('script');
      script.src = settings.clientLibrary;
      script.async = true;
      script.crossOrigin = 'anonymous';
      script.integrity = settings.clientLibraryIntegrity;
      script.setAttribute('data-cybersource-flex', '1');
      script.onload = initMicroform;
      script.onerror = function () {
        showError(Drupal.t('Could not load the secure card fields. Please refresh and try again.'));
      };
      document.head.appendChild(script);
    }
  }

  Drupal.behaviors.cybersourceRest = {
    attach: function (context) {
      once('cybersource-rest', 'form.cybersource-rest-form, .cybersource-rest-form', context).forEach(function (el) {
        var form = el.tagName === 'FORM' ? el : el.closest('form');
        if (form) { attach(form); }
      });
    }
  };
})(Drupal, drupalSettings, once);
