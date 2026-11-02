// Remember the visitor's language choice. The root page (index.html) uses it to redirect next time.
// English stays the default for first-time visitors.
document.querySelectorAll('[data-setlang]').forEach(function (a) {
  a.addEventListener('click', function () {
    try { localStorage.setItem('fa-lang', a.getAttribute('data-setlang')); } catch (e) {}
  });
});
