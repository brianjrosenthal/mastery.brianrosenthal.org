// Shared page behaviors: top-bar popup menus, auto-submit search forms,
// confirm buttons, and auth-page focus.

// Popup menus (Admin dropdown, profile menu) — toggle button + panel pairs.
function setupPopupMenus() {
  var pairs = [
    ['adminToggle', 'adminMenu'],
    ['profileToggle', 'profileMenu'],
  ];

  var menus = [];

  pairs.forEach(function (pair) {
    var btn = document.getElementById(pair[0]);
    var menu = document.getElementById(pair[1]);
    if (!btn || !menu) return;
    menus.push({ btn: btn, menu: menu });

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var willOpen = menu.classList.contains('hidden');
      closeAllMenus();
      if (willOpen) {
        menu.classList.remove('hidden');
        menu.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });

  function closeAllMenus() {
    menus.forEach(function (m) {
      m.menu.classList.add('hidden');
      m.menu.setAttribute('aria-hidden', 'true');
      m.btn.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function (e) {
    var inside = menus.some(function (m) {
      return m.btn.contains(e.target) || m.menu.contains(e.target);
    });
    if (!inside) closeAllMenus();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllMenus();
  });
}

// Auto-submit forms with debouncing (opt in with data-auto-submit)
function setupAutoSubmit() {
  var forms = document.querySelectorAll('form[data-auto-submit]');

  forms.forEach(function (form) {
    var inputs = form.querySelectorAll('input, select');
    var timeout;

    inputs.forEach(function (input) {
      input.addEventListener('input', function () {
        clearTimeout(timeout);
        timeout = setTimeout(function () {
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
          } else {
            form.submit();
          }
        }, 600);
      });
    });
  });
}

// Confirm buttons/links (opt in with data-confirm="message")
function setupConfirmButtons() {
  var buttons = document.querySelectorAll('[data-confirm]');
  buttons.forEach(function (button) {
    button.addEventListener('click', function (e) {
      var message = this.getAttribute('data-confirm') || 'Are you sure?';
      if (!confirm(message)) {
        e.preventDefault();
      }
    });
  });
}

document.addEventListener('DOMContentLoaded', function () {
  setupPopupMenus();
  setupAutoSubmit();
  setupConfirmButtons();

  // Focus first input on auth pages
  if (document.body.classList.contains('auth')) {
    var firstInput = document.querySelector('input[type="email"], input[type="text"]');
    if (firstInput) {
      firstInput.focus();
    }
  }
});
