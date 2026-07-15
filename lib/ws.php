<?php
/**
 * WebSocket state builders - the mempool.space-compatible push payloads that the WS
 * daemon (tools/ws-server.php) streams. Pure functions over the app's cached data
 * (litecoind RPC + Electrum via the same lib the HTTP API uses), so the daemon stays
 * thin. All the RFC6455 socket handling + the subscription/tick loop live in the daemon.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** getmempoolinfo (bitcoind shape) for the "mempoolInfo" / "stats" push. */
function ts_ws_mempoolinfo(array $net): array
{
    $mi = ts_rpc_soft($net, 'getmempoolinfo');
    return is_array($mi) ? $mi : ['loaded' => true, 'size' => 0, 'bytes' => 0];
}

/** Recent mempool txs (TransactionStripped) for the "transactions" push. */
function ts_ws_recent_txs(array $net): array
{
    $out = [];
    foreach (ts_mempool_recent($net) as $t) {
        $vs = (int) ($t['vsize'] ?? ceil(((int) ($t['weight'] ?? 0)) / 4));
        $fee = (int) ($t['fee'] ?? 0);
        $out[] = [
            'txid'  => (string) ($t['txid'] ?? ''),
            'fee'   => $fee,
            'vsize' => (float) $vs,
            'value' => (int) ($t['value'] ?? 0),
            'rate'  => $vs > 0 ? $fee / $vs : 0.0,
        ];
    }
    return $out;
}

/** A BlockExtended (Esplora block + mempool.space "extras") for a block hash. */
function ts_ws_block_extended(array $net, string $hash): ?array
{
    $b = ts_esplora_block($net, $hash);
    if (!$b) {
        return null;
    }
    $bs   = ts_block_stats($net, $hash, (int) ($b['height'] ?? 0));
    $pool = ts_block_pool($net, $hash);
    $b['extras'] = [
        'reward'    => $bs ? (int) ($bs['subsidy'] ?? 0) + (int) ($bs['total_fee'] ?? 0) : 0,
        'totalFees' => $bs ? (int) ($bs['total_fee'] ?? 0) : 0,
        'medianFee' => $bs ? (float) ($bs['med_feerate'] ?? 0) : 0.0,
        'feeRange'  => $bs ? [(float) ($bs['min_feerate'] ?? 0), (float) ($bs['med_feerate'] ?? 0), (float) ($bs['max_feerate'] ?? 0)] : [],
        'pool'      => ['name' => $pool['label'] ?? 'Unknown', 'slug' => ts_pool_slug($pool['label'] ?? 'unknown')],
    ];
    return $b;
}

/** The last $n blocks as BlockExtended, newest-first. */
function ts_ws_recent_blocks(array $net, int $n = 8): array
{
    $out = [];
    foreach (ts_recent_blocks($net, null, $n) as $b) {
        $hash = (string) ($b['id'] ?? $b['hash'] ?? '');
        $ext  = $hash !== '' ? ts_ws_block_extended($net, $hash) : null;
        $out[] = $ext ?: $b;
    }
    return $out;
}

/** conversions (fiat price) push, or null when price is disabled/unavailable. */
function ts_ws_conversions(array $net): ?array
{
    $p = ts_prices_api($net);
    return count($p) > 1 ? $p : null;   // {time, USD, EUR, ...}; just {time} => unavailable
}

/**
 * The recurring "state" push (mempool stats + fees + projected blocks + recent txs),
 * gated by what the client asked for via {"action":"want", ...}. $vbps is the measured
 * mempool ingress (vB/s) the daemon computes across ticks.
 */
function ts_ws_state(array $net, array $wants, float $vbps = 0.0): array
{
    $out  = [];
    $want = array_flip($wants);
    if (isset($want['stats'])) {
        $out['mempoolInfo']     = ts_ws_mempoolinfo($net);
        $out['vBytesPerSecond'] = (int) round($vbps);
        try { $out['fees'] = ts_fees_recommended($net); } catch (Throwable $e) {}
        $out['transactions']    = ts_ws_recent_txs($net);
    }
    if (isset($want['mempool-blocks'])) {
        $out['mempool-blocks'] = ts_mempool_blocks_api($net);
    }
    return $out;
}

/** The initial full-state snapshot sent on {"action":"init"} (and after "want"). */
function ts_ws_init(array $net, array $wants): array
{
    $out = ts_ws_state($net, $wants);
    if (in_array('blocks', $wants, true) || !$wants) {
        $out['blocks'] = ts_ws_recent_blocks($net, 8);
    }
    $out['da'] = ts_difficulty_adjustment($net);
    $conv = ts_ws_conversions($net);
    if ($conv) {
        $out['conversions'] = $conv;
    }
    $out['backend']     = 'electrum';
    $out['backendInfo'] = [
        'hostname'  => ts_config()['canonical_host'] ?? 'litecoinexplorer.org',
        'version'   => 'litecoinexplorer',
        'gitCommit' => '',
        'lightning' => false,
    ];
    $out['loadingIndicators'] = ['mempool' => 100];
    return $out;
}

/**
 * Current mempool txid set as {txid => true} for the track-mempool-txids delta stream, or
 * null on RPC failure (so the daemon doesn't fabricate a "whole mempool removed" delta -
 * an empty mempool returns []). getrawmempool(false) yields a plain txid array.
 */
function ts_ws_mempool_txids(array $net): ?array
{
    $ids = ts_rpc_soft($net, 'getrawmempool', [false]);
    if (!is_array($ids)) {
        return null;
    }
    $set = [];
    foreach ($ids as $t) { if (is_string($t)) { $set[$t] = true; } }
    return $set;
}

/** Last ~2h of mempool snapshots (count + vsize per point) for the live-2h-chart stream. */
function ts_ws_2h_chart(array $net): array
{
    $series = function_exists('ts_stats_series') ? ts_stats_series($net, 2) : [];
    $out = [];
    foreach ($series as $s) {
        $out[] = ['time' => (int) ($s['ts'] ?? 0), 'count' => (int) ($s['mempool_count'] ?? 0), 'vsize' => (int) ($s['mempool_vsize'] ?? 0)];
    }
    return $out;
}
