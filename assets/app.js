/* Litecoin Explorer UI behaviour: theme toggle, "/" search focus, live tip/tx polling, QR.
   SPDX-License-Identifier: AGPL-3.0-or-later */
(function () {
  var root = document.documentElement;
  var btn = document.getElementById('theme-toggle');

  function current() { return root.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; }
  function paint() {
    if (!btn) return;
    // The button holds two inline SVGs (sun/moon) toggled by CSS on [data-theme];
    // only update the a11y state here - don't overwrite the icons with a text glyph.
    btn.setAttribute('aria-pressed', current() === 'light' ? 'true' : 'false');
  }
  function set(t) {
    root.setAttribute('data-theme', t);
    try { localStorage.setItem('ts-theme', t); } catch (e) {}
    paint();
  }
  if (btn) {
    paint();
    btn.addEventListener('click', function () { set(current() === 'dark' ? 'light' : 'dark'); });
  }

  // Mobile nav toggle (real <button> + aria-expanded; replaces the CSS checkbox-hack).
  var burger = document.querySelector('.nav-burger');
  var navLinks = document.getElementById('nav-links');
  if (burger && navLinks) {
    burger.addEventListener('click', function () {
      var open = navLinks.classList.toggle('open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/^(input|textarea|select)$/i.test((e.target && e.target.tagName) || '')) {
      var s = document.querySelector('.search input');
      if (s) { e.preventDefault(); s.focus(); }
    }
  });

  function commas(n) { return Number(n).toLocaleString(); }
  function sizeStr(n) { n = n || 0; if (n >= 1e6) { return (n / 1e6).toFixed(2) + ' MB'; } if (n >= 1000) { return (n / 1000).toFixed(1) + ' kB'; } return n + ' B'; }

  // Keep relative "N ago" labels fresh. Server stamps them once at page load, so without
  // this they freeze - and live-inserted blocks would sit on "just now" forever. Any element
  // with data-time="<unix secs>" is re-rendered every 10s. Format mirrors PHP time_ago().
  function timeAgo(ts) {
    var d = Math.floor(Date.now() / 1000) - ts;
    if (d < 0) { d = 0; }
    if (d < 60) { return d + ' sec ago'; }
    if (d < 3600) { return Math.floor(d / 60) + ' min ago'; }
    if (d < 86400) { return Math.floor(d / 3600) + ' hr ago'; }
    var days = Math.floor(d / 86400);
    return days + ' day' + (d < 172800 ? '' : 's') + ' ago';
  }
  function tickAges() {
    var els = document.querySelectorAll('[data-time]');
    for (var i = 0; i < els.length; i++) {
      var ts = parseInt(els[i].getAttribute('data-time'), 10);
      if (ts) { els[i].textContent = timeAgo(ts); }
    }
  }
  tickAges();
  setInterval(tickAges, 10000);

  // Live chain tip: poll the tip block and update the number + hash together.
  var tip = document.getElementById('live-tip');
  if (tip && tip.dataset.tip) {
    var uiBase = tip.dataset.tip.replace(/\/api\/blocks\/tip$/, '');
    var hashEl = document.getElementById('live-tiphash');
    var memEl = document.getElementById('live-mempool');
    var feeEl = document.getElementById('live-fee');
    var priceEl = document.getElementById('live-price');
    var lastH = parseInt((tip.textContent || '').replace(/[^0-9]/g, ''), 10) || 0;
    function flash(el) { if (!el) return; el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash'); }
    function live() {
      fetch(tip.dataset.tip).then(function (r) { return r.json(); }).then(function (b) {
        if (b && typeof b.height === 'number') {
          tip.textContent = '#' + commas(b.height);
          if (hashEl && b.id) { hashEl.textContent = b.id; hashEl.setAttribute('href', uiBase + '/block/' + b.id); }
          if (b.height > lastH) {
            lastH = b.height; flash(tip);
            var strip = document.getElementById('blocks-strip');
            if (strip && b.id) {
              var a = document.createElement('a');
              a.className = 'blk conf newblk';
              a.style.borderTopColor = 'var(--accent)';
              a.href = uiBase + '/block/' + b.id;
              var bts = parseInt(b.timestamp, 10) || Math.floor(Date.now() / 1000);
              a.innerHTML = '<div class="blk-h">#' + commas(b.height) + '</div>' +
                '<div class="blk-meta">' + commas(b.tx_count || 0) + ' tx · ' + sizeStr(b.size || 0) + '</div>' +
                '<div class="blk-age" data-time="' + bts + '">' + timeAgo(bts) + '</div>';
              var firstConf = strip.querySelector('.blk.conf');
              if (firstConf) { strip.insertBefore(a, firstConf); } else { strip.appendChild(a); }
              tickAges();
            }
          }
        }
      }).catch(function () {});
      if (memEl) {
        fetch(uiBase + '/api/mempool').then(function (r) { return r.json(); }).then(function (m) {
          if (m && typeof m.count === 'number') { memEl.textContent = commas(m.count) + ' tx'; }
        }).catch(function () {});
      }
      if (feeEl) {
        fetch(uiBase + '/api/v1/fees/recommended').then(function (r) { return r.json(); }).then(function (f) {
          if (f && typeof f.fastestFee === 'number') { feeEl.textContent = commas(f.fastestFee) + ' sat/vB'; }
        }).catch(function () {});
      }
      if (priceEl && priceEl.dataset.cur) {
        fetch(uiBase + '/api/v1/prices').then(function (r) { return r.json(); }).then(function (p) {
          var cur = priceEl.dataset.cur;
          if (p && typeof p[cur] === 'number') {
            var v = p[cur];
            priceEl.textContent = (priceEl.dataset.sym || '') +
              v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: v < 1 ? 4 : 2 });
          }
        }).catch(function () {});
      }
    }
    setInterval(live, 20000);
  }

  // Client-side display-currency selector. Re-denominates every server-rendered fiat
  // value (.fiat[data-sat]) and the live price ticker into the chosen currency using
  // our same-origin /api/v1/prices, and persists the choice. No-op when the nav has no
  // selector (price disabled or a single currency) - the server render already stands.
  (function () {
    var sel = document.getElementById('ccy-sel');
    var SYM = { USD: '$', EUR: '€', GBP: '£', JPY: '¥', CAD: 'CA$', AUD: 'A$', CHF: 'CHF ', BTC: '₿' };
    function sym(c) { return SYM[c] || (c + ' '); }
    function fmtFiat(v, s) {                        // mirrors PHP ts_fiat_str decimal scaling
      var a = Math.abs(v), out;
      if (a === 0) { out = '0.00'; }
      else if (a >= 1) { out = v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
      else if (a >= 0.01) { out = v.toFixed(4); }
      else { out = v.toFixed(8).replace(/0+$/, '').replace(/\.$/, ''); }
      return s + out;
    }
    function fmtCompact(v, s) {                     // mirrors PHP ts_num_compact (B/M/k)
      var a = Math.abs(v), div = 1, sfx = '', dec = 2;
      if (a >= 1e9) { div = 1e9; sfx = 'B'; dec = 2; }
      else if (a >= 1e6) { div = 1e6; sfx = 'M'; dec = 2; }
      else if (a >= 1e3) { div = 1e3; sfx = 'k'; dec = 1; }
      return s + (v / div).toFixed(dec).replace(/\.?0+$/, '') + sfx;
    }
    var def = (sel && sel.dataset.default) || 'USD';
    var stored = null;
    try { stored = localStorage.getItem('ts-ccy'); } catch (e) {}
    function valid(c) {                            // scan options directly - a tampered
      if (!sel) { return false; }                  // localStorage value must never throw
      for (var i = 0; i < sel.options.length; i++) { if (sel.options[i].value === c) { return true; } }
      return false;
    }
    var cur = (stored && valid(stored)) ? stored : def;
    if (sel) { sel.value = cur; }

    function apply(rates) {
      var c = cur, s = sym(c), rate = rates && rates[c];
      if (typeof rate !== 'number' || !(rate > 0)) { return; }
      document.querySelectorAll('.fiat[data-sat]').forEach(function (el) {
        var sat = parseInt(el.getAttribute('data-sat'), 10);
        if (!isFinite(sat)) { return; }
        var fiat = sat / 1e8 * rate;
        el.textContent = el.dataset.compact ? fmtCompact(fiat, s) : fmtFiat(fiat, s);
      });
      document.querySelectorAll('.ccy-label').forEach(function (el) { el.textContent = c; });
      // The 24h change % is server-rendered for the default currency only and is
      // genuinely per-currency; hide it when showing a different one rather than
      // pairing a new spot price with the old currency's percentage.
      var chg = document.getElementById('live-change');
      if (chg) { chg.style.display = (c === def) ? '' : 'none'; }
      var pe = document.getElementById('live-price');
      if (pe) {
        pe.dataset.cur = c; pe.dataset.sym = s;
        pe.textContent = s + rate.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: rate < 1 ? 4 : 2 });
      }
    }
    function refresh() {
      fetch('/api/v1/prices').then(function (r) { return r.json(); }).then(apply).catch(function () {});
    }
    if (sel) {
      sel.addEventListener('change', function () {
        cur = sel.value;
        try { localStorage.setItem('ts-ccy', cur); } catch (e) {}
        refresh();
      });
    }
    // Only re-denominate when the stored choice differs from what the server already
    // rendered; otherwise the page is correct as-is and we skip the fetch.
    if (cur !== def) { refresh(); }
  })();

  // Live tx status: poll a pending tx until it confirms, then refresh.
  var st = document.getElementById('tx-status');
  if (st && st.dataset.poll && /unconfirmed/i.test(st.textContent)) {
    var iv = setInterval(function () {
      fetch(st.dataset.poll).then(function (r) { return r.json(); }).then(function (s) {
        if (s && s.confirmed) { clearInterval(iv); location.reload(); }
      }).catch(function () {});
    }, 20000);
  }

  // Address / donation QR codes (inline SVG, CSP-safe, no external image).
  if (window.qrcode) {
    document.querySelectorAll('[data-qr]').forEach(function (el) {
      try {
        var qr = qrcode(0, el.dataset.qrEc || 'M');   // 'H' when a center logo overlays the code
        qr.addData(el.dataset.qr);
        qr.make();
        el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
      } catch (e) {}
    });
  }

  // Copy buttons (donation addresses, etc.).
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.dataset.copy;
      var done = function () {
        var t = b.textContent;
        b.textContent = 'Copied!';
        setTimeout(function () { b.textContent = t; }, 1400);
      };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(v).then(done).catch(function () {});
      } else {
        var ta = document.createElement('textarea');
        ta.value = v; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(ta);
      }
    });
  });

  // Sortable tables: click a column header to sort rows (numeric-aware), toggle dir.
  function cellVal(td) {
    var raw = td.getAttribute('data-sort');
    var t = (raw !== null ? raw : (td.textContent || '')).trim();
    var n = t.replace(/[,\s]/g, '');
    if (/^-?\d*\.?\d+$/.test(n)) { return parseFloat(n); }
    var m = t.match(/-?\d[\d,]*\.?\d*/);
    if (m) { return parseFloat(m[0].replace(/,/g, '')); }
    return t.toLowerCase();
  }
  document.querySelectorAll('.table-wrap > table').forEach(function (table) {
    if (!table.tHead || !table.tBodies.length) { return; }
    var ths = table.tHead.rows[0].cells;
    Array.prototype.forEach.call(ths, function (th, ci) {
      // Keep the native columnheader role; add focusability + aria-sort so the
      // sort is keyboard-operable and announced by screen readers.
      th.classList.add('th-sort');
      th.setAttribute('tabindex', '0');
      th.setAttribute('aria-sort', 'none');
      function doSort() {
        var tb = table.tBodies[0];
        var rows = Array.prototype.slice.call(tb.rows).filter(function (r) { return r.cells.length > ci; });
        var asc = th.getAttribute('data-dir') !== 'asc';
        Array.prototype.forEach.call(ths, function (o) {
          if (o !== th) { o.removeAttribute('data-dir'); o.setAttribute('aria-sort', 'none'); }
        });
        th.setAttribute('data-dir', asc ? 'asc' : 'desc');
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        rows.sort(function (a, b) {
          var av = cellVal(a.cells[ci]), bv = cellVal(b.cells[ci]);
          if (av < bv) { return asc ? -1 : 1; }
          if (av > bv) { return asc ? 1 : -1; }
          return 0;
        });
        rows.forEach(function (r) { tb.appendChild(r); });
      }
      th.addEventListener('click', doSort);
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') { e.preventDefault(); doSort(); }
      });
    });
  });

  // Interactive charts. Framed charts (.ts-chart) get the full controller:
  // nearest-point crosshair (V + H), an HTML data dot, a rich multi-row tooltip,
  // keyboard access + an aria-live readout. Un-framed charts keep a simple
  // <title> follow-tooltip. All vanilla DOM on our own inline SVG - CSP-safe.
  var SVGNS = 'http://www.w3.org/2000/svg';
  var tipEl = null;
  function tipShow(node, x, y) {
    if (!tipEl) { tipEl = document.createElement('div'); tipEl.className = 'chart-tip'; document.body.appendChild(tipEl); }
    tipEl.textContent = ''; tipEl.appendChild(node);
    tipEl.style.display = 'block';
    tipEl.style.left = Math.min(x + 14, window.innerWidth - tipEl.offsetWidth - 8) + 'px';
    tipEl.style.top  = Math.max(8, y - tipEl.offsetHeight - 14) + 'px';
  }
  function tipHide() { if (tipEl) { tipEl.style.display = 'none'; } }
  function richTip(data) {                       // build tooltip DOM from parsed JSON (no innerHTML)
    var f = document.createDocumentFragment();
    var head = document.createElement('div'); head.className = 'tip-h'; head.textContent = data.h || ''; f.appendChild(head);
    (data.rows || []).forEach(function (r) {
      var row = document.createElement('div'); row.className = 'tip-r';
      var sw = document.createElement('i'); sw.style.background = r.c || 'var(--accent)';
      var k = document.createElement('span'); k.className = 'tip-k'; k.textContent = r.k;
      var v = document.createElement('b'); v.className = 'tip-v'; v.textContent = r.v;
      row.appendChild(sw); row.appendChild(k); row.appendChild(v);
      if (r.d) {
        var d = document.createElement('em');
        d.className = 'tip-d ' + (r.d.charAt(0) === '+' ? 'up' : (r.d.charAt(0) === '−' ? 'down' : ''));
        d.textContent = r.d; row.appendChild(d);
      }
      f.appendChild(row);
    });
    return f;
  }
  function plainTip(text) {
    var f = document.createDocumentFragment();
    var head = document.createElement('div'); head.className = 'tip-h'; head.textContent = text; f.appendChild(head);
    return f;
  }
  function svgLine(svg, cls, horiz, vbW, vbH) {
    var l = svg.querySelector('.' + cls);
    if (!l) {
      l = document.createElementNS(SVGNS, 'line'); l.setAttribute('class', cls);
      l.setAttribute('vector-effect', 'non-scaling-stroke');
      if (horiz) { l.setAttribute('x1', 0); l.setAttribute('x2', vbW); }
      else { l.setAttribute('y1', 0); l.setAttribute('y2', vbH); }
      svg.appendChild(l);            // drawn last -> on top; pointer-events:none in CSS
    }
    return l;
  }
  function initFramedChart(fig) {
    var svg = fig.querySelector('svg'); if (!svg) { return; }
    var bands = [].slice.call(svg.querySelectorAll('.ts-hov')); if (!bands.length) { return; }
    var vb = (svg.getAttribute('viewBox') || '0 0 100 40').split(/\s+/);
    var vbW = parseFloat(vb[2]) || 100, vbH = parseFloat(vb[3]) || 40;
    var dot = fig.querySelector('.ts-dot');
    var live = fig.querySelector('.ts-live');
    var cur = -1;
    function clear() {
      cur = -1; tipHide();
      if (dot) { dot.hidden = true; }
      var v = svg.querySelector('.ts-crosshair'), hz = svg.querySelector('.ts-crosshair-h');
      if (v) { v.style.display = 'none'; } if (hz) { hz.style.display = 'none'; }
    }
    function activate(i, evt) {
      if (i < 0 || i >= bands.length) { return; }
      cur = i;
      var b = bands[i];
      var fx = parseFloat(b.getAttribute('data-fx'));
      var fyA = b.getAttribute('data-fy');
      var fy = fyA === null ? NaN : parseFloat(fyA);
      var v = svgLine(svg, 'ts-crosshair', false, vbW, vbH);
      v.setAttribute('x1', fx * vbW); v.setAttribute('x2', fx * vbW); v.style.display = 'block';
      if (fy === fy) {                             // not NaN
        var hz = svgLine(svg, 'ts-crosshair-h', true, vbW, vbH);
        hz.setAttribute('y1', fy * vbH); hz.setAttribute('y2', fy * vbH); hz.style.display = 'block';
        if (dot) { dot.style.left = (fx * 100) + '%'; dot.style.top = (fy * 100) + '%'; dot.hidden = false; }
      }
      var r = svg.getBoundingClientRect();
      var px = evt ? evt.clientX : r.left + fx * r.width;
      var py = r.top + (fy === fy ? fy * r.height : r.height * 0.35);
      var raw = b.getAttribute('data-tip'), data = null;
      if (raw) { try { data = JSON.parse(raw); } catch (e) {} }
      var t = b.querySelector('title');
      if (data) { tipShow(richTip(data), px, py); }
      else { tipShow(plainTip(t ? t.textContent : ''), px, py); }
      if (live) {
        live.textContent = data ? (data.h + ': ' + (data.rows || []).map(function (x) { return x.k + ' ' + x.v; }).join(', ')) : (t ? t.textContent : '');
      }
    }
    function nearest(clientX) {
      var r = svg.getBoundingClientRect(), fx = (clientX - r.left) / r.width, best = 0, bd = 9;
      for (var i = 0; i < bands.length; i++) {
        var d = Math.abs(parseFloat(bands[i].getAttribute('data-fx')) - fx);
        if (d < bd) { bd = d; best = i; }
      }
      return best;
    }
    svg.addEventListener('pointermove', function (e) { activate(nearest(e.clientX), e); });
    svg.addEventListener('pointerdown', function (e) { activate(nearest(e.clientX), e); });
    svg.addEventListener('pointerleave', clear);
    svg.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { activate(cur < 0 ? 0 : Math.min(bands.length - 1, cur + 1)); e.preventDefault(); }
      else if (e.key === 'ArrowLeft') { activate(cur < 0 ? bands.length - 1 : Math.max(0, cur - 1)); e.preventDefault(); }
      else if (e.key === 'Home') { activate(0); e.preventDefault(); }
      else if (e.key === 'End') { activate(bands.length - 1); e.preventDefault(); }
      else if (e.key === 'Escape') { clear(); svg.blur(); }
    });
    svg.addEventListener('blur', clear);
  }
  document.querySelectorAll('.ts-chart').forEach(initFramedChart);

  // Legacy simple <title> follow-tooltip for un-framed charts (goggles, bars,
  // diverging) not wrapped in a .ts-chart figure.
  document.querySelectorAll('.ts-bars, .ts-area, .ts-diverge, .goggles').forEach(function (svg) {
    if (svg.closest('.ts-chart')) { return; }
    svg.addEventListener('mousemove', function (e) {
      var el = e.target, title = null;
      if (el && el.tagName === 'rect') { var tt = el.getElementsByTagName('title')[0]; if (tt) { title = tt.textContent; } }
      if (title) { tipShow(plainTip(title), e.clientX, e.clientY); } else { tipHide(); }
    });
    svg.addEventListener('mouseleave', tipHide);
  });

  // Value-flow ribbons + treemap cells: styled follow-tooltip (address/txid + amount/size)
  // driven by data-a / data-v, so there's no clashing native <title> tooltip.
  document.querySelectorAll('.ts-flow, .ts-treemap').forEach(function (svg) {
    svg.addEventListener('mousemove', function (e) {
      var el = e.target;
      while (el && el !== svg && !(el.getAttribute && el.getAttribute('data-a') !== null)) { el = el.parentNode; }
      if (el && el.getAttribute && el.getAttribute('data-a') !== null) {
        var f = document.createDocumentFragment();
        var a = document.createElement('div'); a.className = 'tip-a'; a.textContent = el.getAttribute('data-a');
        var b = document.createElement('div'); b.className = 'tip-b'; b.textContent = el.getAttribute('data-v') || '';
        f.appendChild(a); f.appendChild(b);
        tipShow(f, e.clientX, e.clientY);
      } else { tipHide(); }
    });
    svg.addEventListener('mouseleave', tipHide);
  });

  // PWA: register the service worker for offline shell + asset caching.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js').catch(function () {});
    });
  }
})();
