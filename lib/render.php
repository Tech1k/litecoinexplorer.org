<?php
/**
 * HTML layout: <head>, nav (search + network switch), footer, plus the small
 * formatting helpers the views share. Styling lives in /assets/app.css so the
 * CSP can keep script-src strict; the no-flash theme set happens in a tiny
 * synchronous /assets/theme-init.js.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** UI base path for the network. Empty in single-chain root mode (see lx_net_url). */
function lx_u(array $net): string
{
    return lx_net_url($net);
}

/** Whether to show cross-links to external explorers (config-gated, default off). */
function lx_extern_links(): bool
{
    return !empty(lx_config()['extern_links']);
}

/**
 * Inline SVG icon (Feather Icons, MIT-licensed, https://feathericons.com).
 * currentColor stroke so it inherits theme colours; markup only, no external
 * requests, CSP-safe. Returns '' for an unknown name.
 */
function lx_icon(string $name, string $cls = 'ico'): string
{
    static $p = [
        'box'         => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'clock'       => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'tool'        => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'code'        => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'heart'       => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'activity'    => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'zap'         => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'trending-up' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'database'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
        'layers'      => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'grid'        => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'repeat'      => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'at-sign'     => '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/>',
        'log-in'      => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'log-out'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'search'      => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'lock'        => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'book-open'   => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'eye-off'     => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
        'gift'        => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
        'clock-sm'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'cpu'         => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
        'target'      => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'hard-drive'  => '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
        'sun'         => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
        'moon'        => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'menu'        => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'inbox'       => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'info'        => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'list'        => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        // Litecoin's official MWEB mark (interwoven links). Carries its own viewBox
        // and stroke weight, so lx_icon renders it with a custom frame below.
        'mweb'        => ['vb' => '291 226 36 36', 'sw' => '3.0', 'd' => '<path d="M301.921903,248.666801 L305.974805,252.626918 C308.824073,255.196225 311.554034,255.205436 314.164687,252.65455 C316.77534,250.103664 316.765913,247.436203 314.136407,244.652164 L310.083505,240.692048"/><path d="M310.077921,240.697503 L314.130824,244.65762 C316.980092,247.226927 319.710052,247.236138 322.320705,244.685252 C324.931358,242.134367 324.921931,239.466905 322.292425,236.682867 L318.239523,232.72275"/><path d="M316.621397,238.778699 L312.568495,234.818582 C309.719227,232.249275 306.989266,232.240064 304.378613,234.79095 C301.76796,237.341836 301.777387,240.009297 304.406893,242.793336 L308.459795,246.753452"/><path d="M308.465379,246.747997 L304.412476,242.78788 C301.563208,240.218573 298.833248,240.209362 296.222595,242.760248 C293.611942,245.311133 293.621369,247.978595 296.250875,250.762633 L300.303777,254.72275"/>'],
    ];
    if (!isset($p[$name])) {
        return '';
    }
    $ic = $p[$name];
    $vb = is_array($ic) ? $ic['vb'] : '0 0 24 24';
    $sw = is_array($ic) ? $ic['sw'] : '2';
    $d  = is_array($ic) ? $ic['d'] : $ic;
    return '<svg class="' . h($cls) . '" viewBox="' . $vb . '" fill="none" stroke="currentColor" '
        . 'stroke-width="' . $sw . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/**
 * Open Graph + Twitter Card + apple-touch-icon meta, shared by every page's
 * <head> so the social/preview tags can't drift per-page. $image is a filename
 * under /assets (default og.png, 1200x630). Returns markup to emit in <head>.
 */
function lx_meta_social(string $title, string $desc, string $url, string $image = 'og-banner.png'): string
{
    // A leading slash means an absolute site path (e.g. a dynamic /og/... card);
    // a bare name is a file under /assets.
    $img = ($image !== '' && $image[0] === '/') ? lx_base_url() . $image : lx_base_url() . '/assets/' . $image;
    $t = h($title); $d = h($desc); $u = h($url); $i = h($img);
    return '<link rel="apple-touch-icon" href="/assets/icon-192.png">' . "\n"
        . '<meta property="og:type" content="website">' . "\n"
        . '<meta property="og:site_name" content="Litecoin Explorer">' . "\n"
        . '<meta property="og:title" content="' . $t . '">' . "\n"
        . '<meta property="og:description" content="' . $d . '">' . "\n"
        . '<meta property="og:url" content="' . $u . '">' . "\n"
        . '<meta property="og:image" content="' . $i . '">' . "\n"
        . '<meta property="og:image:width" content="1200">' . "\n"
        . '<meta property="og:image:height" content="630">' . "\n"
        . '<meta property="og:image:alt" content="Litecoin Explorer - Litecoin (LTC) block explorer">' . "\n"
        . '<meta property="og:locale" content="en_US">' . "\n"
        . '<meta name="twitter:card" content="summary_large_image">' . "\n"
        . '<meta name="twitter:title" content="' . $t . '">' . "\n"
        . '<meta name="twitter:description" content="' . $d . '">' . "\n"
        . '<meta name="twitter:image" content="' . $i . '">';
}

/** Brand accent (single source of truth for the site-wide --brand var): Litecoin blue. */
function lx_brand_color(string $coin): string
{
    $m = ['ltc' => '#4c84d6'];
    return isset($m[$coin]) ? $m[$coin] : '#4c84d6';
}

/** Format difficulty, keeping fractional detail on small/min-difficulty testnet values. */
function lx_diff_str(float $d): string
{
    if ($d >= 1000) {
        return number_format($d);
    }
    return $d >= 1 ? number_format($d, 2) : rtrim(rtrim(sprintf('%.6f', $d), '0'), '.');
}

/** Emit the document head, open <body>, render nav, open <main>. */
function lx_head(array $net, array $opt = []): void
{
    $title = $opt['title'] ?? ($net['label'] . ' Explorer');
    $chromeless = !empty($opt['chromeless']);   // TV / wall-display mode: no nav or footer
    $desc  = $opt['desc'] ?? ($net['label']
        . ' block explorer with first-class MWEB support. Blocks, transactions, addresses, mempool, mining and fees, live from the node.');
    $ogImage = $opt['og_image'] ?? 'og-banner.png';   // dynamic /og/... card or the static banner
    $base  = lx_u($net);
    // Canonical points at the clean resource path, no query string (avoids
    // duplicate-content signals for ?after= pagination and /search).
    $canon = lx_base_url() . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $q = $opt['q'] ?? '';
    header('Content-Type: text/html; charset=utf-8');
    // Default edge-cache for GET HTML: a short shared-cache window lets a CDN
    // absorb polling/crawler bursts (all content is public chain data, no
    // per-user state). Views that set their own Cache-Control (tx/block detail,
    // secret-processing tools) keep theirs; POST responses are never cached.
    $ccSet = false;
    foreach (headers_list() as $hh) {
        if (stripos($hh, 'Cache-Control:') === 0) { $ccSet = true; break; }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !$ccSet) {
        header('Cache-Control: public, s-maxage=5, max-age=0');
    }
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="/assets/theme-init.js?v=2"></script>
<meta name="theme-color" content="#4c84d6">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="manifest" href="/manifest.webmanifest">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($desc) ?>">
<link rel="canonical" href="<?= h($canon) ?>">
<?= lx_meta_social($title, $desc, $canon, $ogImage) ?>

<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'WebSite',
    'name'     => 'Litecoin Explorer',
    'url'      => lx_base_url() . '/',
    'potentialAction' => [
        '@type'  => 'SearchAction',
        'target' => ['@type' => 'EntryPoint', 'urlTemplate' => lx_base_url() . $base . '/search?q={query}'],
        'query-input' => 'required name=query',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<link rel="stylesheet" href="/assets/app.css?v=46">
</head>
<body class="<?= $chromeless ? 'tv' : '' ?>" style="--brand:<?= h(lx_brand_color(isset($net['coin']) ? $net['coin'] : '')) ?>">
<?php if (!$chromeless): ?>
<a class="skip-link" href="#main">Skip to content</a>
<nav>
  <div class="nav-inner">
    <a class="brand" href="<?= h($base) ?>/"><img class="brand-ico" src="/assets/favicon.svg" alt=""><span>Litecoin<b>Explorer</b></span></a>
    <form class="search" action="<?= h($base) ?>/search" method="get" role="search">
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search block, tx or address..." autocomplete="off" spellcheck="false" aria-label="Search">
      <button type="submit" class="btn sm" aria-label="Search">Search</button>
    </form>
    <button type="button" class="nav-burger" aria-label="Toggle menu" aria-expanded="false" aria-controls="nav-links"><?= lx_icon('menu', 'ico') ?></button>
    <div class="nav-links" id="nav-links">
      <?php $isUtxo = ($net['kind'] ?? 'utxo') === 'utxo'; ?>
      <a href="<?= h($base) ?>/blocks"><?= lx_icon('box') ?>Blocks</a>
      <a href="<?= h($base) ?>/mempool"><?= lx_icon('clock') ?>Mempool</a>
      <a href="<?= h($base) ?>/mining"><?= lx_icon('cpu') ?>Mining</a>
      <?php if ($isUtxo): ?><a href="<?= h($base) ?>/charts"><?= lx_icon('trending-up') ?>Charts</a><?php endif; ?>
      <?php if ($isUtxo): ?><a href="<?= h($base) ?>/node"><?= lx_icon('hard-drive') ?>Node</a><?php endif; ?>
      <?php if (lx_mweb_enabled($net)): ?><a href="<?= h($base) ?>/mweb"><?= lx_icon('mweb') ?>MWEB</a><?php endif; ?>
      <a href="<?= h($base) ?>/tools"><?= lx_icon('tool') ?>Tools</a>
      <?php if ($isUtxo): ?><a href="/docs"><?= lx_icon('code') ?>API</a><?php endif; ?>
      <a href="/donate"><?= lx_icon('heart') ?>Donate</a>
      <?php $ccys = function_exists('lx_price_config') ? lx_price_config()['currencies'] : []; if (count($ccys) > 1): $ccyDef = strtoupper(lx_price_config()['display']); ?>
      <select class="ccy-sel" id="ccy-sel" data-default="<?= h($ccyDef) ?>" aria-label="Display currency" title="Display currency"><?php foreach ($ccys as $cc): $ccU = strtoupper($cc); ?><option value="<?= h($ccU) ?>"<?= $ccU === $ccyDef ? ' selected' : '' ?>><?= h($ccU) ?></option><?php endforeach; ?></select>
      <?php endif; ?>
      <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Toggle light / dark theme" title="Toggle theme"><?= lx_icon('sun', 'ico ico-sun') ?><?= lx_icon('moon', 'ico ico-moon') ?></button>
    </div>
  </div>
</nav>
<?php endif; ?>
<main id="main">
<?php
}

/** Close <main>, render footer + scripts, close document. */
/** The shared site footer (identical on every page, standalone or network). */
function lx_footer(): void
{
    ?>
<footer>
  <div class="foot-inner">
    <a href="/status">Status</a>
    <a href="/donate">Donate</a>
    <a class="ext" href="https://litecoin.org" target="_blank" rel="noopener">Litecoin.org</a>
    <a class="ext" href="https://testnetscan.com/ltc-testnet/" target="_blank" rel="noopener" title="Litecoin testnet explorer">Testnet</a>
    <a class="ext" href="https://github.com/Tech1k/litecoinexplorer.org" target="_blank" rel="noopener">Source</a>
    <a class="ext" href="https://github.com/Tech1k/litecoinexplorer.org/blob/HEAD/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a>
    <span class="faint">·</span>
    <span>Made by <a class="ext" href="https://tech1k.com" target="_blank" rel="noopener">Tech1k</a></span>
    <span class="faint">· Litecoin, block by block</span>
  </div>
</footer>
<?php
}

function lx_foot(array $net, array $opt = []): void
{
    ?>
</main>
<?php if (empty($opt['chromeless'])) { lx_footer(); } ?>
<?php if (!empty($opt['qr'])): ?><script src="/assets/qrcode.js?v=1" defer></script><?php endif; ?>
<script src="/assets/app.js?v=26" defer></script>
</body>
</html>
<?php
}

// ---- formatting helpers ---------------------------------------------------

/** Format integer satoshis as "0.00012340 LTC". */
function lx_amount(array $net, int $sat): string
{
    return sat_to_coin($sat) . ' ' . $net['unit'];
}

/** Bare coin amount string (no unit). */
function lx_coin(int $sat): string
{
    return sat_to_coin($sat);
}

/** Compact coin amount from satoshis for tight chart axes: 1e8 -> "1", 1e5 -> "0.001", 5e12 -> "50k". */
function lx_coin_compact(float $sat): string
{
    $c = $sat / 100000000;
    if ($c == 0.0) {
        return '0';
    }
    if (abs($c) >= 1000) {
        return lx_num_compact($c);
    }
    if (abs($c) >= 1) {
        return rtrim(rtrim(number_format($c, 3, '.', ''), '0'), '.');
    }
    return rtrim(rtrim(number_format($c, 8, '.', ''), '0'), '.');
}

/**
 * Human byte/vbyte size that stays informative for tiny testnet blocks: B / kB /
 * MB (decimal). $unit is the base label ("B" for size, "vB" for virtual size),
 * so 8_400 → "8.4 kB" and 1_240_000 → "1.24 MB".
 */
function lx_size_str(int $n, string $unit = 'B', float $step = 0.0): string
{
    if ($n >= 1000000) {
        return number_format($n / 1000000, lx_step_dec($step / 1000000, 2)) . ' M' . $unit;
    }
    if ($n >= 1000) {
        return number_format($n / 1000, lx_step_dec($step / 1000, 1)) . ' k' . $unit;
    }
    return $n . ' ' . $unit;
}

/**
 * Inline SVG bar chart (CSP-safe: it is markup, not script, and needs no JS).
 * $bars is a list of ['value' => number, 'title' => string], drawn left→right
 * in the given order and scaled to the largest value; the final bar is accented
 * as "current". A per-bar <title> gives a native hover tooltip. The <svg> fills
 * its container width at a fixed CSS height (preserveAspectRatio="none").
 */
function lx_chart_bars(array $bars, string $label = 'Activity', array $opts = []): string
{
    static $gid = 0;
    $n = count($bars);
    if ($n === 0) {
        return $opts ? '<div class="chart-empty">Not enough data yet</div>' : '';
    }
    $W = 100.0; $H = 34.0;                 // viewBox units; CSS stretches to fit
    $max = 0.0;
    foreach ($bars as $b) {
        if ((float) $b['value'] > $max) { $max = (float) $b['value']; }
    }
    if ($max <= 0) { $max = 1.0; }
    // Framed: scale to enclosing "nice" bounds (from 0) so bars clear the top
    // gridline, and draw the y-axis; the legacy path keeps the bare max-scaled bars.
    $plotMax = $max; $yt = null;
    if ($opts) {
        $ax = lx_nice_bounds(0.0, $max, isset($opts['ytickn']) ? (int) $opts['ytickn'] : 4);
        $plotMax = max(1e-9, $ax['max']);
        $yt = $ax['ticks'];
    }
    $gap = $n > 50 ? 0.15 : 0.4;
    $bw  = ($W - ($n - 1) * $gap) / $n;
    $id  = 'tsb' . (++$gid);
    $grid = '';
    if ($yt !== null) {
        foreach ($yt as $t) {
            $gy = round($H * (1 - $t['f']), 2);
            $grid .= '<line class="lx-grid" x1="0" y1="' . $gy . '" x2="' . $W . '" y2="' . $gy . '"/>';
        }
    }
    $svg = '<svg class="lx-bars" viewBox="0 0 ' . $W . ' ' . $H
         . '" preserveAspectRatio="none" role="img" aria-label="' . h($label) . '"' . ($opts ? ' tabindex="0"' : '') . '>'
         . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" class="lx-bargrad-a"/><stop offset="1" class="lx-bargrad-b"/></linearGradient></defs>'
         . $grid;
    $x = 0.0; $hover = '';
    foreach ($bars as $i => $b) {
        $v  = (float) $b['value'];
        $bh = $v > 0 ? max($v / $plotMax * $H, 0.6) : 0.0;   // min visible height when nonzero
        $y  = $H - $bh;
        $cls = ($i === $n - 1) ? 'lx-bar hot' : 'lx-bar';
        $svg .= '<rect class="' . $cls . '" fill="url(#' . $id . ')" x="' . round($x, 2) . '" y="' . round($y, 2)
              . '" width="' . round($bw, 2) . '" height="' . round($bh, 2) . '">';
        if (!$opts && !empty($b['title'])) {
            $svg .= '<title>' . h($b['title']) . '</title>';
        }
        $svg .= '</rect>';
        if ($opts) {
            $cx = $x + $bw / 2;
            $attrs = ' data-fx="' . round($cx / $W, 4) . '" data-fy="' . round($y / $H, 4) . '"';
            if (isset($opts['tips'][$i]) && $opts['tips'][$i] !== '') {
                $attrs .= ' data-tip="' . h((string) $opts['tips'][$i]) . '"';
            }
            $hover .= '<rect class="lx-hov" x="' . round($x, 2) . '" y="0" width="' . round($bw + $gap, 2)
                    . '" height="' . $H . '"' . $attrs . '><title>' . h((string) ($b['title'] ?? '')) . '</title></rect>';
        }
        $x += $bw + $gap;
    }
    $svg .= $hover . '</svg>';
    if (!$opts) {
        return $svg;
    }
    $fmt = isset($opts['yfmt']) ? $opts['yfmt'] : 'lx_num_compact';
    $ystep = count($yt) >= 2 ? abs($yt[1]['v'] - $yt[0]['v']) : 0.0;
    $ticks = [];
    foreach ($yt as $t) { $ticks[] = ['f' => $t['f'], 'label' => $fmt($t['v'], $ystep)]; }
    return lx_chart_frame($svg, [
        'ticks'  => $ticks,
        'xticks' => isset($opts['xticks']) ? $opts['xticks'] : [],
        'legend' => isset($opts['legend']) ? $opts['legend'] : [],
    ]);
}

/** Nice step size for a range: 1/2/5x10^n so ~$count intervals span [$min,$max]. */
function lx_nice_step(float $min, float $max, int $count = 4): float
{
    if ($max <= $min) { $max = $min + 1; }
    $raw  = ($max - $min) / max(1, $count);
    $mag  = pow(10, floor(log10($raw > 0 ? $raw : 1)));
    $norm = $raw / $mag;
    return ($norm < 1.5 ? 1 : ($norm < 3 ? 2 : ($norm < 7 ? 5 : 10))) * $mag;
}

/**
 * "Nice" axis bounds that ENCLOSE [$min,$max]: the low/high are rounded outward
 * to the nearest nice step, so a chart plotted to these bounds always has its
 * data below the top gridline (no spike overshooting the axis). Returns
 * ['min','max','step','ticks'=>[['v'=>,'f'=>0..1 across [min,max]]]].
 */
function lx_nice_bounds(float $min, float $max, int $count = 4): array
{
    $step = lx_nice_step($min, $max, $count);
    $lo = floor($min / $step) * $step;
    $hi = ceil($max / $step) * $step;
    if ($hi - $max < $step * 0.001) { $hi += $step; }   // max landed on a tick -> add a step of headroom
    if ($hi <= $lo) { $hi = $lo + $step; }
    $span = $hi - $lo;
    $ticks = [];
    for ($v = $lo; $v <= $hi + $step * 1e-6; $v += $step) {
        $ticks[] = ['v' => $v, 'f' => ($v - $lo) / $span];
    }
    return ['min' => $lo, 'max' => $hi, 'step' => $step, 'ticks' => $ticks];
}

/** Evenly spaced x-axis time ticks over $rows' $xKey (unix), as [['f'=>0..1,'label'=>date]]. */
function lx_time_ticks(array $rows, string $xKey, int $count = 4): array
{
    if (count($rows) < 2) { return []; }
    $xs = [];
    foreach ($rows as $r) { $xs[] = (float) ($r[$xKey] ?? 0); }
    $minX = min($xs); $spanX = max(1e-9, max($xs) - $minX);
    $out = [];
    for ($i = 0; $i <= $count; $i++) {
        $out[] = ['f' => $i / $count, 'label' => gmdate('M j', (int) ($minX + $spanX * $i / $count))];
    }
    return $out;
}

/** One point's data-tip JSON. $rows = [['c'=>color,'k'=>label,'v'=>fmtValue,'d'=>fmtDelta|null]]. */
function lx_tip_json(string $header, array $rows): string
{
    $clean = [];
    foreach ($rows as $r) {
        $row = ['c' => $r['c'], 'k' => $r['k'], 'v' => $r['v']];
        if (!empty($r['d'])) { $row['d'] = $r['d']; }
        $clean[] = $row;
    }
    return json_encode(['h' => $header, 'rows' => $clean], JSON_UNESCAPED_SLASHES);
}

/** Signed percentage delta "+2.1%" / "-0.4%" (real minus sign) / '' for the first point. */
function lx_pct_delta(float $cur, float $prev): string
{
    if ($prev == 0.0) { return ''; }
    $p = ($cur - $prev) / abs($prev) * 100;
    return ($p >= 0 ? '+' : "\xE2\x88\x92") . number_format(abs($p), 1) . '%';
}

/**
 * Wrap a chart SVG in an HTML figure with axis + legend overlays. CSP-safe
 * (markup + inline style only). Everything that would distort under the SVG's
 * preserveAspectRatio="none" (tick labels, the data dot) lives here as HTML, not
 * inside the SVG. $opts: ticks[] (['f'=>,'label'=>]), xticks[], legend[]
 * (['color'=>,'label'=>]). The y-gutter grid cell shares the plot's height so
 * top:(1-f)*100% lines up with the SVG gridlines.
 */
function lx_chart_frame(string $svg, array $opts = []): string
{
    $yax = '';
    if (!empty($opts['ticks'])) {
        $yax = '<div class="lx-yax">';
        foreach ($opts['ticks'] as $t) {
            $yax .= '<span style="top:' . round((1 - $t['f']) * 100, 2) . '%">' . h((string) $t['label']) . '</span>';
        }
        $yax .= '</div>';
    }
    $xax = '';
    if (!empty($opts['xticks'])) {
        $xax = '<div class="lx-xax">';
        foreach ($opts['xticks'] as $t) {
            $xax .= '<span style="left:' . round($t['f'] * 100, 2) . '%">' . h((string) $t['label']) . '</span>';
        }
        $xax .= '</div>';
    }
    $leg = '';
    if (!empty($opts['legend'])) {
        $leg = '<figcaption class="lx-legend">';
        foreach ($opts['legend'] as $l) {
            $leg .= '<span class="lx-leg"><i style="background:' . h($l['color']) . '"></i>' . h((string) $l['label']) . '</span>';
        }
        $leg .= '</figcaption>';
    }
    return '<figure class="lx-chart">' . $yax
         . '<div class="lx-plot">' . $svg . '<div class="lx-dot" hidden></div></div>'
         . $xax . $leg
         . '<div class="lx-live" role="status" aria-live="polite"></div>'
         . '</figure>';
}

/**
 * Inline SVG area/line chart (CSP-safe markup) over $rows, plotting $yKey vs
 * $xKey, auto-scaled to the data's min/max on both axes. Used for difficulty /
 * hashrate trends. Returns '' with fewer than 2 points. Passing $opts upgrades
 * it to a framed chart (HTML y/x axes, nice-value gridlines, rich per-point
 * tooltips, crosshair + dot); an empty $opts keeps the legacy bare-SVG output.
 */
function lx_chart_area(array $rows, string $xKey, string $yKey, string $label = 'Series', array $labels = [], array $opts = []): string
{
    static $gid = 0;
    $n = count($rows);
    if ($n < 2) {
        return $opts ? '<div class="chart-empty">Not enough data yet</div>' : '';
    }
    $W = 100.0; $H = 40.0; $pad = 1.0;
    $xs = []; $ys = [];
    foreach ($rows as $r) {
        $xs[] = (float) ($r[$xKey] ?? 0);
        $ys[] = (float) ($r[$yKey] ?? 0);
    }
    $minX = min($xs); $spanX = max(1e-9, max($xs) - $minX);
    $minY = min($ys); $maxY = max($ys);
    if (!empty($opts['baseline']) && $opts['baseline'] === 'zero') {
        $minY = min(0.0, $minY);                 // anchor the area to zero (kills exaggerated wiggle)
    }
    $spanY = $maxY - $minY;
    $flat = $spanY < 1e-9;                        // constant series -> centre the line
    if ($flat) { $spanY = 1e-9; }
    $iw = $W - 2 * $pad; $ih = $H - 2 * $pad;
    $id = 'tsa' . (++$gid);

    // Plot range + gridlines. Framed charts scale to "nice" bounds that ENCLOSE
    // the data (rounded out to a value at/above the max), so tall spikes never
    // overshoot the top gridline/label; the legacy path keeps the raw data range
    // and its quarter-lines. A flat framed series draws no horizontal rules.
    $plotMin = $minY; $plotSpan = $spanY;
    $yt = null;
    if ($opts && !$flat) {
        $ax = lx_nice_bounds($minY, $maxY, isset($opts['ytickn']) ? (int) $opts['ytickn'] : 4);
        $plotMin  = $ax['min'];
        $plotSpan = max(1e-9, $ax['max'] - $ax['min']);
        $yt = $ax['ticks'];
    } elseif ($opts) {
        $yt = [];
    }
    $grid = '';
    if ($yt !== null) {
        foreach ($yt as $t) {
            $gy = round($pad + $ih * (1 - $t['f']), 2);
            $grid .= '<line class="lx-grid" x1="' . $pad . '" y1="' . $gy . '" x2="' . round($pad + $iw, 2) . '" y2="' . $gy . '"/>';
        }
        foreach ((isset($opts['xticks']) ? $opts['xticks'] : []) as $t) {
            $gx = round($pad + $t['f'] * $iw, 2);
            $grid .= '<line class="lx-grid" x1="' . $gx . '" y1="' . $pad . '" x2="' . $gx . '" y2="' . round($pad + $ih, 2) . '"/>';
        }
    } else {
        foreach ([0.25, 0.5, 0.75] as $g) {
            $gy = round($pad + $ih * $g, 2);
            $grid .= '<line class="lx-grid" x1="' . $pad . '" y1="' . $gy . '" x2="' . round($pad + $iw, 2) . '" y2="' . $gy . '"/>';
        }
    }

    $pts = [];
    foreach ($ys as $i => $yv) {
        $x = $pad + ($xs[$i] - $minX) / $spanX * $iw;
        $y = $flat ? ($pad + $ih * 0.5) : ($pad + $ih - ($yv - $plotMin) / $plotSpan * $ih);
        $pts[] = round($x, 2) . ',' . round($y, 2);
    }
    $line = implode(' ', $pts);
    $area = $pad . ',' . ($pad + $ih) . ' ' . $line . ' ' . ($pad + $iw) . ',' . ($pad + $ih);

    // Per-point hover bands: a <title> for JS-off / native tooltips, plus (when
    // framed) data-fx/fy/tip that the app.js controller reads for the crosshair,
    // dot and rich tooltip. Legacy path (no $opts) is byte-identical to before.
    $hover = '';
    if ($labels) {
        $bw = $iw / max(1, $n - 1);
        for ($i = 0; $i < $n; $i++) {
            $cx = $pad + ($xs[$i] - $minX) / $spanX * $iw;
            $attrs = '';
            if ($opts) {
                $py = $flat ? ($pad + $ih * 0.5) : ($pad + $ih - ($ys[$i] - $plotMin) / $plotSpan * $ih);
                $attrs = ' data-fx="' . round($cx / $W, 4) . '" data-fy="' . round($py / $H, 4) . '"';
                if (isset($opts['tips'][$i]) && $opts['tips'][$i] !== '') {
                    $attrs .= ' data-tip="' . h((string) $opts['tips'][$i]) . '"';
                }
            }
            $hover .= '<rect class="lx-hov" x="' . round(max(0, $cx - $bw / 2), 2) . '" y="0" width="' . round($bw, 2)
                    . '" height="' . $H . '"' . $attrs . '><title>' . h((string) ($labels[$i] ?? '')) . '</title></rect>';
        }
    }

    $svg = '<svg class="lx-area" viewBox="0 0 ' . $W . ' ' . $H
         . '" preserveAspectRatio="none" role="img" aria-label="' . h($label) . '"' . ($opts ? ' tabindex="0"' : '') . '>'
         . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" class="lx-grad-a"/><stop offset="1" class="lx-grad-b"/></linearGradient></defs>'
         . $grid
         . '<polygon fill="url(#' . $id . ')" points="' . h($area) . '"/>'
         . '<polyline class="lx-area-line" points="' . h($line) . '"/>'
         . $hover . '</svg>';
    if (!$opts) {
        return $svg;
    }
    // Tick labels. $fmt is called with ($value, $step) so a step-aware formatter
    // can pick enough precision to keep neighbouring ticks distinct; single-arg
    // formatters simply ignore the step. A flat series collapses to one centred
    // label (spreading identical labels over a straight line reads as broken).
    $fmt = isset($opts['yfmt']) ? $opts['yfmt'] : 'lx_num_compact';
    $ystep = count($yt) >= 2 ? abs($yt[1]['v'] - $yt[0]['v']) : 0.0;
    $ticks = [];
    if ($flat) {
        $ticks[] = ['f' => 0.5, 'label' => $fmt($minY, 0.0)];
    } else {
        foreach ($yt as $t) {
            $ticks[] = ['f' => $t['f'], 'label' => $fmt($t['v'], $ystep)];
        }
    }
    return lx_chart_frame($svg, [
        'ticks'  => $ticks,
        'xticks' => isset($opts['xticks']) ? $opts['xticks'] : [],
        'legend' => isset($opts['legend']) ? $opts['legend'] : [],
    ]);
}

/**
 * Inline SVG diverging bar chart (CSP-safe markup): a per-row "up" value drawn
 * above a centre line and a "down" value below it, both scaled to the shared
 * max. For in/out flows like MWEB peg-in vs peg-out. $rows is a list
 * of assoc arrays; $upKey/$downKey name the numeric fields. Returns '' if empty
 * or all-zero.
 */
function lx_chart_diverging(array $rows, string $upKey, string $downKey, string $label = 'Flow', array $labels = [], array $opts = []): string
{
    $n = count($rows);
    if ($n === 0) {
        return $opts ? '<div class="chart-empty">Not enough data yet</div>' : '';
    }
    $max = 0.0;
    $vals = [];
    foreach ($rows as $r) {
        $u = (float) ($r[$upKey] ?? 0);
        $d = (float) ($r[$downKey] ?? 0);
        if ($u > $max) { $max = $u; }
        if ($d > $max) { $max = $d; }
        if ($u > 0) { $vals[] = $u; }
        if ($d > 0) { $vals[] = $d; }
    }
    if ($max <= 0) {
        return $opts ? '<div class="chart-empty">Not enough data yet</div>' : '';
    }
    // Scale bars to a robust high percentile of activity rather than the absolute
    // max, so one whale peg doesn't crush every normal day to an invisible sliver;
    // bigger days clip to full height (the hover tooltip still carries the exact
    // amount). With few data points, fall back to the true max.
    sort($vals);
    $scaleMax = $max;
    if (count($vals) >= 8) {
        $p = $vals[(int) floor(0.90 * (count($vals) - 1))];
        if ($p > 0) { $scaleMax = $p; }
    }
    $W = 100.0; $H = 40.0; $mid = $H / 2;
    // Gap is a fraction of the per-point slot so the bar width stays positive on a long,
    // dense series (e.g. daily peg-flow over years). A fixed gap makes ($n-1)*gap exceed
    // the 100-unit viewBox once $n is a few hundred, flipping $bw negative and hiding
    // every bar. $bw + $gap == $slot, so the existing advance stays correct.
    $slot = $W / $n;
    $gap  = min($n > 50 ? 0.15 : 0.4, $slot * 0.35);
    $bw   = $slot - $gap;
    $svg = '<svg class="lx-diverge" viewBox="0 0 ' . $W . ' ' . $H
         . '" preserveAspectRatio="none" role="img" aria-label="' . h($label) . '"' . ($opts ? ' tabindex="0"' : '') . '>';
    $svg .= '<line class="lx-mid" x1="0" y1="' . $mid . '" x2="' . $W . '" y2="' . $mid . '"/>';
    $x = 0.0;
    foreach ($rows as $r) {
        $u = (float) ($r[$upKey] ?? 0);
        $d = (float) ($r[$downKey] ?? 0);
        $uh = $u > 0 ? max(min($u / $scaleMax, 1.0) * $mid, 0.8) : 0.0;
        $dh = $d > 0 ? max(min($d / $scaleMax, 1.0) * $mid, 0.8) : 0.0;
        if ($uh > 0) {
            $svg .= '<rect class="lx-up" x="' . round($x, 2) . '" y="' . round($mid - $uh, 2) . '" width="' . round($bw, 2) . '" height="' . round($uh, 2) . '"/>';
        }
        if ($dh > 0) {
            $svg .= '<rect class="lx-down" x="' . round($x, 2) . '" y="' . $mid . '" width="' . round($bw, 2) . '" height="' . round($dh, 2) . '"/>';
        }
        $x += $bw + $gap;
    }
    if ($labels) {
        $hx = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $attrs = '';
            if ($opts) {                                   // no data-fy: two-sided, so the controller draws only the V-crosshair + tooltip
                $attrs = ' data-fx="' . round(($hx + $bw / 2) / $W, 4) . '"';
                if (isset($opts['tips'][$i]) && $opts['tips'][$i] !== '') {
                    $attrs .= ' data-tip="' . h((string) $opts['tips'][$i]) . '"';
                }
            }
            $svg .= '<rect class="lx-hov" x="' . round($hx, 2) . '" y="0" width="' . round($bw + $gap, 2) . '" height="' . $H . '"' . $attrs . '><title>' . h((string) ($labels[$i] ?? '')) . '</title></rect>';
            $hx += $bw + $gap;
        }
    }
    $svg .= '</svg>';
    if (!$opts) {
        return $svg;
    }
    // Symmetric y-axis around the zero centre line: +max at top, 0 mid, −max bottom.
    $fmt = isset($opts['yfmt']) ? $opts['yfmt'] : 'lx_num_compact';
    $ticks = [];
    foreach ([1.0, 0.75, 0.5, 0.25, 0.0] as $f) {
        $mag = abs($f - 0.5) * 2 * $scaleMax;
        if ($f > 0.5)      { $lbl = '+' . $fmt($mag); }
        elseif ($f < 0.5)  { $lbl = "\xE2\x88\x92" . $fmt($mag); }
        else               { $lbl = '0'; }
        $ticks[] = ['f' => $f, 'label' => $lbl];
    }
    return lx_chart_frame($svg, [
        'ticks'  => $ticks,
        'xticks' => isset($opts['xticks']) ? $opts['xticks'] : [],
        'legend' => isset($opts['legend']) ? $opts['legend'] : [],
    ]);
}

/**
 * Fee-rate → colour, the mempool.space fee gradient: low fees green,
 * rising through amber to red as sat/vB climbs. Log-scaled so the low rates a
 * testnet actually sees still spread across the gradient. Returns an hsl() string
 * for inline style (allowed by the CSP's style-src 'unsafe-inline').
 */
function lx_feerate_color(float $rate): string
{
    if ($rate < 0.1) {
        $rate = 0.1;
    }
    $t = log10($rate + 1) / log10(80);      // ~1 sat/vB -> low, ~80+ -> high
    if ($t < 0) { $t = 0.0; }
    if ($t > 1) { $t = 1.0; }
    $hue = 145 - 145 * $t;                    // 145 green -> 0 red
    return 'hsl(' . round($hue) . ', 68%, 50%)';
}

/**
 * "Goggles" treemap of one projected mempool block: pending txs as fee-colored
 * cells stacked by vsize (highest fee at the bottom). CSP-safe SVG (fill is a
 * presentation attribute, not inline style). $b is a lx_projected_blocks entry.
 */
function lx_goggles_block(array $b): string
{
    $cells = $b['cells'] ?? [];
    $total = max(1, (int) ($b['vsize'] ?? 1));
    // Coalesce consecutive same-colour cells into one band. A uniform-fee block
    // then renders as a single rect instead of ~140 abutting slivers, killing the
    // sub-pixel anti-alias seams that otherwise read as horizontal scan-lines.
    $bands = [];
    foreach ($cells as $cell) {
        $vs = (float) $cell['vsize'];
        if ($vs <= 0) {
            continue;
        }
        $col = lx_feerate_color((float) $cell['rate']);
        $n = count($bands);
        if ($n > 0 && $bands[$n - 1]['col'] === $col) {
            $bands[$n - 1]['vs'] += $vs;
        } else {
            $bands[] = ['vs' => $vs, 'col' => $col, 'rate' => (float) $cell['rate']];
        }
    }
    $svg = '<svg class="goggles" viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="Projected mempool block, fee-shaded">';
    $y = 100.0;
    foreach ($bands as $i => $band) {
        $hh = $band['vs'] / $total * 100.0;
        if ($hh <= 0) {
            continue;
        }
        $y -= $hh;
        // Bleed each band 0.6 past its bottom edge (all but the bottom-most) so it
        // overpaints the seam with the band below; overflow:hidden clips the excess.
        $draw = $hh + ($i > 0 ? 0.6 : 0.0);
        $svg .= '<rect x="0" y="' . round($y, 2) . '" width="100" height="' . round($draw, 2)
              . '" fill="' . h($band['col']) . '"><title>'
              . h(number_format($band['rate'], 1) . ' sat/vB') . '</title></rect>';
    }
    return $svg . '</svg>';
}

/**
 * Compact human number: 1_792_932 -> "1.79M", 41_000 -> "41k". Trailing zeros
 * trimmed. Used where space is tight (block-strip metadata) and full precision
 * would overflow.
 */
function lx_num_compact(float $n, float $step = 0.0): string
{
    $a = abs($n);
    if ($a >= 1e9)     { $div = 1e9; $sfx = 'B'; $dec = 2; }
    elseif ($a >= 1e6) { $div = 1e6; $sfx = 'M'; $dec = 2; }
    elseif ($a >= 1e3) { $div = 1e3; $sfx = 'k'; $dec = 1; }
    else               { $div = 1.0; $sfx = '';  $dec = 2; }
    // When the axis step is known, add decimals so neighbouring ticks a $step
    // apart don't round to the same label (e.g. a near-constant difficulty).
    if ($step > 0) {
        $ss = $step / $div;
        if ($ss > 0 && $ss < 1) {
            $need = (int) ceil(-log10($ss));
            if ($need > $dec) { $dec = $need > 6 ? 6 : $need; }
        }
    }
    return rtrim(rtrim(number_format($n / $div, $dec, '.', ''), '0'), '.') . $sfx;
}

/**
 * Inline SVG stacked-area chart (CSP-safe). $rows plotted left→right by $xKey;
 * $keys are the numeric series stacked bottom→top, each filled with the matching
 * entry in $colors. Scaled to the largest per-row total. Returns '' if < 2 rows.
 */
function lx_chart_stacked(array $rows, string $xKey, array $keys, array $colors, string $label = 'Stacked', array $opts = []): string
{
    $n = count($rows);
    if ($n < 2) {
        return $opts ? '<div class="chart-empty">Not enough data yet</div>' : '';
    }
    $W = 100.0; $H = 40.0; $pad = 1.0; $iw = $W - 2 * $pad; $ih = $H - 2 * $pad;
    $xs = [];
    foreach ($rows as $r) { $xs[] = (float) ($r[$xKey] ?? 0); }
    $minX = min($xs); $spanX = max(1e-9, max($xs) - $minX);
    $maxTot = 0.0;
    foreach ($rows as $r) {
        $t = 0.0;
        foreach ($keys as $k) { $t += (float) ($r[$k] ?? 0); }
        if ($t > $maxTot) { $maxTot = $t; }
    }
    $maxTot = max(1e-9, $maxTot);
    // Framed: scale to enclosing "nice" bounds and draw the y-axis + gridlines.
    $plotMax = $maxTot; $yt = null;
    if ($opts) {
        $ax = lx_nice_bounds(0.0, $maxTot, isset($opts['ytickn']) ? (int) $opts['ytickn'] : 4);
        $plotMax = max(1e-9, $ax['max']);
        $yt = $ax['ticks'];
    }
    $grid = '';
    if ($yt !== null) {
        foreach ($yt as $t) {
            $gy = round($pad + $ih * (1 - $t['f']), 2);
            $grid .= '<line class="lx-grid" x1="' . $pad . '" y1="' . $gy . '" x2="' . round($pad + $iw, 2) . '" y2="' . $gy . '"/>';
        }
    }
    $base = array_fill(0, $n, 0.0);
    $svg = '<svg class="lx-area" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img" aria-label="' . h($label) . '"' . ($opts ? ' tabindex="0"' : '') . '>' . $grid;
    foreach ($keys as $ki => $k) {
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $pad + ($xs[$i] - $minX) / $spanX * $iw;
            $cum = $base[$i] + (float) ($rows[$i][$k] ?? 0);
            $pts[] = round($x, 2) . ',' . round($pad + $ih - ($cum / $plotMax) * $ih, 2);
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            $x = $pad + ($xs[$i] - $minX) / $spanX * $iw;
            $pts[] = round($x, 2) . ',' . round($pad + $ih - ($base[$i] / $plotMax) * $ih, 2);
        }
        $svg .= '<polygon points="' . h(implode(' ', $pts)) . '" fill="' . h($colors[$ki] ?? 'var(--accent)') . '" fill-opacity="0.82"/>';
        for ($i = 0; $i < $n; $i++) { $base[$i] += (float) ($rows[$i][$k] ?? 0); }
    }
    $hover = '';
    if ($opts) {
        $bw = $iw / max(1, $n - 1);
        for ($i = 0; $i < $n; $i++) {
            $cx = $pad + ($xs[$i] - $minX) / $spanX * $iw;
            $attrs = ' data-fx="' . round($cx / $W, 4) . '"';
            if (isset($opts['tips'][$i]) && $opts['tips'][$i] !== '') {
                $attrs .= ' data-tip="' . h((string) $opts['tips'][$i]) . '"';
            }
            $hover .= '<rect class="lx-hov" x="' . round(max(0, $cx - $bw / 2), 2) . '" y="0" width="' . round($bw, 2)
                    . '" height="' . $H . '"' . $attrs . '><title>' . h((string) (isset($opts['labels'][$i]) ? $opts['labels'][$i] : '')) . '</title></rect>';
        }
    }
    $svg .= $hover . '</svg>';
    if (!$opts) {
        return $svg;
    }
    $fmt = isset($opts['yfmt']) ? $opts['yfmt'] : 'lx_num_compact';
    $ystep = count($yt) >= 2 ? abs($yt[1]['v'] - $yt[0]['v']) : 0.0;
    $ticks = [];
    foreach ($yt as $t) { $ticks[] = ['f' => $t['f'], 'label' => $fmt($t['v'], $ystep)]; }
    return lx_chart_frame($svg, [
        'ticks'  => $ticks,
        'xticks' => isset($opts['xticks']) ? $opts['xticks'] : [],
        'legend' => isset($opts['legend']) ? $opts['legend'] : [],
    ]);
}

