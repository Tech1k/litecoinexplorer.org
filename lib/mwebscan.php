<?php
/**
 * MWEBscan API client - the chain-analysis overlay for Litecoin MWEB.
 *
 * Litecoin Explorer owns the public boundary (peg-ins/outs, supply, kernels) from
 * its OWN node; this layer fetches MWEBscan's ANALYSIS (round-trip linking, privacy
 * scoring, entity attribution) and the UI joins it on by txid:vout. Everything is
 * best-effort and config-gated: with no `mwebscan_api` base configured for the
 * network, every call returns null and the MWEB surfaces degrade to boundary-only.
 *
 * Security: the base URL is config/constant only (never attacker-derived);
 * forwarded params are whitelisted + validated; responses are treated as
 * untrusted (network-asserted, shape-checked, all rendered through h()).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** MWEBscan network for a net ('testnet'|'mainnet'), or null (not LTC). */
function lx_mwebscan_network(array $net): ?string
{
    if (($net['coin'] ?? '') !== 'ltc') {
        return null;
    }
    $n = (string) ($net['network'] ?? '');
    return ($n === 'mainnet' || $n === 'testnet') ? $n : null;
}

/** Config/constant-only API base for this network, or null (dormant). */
function lx_mwebscan_api_base(array $net): ?string
{
    $base = $net['mwebscan_api'] ?? null;
    if (!is_string($base) || $base === '') {
        $cfg = lx_config()['mwebscan_api'] ?? null;
        if (is_array($cfg)) {
            $base = $cfg[$net['slug']] ?? null;
        } elseif (is_string($cfg)) {
            $base = $cfg;
        }
    }
    return (is_string($base) && $base !== '') ? rtrim($base, '/') : null;
}

/** True when the MWEBscan overlay can be fetched for this network. */
function lx_mwebscan_enabled(array $net): bool
{
    return lx_mwebscan_network($net) !== null
        && lx_mwebscan_api_base($net) !== null
        && function_exists('curl_init');
}

/**
 * GET an MWEBscan endpoint with validated params. Returns the decoded response
 * array (which carries the versioned envelope), or null on any failure (dormant /
 * transport / timeout / non-JSON / network mismatch). A short negative cache backs
 * off when the service is unreachable so page loads don't stall on every request.
 */
function lx_mwebscan_api(array $net, string $endpoint, array $params = [], int $ttl = 60): ?array
{
    if (!lx_mwebscan_enabled($net)) {
        return null;
    }
    $base = lx_mwebscan_api_base($net);
    $want = lx_mwebscan_network($net);

    $ep = trim($endpoint, '/');
    if (!preg_match('/^[a-z][a-z_]*$/', $ep)) {   // fixed endpoint names only - no path injection
        return null;
    }

    // Whitelist + validate every forwarded param before it leaves our process.
    $clean = [];
    foreach ($params as $k => $v) {
        if (!in_array($k, ['q', 'amount', 'limit', 'min_confidence', 'depth'], true)) {
            continue;
        }
        $v = (string) $v;
        if ($v === '' || strlen($v) > 120) {
            continue;
        }
        if ($k === 'q' && !preg_match('/^[0-9A-Za-z]{1,110}$/', $v)) {
            continue;   // txid (hex) / LTC address (bech32/base58) / height - alphanumeric only
        }
        if (($k === 'limit' || $k === 'depth') && !ctype_digit($v)) {
            continue;
        }
        if (($k === 'amount' || $k === 'min_confidence') && !is_numeric($v)) {
            continue;
        }
        $clean[$k] = $v;
    }
    ksort($clean);
    $qs  = $clean ? '?' . http_build_query($clean) : '';
    $url = $base . '/' . $ep . $qs;

    $slug     = $net['slug'];
    $cacheKey = 'mwscan:' . $slug . ':' . md5($ep . $qs);
    $downKey  = 'mwscan:down:' . $slug;

    // Serve a cached response even while the service is slow/down; only the actual
    // network call is gated by the backoff key.
    $hit = cache_get($cacheKey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        return is_array($d) ? $d : null;
    }
    if (cache_get($downKey) !== null) {
        return null;   // recent failure/slow response - skip the call, degrade to boundary-only
    }

    $cfg     = lx_config();
    $headers = ['Accept: application/json'];
    $apiKey  = $cfg['mwebscan_api_key'] ?? null;
    if (is_array($apiKey)) {
        $apiKey = $apiKey[$slug] ?? null;
    }
    if (is_string($apiKey) && $apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
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
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $secs = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    if (!is_string($body) || $body === '' || strlen($body) > 3000000) {
        cache_set($downKey, '1', 20);
        return null;
    }
    $d = json_decode($body, true);
    // Untrusted response: must be JSON, the right instance, and the contract version
    // we code against. Anything else -> back off and degrade.
    if (!is_array($d) || ($d['network'] ?? null) !== $want || ($d['version'] ?? null) !== '1') {
        cache_set($downKey, '1', 20);
        return null;
    }
    // Slow-but-alive upstream: back off so the other surfaces in this request (and
    // nearby requests) skip the network and serve boundary-only rather than stacking
    // multi-second waits.
    if ($secs > 2.5) {
        cache_set($downKey, '1', 20);
    }
    $d['_http'] = $code;
    cache_set($cacheKey, json_encode($d, JSON_UNESCAPED_SLASHES), $ttl);
    return $d;
}

/** Normalize a "reasons" field (a string[] in /links, a JSON-string in /trace) to string[]. */
function lx_mwebscan_reasons($x): array
{
    if (is_array($x)) {
        return array_values(array_filter($x, 'is_string'));
    }
    if (is_string($x) && $x !== '') {
        $d = json_decode($x, true);
        if (is_array($d)) {
            return array_values(array_filter($d, 'is_string'));
        }
        return [$x];
    }
    return [];
}

/** "block N · updated N ago" freshness line from any response envelope, or ''. */
function lx_mwebscan_freshness(?array $resp): string
{
    if (!is_array($resp)) {
        return '';
    }
    $h = isset($resp['as_of_height']) ? (int) $resp['as_of_height'] : 0;
    $u = isset($resp['updated_at']) ? (int) $resp['updated_at'] : 0;
    $parts = [];
    if ($h > 0) { $parts[] = 'block ' . commas($h); }
    if ($u > 0) { $parts[] = 'updated ' . time_ago($u); }
    return $parts ? implode(' · ', $parts) : '';
}

/** Human MWEBscan site URL (API base minus /api), for links + required attribution. */
function lx_mwebscan_site(array $net): string
{
    $base = lx_mwebscan_api_base($net);
    if ($base === null) {
        return 'https://mwebscan.com';
    }
    return preg_replace('#/api/?$#', '', $base);
}
