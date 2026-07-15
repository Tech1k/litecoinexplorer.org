<?php
/**
 * Esplora / mempool.space response builders.
 *
 * These translate Core RPC (blocks, tx, mempool, fees, broadcast) + electrs
 * (address scripthash index) into the exact JSON shapes that blockstream's
 * Esplora and mempool.space return, so wallets are a drop-in.
 *
 * All monetary values are integer satoshis. Caching: confirmed tx and block
 * bodies are immutable for a given hash, so they're memoized with no expiry;
 * tips/fees use short TTLs.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// ---- chain tip ------------------------------------------------------------

function ts_tip_height(array $net): int
{
    return cache_remember('tiph:' . $net['slug'], 5, function () use ($net) {
        return (int) ts_rpc($net, 'getblockcount');
    });
}

function ts_tip_hash(array $net): string
{
    return cache_remember('tiphash:' . $net['slug'], 5, function () use ($net) {
        return (string) ts_rpc($net, 'getbestblockhash');
    });
}

function ts_block_hash_at(array $net, int $height): ?string
{
    if ($height < 0) {
        return null;
    }
    $key = 'h2h:' . $net['slug'] . ':' . $height;
    // Hash-at-height is mutable across reorgs near the tip; short TTL.
    $hit = cache_get($key);
    if ($hit !== null) {
        return $hit;
    }
    $hash = ts_rpc_soft($net, 'getblockhash', [$height]);
    if (!is_string($hash)) {
        return null;
    }
    // Near-tip heights can reorg, so cache briefly; deeper heights are stable.
    $ttl = ($height > ts_tip_height($net) - 10) ? 3 : 30;
    cache_set($key, $hash, $ttl);
    return $hash;
}

/**
 * Batch height->hash resolution. Reuses the same per-height cache (keys + TTL) as
 * ts_block_hash_at, then fetches all misses in ONE getblockhash round-trip. This
 * collapses the home page's ~10-12 serial getblockhash calls (3s near-tip TTL)
 * into a single request. Returns [height => hash]; unresolved heights are omitted.
 */
function ts_block_hashes(array $net, array $heights): array
{
    $out = [];
    $miss = [];
    foreach ($heights as $h) {
        if ($h < 0) { continue; }
        $hit = cache_get('h2h:' . $net['slug'] . ':' . $h);
        if ($hit !== null) { $out[$h] = $hit; } else { $miss[] = $h; }
    }
    if ($miss) {
        $tip = ts_tip_height($net);
        $calls = [];
        foreach ($miss as $h) { $calls[] = ['getblockhash', [$h]]; }
        $res = ts_rpc_batch($net, $calls);
        foreach ($miss as $i => $h) {
            $hash = $res[$i] ?? null;
            if (is_string($hash)) {
                $out[$h] = $hash;
                cache_set('h2h:' . $net['slug'] . ':' . $h, $hash, ($h > $tip - 10) ? 3 : 30);
            }
        }
    }
    return $out;
}

function ts_block_height_for_hash(array $net, string $hash): ?int
{
    $key = 'bh:' . $net['slug'] . ':' . $hash;
    $hit = cache_get($key);
    if ($hit !== null) {
        return (int) $hit;
    }
    $hdr = ts_rpc_soft($net, 'getblockheader', [$hash, true]);
    if (!is_array($hdr) || !isset($hdr['height'])) {
        return null;
    }
    cache_set($key, (string) $hdr['height'], 0);
    return (int) $hdr['height'];
}

function ts_block_time_at(array $net, string $hash): ?int
{
    $key = 'btime:' . $net['slug'] . ':' . $hash;
    $hit = cache_get($key);
    if ($hit !== null) {
        return (int) $hit;
    }
    $hdr = ts_rpc_soft($net, 'getblockheader', [$hash, true]);
    if (!is_array($hdr) || !isset($hdr['time'])) {
        return null;
    }
    cache_set($key, (string) $hdr['time'], 0);
    return (int) $hdr['time'];
}

/** Map an electrs listunspent row to an Esplora utxo (with confirmed block info). */
function ts_map_utxo_row(array $net, array $u): array
{
    $height = (int) ($u['height'] ?? 0);
    $status = ['confirmed' => $height > 0];
    if ($height > 0) {
        $status['block_height'] = $height;
        $bh = ts_block_hash_at($net, $height);
        if ($bh !== null) {
            $status['block_hash'] = $bh;
            $bt = ts_block_time_at($net, $bh);
            if ($bt !== null) {
                $status['block_time'] = $bt;
            }
        }
    }
    return [
        'txid'   => $u['tx_hash'],
        'vout'   => (int) $u['tx_pos'],
        'value'  => (int) $u['value'],
        'status' => $status,
    ];
}

// ---- scriptPubKey type mapping --------------------------------------------

/** Map Core scriptPubKey.type to the Esplora vocabulary. */
function ts_map_spk_type(string $coreType): string
{
    static $m = [
        'pubkeyhash'            => 'p2pkh',
        'scripthash'            => 'p2sh',
        'witness_v0_keyhash'    => 'v0_p2wpkh',
        'witness_v0_scripthash' => 'v0_p2wsh',
        'witness_v1_taproot'    => 'v1_p2tr',
        'witness_unknown'       => 'unknown',
        'nulldata'              => 'op_return',
        'multisig'              => 'multisig',
        'pubkey'                => 'p2pk',
        'anchor'                => 'anchor',
        'nonstandard'           => 'unknown',
        // Litecoin MWEB output types. Preserved verbatim (not normalized to
        // 'unknown') so the LTC lane can flag peg-ins and the HogEx. These only
        // ever appear on Litecoin MWEB outputs, so BTC responses are unchanged.
        'witness_mweb_pegin'    => 'witness_mweb_pegin',
        'witness_mweb_hogaddr'  => 'witness_mweb_hogaddr',
    ];
    return $m[$coreType] ?? 'unknown';
}

/** Map a Core scriptPubKey (+value) into an Esplora vin.prevout / vout entry. */
function ts_map_spk(array $spk, $value): array
{
    $addr = $spk['address'] ?? ($spk['addresses'][0] ?? null);
    $out = [
        'scriptpubkey'      => $spk['hex'] ?? '',
        'scriptpubkey_asm'  => $spk['asm'] ?? '',
        'scriptpubkey_type' => ts_map_spk_type($spk['type'] ?? 'nonstandard'),
    ];
    // Esplora omits scriptpubkey_address entirely when the script has no
    // decodable address (op_return, bare multisig, p2pk, ...). Never null.
    if ($addr !== null) {
        $out['scriptpubkey_address'] = $addr;
    }
    $out['value'] = coin_to_sat($value);
    return $out;
}

// ---- transaction ----------------------------------------------------------

/** Fetch Core's verbose decoded tx, with a blockhash hint for no-txindex nodes. */
function ts_get_tx_verbose(array $net, string $txid, ?string $blockhash = null): ?array
{
    $verbosity = !empty($net['rpc']['verbosity2']) ? 2 : true;
    $params = [$txid, $verbosity];
    if ($blockhash !== null) {
        $params[] = $blockhash;
    }
    $res = ts_rpc_soft($net, 'getrawtransaction', $params);
    if ($res === null && $verbosity === 2) {
        // Node rejected verbosity 2 (older Core / litecoind): retry plain verbose.
        $params = [$txid, true];
        if ($blockhash !== null) {
            $params[] = $blockhash;
        }
        $res = ts_rpc_soft($net, 'getrawtransaction', $params);
    }
    return is_array($res) ? $res : null;
}

/** Resolve any vin prevouts that weren't inlined (no verbosity-2 support). */
function ts_resolve_prevouts(array $net, array &$vin): void
{
    $need = [];           // distinct prev txid -> list of vin indexes
    foreach ($vin as $i => $in) {
        if ($in['is_coinbase'] || $in['prevout'] !== null) {
            continue;
        }
        $need[$in['txid']][] = $i;
    }
    if (!$need) {
        return;
    }
    $txids = array_keys($need);
    $calls = [];
    foreach ($txids as $tid) {
        $calls[] = ['getrawtransaction', [$tid, true]];
    }
    $results = ts_rpc_batch($net, $calls);
    foreach ($txids as $k => $tid) {
        $prevtx = $results[$k];
        if (!is_array($prevtx) || !isset($prevtx['vout'])) {
            continue;
        }
        foreach ($need[$tid] as $i) {
            $voutN = $vin[$i]['vout'];
            if (isset($prevtx['vout'][$voutN])) {
                $pv = $prevtx['vout'][$voutN];
                $vin[$i]['prevout'] = ts_map_spk($pv['scriptPubKey'] ?? [], $pv['value'] ?? 0);
            }
        }
    }
}

/**
 * Map a Core verbose tx into the Esplora tx object. When $deferPrevouts is true
 * the per-tx prevout batch is skipped, leaving unresolved vin['prevout'] === null
 * (and fee possibly 0) so a caller can resolve prevouts for many txs in ONE batch
 * via ts_resolve_prevouts_multi(). The single-tx path leaves it false.
 */
function ts_map_tx(array $net, array $t, bool $deferPrevouts = false): array
{
    $coinbaseTx = false;
    $vin = [];
    foreach ($t['vin'] as $idx => $in) {
        if (isset($in['coinbase'])) {
            $coinbaseTx = true;
            $vin[$idx] = [
                'txid'          => str_repeat('0', 64),
                'vout'          => 0xffffffff,
                'prevout'       => null,
                'scriptsig'     => $in['coinbase'],
                'scriptsig_asm' => '',
                'witness'       => $in['txinwitness'] ?? [],
                'is_coinbase'   => true,
                'sequence'      => (int) ($in['sequence'] ?? 0),
            ];
            continue;
        }
        $item = [
            'txid'          => $in['txid'],
            'vout'          => (int) $in['vout'],
            'prevout'       => null,
            'scriptsig'     => $in['scriptSig']['hex'] ?? '',
            'scriptsig_asm' => $in['scriptSig']['asm'] ?? '',
            'witness'       => $in['txinwitness'] ?? [],
            'is_coinbase'   => false,
            'sequence'      => (int) ($in['sequence'] ?? 0),
        ];
        if (isset($in['prevout']) && is_array($in['prevout'])) {
            $po = $in['prevout'];
            $item['prevout'] = ts_map_spk($po['scriptPubKey'] ?? [], $po['value'] ?? 0);
        }
        $vin[$idx] = $item;
    }
    ksort($vin);
    $vin = array_values($vin);

    if (!$deferPrevouts) {
        ts_resolve_prevouts($net, $vin);
    }

    $vout = [];
    foreach ($t['vout'] as $o) {
        $vout[] = ts_map_spk($o['scriptPubKey'] ?? [], $o['value'] ?? 0);
    }

    // fee
    $fee = 0;
    if ($coinbaseTx) {
        $fee = 0;
    } elseif (isset($t['fee'])) {
        $fee = coin_to_sat($t['fee']);
    } else {
        $inSum = 0;
        $haveAll = true;
        foreach ($vin as $vi) {
            if ($vi['prevout'] === null) {
                $haveAll = false;
                break;
            }
            $inSum += $vi['prevout']['value'];
        }
        if ($haveAll) {
            $outSum = 0;
            foreach ($vout as $vo) {
                $outSum += $vo['value'];
            }
            $fee = max(0, $inSum - $outSum);
        }
    }

    // status
    $status = ['confirmed' => false];
    if (!empty($t['blockhash'])) {
        $status = [
            'confirmed'    => true,
            'block_height' => ts_block_height_for_hash($net, $t['blockhash']),
            'block_hash'   => $t['blockhash'],
            'block_time'   => (int) ($t['blocktime'] ?? $t['time'] ?? 0),
        ];
    }

    $weight = isset($t['weight'])
        ? (int) $t['weight']
        : (int) (($t['vsize'] ?? $t['size'] ?? 0) * 4);

    return [
        'txid'     => $t['txid'],
        'version'  => (int) ($t['version'] ?? 1),
        'locktime' => (int) ($t['locktime'] ?? 0),
        'vin'      => $vin,
        'vout'     => $vout,
        'size'     => (int) ($t['size'] ?? 0),
        'weight'   => $weight,
        'fee'      => $fee,
        'status'   => $status,
    ];
}

/**
 * Cache a mapped tx body iff it is confirmed with a known height and its fee is
 * reliable (every non-coinbase input resolved). Reorg-safe TTL: deep
 * confirmations are immutable (forever), near-tip may reorg (short TTL). Never
 * poisons the cache with a transient null height or an unresolved-prevout fee.
 * Shared by the single-tx and batched builders so the gate can never drift.
 */
function ts_cache_tx_if_confirmed(array $net, string $txid, array $tx): void
{
    if (empty($tx['status']['confirmed']) || $tx['status']['block_height'] === null) {
        return;
    }
    foreach ($tx['vin'] as $vi) {
        if (empty($vi['is_coinbase']) && $vi['prevout'] === null) {
            return;   // fee unreliable
        }
    }
    $depth = ts_tip_height($net) - (int) $tx['status']['block_height'];
    // Cap even deep confirmations at 1 day rather than forever: this cache is
    // keyed by TXID (not block hash), so a deep reorg that moves a tx to a
    // different block would otherwise be pinned permanently. A finite TTL
    // self-heals within the cap.
    cache_set('etx:' . $net['slug'] . ':' . $txid, json_encode($tx, JSON_UNESCAPED_SLASHES), $depth > 100 ? 86400 : 600);
}