/** Worst (largest) aspect ratio a treemap row would have if laid along a side of length $side. */
function lx_tm_worst(array $areas, float $side): float
{
    $sum = array_sum($areas);
    if ($sum <= 0 || $side <= 0) { return INF; }
    $s2 = $sum * $sum; $w2 = $side * $side;
    return max($w2 * max($areas) / $s2, $s2 / ($w2 * min($areas)));
}

/**
 * Squarified treemap layout (Bruls–Huizing–van Wijk): pack $items (each with a positive
 * 'v') into $w×$h so cell area ∝ value while keeping cells as close to square as possible -
 * far more legible than a naive binary split, which degenerates into thin slabs. Rows are
 * grown along the shorter side until adding another cell would worsen the aspect ratio.
 * Appends ['x','y','w','h','it'].
 */
function lx_treemap_squarify(array $items, float $x, float $y, float $w, float $h, array &$cells): void
{
    $total = 0.0;
    foreach ($items as $it) { $total += (float) $it['v']; }
    if ($total <= 0 || $w <= 0 || $h <= 0) { return; }
    $scale = ($w * $h) / $total;                       // value -> area
    $q = [];
    foreach ($items as $it) { $q[] = ['a' => (float) $it['v'] * $scale, 'it' => $it]; }

    $i = 0; $n = count($q);
    while ($i < $n && $w > 0.001 && $h > 0.001) {
        $side = min($w, $h);
        $rowA = [$q[$i]['a']]; $rowI = [$q[$i]]; $j = $i + 1;
        while ($j < $n) {
            $cand = $rowA; $cand[] = $q[$j]['a'];
            if (lx_tm_worst($cand, $side) > lx_tm_worst($rowA, $side)) { break; }   // adding it makes cells worse
            $rowA = $cand; $rowI[] = $q[$j]; $j++;
        }
        $sum = array_sum($rowA);
        $thick = $sum / $side;                          // strip depth perpendicular to $side
        if ($thick <= 0) { break; }
        if ($w >= $h) {                                 // vertical strip of width $thick, full height
            $oy = $y;
            foreach ($rowI as $r) { $ch = $r['a'] / $thick; $cells[] = ['x' => $x, 'y' => $oy, 'w' => $thick, 'h' => $ch, 'it' => $r['it']]; $oy += $ch; }
            $x += $thick; $w -= $thick;
        } else {                                        // horizontal strip of height $thick, full width
            $ox = $x;
            foreach ($rowI as $r) { $cw = $r['a'] / $thick; $cells[] = ['x' => $ox, 'y' => $y, 'w' => $cw, 'h' => $thick, 'it' => $r['it']]; $ox += $cw; }
            $y += $thick; $h -= $thick;
        }
        $i = $j;
    }
}

