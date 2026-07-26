<?php
/**
 * Mining-pool attribution from coinbase scriptSig tags, mempool.space-style.
 * A pool stamps a recognisable ASCII marker into the coinbase input (e.g.
 * "/F2Pool/", "/ViaBTC/"); we extract the printable runs and match them against
 * a known-tag table. On TESTNET many blocks are mined by unlabelled / one-off
 * miners, so a large share resolves to a raw tag or "Unknown" - that is
 * expected and reported honestly rather than guessed.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Lower-case coinbase-tag substring -> canonical pool name. */
function lx_pool_map(): array
{
    static $m = [
        'foundry'         => 'Foundry USA',
        'antpool'         => 'AntPool',
        'f2pool'          => 'F2Pool',
        'viabtc'          => 'ViaBTC',
        'binance'         => 'Binance Pool',
        'slush'           => 'Braiins Pool',
        'braiins'         => 'Braiins Pool',
        'luxor'           => 'Luxor',
        'sbicrypto'       => 'SBI Crypto',
        'sbi crypto'      => 'SBI Crypto',
        'marapool'        => 'MARA Pool',
        'mara pool'       => 'MARA Pool',
        'mara_pool'       => 'MARA Pool',
        'carbon negative' => 'MARA Pool',
        'spiderpool'      => 'SpiderPool',
        'btc.com'         => 'BTC.com',
        'btccom'          => 'BTC.com',
        'poolin'          => 'Poolin',
        'ultimus'         => 'ULTIMUSPOOL',
        'ocean.xyz'       => 'OCEAN',
        'secpool'         => 'SECPOOL',
        'whitepool'       => 'WhitePool',
        'nicehash'        => 'NiceHash',
        'bitfarms'        => 'Bitfarms',
        'kanopool'        => 'KanoPool',
        'litecoinpool'    => 'Litecoinpool.org',
        'prohashing'      => 'Prohashing',
        'emcd'            => 'EMCD',
        'rawpool'         => 'Rawpool',
        'solo.ckpool'     => 'Solo CKPool',
        'ckpool'          => 'CKPool',
        'mining-dutch'    => 'Mining-Dutch',
        'zsolo'           => 'zSolo',
        'c2pool'          => 'c2pool',
    ];
    return $m;
}

/** Printable-ASCII runs (>=3 chars) joined by spaces, from a coinbase scriptSig hex. */
function lx_coinbase_ascii(string $hex): string
{
    $bin = @hex2bin($hex);
    if ($bin === false || $bin === '') {
        return '';
    }
    $runs = [];
    $cur  = '';
    $len  = strlen($bin);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($bin[$i]);
        if ($c >= 0x20 && $c < 0x7f) {
            $cur .= $bin[$i];
        } else {
            if (strlen($cur) >= 3) { $runs[] = $cur; }
            $cur = '';
        }
    }
    if (strlen($cur) >= 3) { $runs[] = $cur; }
    return implode(' ', $runs);
}

/**
 * Attribute a block to a mining pool from its coinbase. Returns
 * ['pool'=>?string, 'label'=>string, 'tag'=>string]: pool = matched canonical
 * name or null; label = pool name, else the cleanest coinbase tag, else
 * 'Unknown'; tag = the raw extracted ASCII. Cached per block hash (immutable
 * once buried; short TTL near the tip for reorg safety).
 */
