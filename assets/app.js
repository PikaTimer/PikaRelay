/*
 * Copyright (C) 2026 John Garner
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

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
