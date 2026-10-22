/* Картка товару: прогресивне покращення поверх форм без JS (UX-аудит S7, S8).
   1) Опції: ціна перераховується на change тим самим GET «Порахувати» (calc=1), кнопка ховається.
   2) Липка панель «ціна + У кошик» на мобільному ховається, поки на екрані кнопка самої форми. */
(function () {
  'use strict';
  var form = document.querySelector('form.add-to-cart');
  var bar = document.querySelector('[data-buy-bar]');
  var barPrice = document.querySelector('[data-buy-bar-price]');
  var fieldset = form && form.querySelector('.product-options');
  var calc = fieldset && fieldset.querySelector('button[name=calc]');

  if (fieldset && calc && window.fetch && window.DOMParser) {
    fieldset.classList.add('js-live');
    var timer = null, seq = 0;
    var recalc = function () {
      var params = new URLSearchParams();
      new FormData(form).forEach(function (value, name) {
        if (name !== '_token' && typeof value === 'string') params.append(name, value);
      });
      params.set('calc', '1');
      var url = calc.getAttribute('formaction') + (calc.getAttribute('formaction').indexOf('?') < 0 ? '?' : '&') + params.toString();
      var my = ++seq;
      fieldset.setAttribute('aria-busy', 'true');
      fetch(url, {credentials: 'same-origin', headers: {Accept: 'text/html'}}).then(function (r) {
        return r.ok ? r.text() : Promise.reject(r.status);
      }).then(function (html) {
        if (my !== seq) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.querySelector('.product-options .price-with-options');
        var current = fieldset.querySelector('.price-with-options');
        if (fresh && current) current.replaceWith(fresh);
        else if (fresh) calc.before(fresh);
        else if (current) current.remove();
        // помилки груп (обов'язкова опція, число поза межами) — з тієї ж відповіді
        fieldset.querySelectorAll('.option-group').forEach(function (group) {
          var code = group.getAttribute('data-option-group');
          var other = doc.querySelector('.option-group[data-option-group="' + code + '"]');
          var oldError = group.querySelector(':scope > .flash');
          var newError = other && other.querySelector(':scope > .flash');
          if (oldError) oldError.remove();
          if (newError) group.appendChild(newError);
          group.classList.toggle('has-error', !!newError);
        });
        var freshBar = doc.querySelector('[data-buy-bar-price]');
        if (barPrice && freshBar) barPrice.textContent = freshBar.textContent;
      }).catch(function () { /* без мережі — лишається кнопка форми й серверна перевірка */ }).then(function () {
        if (my === seq) fieldset.removeAttribute('aria-busy');
      });
    };
    fieldset.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(recalc, 150); });
    fieldset.addEventListener('input', function (e) {
      if (e.target.type === 'number') { clearTimeout(timer); timer = setTimeout(recalc, 500); }
    });
  }

  if (bar && form && 'IntersectionObserver' in window) {
    var target = form.querySelector('button[type=submit]:not([name=calc])') || form;
    new IntersectionObserver(function (entries) {
      bar.classList.toggle('buy-bar-hidden', entries[0].isIntersecting);
    }).observe(target);
  }
})();
