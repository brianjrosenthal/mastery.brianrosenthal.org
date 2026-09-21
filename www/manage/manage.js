// Authoring-page behaviours: Markdown preview and the supporting-links
// editor. video.js handles the video panel (tabs, uploading, recording).

(function () {
  'use strict';

  // ---- Markdown preview -----------------------------------------------
  // Each [data-md-preview-for] button posts its textarea to
  // markdown_preview_eval.php and drops the returned fragment into the
  // matching preview box. Click again to hide it.
  function setupMarkdownPreview() {
    document.querySelectorAll('[data-md-preview-for]').forEach(function (btn) {
      var textarea = document.getElementById(btn.getAttribute('data-md-preview-for'));
      var preview = document.getElementById(btn.getAttribute('data-md-preview-for') + '_preview');
      if (!textarea || !preview) return;

      btn.addEventListener('click', function () {
        if (preview.innerHTML !== '' && btn.textContent === 'Hide preview') {
          preview.innerHTML = '';
          btn.textContent = 'Preview';
          return;
        }
        btn.disabled = true;
        var body = new URLSearchParams();
        body.set('markdown', textarea.value);
        fetch('/manage/markdown_preview_eval.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        }).then(function (r) {
          if (!r.ok) throw new Error('Preview failed (' + r.status + ')');
          return r.text();
        }).then(function (html) {
          preview.innerHTML = html;
          btn.textContent = 'Hide preview';
        }).catch(function (e) {
          preview.innerHTML = '<p class="error-text">' + e.message + '</p>';
        }).finally(function () {
          btn.disabled = false;
        });
      });
    });
  }

  // ---- Supporting links editor -----------------------------------------
  function setupResourceRows() {
    var rows = document.getElementById('resource-rows');
    var addBtn = document.getElementById('resource-add');
    var template = document.getElementById('resource-row-template');
    if (!rows || !addBtn || !template) return;

    function renumber() {
      Array.prototype.forEach.call(rows.querySelectorAll('.resource-row'), function (row, i) {
        row.querySelectorAll('input').forEach(function (input) {
          input.name = input.name.replace(/resources\[\d+\]/, 'resources[' + i + ']');
        });
      });
    }

    rows.addEventListener('click', function (e) {
      var btn = e.target.closest('.remove');
      if (!btn) return;
      var row = btn.closest('.resource-row');
      if (rows.querySelectorAll('.resource-row').length === 1) {
        row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
      } else {
        row.remove();
      }
      renumber();
    });

    addBtn.addEventListener('click', function () {
      var clone = template.content.firstElementChild.cloneNode(true);
      rows.appendChild(clone);
      renumber();
      clone.querySelector('input').focus();
    });
  }

  function init() {
    setupMarkdownPreview();
    setupResourceRows();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
