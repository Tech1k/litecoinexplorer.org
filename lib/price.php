<?php
/**
 * Live coin price (best-effort, server-side, CSP-safe). Fetches the Litecoin price
 * in one or more fiat currencies from a configurable source (CoinGecko by default)
 * and caches it briefly in the SQLite cache. Everything degrades to "no price" if
 * the source is disabled, unreachable, or returns junk - fiat displays simply hide.
 *
 * Server-side ONLY: the strict CSP (connect-src 'self') forbids the browser from
 * calling a third-party price API directly, so PHP fetches it and the UI either
 * renders it server-side or polls our own same-origin /api/v1/prices.
 *
 * Security: the base URL is config/constant only (never attacker-derived); the
 * response is treated as untrusted (shape-checked, numeric-coerced). A short
 * negative cache backs off when the source is slow/down.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Price config merged with defaults. */
function lx_price_config(): array
{
    static $default = ['usd', 'eur', 'gbp', 'cad', 'chf', 'aud', 'jpy'];   // mempool.space's fiat set
    $c = lx_config()['price'] ?? [];
    $cur = array_values(array_filter(array_map('strtolower', (array) ($c['currencies'] ?? $default))));
    return [
        'enabled'    => $c['enabled'] ?? true,
        'source'     => $c['source'] ?? 'coingecko',
        'base'       => rtrim((string) ($c['base'] ?? 'https://api.coingecko.com'), '/'),
        'coin_id'    => (string) ($c['coin_id'] ?? 'litecoin'),
        'currencies' => $cur ?: $default,
        'display'    => strtolower((string) ($c['display'] ?? 'usd')),
        'api_key'    => $c['api_key'] ?? null,     // optional CoinGecko demo/pro key
        'ttl'        => max(15, (int) ($c['ttl'] ?? 60)),
    ];
}

/** True when price display is available for this network. */
function lx_price_enabled(array $net): bool
{
    return ($net['coin'] ?? '') === 'ltc'
        && !empty(lx_price_config()['enabled'])
        && function_exists('curl_init');
}

/**
 * Current price snapshot, or null. Shape:
 *   ['ts'=>int, 'source'=>str, 'prices'=>['usd'=>float,...], 'change_24h'=>['usd'=>float,...]]
 * Cached ~ttl on success; short negative cache on failure so page loads don't stall.
 */
function lx_price(array $net): ?array
{
    if (!lx_price_enabled($net)) {
        return null;
    }
    $cfg = lx_price_config();
    $ck  = 'price:' . $cfg['coin_id'] . ':' . implode(',', $cfg['currencies']);
    $downKey = 'price:down';

    $hit = cache_get($ck);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        return is_array($d) ? $d : null;
    }
    if (cache_get($downKey) !== null) {
        return null;   // recent failure - skip the call, degrade to no-fiat
    }

    $snap = lx_price_fetch($cfg);
    if ($snap === null) {
        cache_set($downKey, '1', 30);
        return null;
    }
    cache_set($ck, json_encode($snap, JSON_UNESCAPED_SLASHES), $cfg['ttl']);
    return $snap;
}