/** Build (and cache, if confirmed) the Esplora tx for a txid. */
function ts_esplora_tx(array $net, string $txid, ?string $blockhash = null): ?array
{
    $ckey = 'etx:' . $net['slug'] . ':' . $txid;
    $hit = cache_get($ckey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) {
            return $d;
        }
    }
    $vtx = ts_get_tx_verbose($net, $txid, $blockhash);
    if (!$vtx) {
        return null;
    }
    $tx = ts_map_tx($net, $vtx);
    ts_cache_tx_if_confirmed($net, $txid, $tx);
    return $tx;
}

/**
 * Build Esplora tx objects for MANY txids in as few RPC round-trips as possible:
 * a cache pass, then ONE getrawtransaction batch for the misses, then ONE
 * cross-tx prevout batch (the no-verbosity-2 / LTC lane) instead of one per tx.
 * Returns a txid => tx map; missing/unresolvable txids are simply absent, and
 * callers preserve their own ordering. Confirmed bodies are cached via the same
 * gate as ts_esplora_tx. This collapses the former 25-serial-RPC list pages
 * (block/address/scripthash txs) to 1 (BTC verbosity-2) or 1+1 (LTC) round-trips.
 */
function ts_esplora_txs(array $net, array $txids, ?string $blockhash = null): array
{
    $out  = [];
    $miss = [];
    foreach ($txids as $txid) {
        $hit = cache_get('etx:' . $net['slug'] . ':' . $txid);
        if ($hit !== null) {
            $d = json_decode($hit, true);
            if (is_array($d)) {
                $out[$txid] = $d;
                continue;
            }
        }
        $miss[$txid] = true;   // dedup while preserving membership
    }
    if (!$miss) {
        return $out;
    }
    $missTxids = array_keys($miss);

    // 1) One getrawtransaction batch. Verbosity 2 (BTC) inlines prevouts + fee.
    $verbosity = !empty($net['rpc']['verbosity2']) ? 2 : true;
    $calls = [];
    foreach ($missTxids as $tid) {
        $p = [$tid, $verbosity];
        if ($blockhash !== null) {
            $p[] = $blockhash;
        }
        $calls[] = ['getrawtransaction', $p];
    }
    $raw = ts_rpc_batch($net, $calls);

    // verbosity-2 wholesale rejection (older Core): re-fetch the nulls plain.
    if ($verbosity === 2) {
        $retry = [];
        $retryIdx = [];
        foreach ($missTxids as $k => $tid) {
            if ($raw[$k] === null) {
                $p = [$tid, true];
                if ($blockhash !== null) {
                    $p[] = $blockhash;
                }
                $retry[]    = ['getrawtransaction', $p];
                $retryIdx[] = $k;
            }
        }
        if ($retry) {
            $rr = ts_rpc_batch($net, $retry);
            foreach ($retryIdx as $j => $k) {
                if ($rr[$j] !== null) {
                    $raw[$k] = $rr[$j];
                }
            }
        }
    }

    // 2) Map without per-tx prevout resolution (deferred to one cross-tx batch).
    $mapped = [];
    foreach ($missTxids as $k => $tid) {
        if (is_array($raw[$k])) {
            $mapped[$tid] = ts_map_tx($net, $raw[$k], true);
        }
    }

    // 3) Resolve every deferred prevout in ONE batch + finalize fees.
    ts_resolve_prevouts_multi($net, $mapped);

    // 4) Cache confirmed bodies and merge into the output map.
    foreach ($mapped as $tid => $tx) {
        ts_cache_tx_if_confirmed($net, $tid, $tx);
        $out[$tid] = $tx;
    }
    return $out;
}

/**
 * Resolve deferred vin prevouts across MANY mapped txs in a single batch, then
 * recompute each affected tx's fee from the now-known input values. $txs is a
 * txid => tx map (Esplora shape) with some vin['prevout'] === null. On the BTC
 * verbosity-2 lane prevouts are already inlined, so $need is empty and only the
 * (exact, identical) fee finalize runs.
 */
function ts_resolve_prevouts_multi(array $net, array &$txs): void
{
    $need = [];   // prevTxid => list of [txid, vinIndex]
    foreach ($txs as $tid => $tx) {
        foreach ($tx['vin'] as $vi => $in) {
            if (!empty($in['is_coinbase']) || $in['prevout'] !== null) {
                continue;
            }
            $need[$in['txid']][] = [$tid, $vi];
        }
    }
    if ($need) {
        $prevTxids = array_keys($need);
        $calls = [];
        foreach ($prevTxids as $ptid) {
            $calls[] = ['getrawtransaction', [$ptid, true]];
        }
        $res = ts_rpc_batch($net, $calls);
        foreach ($prevTxids as $k => $ptid) {
            $prevtx = $res[$k];
            if (!is_array($prevtx) || !isset($prevtx['vout'])) {
                continue;
            }
            foreach ($need[$ptid] as $ref) {
                list($tid, $vi) = $ref;
                $voutN = $txs[$tid]['vin'][$vi]['vout'];
                if (isset($prevtx['vout'][$voutN])) {
                    $pv = $prevtx['vout'][$voutN];
                    $txs[$tid]['vin'][$vi]['prevout'] = ts_map_spk($pv['scriptPubKey'] ?? [], $pv['value'] ?? 0);
                }
            }
        }
    }
    // Finalize fees now that inputs are known (exact integer sats; for BTC this
    // reproduces the inlined fee value, for LTC it fills the deferred 0).
    foreach ($txs as $tid => $tx) {
        if (!empty($tx['vin'][0]['is_coinbase'])) {
            continue;
        }
        $inSum = 0;
        $haveAll = true;
        foreach ($tx['vin'] as $vi) {
            if ($vi['prevout'] === null) {
                $haveAll = false;
                break;
            }
            $inSum += $vi['prevout']['value'];
        }
        if ($haveAll) {
            $outSum = 0;
            foreach ($tx['vout'] as $vo) {
                $outSum += $vo['value'];
            }
            $txs[$tid]['fee'] = max(0, $inSum - $outSum);
        }
    }
}

/** Raw tx hex (text body for /tx/:txid/hex). */
function ts_tx_hex(array $net, string $txid, ?string $blockhash = null): ?string
{
    $params = [$txid, false];
    if ($blockhash !== null) {
        $params[] = $blockhash;
    }
    $res = ts_rpc_soft($net, 'getrawtransaction', $params);
    return is_string($res) ? $res : null;
}

/**
 * Per-output spend status (/tx/:txid/outspends). Matches Esplora's shape:
 * {"spent":false} for unspent, and {"spent":true, "txid":..., "vin":..., "status":...}
 * for a spent output (electrs / mempool.space parity). Provably-unspendable outputs
 * (OP_RETURN) are reported unspent without an RPC. Pass $resolve=false for a cheap
 * flags-only result (UI badges) that skips the spender lookup.
 */
function ts_tx_outspends(array $net, array $tx, bool $resolve = true): array
{
    // Short cache: spent-flags are mutable (an output can be spent any time), but
    // a cheap window stops a crawler re-running the per-output gettxout batch on
    // every /outspends poll of a many-output tx. Resolved (full {txid,vin,status}) and
    // flags-only results live under separate keys so a cheap UI call ($resolve=false)
    // can't poison the API's full-shape cache.
    $ckey = ($resolve ? 'outspends:' : 'outspendflags:') . $net['slug'] . ':' . $tx['txid'];
    $hit = cache_get($ckey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) {
            return $d;
        }
    }
    $out = [];
    $ask = [];
    foreach ($tx['vout'] as $i => $vo) {
        $type = $vo['scriptpubkey_type'] ?? '';
        $spk  = $vo['scriptpubkey'] ?? '';
        if ($type === 'op_return' || substr($spk, 0, 2) === '6a') {
            $out[$i] = ['spent' => false]; // unspendable, never in the UTXO set
        } else {
            $ask[] = $i;
        }
    }
    // gettxout returns null once an output leaves the UTXO set (spent). Chunk to
    // bound the batch size on pathologically large txs.
    $spentIdx = [];
    foreach (array_chunk($ask, 500) as $chunk) {
        $calls = [];
        foreach ($chunk as $i) {
            $calls[] = ['gettxout', [$tx['txid'], $i, true]];
        }
        $res = ts_rpc_batch($net, $calls);
        foreach ($chunk as $k => $i) {
            if (($res[$k] ?? null) === null) {
                $spentIdx[] = $i;          // spent -> resolve the spender below
            } else {
                $out[$i] = ['spent' => false];
            }
        }
    }
    // For each spent output resolve WHO spent it, so the body carries the full Esplora
    // shape {spent, txid, vin, status} (electrs / mempool.space parity). A bare
    // {"spent":true} is a conformance bug: consumers that read outspend.txid - e.g. an
    // atomic-swap maker extracting the HTLC secret from the spending tx - silently break.
    // Memoize history-per-scripthash and fetched txs so a many-output tx paying one address
    // costs a single history call plus one fetch per distinct spender.
    if ($resolve) {
        $histMemo = [];
        $txMemo   = [];
        $deadline = time() + 5; // ONE wall-clock budget shared across every spent output in this tx
        foreach ($spentIdx as $i) {
            $out[$i] = ts_resolve_outspend(
                $net,
                $tx['txid'],
                $i,
                $tx['vout'][$i]['scriptpubkey'] ?? '',
                $histMemo,
                $txMemo,
                $deadline
            );
        }
    } else {
        foreach ($spentIdx as $i) {
            $out[$i] = ['spent' => true];   // flags-only (UI badges); skip the spender lookup
        }
    }
    ksort($out);
    $result = array_values($out);
    cache_set($ckey, json_encode($result, JSON_UNESCAPED_SLASHES), 15);
    return $result;
}

/**
 * Resolve the full Esplora outspend object for a SPENT output: locate the tx that consumes
 * outpoint ($txid,$vout) via the output's scripthash history and return
 * {spent:true, txid, vin, status}. Falls back to {spent:true} only if the spender can't be
 * located (a transient electrum gap) - it never downgrades a spent output to unspent.
 * $histMemo (scripthash -> tx_hash[]) and $txMemo (txid -> esplora tx) are per-call caches.
 */
function ts_resolve_outspend(array $net, string $txid, int $vout, string $spkHex, array &$histMemo, array &$txMemo, int $deadline = 0): array
{
    if ($spkHex === '' || strlen($spkHex) % 2 !== 0) {
        return ['spent' => true];
    }
    // Bound the work: an output on a heavily-reused address has a huge scripthash history,
    // and resolving the spender is one getrawtransaction per candidate. Cap the candidates
    // and honor a wall-clock deadline so a crafted /outspends can't pin an FPM worker; on
    // budget-exceed we fall back to bare {spent:true}, which degrades safely (the maker's
    // extractSecret re-validates the preimage, and a missing txid just retries).
    if ($deadline <= 0) {
        $deadline = time() + 4;
    }
    $cap = (int) (ts_config()['outspend_walk_limit'] ?? 200);
    $sh = bin2hex(strrev(hash('sha256', hex2bin($spkHex), true)));
    if (!array_key_exists($sh, $histMemo)) {
        $ids = [];
        foreach ((ts_scripthash_history($net, $sh) ?? []) as $h) {
            $tid = $h['tx_hash'] ?? '';
            if ($tid !== '' && $tid !== $txid) {
                $ids[] = $tid;
            }
        }
        // Newest-first: a just-spent output's spender sits near the chain tip, so the common
        // case (the maker polling a fresh spend) resolves in one fetch; the cap/deadline bound
        // the pathological case (a spend buried deep in a giant reused-address history).
        $histMemo[$sh] = array_reverse($ids);
    }
    $seen = 0;
    foreach ($histMemo[$sh] as $cand) {
        if (++$seen > $cap || time() > $deadline) {
            break;
        }
        if (!array_key_exists($cand, $txMemo)) {
            $txMemo[$cand] = ts_find_tx($net, $cand);
        }
        $ctx = $txMemo[$cand];
        if (!is_array($ctx) || empty($ctx['vin'])) {
            continue;
        }
        foreach ($ctx['vin'] as $k => $in) {
            if (empty($in['is_coinbase'])
                && ($in['txid'] ?? '') === $txid
                && (int) ($in['vout'] ?? -1) === $vout) {
                return [
                    'spent'  => true,
                    'txid'   => $cand,
                    'vin'    => (int) $k,
                    'status' => $ctx['status'] ?? ['confirmed' => false],
                ];
            }
        }
    }
    return ['spent' => true]; // spent per gettxout, but the spender isn't locatable right now
}

/**
 * Full Esplora outspend for ONE output of $tx (used by /tx/:txid/outspend/:vout). Cheaper
 * than the batch when a caller needs a single output (e.g. a swap maker polling its HTLC
 * output): it resolves the spender for that output only.
 */
function ts_tx_outspend(array $net, array $tx, int $vout): array
{
    if (!isset($tx['vout'][$vout])) {
        return ['spent' => false];
    }
    $vo   = $tx['vout'][$vout];
    $type = $vo['scriptpubkey_type'] ?? '';
    $spk  = $vo['scriptpubkey'] ?? '';
    if ($type === 'op_return' || substr($spk, 0, 2) === '6a') {
        return ['spent' => false];
    }
    $ckey = 'outspend1:' . $net['slug'] . ':' . $tx['txid'] . ':' . $vout;
    $hit = cache_get($ckey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) {
            return $d;
        }
    }
    $res = ts_rpc_batch($net, [['gettxout', [$tx['txid'], $vout, true]]]);
    if (($res[0] ?? null) !== null) {
        $r = ['spent' => false];   // still in the UTXO set
    } else {
        $histMemo = [];
        $txMemo   = [];
        $r = ts_resolve_outspend($net, $tx['txid'], $vout, $spk, $histMemo, $txMemo);
    }
    cache_set($ckey, json_encode($r, JSON_UNESCAPED_SLASHES), 15);
    return $r;
}

