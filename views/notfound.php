<?php
/**
 * 404 / no-result page. Renders with network chrome when $net is available,
 * otherwise a minimal standalone page (e.g. unknown network in the URL).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
if (!headers_sent()) {
    http_response_code(http_response_code() === 200 ? 404 : http_response_code());
    // Never let a transient 404 (reorg / not-yet-indexed / backend blip) be shared
    // from the edge; set this before ts_head so its default s-maxage is skipped.
    header('Cache-Control: no-store');
}
$net = $net ?? ($GLOBALS['net'] ?? null);
$query = $GLOBALS['search_query'] ?? null;

if (is_array($net)) {
    ts_head($net, ['title' => 'Not found - Litecoin Explorer']);
    ?>
    <div class="card">
      <div class="card-b">
        <h1>Not found</h1>
        <?php if ($query !== null): ?>
          <p class="muted">No block, transaction, or address matched
          <span class="mono break"><?= h($query) ?></span> on <?= h($net['label']) ?>.</p>
          <?php if (preg_match('/^[0-9a-fA-F]{1,63}$/', (string) $query)): ?>
            <p class="muted sub">That looks like a partial hash. Paste the full 64-character txid or block hash.</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="muted">That page doesn't exist.</p>
        <?php endif; ?>
        <p><a class="btn ghost sm" href="<?= h(ts_u($net)) ?>/">← Back to <?= h($net['short']) ?></a></p>
      </div>
    </div>
    <?php
    ts_foot($net);
} else {
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en" data-theme="dark"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<script src="/assets/theme-init.js?v=1"></script>
<meta name="theme-color" content="#4c84d6">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="stylesheet" href="/assets/app.css?v=45">
<title>Not found - Litecoin Explorer</title></head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<nav>
  <div class="nav-inner">
    <a class="brand" href="/"><img class="brand-ico" src="/assets/favicon.svg" alt=""><span>Litecoin<b>Explorer</b></span></a>
    <button type="button" class="nav-burger" aria-label="Toggle menu" aria-expanded="false" aria-controls="nav-links"><?= ts_icon('menu', 'ico') ?></button>
    <div class="nav-links" id="nav-links">
      <a href="/docs"><?= ts_icon('code') ?>API</a>
      <a href="/status"><?= ts_icon('activity') ?>Status</a>
      <a href="/donate"><?= ts_icon('heart') ?>Donate</a>
      <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Toggle theme" title="Toggle theme"><?= ts_icon('sun', 'ico ico-sun') ?><?= ts_icon('moon', 'ico ico-moon') ?></button>
    </div>
  </div>
</nav>
<main id="main">
<div class="card"><div class="card-b">
<div class="big-num" style="font-size:3rem">404</div>
<h1>Not found</h1>
<p class="muted">That page doesn't exist.</p>
<p><a class="btn ghost sm" href="/">← Back home</a></p>
</div></div>
</main>
<?php ts_footer(); ?>
<script src="/assets/app.js?v=22" defer></script>
</body></html><?php
}