/**
 * Block transaction treemap (mempool.space-style): each tx a rectangle sized by vsize and
 * fee-shaded, packed squarified so cells stay legible. $items: [['v'=>vsize,'rate'=>satvB,
 * 'txid'=>str], ...]. CSP-safe inline SVG; cells link to the tx. Borders come from CSS
 * (non-scaling 1px) so the stretched viewBox doesn't blow them up into thick bands.
 */
function lx_block_treemap(array $items, string $label = 'Block transactions'): string
{
    $items = array_values(array_filter($items, function ($i) { return (float) ($i['v'] ?? 0) > 0; }));
    if (!$items) {
        return '';
    }
    usort($items, function ($a, $b) { return (float) $b['v'] <=> (float) $a['v']; });
    $items = array_slice($items, 0, 400);
    // Lay out in a wide-short box whose aspect ratio the CSS mirrors (aspect-ratio:100/28),
    // so preserveAspectRatio="none" doesn't distort - cells stay square-ish on screen.
    $cells = [];
    lx_treemap_squarify($items, 0, 0, 100, 28, $cells);
    $svg = '<svg class="lx-treemap" viewBox="0 0 100 28" preserveAspectRatio="none" role="img" aria-label="' . h($label) . '">';
    foreach ($cells as $c) {
        $it   = $c['it'];
        $rate = isset($it['rate']) ? (float) $it['rate'] : null;
        $col  = $rate !== null ? lx_feerate_color($rate) : 'var(--accent)';
        $tid  = (string) ($it['txid'] ?? '');
        $a    = $tid !== '' ? shorten($tid, 10, 6) : 'transaction';
        $v    = lx_size_str((int) $it['v'], 'vB') . ($rate !== null ? ' · ' . number_format($rate, 1) . ' sat/vB' : '');
        $rect = '<rect x="' . round($c['x'], 2) . '" y="' . round($c['y'], 2) . '" width="' . round($c['w'], 2)
              . '" height="' . round($c['h'], 2) . '" fill="' . h($col) . '" data-a="' . h($a) . '" data-v="' . h($v) . '"/>';
        // Fee-rate label on cells with room (uniform scaling keeps the text crisp).
        if ($rate !== null && $c['w'] >= 7.0 && $c['h'] >= 3.4) {
            $rlbl = $rate >= 10 ? number_format($rate, 0) : number_format($rate, 1);
            $rect .= '<text class="lx-treemap-lbl" x="' . round($c['x'] + $c['w'] / 2, 2) . '" y="' . round($c['y'] + $c['h'] / 2, 2) . '">' . h($rlbl) . '</text>';
        }
        $svg .= $tid !== '' ? '<a href="/tx/' . h($tid) . '">' . $rect . '</a>' : $rect;
    }
    return $svg . '</svg>';
}