/** Merkle inclusion proof (/tx/:txid/merkle-proof) via electrs. */
function ts_tx_merkle(array $net, string $txid, int $height): ?array
{
    try {
        $r = ts_electrum($net)->request('blockchain.transaction.get_merkle', [$txid, $height]);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($r)) {
        return null;
    }
    return [
        'block_height' => (int) ($r['block_height'] ?? $height),
        'merkle'       => $r['merkle'] ?? [],
        'pos'          => (int) ($r['pos'] ?? 0),
    ];
}

// ---- block ----------------------------------------------------------------

/** Esplora block summary for a hash (cached forever, immutable per hash). */
function ts_esplora_block(array $net, string $hash): ?array
{
    $ckey = 'eblk:' . $net['slug'] . ':' . $hash;
    $hit = cache_get($ckey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) {
            return $d;
        }
    }
    $b = ts_rpc_soft($net, 'getblock', [$hash, 1]);
    if (!is_array($b)) {
        return null;
    }
    $blk = [
        'id'                => $b['hash'],
        'height'            => (int) $b['height'],
        'version'           => (int) $b['version'],
        'timestamp'         => (int) $b['time'],
        'tx_count'          => (int) ($b['nTx'] ?? count($b['tx'] ?? [])),
        'size'              => (int) $b['size'],
        'weight'            => (int) ($b['weight'] ?? $b['size'] * 4),
        'merkle_root'       => $b['merkleroot'],
        'previousblockhash' => $b['previousblockhash'] ?? null,
        'mediantime'        => (int) ($b['mediantime'] ?? $b['time']),
        'nonce'             => (int) $b['nonce'],
        'bits'              => isset($b['bits']) ? (int) hexdec($b['bits']) : 0,
        'difficulty'        => (float) ($b['difficulty'] ?? 0),
    ];
    cache_set($ckey, json_encode($blk, JSON_UNESCAPED_SLASHES), 0);
    return $blk;
}

/** Ordered list of txids in a block (cached forever per hash). */
function ts_block_txids(array $net, string $hash): ?array
{
    $ckey = 'btxids:' . $net['slug'] . ':' . $hash;
    $hit = cache_get($ckey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) {
            return $d;
        }
    }
    $b = ts_rpc_soft($net, 'getblock', [$hash, 1]);
    if (!is_array($b) || !isset($b['tx'])) {
        return null;
    }
    cache_set($ckey, json_encode($b['tx'], JSON_UNESCAPED_SLASHES), 0);
    return $b['tx'];
}

/** A page of 25 full Esplora txs from a block, starting at $start. */
function ts_block_txs(array $net, string $hash, int $start = 0): ?array
{
    $txids = ts_block_txids($net, $hash);
    if ($txids === null) {
        return null;
    }
    $slice = array_slice($txids, $start, 25);
    $map = ts_esplora_txs($net, $slice, $hash);   // one batch, not 25 serial RPCs
    $out = [];
    foreach ($slice as $txid) {
        if (isset($map[$txid])) {
            $out[] = $map[$txid];
        }
    }
    return $out;
}

/** Chain-membership status of a block (/block/:hash/status). */
function ts_block_status(array $net, string $hash): ?array
{
    $hdr = ts_rpc_soft($net, 'getblockheader', [$hash, true]);
    if (!is_array($hdr) || !isset($hdr['height'])) {
        return null;
    }
    $inChain = ((int) ($hdr['confirmations'] ?? -1)) !== -1;
    return [
        'in_best_chain' => $inChain,
        'height'        => (int) $hdr['height'],
        'next_best'     => $inChain ? ($hdr['nextblockhash'] ?? null) : null,
    ];
}

/** Esplora /blocks[/:start_height]: up to 10 block summaries, descending. */
function ts_recent_blocks(array $net, ?int $startHeight = null, int $count = 10): array
{
    $tip = ts_tip_height($net);
    $start = $startHeight === null ? $tip : min($startHeight, $tip);
    $heights = [];
    for ($h = $start; $h > $start - $count && $h >= 0; $h--) { $heights[] = $h; }
    $hashes = ts_block_hashes($net, $heights);   // one batched getblockhash for the misses
    $out = [];
    foreach ($heights as $h) {
        if (!isset($hashes[$h])) { break; }
        $blk = ts_esplora_block($net, $hashes[$h]);   // bodies are immutable-cached; only new blocks are cold
        if ($blk) { $out[] = $blk; }
    }
    return $out;
}

// ---- address (electrs) ----------------------------------------------------

/**
 * Core address/scripthash stats. The aggregate result is cached with a short
 * TTL keyed by a history fingerprint, so repeated wallet polls are instant and
 * only recompute when history actually changes. For very busy addresses
 * (> address_walk_limit txs, e.g. faucet hot-wallets) the exact per-tx walk is
 * skipped in favour of electrs get_balance, keeping (funded - spent) and
 * tx_count exact while the per-output breakdown is approximate. This also
 * defuses the unauthenticated walk-amplification DoS.
 */
function ts_stats_for_scripthash(array $net, string $sh, callable $match, string $keyName, string $keyVal): ?array
{
    $history = ts_scripthash_history($net, $sh);
    if ($history === null) {
        return null;   // electrs unavailable - never report a false zero balance
    }
    $conf = [];
    $mem  = [];
    $maxH = 0;
    foreach ($history as $r) {
        $h = (int) ($r['height'] ?? 0);
        if ($h > 0) {
            $conf[] = $r['tx_hash'];
            if ($h > $maxH) {
                $maxH = $h;
            }
        } else {
            $mem[] = $r['tx_hash'];
        }
    }

    // fingerprint changes whenever history changes -> cache auto-invalidates
    $ckey = 'astats:' . $net['slug'] . ':' . $sh . ':' . count($conf) . '-' . $maxH . '-' . count($mem);
    $hit = cache_get($ckey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) {
            $d[$keyName] = $keyVal;
            return $d;
        }
    }

    $cap       = (int) (ts_config()['address_tx_cap'] ?? 5000);
    $walkLimit = (int) (ts_config()['address_walk_limit'] ?? 500);
    $empty = [
        'funded_txo_count' => 0, 'funded_txo_sum' => 0,
        'spent_txo_count'  => 0, 'spent_txo_sum'  => 0,
        'tx_count'         => 0,
    ];
    $chain = $empty;
    $mem2  = $empty;
    $chain['tx_count'] = count($conf);
    $mem2['tx_count']  = count($mem);

    if (count($conf) > $walkLimit || count($mem) > $walkLimit) {
        // Too large to walk synchronously, so keep balance exact via electrs,
        // approximate the funded/spent breakdown.
        try {
            $bal = ts_electrum($net)->request('blockchain.scripthash.get_balance', [$sh]);
        } catch (Throwable $e) {
            $bal = null;
        }
        if (!is_array($bal)) {
            // electrs blipped mid-request (history succeeded, balance didn't): degrade to
            // 503 rather than cache a false 0-balance for this busy address.
            return null;
        }
        $chain['funded_txo_sum']   = max(0, (int) ($bal['confirmed'] ?? 0));
        $chain['funded_txo_count'] = count($conf);
        $mem2['funded_txo_sum']    = max(0, (int) ($bal['unconfirmed'] ?? 0));
        $mem2['funded_txo_count']  = count($mem);
    } else {
        $deadline = time() + 6; // wall-clock budget, never pin a worker
        // Batch the resolution in chunks of 25 (via ts_esplora_txs) so a cold page
        // is a handful of round-trips, not ~500 serial getrawtransaction calls that
        // would pin an FPM worker and let an attacker amplify a single GET into
        // hundreds of RPCs. Confirmed bodies are cached, so warm walks are cheap.
        $walk = function (array $txids, array &$bucket) use ($net, $match, $cap, $deadline) {
            foreach (array_chunk(array_slice($txids, 0, $cap), 25) as $chunk) {
                if (time() > $deadline) {
                    break;
                }
                $map = ts_esplora_txs($net, $chunk);
                foreach ($chunk as $txid) {
                    if (!isset($map[$txid])) {
                        continue;
                    }
                    $tx = $map[$txid];
                    foreach ($tx['vout'] as $o) {
                        if ($match($o['scriptpubkey'] ?? '')) {
                            $bucket['funded_txo_count']++;
                            $bucket['funded_txo_sum'] += $o['value'];
                        }
                    }
                    foreach ($tx['vin'] as $vi) {
                        $po = $vi['prevout'] ?? null;
                        if ($po && $match($po['scriptpubkey'] ?? '')) {
                            $bucket['spent_txo_count']++;
                            $bucket['spent_txo_sum'] += $po['value'];
                        }
                    }
                }
            }
        };
        $walk($conf, $chain);
        $walk($mem, $mem2);
    }

    $result = [$keyName => $keyVal, 'chain_stats' => $chain, 'mempool_stats' => $mem2];
    cache_set($ckey, json_encode($result, JSON_UNESCAPED_SLASHES), 30);
    return $result;
}

/** Esplora /address/:addr: chain_stats + mempool_stats. */
function ts_address_stats(array $net, string $address): ?array
{
    $spk = ts_address_to_scriptpubkey($net, $address);
    if ($spk === null) {
        return null;
    }
    $sh = bin2hex(strrev(hash('sha256', hex2bin($spk), true)));
    $match = function ($h) use ($spk) {
        return $h === $spk;
    };
    return ts_stats_for_scripthash($net, $sh, $match, 'address', $address);
}

/**
 * Esplora address tx pages.
 *   mode 'all'     -> all mempool txs + newest 25 confirmed
 *   mode 'chain'   -> 25 confirmed after $afterTxid (or newest 25 if null)
 *   mode 'mempool' -> mempool txs only
 */
function ts_address_txs(array $net, string $address, string $mode = 'all', ?string $afterTxid = null): ?array
{
    $spk = ts_address_to_scriptpubkey($net, $address);
    if ($spk === null) {
        return null;
    }
    $sh = bin2hex(strrev(hash('sha256', hex2bin($spk), true)));
    $history = ts_scripthash_history($net, $sh);   // cached (coalesces repeated polls)
    if ($history === null) {
        return null;   // electrs unavailable - don't fake an empty tx list
    }

    $confirmed = [];
    $mempool = [];
    foreach ($history as $r) {
        if ((int) ($r['height'] ?? 0) > 0) {
            $confirmed[] = $r;
        } else {
            $mempool[] = $r;
        }
    }
    $mempool = array_slice($mempool, 0, 50); // Esplora MAX_MEMPOOL_TXS
    // newest first
    usort($confirmed, function ($a, $b) {
        return ((int) $b['height']) <=> ((int) $a['height']);
    });
    $confTxids = array_map(function ($r) {
        return $r['tx_hash'];
    }, $confirmed);

    // Build the ordered txid list first (mempool then confirmed slice), then
    // resolve them all in one batch instead of one getrawtransaction per tx.
    $order = [];
    if ($mode === 'mempool' || $mode === 'all') {
        foreach ($mempool as $r) {
            $order[] = $r['tx_hash'];
        }
    }
    if ($mode !== 'mempool') {
        $startIdx = 0;
        if ($mode === 'chain' && $afterTxid !== null) {
            $pos = array_search($afterTxid, $confTxids, true);
            $startIdx = ($pos === false) ? count($confTxids) : $pos + 1;
        }
        foreach (array_slice($confTxids, $startIdx, 25) as $txid) {
            $order[] = $txid;
        }
    }
    $map = ts_esplora_txs($net, $order);
    $out = [];
    foreach ($order as $txid) {
        if (isset($map[$txid])) {
            $out[] = $map[$txid];
        }
    }
    return $out;
}

/** Esplora /address/:addr/utxo via electrs listunspent (scripthash-cached). */
function ts_address_utxos(array $net, string $address): ?array
{
    $spk = ts_address_to_scriptpubkey($net, $address);
    if ($spk === null) {
        return null;
    }
    $sh = bin2hex(strrev(hash('sha256', hex2bin($spk), true)));
    return ts_scripthash_utxos($net, $sh);
}

// ---- mempool --------------------------------------------------------------

/**
 * Shared verbose mempool (getrawmempool true) cached 5s, so the histogram,
 * projected-block packing and recent-tx paths reuse one heavy fetch instead of
 * each issuing their own getrawmempool(true) on the same request.
 */
function ts_mempool_verbose(array $net): array
{
    return cache_remember('memverbose:' . $net['slug'], 5, function () use ($net) {
        $r = ts_rpc_soft($net, 'getrawmempool', [true]);
        return is_array($r) ? $r : [];
    });
}

