/*!
 * Rolepod WP — admin progressive enhancements.
 * Vanilla JS only. ~3 KB. Enqueued only on Rolepod admin pages.
 *
 * Handles:
 *   - Copy-to-clipboard buttons (data-rp-copy="<selector>")
 *   - Bulk-select master checkbox (data-rp-toggle-all="<group>")
 *   - Confirm-before-submit for danger actions (data-rp-confirm="message")
 *   - Optional client-side filter on ledger search (data-rp-search="<row-selector>")
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    // Fallback for older browsers
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { /* noop */ }
    document.body.removeChild(ta);
    return Promise.resolve();
  }

  ready(function () {
    // === Copy buttons ===
    document.querySelectorAll('[data-rp-copy]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var targetSel = btn.getAttribute('data-rp-copy');
        var source = targetSel ? document.querySelector(targetSel) : null;
        var text = '';
        if (source) {
          text = ('value' in source) ? source.value : source.textContent;
        } else {
          text = btn.getAttribute('data-rp-copy-text') || '';
        }
        copyText(text).then(function () {
          var label = btn.querySelector('[data-rp-copy-label]') || btn;
          var orig = label.textContent;
          label.textContent = 'Copied';
          btn.classList.add('is-copied');
          setTimeout(function () {
            label.textContent = orig;
            btn.classList.remove('is-copied');
          }, 1500);
        });
      });
    });

    // === Bulk-select master checkbox ===
    document.querySelectorAll('[data-rp-toggle-all]').forEach(function (master) {
      var group = master.getAttribute('data-rp-toggle-all');
      master.addEventListener('change', function () {
        document.querySelectorAll('input[type="checkbox"][data-rp-group="' + group + '"]').forEach(function (cb) {
          cb.checked = master.checked;
        });
      });
    });

    // === Confirm before submit ===
    document.querySelectorAll('[data-rp-confirm]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        var msg = el.getAttribute('data-rp-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) {
          e.preventDefault();
          e.stopPropagation();
        }
      });
    });

    // === Client-side filter on ledger search ===
    document.querySelectorAll('[data-rp-search]').forEach(function (input) {
      var rowSel = input.getAttribute('data-rp-search');
      var rows = document.querySelectorAll(rowSel);
      var debounce;
      input.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () {
          var q = input.value.trim().toLowerCase();
          rows.forEach(function (row) {
            if (q === '') {
              row.style.display = '';
              return;
            }
            var hay = (row.getAttribute('data-rp-haystack') || row.textContent || '').toLowerCase();
            row.style.display = hay.indexOf(q) >= 0 ? '' : 'none';
          });
        }, 80);
      });
    });
  });
})();