/**
 * Transaction value-flow diagram (litecoinspace/mempool.space-style bowtie Sankey): each input
 * is its own value-weighted ribbon converging into the transaction, which fans back out to
 * every output + the fee. All-blue with a fixed left-to-right gradient (dark at the tips, bright
 * at the centre) plus chevron caps on the inputs and pennant caps on the outputs. UTXO inputs
 * don't map to specific outputs, so the centre is the pooling point (in = out + fee). CSP-safe.
 */
function lx_tx_flow(array $net, array $tx, array $outspends = []): string
{
    $H = 48.0; $pad = 3.0; $barH = $H - 2 * $pad;
    $isCb = !empty($tx['vin'][0]['is_coinbase']);

    // Each input carries its source tx (vin.txid) so a click walks backward one hop.
    $ins = []; $inSum = 0; $inKnown = true;
    foreach (($tx['vin'] ?? []) as $in) {
        if ($isCb) { continue; }
        $v = isset($in['prevout']['value']) ? (int) $in['prevout']['value'] : null;
        if ($v === null) { $inKnown = false; $v = 0; }
        $ins[] = ['v' => $v, 'label' => $in['prevout']['scriptpubkey_address'] ?? 'input',
                  'href' => isset($in['txid']) ? '/tx/' . $in['txid'] : null];
        $inSum += $v;
    }
    // Each output links to its spending tx (from resolved outspends) so a click walks forward.
    $outs = []; $outSum = 0;
    foreach (($tx['vout'] ?? []) as $i => $vo) {
        $val = (int) ($vo['value'] ?? 0);
        $sp  = $outspends[$i] ?? null;
        $outs[] = ['v' => $val, 'label' => $vo['scriptpubkey_address'] ?? lx_spk_label($vo['scriptpubkey_type'] ?? ''),
                   'href' => (is_array($sp) && !empty($sp['spent']) && !empty($sp['txid'])) ? '/tx/' . $sp['txid'] : null];
        $outSum += $val;
    }
    $fee = (int) ($tx['fee'] ?? 0);
    $collapse = function (array $segs, string $noun) {   // keep the 12 biggest, pool the rest (unlinked)
        usort($segs, function ($a, $b) { return $b['v'] <=> $a['v']; });
        if (count($segs) > 13) {
            $keep = array_slice($segs, 0, 12);
            $other = 0; foreach (array_slice($segs, 12) as $s) { $other += $s['v']; }
            $keep[] = ['v' => $other, 'label' => (count($segs) - 12) . ' more ' . $noun, 'href' => null];
            return $keep;
        }
        return $segs;
    };
    $outs = $collapse($outs, 'outputs');
    // Coinbase or unresolved input values collapse to one full-value input ribbon.
    if ($isCb || !$inKnown || !$ins) {
        $ins = [['v' => max(1, $outSum + $fee), 'label' => $isCb ? 'Coinbase (newly minted)' : 'inputs (values unknown)', 'href' => null]];
        $inSum = $outSum + $fee;
    } else {
        $ins = $collapse($ins, 'inputs');
    }
    $rights = $outs;                                     // right side = outputs then the fee sliver
    if ($fee > 0) { $rights[] = ['v' => $fee, 'label' => 'fee', 'fee' => true, 'href' => null]; }

    $capL0 = 2.0; $bandL = 12.0; $cx = 50.0; $bandR = 88.0; $capR1 = 98.0;
    $y0 = $pad; $waistH = $barH * 0.68; $wTop = $pad + ($barH - $waistH) / 2.0;
    $BLUE = 'url(#lx-flow-grad)'; $AMBER = '#e0a33a';

    // Vertical fraction per segment WITH a floor, so a tiny output (e.g. a 2.6 LTC peg-out next
    // to a 337k LTC HogEx supply) still gets a visible, clickable band instead of a sub-pixel
    // sliver. Fractions sum to 1 and are shared by the full-height and waist stacks so the
    // ribbons don't twist. The tooltip still shows the true value.
    $fracs = function (array $segs) {
        $n = count($segs);
        if ($n === 0) { return []; }
        $minF = min(0.04, 0.6 / $n);
        $flex = 1.0 - $minF * $n;
        $total = 0.0; foreach ($segs as $s) { $total += (float) $s['v']; }
        $out = [];
        foreach ($segs as $s) { $out[] = $minF + ($total > 0 ? (float) $s['v'] / $total * $flex : $flex / $n); }
        return $out;
    };
    $place = function (array $fr, float $top, float $region) {
        $pos = []; $y = $top;
        foreach ($fr as $f) { $hh = $f * $region; $pos[] = [$y, $hh]; $y += $hh; }
        return $pos;
    };
    $inF = $fracs($ins); $outF = $fracs($rights);
    $inL = $place($inF, $y0, $barH);   $inW = $place($inF, $wTop, $waistH);   // inputs: caps + centre side
    $outR = $place($outF, $y0, $barH); $outW = $place($outF, $wTop, $waistH); // outputs: caps + centre side
    $unit = $net['unit'];
    // data-a / data-v feed the custom hover tooltip (app.js); no <title> so there's no clashing
    // native tooltip. Accessibility lives in the Inputs/Outputs table below.
    $aOf = function (array $s) { return !empty($s['fee']) ? 'Fee' : (string) $s['label']; };
    $vOf = function (array $s) use ($unit) { return lx_coin((int) $s['v']) . ' ' . $unit; };
    $wrap = function (string $shape, ?string $href) { return $href !== null ? '<a href="' . h($href) . '">' . $shape . '</a>' : $shape; };

    $svg = '<svg class="lx-flow" viewBox="0 0 100 ' . $H . '" preserveAspectRatio="none" role="img" aria-label="Transaction value flow">';
    // Fixed x-positioned gradient: darkest at the outer tips, brightest through the centre.
    $svg .= '<defs><linearGradient id="lx-flow-grad" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="100" y2="0">'
          . '<stop offset="0" stop-color="#0c3a5f"/><stop offset="0.22" stop-color="#1c7fd6"/>'
          . '<stop offset="0.5" stop-color="#2ba4fa"/><stop offset="0.8" stop-color="#2189e2"/>'
          . '<stop offset="1" stop-color="#155f9f"/></linearGradient></defs>';

    $ribbon = function (float $x0, float $la, float $lb, float $x1, float $ra, float $rb, string $fill, array $s) use (&$svg, $aOf, $vOf, $wrap) {
        $mx = ($x0 + $x1) / 2.0;                         // S-curve control at the midpoint
        $p = '<path class="lx-flow-ribbon" fill="' . $fill . '" data-a="' . h($aOf($s)) . '" data-v="' . h($vOf($s)) . '" d="M' . round($x0, 2) . ',' . round($la, 2)
              . ' C' . round($mx, 2) . ',' . round($la, 2) . ' ' . round($mx, 2) . ',' . round($ra, 2) . ' ' . round($x1, 2) . ',' . round($ra, 2)
              . ' L' . round($x1, 2) . ',' . round($rb, 2)
              . ' C' . round($mx, 2) . ',' . round($rb, 2) . ' ' . round($mx, 2) . ',' . round($lb, 2) . ' ' . round($x0, 2) . ',' . round($lb, 2)
              . ' Z"/>';
        $svg .= $wrap($p, $s['href'] ?? null);
    };
    foreach ($ins as $k => $s) {                         // inputs -> centre
        [$lt, $lh] = $inL[$k]; [$wt, $wh] = $inW[$k];
        $ribbon($bandL, $lt, $lt + $lh, $cx, $wt, $wt + $wh, $BLUE, $s);
    }
    foreach ($rights as $k => $s) {                      // centre -> outputs + fee
        [$wt, $wh] = $outW[$k]; [$rt, $rh] = $outR[$k];
        $ribbon($cx, $wt, $wt + $wh, $bandR, $rt, $rt + $rh, empty($s['fee']) ? $BLUE : $AMBER, $s);
    }
    $cap = function (string $pts, string $fill, array $s) use (&$svg, $aOf, $vOf, $wrap) {
        $svg .= $wrap('<polygon class="lx-flow-cap" points="' . $pts . '" fill="' . $fill . '" data-a="' . h($aOf($s)) . '" data-v="' . h($vOf($s)) . '"/>', $s['href'] ?? null);
    };
    foreach ($ins as $k => $s) {                         // input chevron caps (point right)
        [$top, $hh] = $inL[$k]; $mid = $top + $hh / 2.0;
        $cap(round($capL0, 2) . ',' . round($top, 2) . ' ' . round($bandL - 2, 2) . ',' . round($top, 2) . ' ' . round($bandL, 2) . ',' . round($mid, 2)
           . ' ' . round($bandL - 2, 2) . ',' . round($top + $hh, 2) . ' ' . round($capL0, 2) . ',' . round($top + $hh, 2) . ' ' . round($capL0 + 2, 2) . ',' . round($mid, 2), $BLUE, $s);
    }
    foreach ($rights as $k => $s) {                      // output pennant caps (arrowhead)
        [$top, $hh] = $outR[$k]; $mid = $top + $hh / 2.0;
        $cap(round($bandR, 2) . ',' . round($top, 2) . ' ' . round($capR1 - 3, 2) . ',' . round($top, 2) . ' ' . round($capR1, 2) . ',' . round($mid, 2)
           . ' ' . round($capR1 - 3, 2) . ',' . round($top + $hh, 2) . ' ' . round($bandR, 2) . ',' . round($top + $hh, 2), empty($s['fee']) ? $BLUE : $AMBER, $s);
    }
    return $svg . '</svg>';
}

