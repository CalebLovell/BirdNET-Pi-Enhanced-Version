(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
    });
  }

  function formatBytes(bytes) {
    var value = Number(bytes || 0);
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
      value = value / 1024;
      unit++;
    }
    return (unit === 0 ? value.toFixed(0) : value.toFixed(value >= 10 ? 1 : 2)) + ' ' + units[unit];
  }

  function formatDateTime(value) {
    if (!value) return 'Never';
    var date = value instanceof Date ? value : new Date(value);
    if (isNaN(date.getTime())) return String(value);
    return date.toLocaleString([], {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  function skeleton(lines) {
    var count = lines || 3;
    var html = '<div class="ui-skeleton-block" aria-hidden="true">';
    for (var i = 0; i < count; i++) {
      html += '<span class="ui-skeleton-line" style="width:' + (90 - (i % 3) * 14) + '%"></span>';
    }
    html += '</div>';
    return html;
  }

  function message(type, title, detail) {
    return '<div class="ui-message ui-message-' + escapeHtml(type || 'info') + '" role="status">' +
      '<strong>' + escapeHtml(title || '') + '</strong>' +
      (detail ? '<span>' + escapeHtml(detail) + '</span>' : '') +
      '</div>';
  }

  function setMessage(target, type, title, detail) {
    var el = typeof target === 'string' ? document.querySelector(target) : target;
    if (!el) return;
    el.innerHTML = message(type, title, detail);
  }

  function statusPill(status, label) {
    var safeStatus = String(status || 'unknown').toLowerCase();
    return '<span class="ui-status-pill ui-status-' + escapeHtml(safeStatus) + '">' + escapeHtml(label || status || 'Unknown') + '</span>';
  }

  function confirmAction(options) {
    options = options || {};
    if (!document.body || typeof HTMLDialogElement === 'undefined') {
      return Promise.resolve(window.confirm(options.message || options.title || 'Continue?'));
    }

    return new Promise(function (resolve) {
      var dialog = document.createElement('dialog');
      dialog.className = 'ui-confirm-dialog';
      dialog.innerHTML =
        '<form method="dialog">' +
        '<h3>' + escapeHtml(options.title || 'Confirm action') + '</h3>' +
        '<p>' + escapeHtml(options.message || 'Are you sure you want to continue?') + '</p>' +
        '<div class="ui-confirm-actions">' +
        '<button value="cancel" class="ui-btn-secondary">' + escapeHtml(options.cancelText || 'Cancel') + '</button>' +
        '<button value="confirm" class="ui-btn-primary ' + (options.danger ? 'ui-btn-danger' : '') + '">' + escapeHtml(options.confirmText || 'Continue') + '</button>' +
        '</div>' +
        '</form>';

      document.body.appendChild(dialog);
      dialog.addEventListener('close', function () {
        var ok = dialog.returnValue === 'confirm';
        dialog.remove();
        resolve(ok);
      });
      dialog.showModal();
      var cancel = dialog.querySelector('[value="cancel"]');
      if (cancel) cancel.focus();
    });
  }

  function submitButton(form, button) {
    if (button && button.name) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = button.name;
      hidden.value = button.value;
      form.appendChild(hidden);
    }
    form.submit();
  }

  function confirmSubmit(event, options) {
    event.preventDefault();
    var button = event.currentTarget || event.target;
    var form = button.form || button.closest('form');
    confirmAction(options).then(function (ok) {
      if (ok && form) submitButton(form, button);
    });
    return false;
  }

  function confirmLink(event, options) {
    event.preventDefault();
    var link = event.currentTarget || event.target;
    confirmAction(options).then(function (ok) {
      if (ok && link.href) window.location.href = link.href;
    });
    return false;
  }

  function bindPersistedControls(root) {
    var scope = root || document;
    var controls = scope.querySelectorAll('[data-ui-persist]');
    var params = new URLSearchParams(window.location.search);
    controls.forEach(function (control) {
      var key = 'birdnet-ui:' + control.getAttribute('data-ui-persist');
      var saved = localStorage.getItem(key);
      if (saved !== null && !(control.name && params.has(control.name))) {
        if (control.type === 'checkbox') control.checked = saved === '1';
        else control.value = saved;
        control.dataset.uiRestored = 'true';
        control.dispatchEvent(new CustomEvent('birdnet:restored', { bubbles: true }));
      }
      control.addEventListener('change', function () {
        localStorage.setItem(key, control.type === 'checkbox' ? (control.checked ? '1' : '0') : control.value);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindPersistedControls(document);
  });

  window.BirdNETUI = {
    escapeHtml: escapeHtml,
    formatBytes: formatBytes,
    formatDateTime: formatDateTime,
    skeleton: skeleton,
    message: message,
    setMessage: setMessage,
    statusPill: statusPill,
    confirmAction: confirmAction,
    confirmSubmit: confirmSubmit,
    confirmLink: confirmLink,
    bindPersistedControls: bindPersistedControls
  };
})();
