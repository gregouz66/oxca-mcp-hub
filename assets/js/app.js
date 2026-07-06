/* OXCA MCP Hub — interactions légères, sans dépendance. */
(function () {
  'use strict';

  /* Boutons « copier » : [data-copy] copie sa valeur et confirme visuellement. */
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-copy]');
    if (!btn) return;
    var write = navigator.clipboard
      ? navigator.clipboard.writeText(btn.getAttribute('data-copy'))
      : Promise.reject();
    write.catch(function () {
      var tmp = document.createElement('textarea');
      tmp.value = btn.getAttribute('data-copy');
      document.body.appendChild(tmp);
      tmp.select();
      document.execCommand('copy');
      tmp.remove();
    }).finally(function () {
      var original = btn.innerHTML;
      btn.innerHTML =
        '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">' +
        '<path d="M5 12.5l4.2 4.2L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      btn.disabled = true;
      setTimeout(function () {
        btn.innerHTML = original;
        btn.disabled = false;
      }, 1200);
    });
  });

  /* Confirmation avant les actions destructrices : <form data-confirm="…">. */
  document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  });

  /* Les messages flash disparaissent seuls après quelques secondes. */
  document.querySelectorAll('.flash').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity .6s, transform .6s';
      el.style.opacity = '0';
      el.style.transform = 'translateY(-6px)';
      setTimeout(function () { el.remove(); }, 650);
    }, 6000);
  });

  /* Champ code : ne garder que les chiffres, soumettre à 6. */
  var code = document.querySelector('.input-code');
  if (code) {
    code.addEventListener('input', function () {
      code.value = code.value.replace(/\D/g, '').slice(0, 6);
      if (code.value.length === 6 && code.form) code.form.requestSubmit();
    });
  }
})();