function lx_tx_href(array $net, string $txid): string
{
    return lx_u($net) . '/tx/' . $txid;
}

function lx_block_href(array $net, string $hash): string
{
    return lx_u($net) . '/block/' . $hash;
}

function lx_addr_href(array $net, string $addr): string
{
    return lx_u($net) . '/address/' . rawurlencode($addr);
}

/** A confirmation badge for a tx/block status. */
function lx_status_badge(array $net, array $status): string
{
    if (empty($status['confirmed'])) {
        return '<span class="badge warn">Unconfirmed</span>';
    }
    // A confirmed tx whose height briefly failed to resolve (soft-RPC hiccup)
    // must not compute tip - 0 + 1 = tip+1 confirmations.
    if (!isset($status['block_height']) || $status['block_height'] === null) {
        return '<span class="badge ok">Confirmed</span>';
    }
    $tip = lx_tip_height($net);
    $conf = $tip - (int) $status['block_height'] + 1;
    if ($conf < 1) {
        $conf = 1;
    }
    return '<span class="badge ok">' . commas($conf) . ' conf</span>';
}

/** Short link to an address (or "unknown" for unspendable outputs). */
function lx_addr_cell(array $net, ?string $addr, string $type = ''): string
{
    if ($addr === null || $addr === '') {
        $label = $type === 'op_return' ? 'OP_RETURN'
            : ($type === 'witness_mweb_pegin' ? 'MWEB peg-in'
            : ($type === 'witness_mweb_hogaddr' ? 'MWEB HogEx'
            : ($type === 'p2pk' ? 'P2PK' : 'Unparsed script')));
        return '<span class="muted">' . h($label) . '</span>';
    }
    return '<a class="addr" href="' . h(lx_addr_href($net, $addr)) . '">' . h($addr) . '</a>';
}