function ts_esplora_mempool(array $net): array
{
    return cache_remember('mempool:' . $net['slug'], 5, function () use ($net) {
        $info  = ts_rpc_soft($net, 'getmempoolinfo') ?: [];
        $count = (int) ($info['size'] ?? 0);
        $vsize = (int) ($info['bytes'] ?? 0);
        $totalFee = isset($info['total_fee']) ? coin_to_sat($info['total_fee']) : 0;
        $histogram = [];

        // litecoind 0.21 getmempoolinfo lacks total_fee, and ElectrumX may not
        // serve a fee histogram, so derive both from getrawmempool(true) in one
        // pass (bounded by mempool size; the 5s cache absorbs the cost).
        if ($count > 0) {
            $verbose = ts_mempool_verbose($net);
            if ($verbose) {
                $sum = 0;
                $byRate = [];              // feerate (int sat/vB) -> summed vsize
                foreach ($verbose as $e) {
                    $feeSat = isset($e['fees']['base'])
                        ? coin_to_sat($e['fees']['base'])
                        : coin_to_sat($e['fee'] ?? 0);
                    $vs = (int) ($e['vsize'] ?? $e['size'] ?? 0);
                    $sum += $feeSat;
                    if ($vs > 0) {
                        $rate = (int) round($feeSat / $vs * 10);   // 0.1 sat/vB resolution (int key)
                        $byRate[$rate] = ($byRate[$rate] ?? 0) + $vs;
                    }
                }
                if (!isset($info['total_fee'])) {
                    $totalFee = $sum;
                }
                krsort($byRate); // Esplora histogram is [[feerate, vsize], ...] high->low
                foreach ($byRate as $rate => $vs) {
                    $histogram[] = [$rate / 10, $vs];   // back to fractional sat/vB
                }
            }
        }

        return [
            'count'         => $count,
            'vsize'         => $vsize,
            'total_fee'     => $totalFee,
            'fee_histogram' => $histogram,
            'usage'         => (int) ($info['usage'] ?? 0),        // in-memory bytes
            'max'           => (int) ($info['maxmempool'] ?? 0),   // maxmempool cap, bytes
        ];
    });
}

function ts_mempool_txids(array $net): array
{
    $r = ts_rpc_soft($net, 'getrawmempool', [false]);
    return is_array($r) ? array_values($r) : [];
}

/** Esplora /mempool/recent: up to 10 entries with output-value sums. */
function ts_mempool_recent(array $net): array
{
    return cache_remember('memrecent:' . $net['slug'], 5, function () use ($net) {
        $r = ts_mempool_verbose($net);
        if (!$r) {
            return [];
        }
        // Esplora returns the most-recently-seen txs; sort by entry time desc.
        $all = [];
        foreach ($r as $txid => $e) {
            $all[] = [$txid, $e, (int) ($e['time'] ?? 0)];
        }
        usort($all, function ($a, $b) {
            return $b[2] <=> $a[2];
        });
        $entries = array_slice($all, 0, 10);
        // Batch-fetch the (<=10) txs to compute the total output value per tx.
        $calls = [];
        foreach ($entries as $en) {
            $calls[] = ['getrawtransaction', [$en[0], true]];
        }
        $txs = $calls ? ts_rpc_batch($net, $calls) : [];

        $out = [];
        foreach ($entries as $i => $en) {
            [$txid, $e] = $en;
            $fee = isset($e['fees']['base']) ? coin_to_sat($e['fees']['base']) : coin_to_sat($e['fee'] ?? 0);
            $value = 0;
            $tx = $txs[$i] ?? null;
            if (is_array($tx) && isset($tx['vout'])) {
                foreach ($tx['vout'] as $vo) {
                    $value += coin_to_sat($vo['value'] ?? 0);
                }
            }
            $out[] = [
                'txid'  => $txid,
                'fee'   => $fee,
                'vsize' => (int) ($e['vsize'] ?? 0),
                'value' => $value,
            ];
        }
        return $out;
    });
}

// ---- fees -----------------------------------------------------------------

/** estimatesmartfee(target) -> sat/vB, or null if the node can't estimate. */
function ts_smartfee_satvb(array $net, int $blocks)
{
    $r = ts_rpc_soft($net, 'estimatesmartfee', [$blocks]);
    if (is_array($r) && isset($r['feerate']) && $r['feerate'] > 0) {
        return $r['feerate'] * 100000.0; // BTC/kvB -> sat/vB
    }
    return null;
}

/** Esplora /fee-estimates: {target: sat/vB}. */
function ts_fee_estimates(array $net): array
{
    return cache_remember('fees:' . $net['slug'], 60, function () use ($net) {
        $targets = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
            16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 144, 504, 1008];
        // One batched round-trip instead of 28 serial estimatesmartfee calls.
        $calls = [];
        foreach ($targets as $t) {
            $calls[] = ['estimatesmartfee', [$t]];
        }
        $res = ts_rpc_batch($net, $calls);
        // Seed with the FIRST real estimate: on sparse testnets Core often can't
        // estimate the 1-block target but can estimate larger ones. Without this,
        // a missing target-1 seeded $last=1.0 and the non-increasing clamp then
        // pinned every larger real estimate down to 1.0 (whole curve collapses).
        $seed = null;
        foreach ($targets as $i => $t) {
            $r = $res[$i] ?? null;
            if (is_array($r) && isset($r['feerate']) && $r['feerate'] > 0) {
                $seed = $r['feerate'] * 100000.0;
                break;
            }
        }
        $out = [];
        $last = $seed;   // null only if the node can estimate nothing
        foreach ($targets as $i => $t) {
            $r = $res[$i] ?? null;
            $rate = (is_array($r) && isset($r['feerate']) && $r['feerate'] > 0)
                ? $r['feerate'] * 100000.0     // BTC/kvB -> sat/vB
                : null;
            if ($rate === null) {
                $rate = $last;                 // carry forward the last real value
            } elseif ($last !== null && $rate > $last) {
                $rate = $last;                 // curve is non-increasing with target
            }
            if ($rate !== null) {
                $last = $rate;
            }
            $out[(string) $t] = round($rate ?? 1.0, 2);
        }
        return $out;
    });
}

/** mempool.space /v1/fees/recommended extension. */
function ts_fees_recommended(array $net): array
{
    return cache_remember('feesrec:' . $net['slug'], 60, function () use ($net) {
        $info = ts_rpc_soft($net, 'getmempoolinfo') ?: [];
        $minfee = isset($info['mempoolminfee'])
            ? max(1, (int) ceil($info['mempoolminfee'] * 100000.0))
            : 1;
        $fastest = ts_smartfee_satvb($net, 1);
        $half    = ts_smartfee_satvb($net, 3);
        $hour    = ts_smartfee_satvb($net, 6);
        $econ    = ts_smartfee_satvb($net, 144);

        $fastest = $fastest === null ? $minfee : max($minfee, (int) ceil($fastest));
        $half    = $half === null ? $fastest : max($minfee, (int) ceil($half));
        $hour    = $hour === null ? $half : max($minfee, (int) ceil($hour));
        $econ    = $econ === null ? $minfee : max($minfee, (int) ceil($econ));

        // enforce fastest >= half >= hour >= economy >= minimum
        $half = min($half, $fastest);
        $hour = min($hour, $half);
        $econ = min($econ, $hour);

        return [
            'fastestFee'  => $fastest,
            'halfHourFee' => $half,
            'hourFee'     => $hour,
            'economyFee'  => $econ,
            'minimumFee'  => $minfee,
        ];
    });
}

// ---- broadcast ------------------------------------------------------------

/** POST /tx: returns [txid] or [null, errorMessage]. */
function ts_broadcast(array $net, string $rawhex): array
{
    $rawhex = trim($rawhex);
    if (strlen($rawhex) > 4200000) {
        return [null, 'transaction too large'];
    }
    if (!ctype_xdigit($rawhex) || strlen($rawhex) % 2 !== 0) {
        return [null, 'invalid transaction hex'];
    }
    try {
        $txid = ts_rpc($net, 'sendrawtransaction', [$rawhex]);
        return [is_string($txid) ? $txid : null, null];
    } catch (RpcException $e) {
        // Surface only Core's application-level rejection (rpcCode != 0, e.g.
        // "min relay fee not met", "txn-already-known"). A transport/auth failure
        // (rpcCode 0) embeds the RPC endpoint host:port in its message, so return
        // a generic error rather than leaking internal infrastructure.
        return [null, $e->rpcCode !== 0 ? $e->getMessage() : 'broadcast failed: node unavailable'];
    }
}

/**
 * Dry-run mempool acceptance (testmempoolaccept) WITHOUT broadcasting. Returns
 * ['txid','allowed'=>bool,'reject'=>str,'vsize'=>?int,'fee'=>?int] or
 * ['error'=>str]. $maxfeerate is an optional sat/vB ceiling (converted to the
 * BTC/kvB the RPC expects); empty uses the node default (0.10 BTC/kvB).
 */
function ts_test_mempool_accept(array $net, string $rawhex, $maxfeerate = null): array
{
    $rawhex = trim($rawhex);
    if (strlen($rawhex) > 4200000) {
        return ['error' => 'transaction too large'];
    }
    if (!ctype_xdigit($rawhex) || strlen($rawhex) % 2 !== 0) {
        return ['error' => 'invalid transaction hex'];
    }
    $params = [[$rawhex]];
    if ($maxfeerate !== null && $maxfeerate !== '' && (float) $maxfeerate > 0) {
        $params[] = (float) $maxfeerate * 0.00001;   // sat/vB -> BTC/kvB
    }
    try {
        $res = ts_rpc($net, 'testmempoolaccept', $params);
        if (!is_array($res) || !isset($res[0]) || !is_array($res[0])) {
            return ['error' => 'unexpected node response'];
        }
        $r = $res[0];
        return [
            'txid'    => (string) ($r['txid'] ?? ''),
            'allowed' => !empty($r['allowed']),
            'reject'  => (string) ($r['reject-reason'] ?? ''),
            'vsize'   => isset($r['vsize']) ? (int) $r['vsize'] : null,
            'fee'     => isset($r['fees']['base']) ? coin_to_sat($r['fees']['base']) : null,
        ];
    } catch (RpcException $e) {
        return ['error' => $e->rpcCode !== 0 ? $e->getMessage() : 'test failed: node unavailable'];
    }
}

/** Decode a raw tx hex into the Esplora shape (no broadcast) for the tools page. */
function ts_decode_rawtx(array $net, string $hex): ?array
{
    $hex = trim($hex);
    if ($hex === '' || strlen($hex) > 4200000 || !ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
        return null;
    }
    $dec = ts_rpc_soft($net, 'decoderawtransaction', [$hex]);
    if (!is_array($dec) || !isset($dec['vin'], $dec['vout'])) {
        return null;
    }
    // ts_map_tx resolves prevouts (if the referenced outputs exist) and the fee;
    // there's no blockhash, so status is unconfirmed.
    return ts_map_tx($net, $dec);
}

// ---- no-txindex fallbacks -------------------------------------------------

/** electrs extension: confirming block hash of a txid (works without txindex). */
function ts_get_confirmed_blockhash(array $net, string $txid): ?string
{
    try {
        $r = ts_electrum($net)->request('blockchain.transaction.get_confirmed_blockhash', [$txid]);
    } catch (Throwable $e) {
        return null;
    }
    if (is_array($r)) {
        return $r['block_hash'] ?? null;
    }
    return is_string($r) ? $r : null;
}

/** Resolve a tx even if the node lacks txindex (ask electrs for its block). */
function ts_find_tx(array $net, string $txid): ?array
{
    // Negative-cache "not found" briefly: a flood of nonexistent-but-hex txids (WS track-tx
    // spam, /search probes) otherwise re-hits getrawtransaction + the electrs confirmed-blockhash
    // fallback on every call/tick. A genuinely-new tx still surfaces within the short TTL.
    $nk = 'txnf:' . $net['slug'] . ':' . $txid;
    if (cache_get($nk) !== null) {
        return null;
    }
    $tx = ts_esplora_tx($net, $txid);
    if ($tx) {
        return $tx;
    }
    $bh = ts_get_confirmed_blockhash($net, $txid);
    if ($bh) {
        return ts_esplora_tx($net, $txid, $bh);
    }
    cache_set($nk, '1', 30);
    return null;
}

/** Raw hex with the same no-txindex fallback. */
function ts_find_tx_hex(array $net, string $txid): ?string
{
    $hex = ts_tx_hex($net, $txid);
    if ($hex !== null) {
        return $hex;
    }
    $bh = ts_get_confirmed_blockhash($net, $txid);
    return $bh ? ts_tx_hex($net, $txid, $bh) : null;
}

// ---- scripthash endpoints (full Esplora compat) ---------------------------

function ts_scripthash_history(array $net, string $sh): ?array
{
    // Short server cache: the FULL history is fetched on every address/scripthash
    // stats + tx-list request (the astats fingerprint key is derived from it, so
    // it runs even on a warm stats cache). Coalescing repeated polls/crawls of the
    // same address into one electrs round-trip defuses that amplification.
    // Returns NULL when electrs is unreachable/erroring (index down or mid-resync) so
    // callers show a degraded state instead of a false, confident "0 balance". An empty
    // array is a genuinely unused address.
    $cached = cache_remember('shist:' . $net['slug'] . ':' . $sh, 8, function () use ($net, $sh) {
        try {
            $h = ts_electrum($net)->request('blockchain.scripthash.get_history', [$sh]);
        } catch (Throwable $e) {
            return null;   // electrs unreachable -> not cached; caller treats as unavailable
        }
        return is_array($h) ? $h : null;   // null -> not cached (transient electrs error)
    });
    return is_array($cached) ? $cached : null;
}

