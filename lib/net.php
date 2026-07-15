<?php
/**
 * Network registry. Static chain parameters for Litecoin mainnet, merged with the
 * per-network runtime config (rpc/electrum credentials) from config.php.
 *
 * This is a single-chain Litecoin mainnet explorer served at the site root, so
 * URLs carry no network prefix:
 *   /                    explorer home
 *   /block|tx|address/…  HTML views
 *   /api/…               Esplora / mempool.space REST API
 *
 * The registry keeps a 'slug' as an internal namespace (cache keys + the SQLite
 * `net` column); it never appears in a URL. ts_u()/ts_net_url() return '' so every
 * link is root-relative.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Static chain parameters, keyed by network slug. */
function ts_net_params(): array
{
    return [
        'ltc-mainnet' => [
            'slug'        => 'ltc-mainnet',
            'kind'        => 'utxo',          // utxo (selects data layer + views)
            'coin'        => 'ltc',
            'network'     => 'mainnet',       // drives MWEBscan overlay + address codec
            'path'        => 'ltc-mainnet',   // vestigial: root-level URLs (ts_u returns '')
            'label'       => 'Litecoin',
            'short'       => 'Litecoin',
            'ticker'      => 'LTC',
            'unit'        => 'LTC',
            'decimals'    => 8,
            'accent'      => '#4c84d6',       // Litecoin blue
            // address codec params (Litecoin mainnet, per Core chainparams.cpp)
            'p2pkh'       => 0x30,            // 48 -> 'L'
            'p2sh'        => 0x32,            // 50 -> 'M' (current default)
            'p2sh_alt'    => 0x05,            // 5  -> '3' (legacy Bitcoin-style, still valid)
            'bech32'      => 'ltc',           // native segwit: ltc1...
            'mweb_hrp'    => 'ltcmweb',       // MWEB stealth-address hrp: ltcmweb1... (display badge)
            'mweb_activation' => 2265984,     // MWEB soft-fork activation height (2022-05-19)
            'has_taproot' => true,            // Taproot active on LTC mainnet (May 2022, same upgrade as MWEB)
            // external cross-reference explorer (for "view elsewhere" links)
            'extern_tx'   => 'https://litecoinspace.org/tx/',
            'extern_block'=> 'https://litecoinspace.org/block/',
            'extern_name' => 'litecoinspace.org',
            // MWEBscan analysis overlay (round-trip linking / privacy scoring / entity
            // attribution) joined onto our boundary data by txid:vout. The public API is
            // rate-limited (60/min/IP) - override in config.php['mwebscan_api'] with a
            // keyed or allow-listed instance for production explorer traffic.
            'mwebscan_api' => 'https://mwebscan.com/api',
        ],
    ];
}

/**
 * Resolve a network slug to its full merged config (static params + runtime
 * rpc/electrum), or null if unknown or disabled.
 */
function ts_net(?string $slug): ?array
{
    if ($slug === null) {
        return null;
    }
    $params = ts_net_params();
    if (!isset($params[$slug])) {
        return null;
    }
    $cfg = ts_config()['networks'][$slug] ?? null;
    if (!$cfg || empty($cfg['enabled'])) {
        return null;
    }
    // Runtime config wins for rpc/electrum/mweb; static params for everything else.
    return array_merge($params[$slug], $cfg);
}

/**
 * The single enabled network for this root-served explorer. Returns the first
 * enabled net (there is only one), or null if none is configured/enabled.
 */
function ts_net_default(): ?array
{
    $nets = ts_networks();
    return $nets ? reset($nets) : null;
}

/** Resolve a coin + network to its net, e.g. ts_net_from_path('ltc','mainnet'). */
function ts_net_from_path(string $coin, string $network): ?array
{
    return ts_net($coin . '-' . $network);
}

/** All enabled networks, in display order. */
function ts_networks(): array
{
    $out = [];
    foreach (array_keys(ts_net_params()) as $slug) {
        $n = ts_net($slug);
        if ($n) {
            $out[$slug] = $n;
        }
    }
    return $out;
}

/** Absolute URL base for the current request (https + sane Host). */
function ts_base_url(): string
{
    $cfg = ts_config();
    $canonical = $cfg['canonical_host'] ?? 'litecoinexplorer.org';
    // Only reflect a Host we trust into canonical/OG URLs. An allowlist
    // prevents canonical/cache poisoning from a spoofed Host header.
    $allowed = array_merge([$canonical], $cfg['allowed_hosts'] ?? []);
    $host = $_SERVER['HTTP_HOST'] ?? $canonical;
    if (!in_array($host, $allowed, true)) {
        $host = $canonical;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
    return $scheme . '://' . $host;
}

/**
 * Root-relative UI base for a network. Empty string in single-chain root mode:
 * every caller appends '/segment...', so '' yields clean root URLs (e.g.
 * '' . '/block/<hash>' -> '/block/<hash>').
 */
function ts_net_url(array $net): string
{
    return '';
}
