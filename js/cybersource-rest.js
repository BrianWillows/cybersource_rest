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
        });
      });
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