/** One HTTP fetch from the configured source (CoinGecko simple/price shape). */
function lx_price_fetch(array $cfg): ?array
{
    if ($cfg['source'] !== 'coingecko') {
        return null;   // only the CoinGecko shape is implemented
    }
    $vs  = $cfg['currencies'];
    $url = $cfg['base'] . '/api/v3/simple/price?ids=' . rawurlencode($cfg['coin_id'])
         . '&vs_currencies=' . rawurlencode(implode(',', $vs)) . '&include_24hr_change=true';

    $headers = ['Accept: application/json'];
    if (is_string($cfg['api_key']) && $cfg['api_key'] !== '') {
        $headers[] = 'x-cg-demo-api-key: ' . $cfg['api_key'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'litecoinexplorer',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS      => 0,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if (!is_string($body) || $body === '' || strlen($body) > 100000) {
        return null;
    }
    $d = json_decode($body, true);
    if (!is_array($d) || !isset($d[$cfg['coin_id']]) || !is_array($d[$cfg['coin_id']])) {
        return null;
    }
    $row = $d[$cfg['coin_id']];
    $prices = []; $chg = [];
    foreach ($vs as $c) {
        if (isset($row[$c]) && is_numeric($row[$c])) {
            $prices[$c] = (float) $row[$c];
        }
        if (isset($row[$c . '_24h_change']) && is_numeric($row[$c . '_24h_change'])) {
            $chg[$c] = (float) $row[$c . '_24h_change'];
        }
    }
    if (!$prices) {
        return null;
    }
    return ['ts' => time(), 'source' => 'coingecko', 'prices' => $prices, 'change_24h' => $chg];
}

/**
 * Historical daily price series from CoinGecko's market_chart endpoint, so the
 * price chart works immediately without waiting for the local snapshot cron to
 * accumulate points. Returns [['t'=>unix, 'p'=>float], ...] oldest-first, or []
 * on failure. Cached 6h on success, 10min on failure (bounded retry). Server-side
 * only (same strict-CSP reasoning as lx_price_fetch); response treated as untrusted.
 */
function lx_price_market_chart(array $net, string $cur, int $days = 365): array
{
    if (!lx_price_enabled($net)) {
        return [];
    }
    $cfg = lx_price_config();
    if ($cfg['source'] !== 'coingecko') {
        return [];
    }
    $cur = strtolower($cur);
    $key = 'pmc:' . $cfg['coin_id'] . ':' . $cur . ':' . (int) $days;
    $hit = cache_get($key);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        return is_array($d) ? $d : [];
    }
    $url = $cfg['base'] . '/api/v3/coins/' . rawurlencode($cfg['coin_id'])
         . '/market_chart?vs_currency=' . rawurlencode($cur) . '&days=' . (int) $days . '&interval=daily';
    $headers = ['Accept: application/json'];
    if (is_string($cfg['api_key']) && $cfg['api_key'] !== '') {
        $headers[] = 'x-cg-demo-api-key: ' . $cfg['api_key'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'litecoinexplorer',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS      => 0,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $out = [];
    if (is_string($body) && $body !== '' && strlen($body) < 2000000) {
        $d = json_decode($body, true);
        if (is_array($d) && isset($d['prices']) && is_array($d['prices'])) {
            foreach ($d['prices'] as $pt) {
                if (is_array($pt) && count($pt) >= 2 && is_numeric($pt[0]) && is_numeric($pt[1])) {
                    $out[] = ['t' => (int) round($pt[0] / 1000), 'p' => (float) $pt[1]];
                }
            }
        }
    }
    cache_set($key, json_encode($out), $out ? 21600 : 600);
    return $out;
}

/** Display-currency price float, or null. */
function lx_price_now(array $net): ?float
{
    $p = lx_price($net);
    if (!$p || empty($p['prices'])) {
        return null;
    }
    $vs = lx_price_config()['display'];
    if (isset($p['prices'][$vs])) {
        return (float) $p['prices'][$vs];
    }
    $first = reset($p['prices']);
    return is_numeric($first) ? (float) $first : null;
}

/** 24h % change in the display currency, or null. */
function lx_price_change(array $net): ?float
{
    $p = lx_price($net);
    if (!$p) {
        return null;
    }
    $vs = lx_price_config()['display'];
    return isset($p['change_24h'][$vs]) ? (float) $p['change_24h'][$vs] : null;
}

/** Currency symbol for a lower-case code (falls back to the upper-case code). */
function lx_price_symbol(string $cur): string
{
    static $m = ['usd' => '$', 'eur' => "\xE2\x82\xAC", 'gbp' => "\xC2\xA3", 'jpy' => "\xC2\xA5",
                 'cad' => 'CA$', 'aud' => 'A$', 'chf' => 'CHF ', 'btc' => "\xE2\x82\xBF"];
    return $m[strtolower($cur)] ?? (strtoupper($cur) . ' ');
}

/**
 * Fiat string for integer satoshis at $price (display currency), or null when no
 * price. Scales decimals so small amounts stay legible.
 */
function lx_fiat_str(int $sat, ?float $price, ?string $cur = null): ?string
{
    if ($price === null || $price <= 0) {
        return null;
    }
    $cur = $cur ?? lx_price_config()['display'];
    $v   = $sat / 100000000 * $price;
    $sym = lx_price_symbol($cur);
    $abs = abs($v);
    if ($abs == 0.0)      { $s = '0.00'; }
    elseif ($abs >= 1)    { $s = number_format($v, 2); }
    elseif ($abs >= 0.01) { $s = number_format($v, 4); }
    else                  { $s = rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.'); }
    return $sym . $s;
}

/**
 * A fiat <span> carrying data-sat so the client-side currency selector (app.js) can
 * re-denominate it live. Empty string when price is unavailable. The "≈ " prefix is the
 * caller's; this returns just the value span.
 */
function lx_fiat_el(int $sat, ?float $price): string
{
    $s = lx_fiat_str($sat, $price);
    if ($s === null) {
        return '';
    }
    return '<span class="fiat" data-sat="' . $sat . '">' . h($s) . '</span>';
}

/** Display-currency price nearest a unix timestamp (from the hourly history), or null. */
function lx_price_at(array $net, int $ts, ?string $cur = null): ?float
{
    if (!function_exists('lx_price_history')) {
        return null;
    }
    $cur = strtolower($cur ?? lx_price_config()['display']);
    $h = lx_price_history($net, $cur, $ts);
    $pts = $h['prices'] ?? [];
    if (!$pts) {
        return null;
    }
    // The history is hourly; if the nearest recorded point is more than a day from the target
    // the timestamp is outside our recorded range (e.g. a block older than the price DB) - return
    // null rather than mislabel today's price as "value when mined".
    $p = $pts[0];
    if (!isset($p['time']) || abs((int) $p['time'] - $ts) > 90000) {
        return null;
    }
    $u = strtoupper($cur);
    return isset($p[$u]) ? (float) $p[$u] : null;
}

/** Formatted display-currency spot price, or null (e.g. "$65.42"). */
function lx_price_str(array $net): ?string
{
    $p = lx_price_now($net);
    if ($p === null) {
        return null;
    }
    return lx_price_symbol(lx_price_config()['display']) . number_format($p, $p < 1 ? 4 : 2);
}

/** mempool.space-compatible /api/v1/prices payload: {"time":unix,"USD":..,...}. */
function lx_prices_api(array $net): array
{
    $p = lx_price($net);
    $out = ['time' => $p['ts'] ?? time()];
    foreach (($p['prices'] ?? []) as $c => $v) {
        $out[strtoupper($c)] = $v;
    }
    return $out;
}

// ---- historical price (/api/v1/historical-price) --------------------------
// Populated by the snapshot cron (lx_price_record), one row per hour, kept long.

function lx_price_db(bool $create = false): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $cache = lx_config()['cache_db'] ?? null;
    $path = $cache ? dirname($cache) . '/price.sqlite' : null;
    if (!$path || (!$create && !is_file($path))) {
        return $pdo = null;
    }
    try {
        if ($create && !is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 2000');
        $db->exec('PRAGMA journal_mode = WAL');   // readers (charts/API) don't block on the cron writer

        // coin+hour bucket -> JSON {cur:price}. One row/hour keeps years compact.
        $db->exec('CREATE TABLE IF NOT EXISTS price_hist (coin TEXT NOT NULL, ts INTEGER NOT NULL, prices TEXT NOT NULL, PRIMARY KEY (coin, ts))');
        return $pdo = $db;
    } catch (Throwable $e) {
        return $pdo = null;
    }
}

/** Record the current price into the hourly history (called by the snapshot cron). */
function lx_price_record(array $net): bool
{
    if (!lx_price_enabled($net)) {
        return false;
    }
    $snap = lx_price($net);
    if (!$snap || empty($snap['prices'])) {
        return false;
    }
    $db = lx_price_db(true);
    if (!$db) {
        return false;
    }
    try {
        $hour = intdiv(time(), 3600) * 3600;
        $db->prepare('INSERT OR REPLACE INTO price_hist (coin, ts, prices) VALUES (?, ?, ?)')
           ->execute([$net['coin'], $hour, json_encode($snap['prices'], JSON_UNESCAPED_SLASHES)]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * GET /api/v1/historical-price[?currency=USD&timestamp=...]. mempool.space shape:
 * {prices:[{time, USD, EUR, ...}], exchangeRates:{USDEUR, ...}}. With ?timestamp the
 * single closest point is returned; with ?currency each point still carries all
 * currencies (mempool behaviour) but is filtered to time+that currency.
 */
/** GET /api/v1/historical-price - reads ?currency / ?timestamp then delegates. */
function lx_historical_price_api(array $net): array
{
    $cur = isset($_GET['currency']) && is_string($_GET['currency']) ? strtolower($_GET['currency']) : null;
    $ts  = isset($_GET['timestamp']) && ctype_digit((string) $_GET['timestamp']) ? (int) $_GET['timestamp'] : null;
    return lx_price_history($net, $cur, $ts);
}

/**
 * Price history as the mempool.space historical-price shape. $cur filters to one fiat
 * (else every point carries all currencies); $ts returns the single closest point.
 * Query-param-free so views (e.g. /charts) can call it without $_GET leaking in.
 */
function lx_price_history(array $net, ?string $cur = null, ?int $ts = null): array
{
    $out = ['prices' => [], 'exchangeRates' => new stdClass()];
    $db = lx_price_db(false);
    if (!$db) {
        return $out;
    }
    try {
        if ($ts !== null) {
            $st = $db->prepare('SELECT ts, prices FROM price_hist WHERE coin = ? ORDER BY ABS(ts - ?) ASC LIMIT 1');
            $st->execute([$net['coin'], $ts]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Newest 4000 points, returned oldest-first.
            $st = $db->prepare('SELECT ts, prices FROM price_hist WHERE coin = ? ORDER BY ts DESC LIMIT 4000');
            $st->execute([$net['coin']]);
            $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
        }
    } catch (Throwable $e) {
        return $out;
    }
    $latest = [];
    foreach ($rows as $r) {
        $p = json_decode($r['prices'], true);
        if (!is_array($p)) { continue; }
        $entry = ['time' => (int) $r['ts']];
        foreach ($p as $c => $v) {
            if ($cur !== null && $c !== $cur) { continue; }
            $entry[strtoupper($c)] = (float) $v;
        }
        $out['prices'][] = $entry;
        $latest = $p;
    }
    // exchangeRates = USD -> other fiat, derived from the newest stored point.
    if ($latest && isset($latest['usd']) && (float) $latest['usd'] > 0) {
        $usd = (float) $latest['usd'];
        $ex = [];
        foreach ($latest as $c => $v) {
            if ($c === 'usd') { continue; }
            $ex['USD' . strtoupper($c)] = round((float) $v / $usd, 6);
        }
        if ($ex) { $out['exchangeRates'] = $ex; }
    }
    return $out;
}