/** Cached (~10s) electrs reachability probe, so views can show a degraded state instead of zeros. */
function ts_electrum_reachable(array $net): bool
{
    return (bool) cache_remember('electrumok:' . $net['slug'], 10, function () use ($net) {
        try {
            ts_electrum($net)->request('server.ping');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    });
}

function ts_scripthash_stats(array $net, string $sh): ?array
{
    $match = function ($spkHex) use ($sh) {
        if ($spkHex === '') {
            return false;
        }
        return bin2hex(strrev(hash('sha256', hex2bin($spkHex), true))) === $sh;
    };
    return ts_stats_for_scripthash($net, $sh, $match, 'scripthash', $sh);
}

function ts_scripthash_txs(array $net, string $sh, string $mode = 'all', ?string $afterTxid = null): ?array
{
    $history = ts_scripthash_history($net, $sh);
    if ($history === null) {
        return null;   // electrs unavailable
    }
    $conf = [];
    $mem = [];
    foreach ($history as $r) {
        if ((int) ($r['height'] ?? 0) > 0) {
            $conf[] = $r;
        } else {
            $mem[] = $r;
        }
    }
    $mem = array_slice($mem, 0, 50); // Esplora MAX_MEMPOOL_TXS
    usort($conf, function ($a, $b) {
        return ((int) $b['height']) <=> ((int) $a['height']);
    });
    $confTxids = array_map(function ($r) {
        return $r['tx_hash'];
    }, $conf);

    $order = [];
    if ($mode === 'mempool' || $mode === 'all') {
        foreach ($mem as $r) {
            $order[] = $r['tx_hash'];
        }
    }
    if ($mode !== 'mempool') {
        $startIdx = 0;
        if ($mode === 'chain' && $afterTxid !== null) {
            $pos = array_search($afterTxid, $confTxids, true);
            $startIdx = ($pos === false) ? count($confTxids) : $pos + 1;
        }
        foreach (array_slice($confTxids, $startIdx, 25) as $txid) {
            $order[] = $txid;
        }
    }
    $map = ts_esplora_txs($net, $order);   // one batch, not one RPC per tx
    $out = [];
    foreach ($order as $txid) {
        if (isset($map[$txid])) {
            $out[] = $map[$txid];
        }
    }
    return $out;
}

function ts_scripthash_utxos(array $net, string $sh): ?array
{
    // Short TTL: UTXOs are mutable, but wallets poll this repeatedly between
    // blocks. A few-second server cache collapses those polls without ever
    // serving a long-stale set. No history fingerprint (that would double the
    // electrs round-trips just to guard a cheap listunspent). NULL when electrs is
    // unavailable so callers 503 rather than report a false "no coins".
    $cached = cache_remember('autxo:' . $net['slug'] . ':' . $sh, 10, function () use ($net, $sh) {
        try {
            $utxos = ts_electrum($net)->request('blockchain.scripthash.listunspent', [$sh]);
        } catch (Throwable $e) {
            return null;   // electrs unreachable
        }
        if (!is_array($utxos)) {
            return null;   // transient electrs error: don't cache, don't poison
        }
        $out = [];
        foreach ($utxos as $u) {
            $out[] = ts_map_utxo_row($net, $u);
        }
        return $out;
    });
    return is_array($cached) ? $cached : null;
}

// ---- mempool.space /v1 extensions -----------------------------------------

/** /v1/validate-address/:address: Core validateaddress shape, from our codec. */
function ts_validate_address(array $net, string $address): array
{
    $spk = ts_address_to_scriptpubkey($net, $address);
    if ($spk === null) {
        return ['isvalid' => false, 'address' => $address];
    }
    $type = ts_address_type($net, $address);
    $isScript = in_array($type, ['p2sh', 'v0_p2wsh'], true);
    $isWitness = strncmp($type, 'v0_', 3) === 0
        || strncmp($type, 'v1_', 3) === 0
        || strncmp($type, 'witness_v', 9) === 0;
    $out = [
        'isvalid'      => true,
        'address'      => $address,
        'scriptPubKey' => $spk,
        'isscript'     => $isScript,
        'iswitness'    => $isWitness,
    ];
    if ($isWitness && !empty($net['bech32'])) {
        $seg = ts_segwit_decode($net['bech32'], $address);
        if ($seg !== null) {
            $out['witness_version'] = $seg[0];
            $out['witness_program'] = bin2hex($seg[1]);
        }
    }
    return $out;
}

/**
 * /v1/difficulty-adjustment: retarget math over the 2016-block epoch.
 * Note: on testnet the 20-minute minimum-difficulty rule makes
 * difficultyChange noisy; the structural fields stay accurate.
 */
function ts_difficulty_adjustment(array $net): array
{
    return cache_remember('diffadj:' . $net['slug'], 60, function () use ($net) {
        $height = (int) ts_rpc($net, 'getblockcount');
        $epoch = 2016;
        $targetSpacing = $net['coin'] === 'ltc' ? 150 : 600; // seconds per block
        $epochStart = intdiv($height, $epoch) * $epoch;
        $nextRetargetHeight = $epochStart + $epoch;
        $blocksInEpoch = $height - $epochStart;           // 0..2015
        $remainingBlocks = $nextRetargetHeight - $height;
        $progressPercent = ($blocksInEpoch / $epoch) * 100;

        $difficultyChange = 0.0;
        $expectedBlocks = (float) $blocksInEpoch;
        $timeAvg = $targetSpacing * 1000;
        if ($blocksInEpoch > 0) {
            $tipHash = ts_block_hash_at($net, $height);
            $startHash = ts_block_hash_at($net, $epochStart);
            $tipHdr = $tipHash ? ts_rpc_soft($net, 'getblockheader', [$tipHash, true]) : null;
            $startHdr = $startHash ? ts_rpc_soft($net, 'getblockheader', [$startHash, true]) : null;
            if (is_array($tipHdr) && is_array($startHdr)) {
                $elapsed = (int) $tipHdr['time'] - (int) $startHdr['time'];
                if ($elapsed > 0) {
                    $timeAvg = (int) round(($elapsed / $blocksInEpoch) * 1000);
                    $expected = $blocksInEpoch * $targetSpacing;
                    $difficultyChange = (($expected / $elapsed) - 1) * 100;
                    $expectedBlocks = $elapsed / $targetSpacing;
                }
            }
        }

        // previousRetarget = the realized change of the LAST completed retarget:
        // difficulty at this epoch's start vs the prior epoch's start.
        $previousRetarget = 0.0;
        if ($epochStart >= $epoch) {
            $h1 = ts_block_hash_at($net, $epochStart);
            $h0 = ts_block_hash_at($net, $epochStart - $epoch);
            $d1 = $h1 ? ts_rpc_soft($net, 'getblockheader', [$h1, true]) : null;
            $d0 = $h0 ? ts_rpc_soft($net, 'getblockheader', [$h0, true]) : null;
            if (is_array($d1) && is_array($d0) && (float) ($d0['difficulty'] ?? 0) > 0) {
                $previousRetarget = (((float) $d1['difficulty'] / (float) $d0['difficulty']) - 1) * 100;
            }
        }

        return [
            'progressPercent'       => round($progressPercent, 4),
            'difficultyChange'      => round($difficultyChange, 4),
            'estimatedRetargetDate' => (time() + $remainingBlocks * $targetSpacing) * 1000,
            'remainingBlocks'       => $remainingBlocks,
            'remainingTime'         => $remainingBlocks * $targetSpacing * 1000,
            'previousRetarget'      => round($previousRetarget, 4),
            'nextRetargetHeight'    => $nextRetargetHeight,
            'expectedBlocks'        => round($expectedBlocks, 4),
            'timeAvg'               => $timeAvg,
            'timeOffset'            => 0,
        ];
    });
}

/**
 * Difficulty at the last $n retarget boundaries (every 2016 blocks on BTC/LTC),
 * with each epoch's % change and timespan. Two batched round-trips (hashes then
 * headers). Cached ~30 min; the history is effectively immutable (a new epoch
 * lands only every ~2016 blocks). Returns newest-first, up to $n rows.
 */
function ts_difficulty_epochs(array $net, int $n = 12): array
{
    $interval = 2016;
    $tip = ts_tip_height($net);
    if ($tip < $interval) {
        return [];
    }
    $ckey = 'diffepochs:' . $net['slug'] . ':' . $n;
    $hit = cache_get($ckey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) { return $d; }
    }
    $lastBoundary = $tip - ($tip % $interval);
    $heights = [];
    for ($i = 0; $i <= $n; $i++) {              // one extra older boundary for the first % change
        $h = $lastBoundary - $i * $interval;
        if ($h < 0) { break; }
        $heights[] = $h;
    }
    $hashCalls = [];
    foreach ($heights as $h) { $hashCalls[] = ['getblockhash', [$h]]; }
    $hashes = ts_rpc_batch($net, $hashCalls);
    $hdrCalls = [];
    $validHeights = [];
    foreach ($hashes as $i => $hash) {
        if (is_string($hash)) { $hdrCalls[] = ['getblockheader', [$hash]]; $validHeights[] = $heights[$i]; }
    }
    $hdrs = $hdrCalls ? ts_rpc_batch($net, $hdrCalls) : [];
    $rows = [];
    foreach ($hdrs as $i => $hdr) {
        if (!is_array($hdr)) { continue; }
        $rows[] = ['height' => $validHeights[$i], 'time' => (int) ($hdr['time'] ?? 0), 'difficulty' => (float) ($hdr['difficulty'] ?? 0)];
    }
    $out = [];
    for ($i = 0; $i < count($rows) - 1; $i++) {
        $cur = $rows[$i];
        $prev = $rows[$i + 1];
        if ($prev['height'] !== $cur['height'] - $interval) { continue; }   // only adjacent boundaries (a gap would give a wrong % change)
        $pct = $prev['difficulty'] > 0 ? ($cur['difficulty'] - $prev['difficulty']) / $prev['difficulty'] * 100 : 0.0;
        $out[] = [
            'height'     => $cur['height'],
            'time'       => $cur['time'],
            'difficulty' => $cur['difficulty'],
            'pct_change' => $pct,
            'timespan'   => $cur['time'] - $prev['time'],
        ];
    }
    // Cache only a complete table (every boundary header resolved); a transient
    // partial/empty result is returned but NOT cached, so it self-heals next request.
    if ($out && count($rows) === count($heights)) {
        cache_set($ckey, json_encode($out, JSON_UNESCAPED_SLASHES), 1800);
    }
    return $out;
}

/**
 * Estimated network hashrate (H/s) via Core's getnetworkhashps over the last
 * $nblocks. Core divides the work done by the ACTUAL elapsed time across the
 * window, so it's robust to a single min-difficulty testnet block (unlike a
 * one-block "difficulty * 2^32 / target-spacing" estimate). Cached ~60s, soft;
 * returns 0.0 when unavailable so the caller can fall back.
 */
function ts_network_hashrate(array $net, int $nblocks = 120): float
{
    return (float) cache_remember('nethash:' . $net['slug'] . ':' . $nblocks, 60, function () use ($net, $nblocks) {
        $r = ts_rpc_soft($net, 'getnetworkhashps', [$nblocks]);
        return (is_int($r) || is_float($r)) && $r > 0 ? (float) $r : 0.0;
    });
}

/**
 * Deterministic coin-supply + halving stats for the fixed-subsidy Litecoin chain.
 * Pure arithmetic from the tip height + consensus params (no RPC), so it is free
 * to compute every request. Returns null for chains without a fixed subsidy
 * schedule. Amounts are integer satoshis. Litecoin: 840k-block halving interval,
 * 84M cap, 50-LTC start, 150s target spacing.
 */
function ts_supply_info(array $net): ?array
{
    switch ($net['coin'] ?? '') {
        case 'ltc': $interval = 840000; $maxCoins = 84000000; $spacing = 150; break;
        default:    return null;
    }
    $tip = ts_tip_height($net);
    if ($tip < 0) {
        return null;
    }
    // Sum every block's subsidy over heights 0..tip using the halving schedule.
    $subsidy = 5000000000;          // 50 coins, in satoshis
    $blocks  = $tip + 1;            // heights 0..tip inclusive
    $mined   = 0;
    while ($blocks > 0 && $subsidy > 0) {
        $take   = $blocks < $interval ? $blocks : $interval;
        $mined += $take * $subsidy;
        $blocks -= $take;
        $subsidy = intdiv($subsidy, 2);
    }
    $halvings     = intdiv($tip, $interval);
    $reward       = $halvings < 63 ? intdiv(5000000000, 1 << $halvings) : 0;
    $nextReward   = $halvings + 1 < 63 ? intdiv(5000000000, 1 << ($halvings + 1)) : 0;
    $nextHalving  = ($halvings + 1) * $interval;
    $toHalving    = $nextHalving - $tip;
    $maxSupplySat = $maxCoins * 100000000;
    return [
        'mined_sat'         => $mined,
        'reward_sat'        => $reward,
        'next_reward_sat'   => $nextReward,
        'max_supply_sat'    => $maxSupplySat,
        'pct_mined'         => $maxSupplySat > 0 ? $mined / $maxSupplySat * 100 : 0.0,
        'era'               => $halvings,
        'next_halving'      => $nextHalving,
        'blocks_to_halving' => $toHalving,
        'halving_eta'       => time() + $toHalving * $spacing,
        'halving_progress'  => $interval > 0 ? (($tip - $halvings * $interval) / $interval * 100) : 0.0,
    ];
}

/**
 * Node + chain health for the "Node & chain" card: one getblockchaininfo plus
 * one getnetworkinfo, cached ~20s. Soft RPC (never throws); returns null only
 * when the chain info itself is unreachable, with network fields degrading to 0.
 */