/**
 * Friendly label for an Esplora scriptpubkey_type. electrs names segwit types by
 * their witness version (v0_p2wpkh, v1_p2tr, ...); this maps them to the familiar
 * P2WPKH / P2TR / ... abbreviations shown by mempool.space.
 */
function lx_spk_label(string $type): string
{
    static $m = [
        'v0_p2wpkh'            => 'P2WPKH',
        'v0_p2wsh'             => 'P2WSH',
        'v1_p2tr'              => 'P2TR',
        'p2pkh'                => 'P2PKH',
        'p2sh'                 => 'P2SH',
        'p2pk'                 => 'P2PK',
        'multisig'             => 'Multisig',
        'op_return'            => 'OP_RETURN',
        'mweb'                 => 'MWEB',
        'witness_mweb_pegin'   => 'MWEB peg-in',
        'witness_mweb_hogaddr' => 'MWEB HogEx',
        'unknown'              => 'Unknown',
        ''                     => 'Unknown',
    ];
    if (isset($m[$type])) {
        return $m[$type];
    }
    if (preg_match('/^v\d+_(.+)$/', $type, $mm)) {        // e.g. v2_p2wxx -> P2WXX
        return strtoupper($mm[1]);
    }
    if (preg_match('/^witness_v(\d+)_/', $type, $mm)) {   // raw electrs witness_vN_*
        return 'Witness v' . $mm[1];
    }
    return strtoupper($type);
}
