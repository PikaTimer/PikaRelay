// PikaRelay admin UI helpers. Loaded as an external file so the Content-Security-Policy can forbid inline scripts.
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    // Show times in the viewer's local time zone.
    document.querySelectorAll('time[data-ms]').forEach(function (el) {
      var ms = Number(el.getAttribute('data-ms'));
      if (ms > 0) {
        el.textContent = new Date(ms).toLocaleString();
      }
    });

    // Ask before destructive form submissions.
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-confirm'))) {
          event.preventDefault();
        }
      });
    });

    // Periodic refresh of the readers dashboard.
    var refresh = document.querySelector('[data-autorefresh]');
    if (refresh) {
      var seconds = Number(refresh.getAttribute('data-autorefresh')) || 15;
      window.setTimeout(function () { window.location.reload(); }, seconds * 1000);
    }

    // Warn when the config or data folder is reachable over HTTP.
    var warning = document.getElementById('exposure-warning');
    if (warning && window.fetch) {
      warning.getAttribute('data-probes').split(' ').forEach(function (url) {
        if (!url) { return; }
        fetch(url, { cache: 'no-store', credentials: 'omit' })
          .then(function (response) { return response.ok ? response.text() : ''; })
          .then(function (text) {
            if (text.indexOf('PIKARELAY-PROBE') !== -1) {
              warning.hidden = false;
            }
          })
          .catch(function () { /* blocked: that is the desired outcome */ });
      });
    }
  });
})();