function ts_node_info(array $net): ?array
{
    return cache_remember('nodeinfo:' . $net['slug'], 20, function () use ($net) {
        $bc = ts_rpc_soft($net, 'getblockchaininfo');
        if (!is_array($bc)) {
            return null;
        }
        $nw = ts_rpc_soft($net, 'getnetworkinfo');
        $nw = is_array($nw) ? $nw : [];
        return [
            'chain'        => (string) ($bc['chain'] ?? ''),
            'blocks'       => (int) ($bc['blocks'] ?? 0),
            'headers'      => (int) ($bc['headers'] ?? 0),
            'size_on_disk' => (int) ($bc['size_on_disk'] ?? 0),
            'progress'     => (float) ($bc['verificationprogress'] ?? 0),
            'pruned'       => (bool) ($bc['pruned'] ?? false),
            'connections'  => (int) ($nw['connections'] ?? 0),
            'subversion'   => trim((string) ($nw['subversion'] ?? ''), '/'),
            'protocol'     => (int) ($nw['protocolversion'] ?? 0),
        ];
    });
}

/**
 * Fuller node + chain + mempool + uptime bundle for the dedicated Node page.
 * All soft RPC (never throws on RPC-level errors); returns null only when the
 * daemon itself is unreachable. Cached ~15s. Superset of ts_node_info.
 */
function ts_node_report(array $net): ?array
{
    return cache_remember('nodereport:' . $net['slug'], 15, function () use ($net) {
      try {
        // One batched round-trip instead of four serial RPCs.
        $res = ts_rpc_batch($net, [
            ['getblockchaininfo', []],
            ['getnetworkinfo', []],
            ['getmempoolinfo', []],
            ['uptime', []],
        ]);
        $bc = is_array($res[0] ?? null) ? $res[0] : null;
        if ($bc === null) {
            return null;
        }
        $nw = is_array($res[1] ?? null) ? $res[1] : [];
        $mp = is_array($res[2] ?? null) ? $res[2] : [];
        $upt = $res[3] ?? null;   // integer seconds, or null on older nodes
        $warn = isset($bc['warnings']) && $bc['warnings'] !== '' ? $bc['warnings'] : ($nw['warnings'] ?? '');
        if (is_array($warn)) { $warn = implode(' ', $warn); }
        return [
            'chain'          => (string) ($bc['chain'] ?? ''),
            'blocks'         => (int) ($bc['blocks'] ?? 0),
            'headers'        => (int) ($bc['headers'] ?? 0),
            'besthash'       => (string) ($bc['bestblockhash'] ?? ''),
            'difficulty'     => (float) ($bc['difficulty'] ?? 0),
            'mediantime'     => (int) ($bc['mediantime'] ?? 0),
            'progress'       => (float) ($bc['verificationprogress'] ?? 0),
            'size_on_disk'   => (int) ($bc['size_on_disk'] ?? 0),
            'pruned'         => (bool) ($bc['pruned'] ?? false),
            'ibd'            => (bool) ($bc['initialblockdownload'] ?? false),
            'version'        => (int) ($nw['version'] ?? 0),
            'subversion'     => trim((string) ($nw['subversion'] ?? ''), '/'),
            'protocol'       => (int) ($nw['protocolversion'] ?? 0),
            'connections'    => (int) ($nw['connections'] ?? 0),
            'conn_in'        => (int) ($nw['connections_in'] ?? 0),
            'conn_out'       => (int) ($nw['connections_out'] ?? 0),
            'networkactive'  => (bool) ($nw['networkactive'] ?? true),
            'relayfee'       => (float) ($nw['relayfee'] ?? 0),
            'mempool_txs'    => (int) ($mp['size'] ?? 0),
            'mempool_bytes'  => (int) ($mp['bytes'] ?? 0),
            'mempool_usage'  => (int) ($mp['usage'] ?? 0),
            'mempool_max'    => (int) ($mp['maxmempool'] ?? 0),
            'mempool_minfee' => (float) ($mp['mempoolminfee'] ?? 0),
            'uptime'         => (is_int($upt) || is_float($upt)) ? (int) $upt : null,
            'warnings'       => (string) $warn,
        ];
      } catch (Throwable $e) {
        return null;   // transport/timeout/auth: degrade to the "daemon unreachable" view
      }
    });
}

/**
 * Mask a peer host for public display: privacy-preserving by default so the
 * explorer never publishes its node's exact peer IPs (a mild eclipse-attack
 * aid). IPv4 keeps the first two octets, IPv6 the first hextet, onion the first
 * few chars; the port is preserved. Returns [masked_addr, network_type].
 */
function ts_mask_peer_addr(string $addr): array
{
    $host = $addr;
    $port = '';
    if (substr($host, 0, 1) === '[') {                 // [ipv6]:port
        $rb = strpos($host, ']');
        if ($rb !== false) {
            $after = substr($host, $rb + 1);
            $host = substr($host, 1, $rb - 1);
            if (substr($after, 0, 1) === ':') { $port = substr($after, 1); }
        }
    } else {
        $cpos = strrpos($host, ':');
        if ($cpos !== false && strpos($host, ':') === $cpos) {  // single colon => host:port
            $port = substr($host, $cpos + 1);
            $host = substr($host, 0, $cpos);
        }
    }
    $lower = strtolower($host);
    if (substr($lower, -6) === '.onion') {
        $net = 'onion';
        $b = substr($host, 0, -6);
        $masked = (strlen($b) > 6 ? substr($b, 0, 6) : $b) . '….onion';
    } elseif (substr($lower, -4) === '.i2p') {
        $net = 'i2p';
        $masked = substr($host, 0, 6) . '….i2p';
    } elseif (strpos($host, ':') !== false) {
        $net = 'ipv6';
        $parts = explode(':', $host);
        $masked = $parts[0] . ':' . ($parts[1] ?? '') . ':…';
    } elseif (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host)) {
        $net = 'ipv4';
        $o = explode('.', $host);
        $masked = $o[0] . '.' . $o[1] . '.x.x';
    } else {
        $net = 'other';
        $masked = strlen($host) > 10 ? substr($host, 0, 10) . '…' : $host;
    }
    return [$port !== '' ? $masked . ':' . $port : $masked, $net];
}

/**
 * Connected peers (getpeerinfo), address-masked and summarized for the Node
 * page. Cached ~15s, soft. Sorts outbound first then oldest connection first.
 * Never throws: returns ok=false when the node is unreachable.
 */
function ts_node_peers(array $net): array
{
    return cache_remember('peers:' . $net['slug'], 15, function () use ($net) {
        try {
            $raw = ts_rpc_soft($net, 'getpeerinfo');
        } catch (Throwable $e) {
            $raw = null;   // node down: degrade to the "peers unavailable" state
        }
        if (!is_array($raw)) {
            return ['ok' => false, 'peers' => [], 'inbound' => 0, 'outbound' => 0, 'total' => 0, 'nets' => []];
        }
        $peers = [];
        $in = 0;
        $out = 0;
        $nets = [];
        foreach ($raw as $x) {
            if (!is_array($x)) { continue; }
            $inbound = !empty($x['inbound']);
            if ($inbound) { $in++; } else { $out++; }
            list($maddr, $ntype) = ts_mask_peer_addr((string) ($x['addr'] ?? ''));
            if (isset($x['network']) && is_string($x['network']) && $x['network'] !== '') { $ntype = $x['network']; }
            $nets[$ntype] = ($nets[$ntype] ?? 0) + 1;
            $peers[] = [
                'addr'      => $maddr,
                'network'   => $ntype,
                'subver'    => trim((string) ($x['subver'] ?? ''), '/'),
                'version'   => (int) ($x['version'] ?? 0),
                'inbound'   => $inbound,
                'conntime'  => (int) ($x['conntime'] ?? 0),
                'pingtime'  => isset($x['pingtime']) && $x['pingtime'] !== null ? (float) $x['pingtime'] : null,
                'bytessent' => (int) ($x['bytessent'] ?? 0),
                'bytesrecv' => (int) ($x['bytesrecv'] ?? 0),
                'synced'    => (int) ($x['synced_blocks'] ?? -1),
            ];
        }
        usort($peers, function ($a, $b) {
            if ($a['inbound'] !== $b['inbound']) { return $a['inbound'] ? 1 : -1; }
            return $a['conntime'] <=> $b['conntime'];
        });
        return ['ok' => true, 'peers' => $peers, 'inbound' => $in, 'outbound' => $out, 'total' => count($peers), 'nets' => $nets];
    });
}

/**
 * UTXO-set summary via gettxoutsetinfo. Passes hash_type 'none' to skip the
 * expensive muhash (cheap on the small testnet chainstates); falls back to the
 * default call for older nodes that reject the argument. Cached 5 min, fully
 * soft (a slow scan or timeout degrades to null instead of 500-ing the page).
 */
function ts_txoutset_info(array $net): ?array
{
    // Read-only: serve ONLY the value warmed by the snapshot cron
    // (ts_txoutset_refresh). We deliberately never compute inline here, because
    // gettxoutsetinfo scans the whole chainstate; a public /node hit must not be
    // able to trigger it (that would let requests pin FPM workers). Cold => null,
    // and the Node page renders a graceful "not available yet" state.
    $cached = json_decode((string) cache_get('txoutset:' . $net['slug']), true);
    return is_array($cached) ? $cached : null;
}

/**
 * Compute the UTXO-set summary (gettxoutsetinfo, hash_type 'none' to skip the
 * expensive muhash) and cache it ~6h. Litecoin has no coinstatsindex, so this is
 * always a minutes-long chainstate scan - hence the long cache + scan debounce.
 * Fully soft. Called by the snapshot cron so the Node page reads a warm value
 * instead of ever scanning inline on a request.
 */
function ts_txoutset_refresh(array $net, bool $force = false): ?array
{
    $key = 'txoutset:' . $net['slug'];
    // Only scan when the warm value has expired. Litecoin Core (0.21 base) has NO
    // coinstatsindex (that's a Bitcoin Core 22.0 feature Litecoin never rebased onto),
    // so gettxoutsetinfo ALWAYS walks the whole chainstate - minutes long, locking
    // cs_main. There's no fast path, so we scan rarely: the long cache below is the
    // refresh cadence, and the snapshot cron calling this every run is a cheap no-op
    // until it expires. $force (`snapshot.php --utxo`) bypasses the cache + debounce.
    if (!$force) {
        $cached = cache_get($key);
        if ($cached !== null) {
            $d = json_decode((string) $cached, true);
            return is_array($d) ? $d : null;
        }
        // Debounce so two overlapping cron runs can't both launch the expensive scan.
        if (cache_get($key . ':scan') !== null) {
            return null;
        }
    }
    cache_set($key . ':scan', '1', 1320);   // must outlive the scan below so two runs can't overlap
    try {
        // Long per-call timeout (20 min): cron-only warmer, and a coinstatsindex-less node
        // needs many minutes on a large chainstate that only grows. It's a CEILING, not a
        // fixed wait - the call returns the moment the scan finishes. 'none' skips the muhash.
        $r = ts_rpc_soft($net, 'gettxoutsetinfo', ['none'], 1200);
        if (!is_array($r)) {
            $r = ts_rpc_soft($net, 'gettxoutsetinfo', [], 1200);
        }
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($r)) {
        return null;
    }
    $data = [
        'height'       => (int) ($r['height'] ?? 0),
        'bestblock'    => (string) ($r['bestblock'] ?? ''),
        'transactions' => (int) ($r['transactions'] ?? 0),
        'txouts'       => (int) ($r['txouts'] ?? 0),
        'disk_size'    => (int) ($r['disk_size'] ?? 0),
        'total_amount' => (float) ($r['total_amount'] ?? 0),
    ];
    // 6h: the UTXO-set totals move slowly, and the scan is a minutes-long cs_main lock,
    // so refresh only a few times a day. Raise this if the lock disrupts your node.
    cache_set($key, json_encode($data), 21600);
    return $data;
}

/**
 * Per-block fee + size stats via getblockstats (no full-tx walk). Powers the
 * mempool.space-style block cards: median/min/max fee rate (sat/vB), tx count,
 * size, weight, total fee, subsidy, time. Cached forever once buried, short TTL
 * near the tip (reorg-safe). A limited stats filter keeps the RPC cheap. Returns
 * null if the block is unavailable (e.g. reorged out).
 */
/** The getblockstats field filter (shared so single + batch paths agree). */
function ts_blockstats_fields(): array
{
    return ['height', 'txs', 'total_size', 'total_weight', 'totalfee', 'subsidy',
        'feerate_percentiles', 'minfeerate', 'maxfeerate', 'time', 'avgfeerate'];
}

/** Map a raw getblockstats result to our shape. */
function ts_map_blockstats(array $s, string $hash, ?int $height): array
{
    $pct = $s['feerate_percentiles'] ?? [];   // [p10, p25, p50, p75, p90], sat/vB
    return [
        'height'      => (int) ($s['height'] ?? $height ?? 0),
        'hash'        => $hash,
        'txs'         => (int) ($s['txs'] ?? 0),
        'size'        => (int) ($s['total_size'] ?? 0),
        'weight'      => (int) ($s['total_weight'] ?? 0),
        'total_fee'   => (int) ($s['totalfee'] ?? 0),
        'subsidy'     => (int) ($s['subsidy'] ?? 0),
        'med_feerate' => (float) ($pct[2] ?? $s['avgfeerate'] ?? 0),
        'min_feerate' => (float) ($s['minfeerate'] ?? 0),
        'max_feerate' => (float) ($s['maxfeerate'] ?? 0),
        'avg_feerate' => (float) ($s['avgfeerate'] ?? 0),
        // p10/p25/p50/p75/p90 sat/vB (drives the fee-rates timeseries + block fee-range).
        'feerate_pcts' => [(float) ($pct[0] ?? 0), (float) ($pct[1] ?? 0), (float) ($pct[2] ?? 0), (float) ($pct[3] ?? 0), (float) ($pct[4] ?? 0)],
        'time'        => (int) ($s['time'] ?? 0),
    ];
}

