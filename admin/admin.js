// FACT.AI dashboard: language tabs, password generator, delete confirmation.
(function () {
  document.documentElement.classList.add('js');

  // language tabs in the news editor
  var tabs = document.querySelectorAll('[data-tab]');
  var panes = document.querySelectorAll('fieldset.lang');
  function show(lang) {
    tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.tab === lang ? 'true' : 'false'); });
    panes.forEach(function (p) { p.hidden = p.dataset.lang !== lang; });
  }
  if (tabs.length) {
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab); }); });
    show('en');
    // if the browser blocks the form because a hidden language field is invalid, reveal that tab
    document.addEventListener('invalid', function (e) {
      var p = e.target.closest && e.target.closest('fieldset.lang');
      if (p && p.hidden) { show(p.dataset.lang); }
    }, true);
  }

  // password generator (readable alphabet, 16 characters)
  document.querySelectorAll('[data-genpw]').forEach(function (b) {
    b.addEventListener('click', function () {
      var chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      var a = new Uint32Array(16);
      crypto.getRandomValues(a);
      var pw = '';
      for (var i = 0; i < a.length; i++) { pw += chars[a[i] % chars.length]; }
      document.getElementById(b.dataset.genpw).value = pw;
    });
  });

  // confirm before destructive actions
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.dataset.confirm)) { e.preventDefault(); }
    });
  });
})();
