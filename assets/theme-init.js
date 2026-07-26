/* No-flash theme: runs synchronously in <head> before first paint.
   SPDX-License-Identifier: AGPL-3.0-or-later */
(function () {
  try {
    var t = localStorage.getItem('lx-theme');
    if (t !== 'light' && t !== 'dark') {
      t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
    }
    document.documentElement.setAttribute('data-theme', t);
  } catch (e) { /* default dark from markup */ }
})();