function ts_block_stats(array $net, string $hash, ?int $height = null): ?array
{
    $ckey = 'bstats:' . $net['slug'] . ':' . $hash;
    $hit = cache_get($ckey);
    if ($hit !== null) {
        $d = json_decode($hit, true);
        if (is_array($d)) {
            return $d;
        }
    }
    $s = ts_rpc_soft($net, 'getblockstats', [$hash, ts_blockstats_fields()]);
    if (!is_array($s)) {
        return null;
    }
    $out = ts_map_blockstats($s, $hash, $height);
    $depth = ts_tip_height($net) - $out['height'];
    cache_set($ckey, json_encode($out, JSON_UNESCAPED_SLASHES), $depth > 100 ? 0 : 600);
    return $out;
}

/** Newest-first list of getblockstats for the last $count blocks (for the strip). */
function ts_recent_block_stats(array $net, int $count = 12): array
{
    $tip = ts_tip_height($net);
    $heights = [];
    for ($h = $tip; $h > $tip - $count && $h >= 0; $h--) { $heights[] = $h; }
    $hashes = ts_block_hashes($net, $heights);   // one batched getblockhash

    // Serve cache hits; batch the misses' getblockstats in a single round-trip.
    $stats = [];
    $need = [];
    foreach ($heights as $h) {
        if (!isset($hashes[$h])) { break; }
        $hash = $hashes[$h];
        $c = cache_get('bstats:' . $net['slug'] . ':' . $hash);
        if ($c !== null) {
            $d = json_decode($c, true);
            if (is_array($d)) { $stats[$h] = $d; continue; }
        }
        $need[$h] = $hash;
    }
    if ($need) {
        $calls = [];
        foreach ($need as $hash) { $calls[] = ['getblockstats', [$hash, ts_blockstats_fields()]]; }
        $res = ts_rpc_batch($net, $calls);
        $i = 0;
        foreach ($need as $h => $hash) {
            $s = $res[$i++] ?? null;
            if (is_array($s)) {
                $mapped = ts_map_blockstats($s, $hash, $h);
                cache_set('bstats:' . $net['slug'] . ':' . $hash, json_encode($mapped, JSON_UNESCAPED_SLASHES), ($tip - $mapped['height']) > 100 ? 0 : 600);
                $stats[$h] = $mapped;
            }
        }
    }
    // Assemble newest-first; SKIP any block whose stats failed (matches the old
    // skip-and-continue) so one transient per-item error can't truncate the strip.
    $out = [];
    foreach ($heights as $h) {
        if (!isset($stats[$h])) { continue; }
        $out[] = $stats[$h];
    }
    return $out;
}

/**
 * Per-tx mempool fee rates, highest first (for projected-block packing + the
 * goggles treemap). Each entry ['rate' => sat/vB float, 'vsize' => int]. From
 * getrawmempool(true), cached 5s, capped so downstream markup stays bounded.
 */
function ts_mempool_txfees(array $net): array
{
    return cache_remember('memtxfees:' . $net['slug'], 5, function () use ($net) {
        $verbose = ts_mempool_verbose($net);
        if (!$verbose) {
            return [];
        }
        $out = [];
        foreach ($verbose as $e) {
            $feeSat = isset($e['fees']['base']) ? coin_to_sat($e['fees']['base']) : coin_to_sat($e['fee'] ?? 0);
            $vs = (int) ($e['vsize'] ?? $e['size'] ?? 0);
            if ($vs > 0) {
                $out[] = ['rate' => $feeSat / $vs, 'vsize' => $vs];
            }
        }
        usort($out, function ($a, $b) {
            return $b['rate'] <=> $a['rate'];   // high fee rate first
        });
        return count($out) > 4000 ? array_slice($out, 0, 4000) : $out;
    });
}

/** Finalise one projected block: weighted-median rate + capped cells for the treemap. */
function ts_proj_block_finish(int $vs, ?float $min, ?float $max, int $count, int $fee, array $cells, bool $partial): array
{
    $half = $vs / 2; $c = 0;
    $med = $cells ? (float) $cells[count($cells) - 1]['rate'] : 0.0;
    foreach ($cells as $cell) {
        $c += $cell['vsize'];
        if ($c >= $half) { $med = (float) $cell['rate']; break; }
    }
    // Downsample into ~140 vsize-buckets (fee-ordered) so the goggles treemap
    // fills the whole column and the markup stays bounded even for a block packed
    // with thousands of txs. The merged cells' vsize still sums to $vs, so scaling
    // by $vs in ts_goggles_block fills 0-100% and preserves the fee distribution.
    if (count($cells) > 140 && $vs > 0) {
        $target = $vs / 140;
        $merged = [];
        $accVs = 0; $accRateVs = 0.0;
        foreach ($cells as $cell) {
            $accVs += (int) $cell['vsize'];
            $accRateVs += (float) $cell['rate'] * (int) $cell['vsize'];
            if ($accVs >= $target) {
                $merged[] = ['rate' => $accRateVs / $accVs, 'vsize' => $accVs];
                $accVs = 0; $accRateVs = 0.0;
            }
        }
        if ($accVs > 0) {
            $merged[] = ['rate' => $accRateVs / $accVs, 'vsize' => $accVs];
        }
        $cells = $merged;
    }
    return [
        'vsize' => $vs, 'min' => $min ?? 0.0, 'max' => $max ?? 0.0, 'med' => $med,
        'count' => $count, 'fee' => $fee, 'partial' => $partial,
        'cells' => $cells,
    ];
}

/**
 * mempool.space-compatible /api/v1/fees/mempool-blocks payload: the projected
 * blocks reshaped to {blockSize, blockVSize, nTx, totalFees, medianFee,
 * feeRange}. We only track virtual size, so blockSize mirrors blockVSize.
 * feeRange is an ascending 7-point fee-rate percentile sweep of each block.
 */
function ts_mempool_blocks_api(array $net): array
{
    $out = [];
    foreach (ts_projected_blocks($net, 8) as $b) {
        $vs    = (int) $b['vsize'];
        $cells = $b['cells'];                 // fee-ordered, highest rate first
        $range = [];
        if ($cells) {
            $asc   = array_reverse($cells);   // ascending by rate
            $total = 0;
            foreach ($asc as $c) { $total += (int) $c['vsize']; }
            $total = max(1, $total);
            foreach ([0.0, 0.10, 0.25, 0.5, 0.75, 0.9, 1.0] as $p) {
                $target = $p * $total; $acc = 0; $rate = (float) $asc[0]['rate'];
                foreach ($asc as $c) {
                    $acc += (int) $c['vsize'];
                    if ($acc >= $target) { $rate = (float) $c['rate']; break; }
                }
                $range[] = round($rate, 2);
            }
        } else {
            $range = [round((float) $b['min'], 2), round((float) $b['max'], 2)];
        }
        $out[] = [
            'blockSize'  => $vs,
            'blockVSize' => $vs,
            'nTx'        => (int) $b['count'],
            'totalFees'  => (int) $b['fee'],
            'medianFee'  => round((float) $b['med'], 2),
            'feeRange'   => $range,
        ];
    }
    return $out;
}

/**
 * /api/v1/statistics - mempool + fee-rate history from the snapshot store
 * (tools/snapshot.php cron). Empty array when no snapshots exist yet. Not a
 * byte-exact mempool.space clone; keys are self-describing. Newest last.
 */
function ts_statistics_api(array $net): array
{
    if (!function_exists('ts_stats_series')) {
        return [];
    }
    $out = [];
    foreach (ts_stats_series($net, 48) as $r) {
        $out[] = [
            'time'          => (int) $r['ts'],
            'tip_height'    => (int) $r['tip_height'],
            'mempool_count' => (int) $r['mempool_count'],
            'vsize'         => (int) $r['mempool_vsize'],
            'total_fee'     => (int) $r['mempool_fee'],
            'fastest_fee'   => (int) $r['fast_fee'],
            'fee_vsize'     => [
                (int) $r['t0'], (int) $r['t1'], (int) $r['t2'],
                (int) $r['t3'], (int) $r['t4'], (int) $r['t5'],
            ],
        ];
    }
    return $out;
}

/**
 * /api/v1/mining/pools[/:period] - coinbase-tag pool distribution over the
 * recent window (mempool.space-ish shape). Any :period segment is accepted but
 * ignored; the window is our fixed recent span.
 */
function ts_mining_pools_api(array $net, int $window = 50): array
{
    $d = ts_mining_distribution($net, $window);
    $pools = [];
    foreach ($d['pools'] as $i => $p) {
        $pools[] = [
            'name'       => (string) $p['name'],
            'blockCount' => (int) $p['count'],
            'rank'       => $i + 1,
            'share'      => round((float) $p['pct'], 2),
        ];
    }
    return ['blockCount' => (int) $d['window'], 'pools' => $pools];
}

/**
 * /api/v1/mining/hashrate[/:period] - estimated hashrate + difficulty over a
 * recent sampled window (oldest first), plus the current values.
 */
function ts_mining_hashrate_api(array $net, string $period = ''): array
{
    // mempool.space /mining/hashrate/:timePeriod - window (in blocks) follows the period.
    static $spanMap = ['24h' => 576, '3d' => 1728, '1w' => 4032, '1m' => 17280, '3m' => 51840,
                       '6m' => 103680, '1y' => 210240, '2y' => 420480, '3y' => 630720, 'all' => 1000000];
    $span = $spanMap[$period] ?? 12000;
    $hr = [];
    foreach (ts_difficulty_series($net, 45, $span) as $r) {
        $hr[] = [
            'timestamp'   => (int) $r['time'],
            'height'      => (int) $r['height'],
            'difficulty'  => (float) $r['difficulty'],
            'avgHashrate' => (float) $r['hashrate'],
        ];
    }
    $cur = $hr ? $hr[count($hr) - 1] : null;
    return [
        'currentHashrate'   => $cur ? $cur['avgHashrate'] : 0,
        'currentDifficulty' => $cur ? $cur['difficulty'] : 0,
        'hashrates'         => $hr,
    ];
}

/**
 * Projected "mempool blocks" (mempool.space style): pack pending txs (highest
 * fee first) into ~1 vMB blocks, most-urgent first. Each block carries fee range
 * + weighted-median + total vsize + tx count + total fee + per-tx cells (for the
 * goggles treemap). Up to $maxBlocks; the last may be a partial (not-yet-full) block.
 */
function ts_projected_blocks(array $net, int $maxBlocks = 8): array
{
    $txs = ts_mempool_txfees($net);
    if (!$txs) {
        return [];
    }
    $CAP = 1000000;                            // ~1 vMB per projected block
    $blocks = [];
    $vs = 0; $fee = 0; $count = 0; $min = null; $max = null; $cells = [];
    foreach ($txs as $t) {
        $rate = (float) $t['rate'];
        $tvs  = (int) $t['vsize'];
        $vs += $tvs;
        $fee += (int) round($rate * $tvs);
        $count++;
        $cells[] = ['rate' => $rate, 'vsize' => $tvs];
        if ($min === null || $rate < $min) { $min = $rate; }
        if ($max === null || $rate > $max) { $max = $rate; }
        if ($vs >= $CAP) {
            $blocks[] = ts_proj_block_finish($vs, $min, $max, $count, $fee, $cells, false);
            if (count($blocks) >= $maxBlocks) {
                return $blocks;
            }
            $vs = 0; $fee = 0; $count = 0; $min = null; $max = null; $cells = [];
        }
    }
    if ($vs > 0 && count($blocks) < $maxBlocks) {
        $blocks[] = ts_proj_block_finish($vs, $min, $max, $count, $fee, $cells, true);
    }
    return $blocks;
}

/**
 * The transactions packed into projected block $index (0-based), fee-ordered
 * highest first, each ['txid','rate' (sat/vB),'vsize','fee' (sat)]. Uses the
 * exact ~1 vMB packing rule ts_projected_blocks() does, so block $index here is
 * the same block shown there. Bounded to $limit rows for display. Cached 5s.
 */
function ts_projected_block_txlist(array $net, int $index, int $limit = 60): array
{
    if ($index < 0) {
        return [];
    }
    return cache_remember('projtxs:' . $net['slug'] . ':' . $index . ':' . $limit, 5, function () use ($net, $index, $limit) {
        $verbose = ts_mempool_verbose($net);
        if (!$verbose) {
            return [];
        }
        $rows = [];
        foreach ($verbose as $txid => $e) {
            $vs = (int) ($e['vsize'] ?? $e['size'] ?? 0);
            if ($vs <= 0) {
                continue;
            }
            $feeSat = isset($e['fees']['base']) ? coin_to_sat($e['fees']['base']) : coin_to_sat($e['fee'] ?? 0);
            $rows[] = ['txid' => (string) $txid, 'rate' => $feeSat / $vs, 'vsize' => $vs, 'fee' => $feeSat];
        }
        usort($rows, function ($a, $b) {
            return $b['rate'] <=> $a['rate'];   // highest fee rate first
        });
        if (count($rows) > 4000) {
            $rows = array_slice($rows, 0, 4000);   // match ts_mempool_txfees' cap so block boundaries agree
        }
        $CAP = 1000000;
        $acc = 0; $blk = 0; $out = [];
        foreach ($rows as $r) {
            if ($blk === $index) {
                if (count($out) < $limit) { $out[] = $r; }
            } elseif ($blk > $index) {
                break;
            }
            $acc += $r['vsize'];
            if ($acc >= $CAP) { $blk++; $acc = 0; }   // crossing tx counts in the current block, matching ts_projected_blocks
        }
        return $out;
    });
}