function lx_block_pool(array $net, string $hash): array
{
    $ckey = 'pool:' . $net['slug'] . ':' . $hash;
    $hit  = cache_get($ckey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) { return $d; }
    }
    $unknown = ['pool' => null, 'label' => 'Unknown', 'tag' => ''];
    $txids = lx_block_txids($net, $hash);
    if (!$txids) {
        return $unknown;
    }
    $cb = lx_esplora_tx($net, $txids[0], $hash);
    if (!$cb || empty($cb['vin'][0]['is_coinbase'])) {
        return $unknown;
    }
    $ascii = lx_coinbase_ascii($cb['vin'][0]['scriptsig'] ?? '');
    $lc = strtolower($ascii);
    $pool = null;
    foreach (lx_pool_map() as $needle => $name) {
        if ($needle !== '' && strpos($lc, $needle) !== false) {
            $pool = $name;
            break;
        }
    }
    $label = $pool;
    if ($label === null) {
        $best = '';
        foreach (explode(' ', $ascii) as $run) {
            if (strlen($run) > strlen($best) && !ctype_digit($run)) { $best = $run; }
        }
        $best = trim($best, "/\\ \t");
        // Group "miner/worker" tags under the base miner, so one miner's per-worker
        // suffixes (e.g. c2pool/n, c2pool/w) don't each count as a separate pool.
        $slash = strpos($best, '/');
        if ($slash !== false && $slash >= 3) {
            $best = substr($best, 0, $slash);
        }
        // Only surface a raw coinbase run as a miner label when it actually looks
        // like an identifier: >=5 chars, starts alphanumeric, and is made of tag
        // characters. This rejects the binary-junk fragments the printable-run
        // scan otherwise picks up on testnet (e.g. "}Kj", "@Kj", "0fKj"), which
        // would each masquerade as a separate one-block "pool".
        $label = (strlen($best) >= 5 && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/+-]{4,}$/', $best))
            ? $best : 'Unknown';
    }
    $out = ['pool' => $pool, 'label' => $label, 'tag' => $ascii];
    $h = lx_block_height_for_hash($net, $hash);
    $depth = $h !== null ? lx_tip_height($net) - $h : 999;
    cache_set($ckey, json_encode($out, JSON_UNESCAPED_SLASHES), $depth > 100 ? 0 : 600);
    return $out;
}

/**
 * Mining distribution over the last $window blocks: newest-inclusive tally by
 * pool/miner label. Returns ['window'=>int, 'pools'=>[['name','count','pct'],..]]
 * sorted by count desc. Cached ~2 min (per-block attribution is cached forever).
 */
function lx_mining_distribution(array $net, int $window = 50): array
{
    $window = max(10, min(200, $window));
    return cache_remember('minedist:' . $net['slug'] . ':' . $window, 120, function () use ($net, $window) {
        $tip = lx_tip_height($net);
        $tally = [];
        $seen = 0;
        for ($h = $tip; $h > $tip - $window && $h >= 0; $h--) {
            $hash = lx_block_hash_at($net, $h);
            if ($hash === null) { break; }
            $p = lx_block_pool($net, $hash);
            $name = $p['label'];
            $tally[$name] = ($tally[$name] ?? 0) + 1;
            $seen++;
        }
        if ($seen === 0) {
            return ['window' => 0, 'pools' => []];
        }
        arsort($tally);
        $pools = [];
        foreach ($tally as $name => $count) {
            $pools[] = ['name' => $name, 'count' => $count, 'pct' => $count / $seen * 100];
        }
        return ['window' => $seen, 'pools' => $pools];
    });
}

/**
 * Blocks attributed to one pool/miner label over the last $window blocks
 * (newest-first), for the pool detail page. Per-block attribution is cached, so
 * this is cheap after the distribution has been built. Returns a list of
 * ['height', 'hash', 'tag'].
 */
function lx_pool_blocks(array $net, string $label, int $window = 100): array
{
    $window = max(10, min(300, $window));
    // Cache the window walk (per-block attribution is itself cached, so this is
    // mostly a re-tally) so repeated loads of a pool page don't re-walk each time.
    $key = 'poolblocks:' . $net['slug'] . ':' . $window . ':' . md5($label);
    $cached = cache_remember($key, 120, function () use ($net, $label, $window) {
        $tip = lx_tip_height($net);
        $out = [];
        for ($h = $tip; $h > $tip - $window && $h >= 0; $h--) {
            $hash = lx_block_hash_at($net, $h);
            if ($hash === null) {
                break;
            }
            $p = lx_block_pool($net, $hash);
            if ($p['label'] === $label) {
                $out[] = ['height' => $h, 'hash' => $hash, 'tag' => $p['tag']];
            }
        }
        return $out;
    });
    return is_array($cached) ? $cached : [];
}
