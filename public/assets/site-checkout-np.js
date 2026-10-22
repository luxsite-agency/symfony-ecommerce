/*! Symfony ecommerce LuxSite · luxsite.agency · Apache-2.0 */
/* Нова пошта на checkout (progressive enhancement, без залежностей): місто — datalist з /np/cities.json,
   відділення — select з /np/warehouses.json; без JS лишаються текстові поля і серверний фолбек-select. */
(function () {
  var root = document.querySelector('.np-picker');
  if (!root || !window.fetch) return;
  var cityInput = root.querySelector('[data-np-city]');
  var cityRef = root.querySelector('[data-np-city-ref]');
  var list = root.querySelector('#np-cities');
  var whWrap = root.querySelector('[data-np-warehouse-wrap]');
  var wh = root.querySelector('[data-np-warehouse]');
  var addrWrap = root.querySelector('[data-np-address-wrap]');
  var addr = root.querySelector('[data-np-address]');
  var citiesUrl = root.getAttribute('data-np-cities');
  var whUrl = root.getAttribute('data-np-warehouses');
  var npMethod = root.getAttribute('data-np-method');
  var placeholder = wh.options.length ? wh.options[0].textContent : '—';
  var known = {};
  var timer = null;

  function isNp() {
    var checked = document.querySelector('input[name="shipping"]:checked');
    return !checked || checked.value === npMethod;
  }
  function toggle() {
    var np = isNp();
    whWrap.hidden = !np || wh.options.length <= 1;
    addrWrap.hidden = np && wh.options.length > 1 && wh.value !== '';
  }
  function getJson(url) {
    return fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin'})
      .then(function (r) { return r.ok ? r.json() : {items: []}; })
      .catch(function () { return {items: []}; });
  }
  function fillCities(q) {
    getJson(citiesUrl + '?q=' + encodeURIComponent(q)).then(function (data) {
      list.innerHTML = '';
      known = {};
      data.items.forEach(function (c) {
        known[c.label] = c;
        var o = document.createElement('option');
        o.value = c.label;
        list.appendChild(o);
      });
    });
  }
  function fillWarehouses(ref) {
    wh.innerHTML = '';
    var first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    wh.appendChild(first);
    if (!ref) { wh.disabled = true; toggle(); return; }
    getJson(whUrl + '?city=' + encodeURIComponent(ref)).then(function (data) {
      data.items.forEach(function (w) {
        var o = document.createElement('option');
        o.value = w.ref;
        o.textContent = w.label;
        wh.appendChild(o);
      });
      wh.disabled = data.items.length === 0;
      toggle();
    });
  }
  cityInput.addEventListener('input', function () {
    var value = cityInput.value.trim();
    var hit = known[value];
    if (hit) {
      if (cityRef.value !== hit.ref) { cityRef.value = hit.ref; cityInput.value = hit.name; fillWarehouses(hit.ref); }
      return;
    }
    if (cityRef.value) { cityRef.value = ''; fillWarehouses(''); }
    clearTimeout(timer);
    if (value.length >= 2) timer = setTimeout(function () { fillCities(value); }, 250);
  });
  wh.addEventListener('change', function () {
    if (wh.value !== '') { addr.value = ''; }
    toggle();
  });
  document.querySelectorAll('input[name="shipping"]').forEach(function (r) { r.addEventListener('change', toggle); });
  if (cityRef.value && wh.options.length <= 1) fillWarehouses(cityRef.value);
  toggle();
})();