/**
 * Confirmation ETA for an unconfirmed tx: which projected mempool block does its
 * fee rate reach? Projected blocks are packed highest-fee-first, so the tx lands
 * in the first block whose fee floor it clears. Returns a short human label.
 */
function ts_tx_eta(array $net, float $feeRate): string
{
    $spacing = ($net['coin'] ?? '') === 'ltc' ? 150 : 600;
    $proj = ts_projected_blocks($net, 8);
    if (!$proj) {
        return '~ next block · ~' . round($spacing / 60) . ' min';   // ~empty mempool
    }
    $pos = null;
    foreach ($proj as $i => $p) {
        if ($feeRate >= (float) $p['min']) { $pos = $i; break; }
    }
    if ($pos === null) {
        return 'low priority - beyond the next ' . count($proj) . ' blocks';
    }
    $blocks = $pos + 1;
    $secs = $blocks * $spacing;
    $tstr = $secs < 3600 ? round($secs / 60) . ' min' : round($secs / 3600, 1) . ' hr';
    return ($blocks === 1 ? '~ next block' : '~ ' . $blocks . ' blocks') . ' · ~' . $tstr;
}

/**
 * Explicit mempool position for an unconfirmed tx (mempool.space style): which
 * projected block it lands in, out of how many, and the higher-fee vsize packed
 * ahead of it. Shares the projection with ts_tx_eta. Returns null when the
 * mempool is empty or the tx is beyond the projected horizon.
 */
function ts_mempool_position(array $net, float $feeRate): ?array
{
    $proj = ts_projected_blocks($net, 8);
    if (!$proj) {
        return null;
    }
    $ahead = 0;
    foreach ($proj as $i => $p) {
        if ($feeRate >= (float) $p['min']) {
            return ['block' => $i + 1, 'blocks_total' => count($proj), 'vsize_ahead' => $ahead];
        }
        $ahead += (int) $p['vsize'];
    }
    return null;   // beyond the projected horizon
}

/**
 * Detect an RBF replacement of an unconfirmed tx via gettxspendingprevout
 * (Bitcoin Core 24+). If a DIFFERENT mempool tx now spends one of this tx's
 * inputs, this tx was replaced (or conflicts); returns that txid, else null.
 * Soft-fails to null on nodes without the RPC (older litecoind).
 */
function ts_tx_replacement(array $net, array $tx): ?string
{
    if (!empty($tx['status']['confirmed'])) {
        return null;
    }
    $outpoints = [];
    foreach ($tx['vin'] as $vi) {
        if (empty($vi['is_coinbase'])) {
            $outpoints[] = ['txid' => $vi['txid'], 'vout' => (int) $vi['vout']];
        }
    }
    if (!$outpoints) {
        return null;
    }
    // Short-cache so a refresh loop / crawler on one unconfirmed txid coalesces
    // into a single gettxspendingprevout round-trip. Cached value is a txid or ''.
    $txid = $tx['txid'];
    $spender = cache_remember('rbf:' . $net['slug'] . ':' . $txid, 5, function () use ($net, $outpoints, $txid) {
        $res = ts_rpc_soft($net, 'gettxspendingprevout', [$outpoints]);
        if (!is_array($res)) {
            return '';
        }
        foreach ($res as $r) {
            if (!is_array($r)) {
                continue;   // tolerate a non-conforming node's response element
            }
            $s = $r['spendingtxid'] ?? null;
            if ($s !== null && $s !== $txid) {
                return (string) $s;
            }
        }
        return '';
    });
    return ($spender !== null && $spender !== '') ? (string) $spender : null;
}

/**
 * Forward RBF replacement chain for an unconfirmed tx: this tx -> its replacer
 * -> ... following ts_tx_replacement at each hop until a still-live or confirmed
 * tx (or the cap). Returns a list of ['txid','confirmed','feerate'] in order.
 * Empty for a tx that has not been replaced.
 */
function ts_rbf_chain(array $net, array $tx, int $max = 6): array
{
    if (!empty($tx['status']['confirmed'])) {
        return [];
    }
    $chain = [];
    $cur = $tx;
    $seen = [$tx['txid'] => true];
    for ($i = 0; $i < $max; $i++) {
        $repl = ts_tx_replacement($net, $cur);
        if ($repl === null || isset($seen[$repl])) {
            break;
        }
        $seen[$repl] = true;
        $next = ts_find_tx($net, $repl);
        $vs = $next ? (int) ceil(($next['weight'] ?? 0) / 4) : 0;
        $chain[] = [
            'txid'      => $repl,
            'confirmed' => $next ? !empty($next['status']['confirmed']) : false,
            'feerate'   => ($next && $vs > 0) ? ($next['fee'] ?? 0) / $vs : null,
        ];
        if (!$next || !empty($next['status']['confirmed'])) {
            break;
        }
        $cur = $next;
    }
    return $chain;
}

/** One related-tx row (txid + fee rate) for the CPFP package display. */
function ts_cpfp_relrow(array $net, string $id): array
{
    $t = ts_esplora_tx($net, $id);
    $vs = $t ? (int) ceil(($t['weight'] ?? 0) / 4) : 0;
    return ['txid' => $id, 'feerate' => ($t && $vs > 0) ? ($t['fee'] ?? 0) / $vs : null];
}

/**
 * CPFP package neighbours for an unconfirmed tx from getmempoolentry: its
 * unconfirmed ancestors (depends) and descendants (spentby), each with a fee
 * rate, plus the total ancestor/descendant counts. Returns null when the tx is
 * a standalone package (or is confirmed / not in the mempool).
 */
function ts_cpfp_package(array $net, string $txid): ?array
{
    $e = ts_mempool_entry($net, $txid);
    if (!is_array($e)) {
        return null;
    }
    $depends = is_array($e['depends'] ?? null) ? $e['depends'] : [];
    $spentby = is_array($e['spentby'] ?? null) ? $e['spentby'] : [];
    if (!$depends && !$spentby) {
        return null;
    }
    $out = [
        'ancestors'   => (int) ($e['ancestorcount'] ?? 1),
        'descendants' => (int) ($e['descendantcount'] ?? 1),
        'depends'     => [],
        'spentby'     => [],
    ];
    foreach (array_slice($depends, 0, 12) as $id) {
        $out['depends'][] = ts_cpfp_relrow($net, $id);
    }
    foreach (array_slice($spentby, 0, 12) as $id) {
        $out['spentby'][] = ts_cpfp_relrow($net, $id);
    }
    return $out;
}

/**
 * Difficulty + estimated hashrate sampled across recent history, oldest-first,
 * for the mining charts. Samples $points heights spread over the last ~$span
 * blocks and resolves them in just TWO batched round-trips (one getblockhash
 * batch, one getblockheader batch). Cached ~10 min. Hashrate = difficulty *
 * 2^32 / target-spacing.
 */
function ts_difficulty_series(array $net, int $points = 24, int $span = 12000): array
{
    $points = max(2, min(60, $points));
    return cache_remember('diffseries:' . $net['slug'] . ':' . $points . ':' . $span, 600, function () use ($net, $points, $span) {
        $tip = ts_tip_height($net);
        if ($tip < 1) {
            return [];
        }
        $step = max(1, intdiv(min($span, $tip), $points));
        $heights = [];
        for ($h = $tip; $h >= 0 && count($heights) < $points; $h -= $step) {
            $heights[] = $h;
        }
        $heights = array_reverse($heights);   // oldest first

        $calls = [];
        foreach ($heights as $hh) {
            $calls[] = ['getblockhash', [$hh]];
        }
        $hashes = ts_rpc_batch($net, $calls);

        $hdrCalls = [];
        $idxMap   = [];
        foreach ($hashes as $i => $hash) {
            if (is_string($hash)) {
                $hdrCalls[] = ['getblockheader', [$hash, true]];
                $idxMap[]   = $i;
            }
        }
        $hdrs = ts_rpc_batch($net, $hdrCalls);

        $spacing = $net['coin'] === 'ltc' ? 150 : 600;
        $out = [];
        foreach ($idxMap as $k => $i) {
            $hdr = $hdrs[$k];
            if (!is_array($hdr)) {
                continue;
            }
            $diff = (float) ($hdr['difficulty'] ?? 0);
            $out[] = [
                'height'     => $heights[$i],
                'time'       => (int) ($hdr['time'] ?? 0),
                'difficulty' => $diff,
                'hashrate'   => $diff > 0 ? $diff * 4294967296 / $spacing : 0,
            ];
        }
        return $out;
    });
}

// ---- extras (RBF/CPFP, merkleblock-proof, health) -------------------------

/** getmempoolentry for an unconfirmed tx (RBF flag + ancestor/descendant), or null. */
function ts_mempool_entry(array $net, string $txid): ?array
{
    $e = ts_rpc_soft($net, 'getmempoolentry', [$txid]);
    return is_array($e) ? $e : null;
}

/** /tx/:txid/merkleblock-proof: bitcoind merkleblock hex via gettxoutproof. */
function ts_merkleblock_proof(array $net, string $txid, ?string $blockhash = null): ?string
{
    $params = $blockhash !== null ? [[$txid], $blockhash] : [[$txid]];
    $r = ts_rpc_soft($net, 'gettxoutproof', $params);
    return is_string($r) ? $r : null;
}

/** Health snapshot for /{coin}/{net}/api/health and the status page. */
function ts_health(array $net): array
{
    $rpcOk = false;
    $height = null;
    $tipHash = null;
    $mempoolCount = null;
    try {
        $height = (int) ts_rpc($net, 'getblockcount');
        $rpcOk = true;
        $bh = ts_rpc_soft($net, 'getbestblockhash');
        if (is_string($bh)) {
            $tipHash = $bh;
        }
        $mi = ts_rpc_soft($net, 'getmempoolinfo');
        if (is_array($mi)) {
            $mempoolCount = (int) ($mi['size'] ?? 0);
        }
    } catch (Throwable $e) {
        $rpcOk = false;
    }
    $electrumOk = false;
    try {
        ts_electrum($net)->request('server.ping');
        $electrumOk = true;
    } catch (Throwable $e) {
        $electrumOk = false;
    }
    return [
        'network'  => $net['slug'],
        'ok'       => $rpcOk && $electrumOk,
        'rpc'      => ['ok' => $rpcOk, 'height' => $height],
        'electrum' => ['ok' => $electrumOk],
        'mempool'  => $mempoolCount,
        'tip_hash' => $tipHash,
    ];
}

// ---- dev tools (decode script / PSBT, encode OP_RETURN, verify message) ----

/** decodescript: ASM, type, and derived p2sh/p2wsh addresses for a raw script hex. */
function ts_decode_script(array $net, string $hex): ?array
{
    $hex = preg_replace('/\s+/', '', trim($hex));
    if ($hex === '' || !ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
        return null;
    }
    $r = ts_rpc_soft($net, 'decodescript', [$hex]);
    return is_array($r) ? $r : null;
}

/** decodepsbt + analyzepsbt for a base64 PSBT. */
function ts_decode_psbt(array $net, string $psbt): ?array
{
    $psbt = trim($psbt);
    if ($psbt === '') {
        return null;
    }
    $decoded = ts_rpc_soft($net, 'decodepsbt', [$psbt]);
    if (!is_array($decoded)) {
        return null;
    }
    $analysis = ts_rpc_soft($net, 'analyzepsbt', [$psbt]);
    return ['decoded' => $decoded, 'analysis' => is_array($analysis) ? $analysis : null];
}

/** Build an OP_RETURN scriptPubKey (hex) from text or hex. Inverse of ts_parse_op_return. */
function ts_encode_op_return(string $input, bool $isHex): ?array
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    if ($isHex) {
        $input = preg_replace('/\s+/', '', $input);
        if (!ctype_xdigit($input) || strlen($input) % 2 !== 0) {
            return ['error' => 'not valid hex'];
        }
        $data = hex2bin($input);
    } else {
        $data = $input;
    }
    $len = strlen($data);
    if ($len <= 75) {
        $prefix = chr($len);
    } elseif ($len <= 255) {
        $prefix = chr(0x4c) . chr($len);
    } elseif ($len <= 520) {
        $prefix = chr(0x4d) . chr($len & 0xff) . chr(($len >> 8) & 0xff);
    } else {
        return ['error' => 'data too large for a single push (max 520 bytes)'];
    }
    $spk = chr(0x6a) . $prefix . $data;
    return [
        'data_hex'      => bin2hex($data),
        'data_len'      => $len,
        'scriptpubkey'  => bin2hex($spk),
        'over_standard' => $len > 80, // default datacarriersize relay limit is 80 bytes
    ];
}

/** verifymessage (legacy p2pkh signing only). Returns true/false, or null on bad input. */
function ts_verify_message(array $net, string $addr, string $sig, string $msg)
{
    $r = ts_rpc_soft($net, 'verifymessage', [$addr, $sig, $msg]);
    return is_bool($r) ? $r : null;
}
