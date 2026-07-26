<?php
/**
 * MWEB (MimbleWimble Extension Blocks) support for the Litecoin lanes.
 *
 * Everything here reads ONLY from litecoind's JSON-RPC, the same calls the
 * Esplora builders already use (getblock verbosity 1 for the txid list,
 * getrawtransaction verbose for each tx). It never touches the heavy getblock
 * verbosity-2 ".mweb" object (which 500s on some Core builds) and needs no
 * external analytics database.
 *
 * How MWEB shows up on the canonical chain:
 *   - Every post-activation block carries one HogEx (integration) transaction,
 *     conventionally the last tx. Its vout[0] is a witness_mweb_hogaddr output
 *     whose value is the ABSOLUTE MWEB supply at that height.
 *   - The HogEx's remaining outputs (vout[1:], ordinary public scripts) are
 *     peg-outs: coins leaving MWEB back to the public chain, each with a
 *     visible address + amount.
 *   - The HogEx's inputs are the previous block's HogEx output (the rolling
 *     supply) plus every peg-in output created in this block. A peg-in output
 *     is a witness_mweb_pegin script carrying a public amount and no address.
 *
 * So a single HogEx transaction, with its prevouts resolved (which the Esplora
 * tx builder already does), yields the whole per-block MWEB picture: supply and
 * peg-outs from its outputs, peg-ins from its inputs. No .mweb parsing needed.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Max heights per /api/mweb/blocks scan. Each height is a full HogEx fetch
// (several RPC round-trips when cold), so this is deliberately far lower than
// the wallet helper's raw-passthrough range, and the scan is also time-budgeted.
const LX_MWEB_MAX_RANGE = 30;

/** True iff MWEB features apply to this network and are enabled in config. */
function lx_mweb_enabled(array $net): bool
{
    if (($net['coin'] ?? '') !== 'ltc') {
        return false;
    }
    $flag = $net['mweb']['enabled'] ?? false;
    if ($flag === 'auto') {
        return lx_mweb_active($net)['active'];
    }
    return !empty($flag);
}

/** Configured MWEB activation height (below which no block is scanned). */
function lx_mweb_activation(array $net): int
{
    return (int) ($net['mweb']['activation'] ?? $net['mweb_activation'] ?? 0);
}

/**
 * Chain tip plus MWEB soft-fork state (short TTL). Never throws: on an RPC
 * failure it reports inactive rather than erroring, so callers can gate safely.
 */
function lx_mweb_active(array $net): array
{
    return cache_remember('mwebact:' . $net['slug'], 5, function () use ($net) {
        $c = lx_rpc_soft($net, 'getblockchaininfo');
        if (!is_array($c)) {
            return ['height' => 0, 'hash' => '', 'chain' => '', 'active' => false];
        }
        return [
            'height' => (int) ($c['blocks'] ?? 0),
            'hash'   => (string) ($c['bestblockhash'] ?? ''),
            'chain'  => (string) ($c['chain'] ?? ''),
            'active' => !empty($c['softforks']['mweb']['active']),
        ];
    });
}

/**
 * Classify one Esplora-mapped output/prevout (or raw Core scriptPubKey) as an
 * MWEB peg-in / hogaddr, or null. Prefers Core's scriptPubKey.type token and
 * falls back to the witness-program bytes so a Core build that does not label
 * the MWEB types cannot blind the detector:
 *   peg-in  = OP_9 (0x59) push-32 -> hex "5920..."
 *   hogaddr = OP_8 (0x58) push-32 -> hex "5820..."
 */
function lx_mweb_spk_kind(array $o): ?string
{
    $t = $o['scriptpubkey_type'] ?? ($o['type'] ?? '');
    if ($t === 'witness_mweb_pegin') {
        return 'pegin';
    }
    if ($t === 'witness_mweb_hogaddr') {
        return 'hogaddr';
    }
    $hex = $o['scriptpubkey'] ?? ($o['hex'] ?? '');
    $prefix = substr((string) $hex, 0, 4);
    if ($prefix === '5920') {
        return 'pegin';
    }
    if ($prefix === '5820') {
        return 'hogaddr';
    }
    return null;
}

/**
 * MWEB summary for an already-built Esplora tx, or null if the tx has no MWEB
 * outputs. Drives the tx-page badges and is reused by lx_mweb_block for the
 * HogEx. Note peg-ins here are this tx's own peg-in OUTPUTS (a peg-in tx);
 * block-level peg-ins are read from the HogEx inputs in lx_mweb_block.
 */
function lx_mweb_tx_info(array $tx): ?array
{
    $vout = $tx['vout'] ?? [];
    if (!$vout) {
        return null;
    }
    $isHogex = lx_mweb_spk_kind($vout[0]) === 'hogaddr';

    $pegins = [];
    $peginTotal = 0;
    foreach ($vout as $n => $vo) {
        if (lx_mweb_spk_kind($vo) === 'pegin') {
            $v = (int) ($vo['value'] ?? 0);
            $pegins[] = ['n' => $n, 'value_sat' => $v];
            $peginTotal += $v;
        }
    }

    $pegouts = [];
    $pegoutTotal = 0;
    $supply = null;
    if ($isHogex) {
        $supply = (int) ($vout[0]['value'] ?? 0);
        foreach ($vout as $n => $vo) {
            if ($n === 0 || lx_mweb_spk_kind($vo) === 'hogaddr') {
                continue;   // vout[0] is the supply commitment, not a peg-out
            }
            $v = (int) ($vo['value'] ?? 0);
            $pegouts[] = ['n' => $n, 'value_sat' => $v, 'address' => $vo['scriptpubkey_address'] ?? null];
            $pegoutTotal += $v;
        }
    }

    if (!$isHogex && !$pegins) {
        return null;
    }
    return [
        'is_hogex'        => $isHogex,
        'supply_sat'      => $supply,
        'pegins'          => $pegins,
        'pegin_total_sat' => $peginTotal,
        'pegouts'         => $pegouts,
        'pegout_total_sat' => $pegoutTotal,
    ];
}

/**
 * Per-block MWEB summary derived from the block's HogEx transaction, or null
 * when the block is below activation or carries no HogEx. Cached forever by
 * block hash (immutable). Amounts are integer satoshis.
 */
function lx_mweb_block(array $net, string $hash, ?int $knownHeight = null): ?array
{
    $ckey = 'mwebblk:' . $net['slug'] . ':' . $hash;
    $hit = cache_get($ckey);
    if ($hit !== null) {
        if ($hit === 'null') {
            return null;
        }
        $d = json_decode($hit, true);
        if (is_array($d)) {
            return $d;
        }
    }

    // Index fast path: when the peg index is enabled and fresh, serve confirmed
    // blocks straight from SQLite. A miss (a not-yet-indexed or zero-activity
    // block) falls through to the RPC walk below, so correctness never depends
    // on the index; it is purely an acceleration layer.
    if (lx_mweb_index_ready($net)) {
        $row = lx_mweb_index_block_by_hash($net, $hash);
        if ($row !== null) {
            cache_set($ckey, json_encode($row, JSON_UNESCAPED_SLASHES), 0);
            return $row;
        }
    }

    // Height is used only to short-circuit pre-activation blocks; callers that
    // already know it (range/recent walks) pass it in to save an RPC round-trip.
    $height = $knownHeight ?? lx_block_height_for_hash($net, $hash);
    if ($height !== null && $height < lx_mweb_activation($net)) {
        return null;   // pre-activation, cannot carry a HogEx
    }

    $txids = lx_block_txids($net, $hash);
    if (!$txids) {
        return null;
    }

    // The HogEx is conventionally the last tx; verify by vout[0] type and scan
    // back a couple of positions for robustness across builds.
    $hogex = null;
    $hogexTxid = null;
    $fetchFailed = false;
    $lim = max(0, count($txids) - 3);
    for ($i = count($txids) - 1; $i >= $lim; $i--) {
        $cand = lx_esplora_tx($net, $txids[$i], $hash);
        if ($cand === null) {
            $fetchFailed = true;   // transient RPC error, not a confirmed non-HogEx
            continue;
        }
        if (isset($cand['vout'][0]) && lx_mweb_spk_kind($cand['vout'][0]) === 'hogaddr') {
            $hogex = $cand;
            $hogexTxid = $txids[$i];
            break;
        }
    }
    if (!$hogex) {
        // Only cache the negative result permanently when every candidate read
        // genuinely succeeded (a real non-MWEB block, immutable by hash). If any
        // fetch failed transiently, do NOT poison the cache -- retry next time.
        if (!$fetchFailed) {
            cache_set($ckey, 'null', 0);
        }
        return null;
    }

    $info = lx_mweb_tx_info($hogex);

    // Peg-ins: the HogEx spends every peg-in output created in this block.
    $pegins = [];
    $peginTotal = 0;
    foreach ($hogex['vin'] as $vi) {
        $po = $vi['prevout'] ?? null;
        if ($po !== null && lx_mweb_spk_kind($po) === 'pegin') {
            $v = (int) ($po['value'] ?? 0);
            $pegins[] = ['txid' => $vi['txid'], 'vout' => (int) $vi['vout'], 'value_sat' => $v];
            $peginTotal += $v;
        }
    }

    $pegouts = [];
    foreach ($info['pegouts'] as $p) {
        $pegouts[] = [
            'txid'      => $hogexTxid,
            'n'         => $p['n'],
            'value_sat' => $p['value_sat'],
            'address'   => $p['address'],
        ];
    }

    $blk = [
        'height'           => (int) $height,
        'hash'             => $hash,
        'block_time'       => (int) ($hogex['status']['block_time'] ?? 0),
        'hogex_txid'       => $hogexTxid,
        'supply_sat'       => $info['supply_sat'],
        'pegin_count'      => count($pegins),
        'pegin_total_sat'  => $peginTotal,
        'pegout_count'     => count($pegouts),
        'pegout_total_sat' => $info['pegout_total_sat'],
        'pegins'           => $pegins,
        'pegouts'          => $pegouts,
    ];
    cache_set($ckey, json_encode($blk, JSON_UNESCAPED_SLASHES), 0);
    return $blk;
}

/**
 * The last $limit blocks from the tip with their MWEB summary (all have a
 * HogEx, so all carry a supply figure; most have zero peg activity). Bounded
 * and cheap. Cached ~15s. Newest first.
 */
function lx_mweb_recent(array $net, int $limit = 15): array
{
    return cache_remember('mwebrecent:' . $net['slug'] . ':' . $limit, 15, function () use ($net, $limit) {
        if (lx_mweb_index_ready($net) && ($db = lx_mweb_index_pdo($net, false)) !== null) {
            try {
                $st = $db->prepare('SELECT * FROM mweb_blocks ORDER BY block_height DESC LIMIT ?');
                $st->bindValue(1, $limit, PDO::PARAM_INT);
                $st->execute();
                $rows = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $rows[] = lx_mweb_index_hydrate($db, $b);
                }
                if ($rows) {
                    return $rows;
                }
            } catch (Throwable $e) {
                // fall through to the live RPC walk
            }
        }
        $tip = lx_tip_height($net);
        $act = lx_mweb_activation($net);
        $deadline = microtime(true) + 6.0;   // bound the cold-cache first load
        $rows = [];
        for ($h = $tip; $h > $tip - $limit && $h >= $act; $h--) {
            if (microtime(true) > $deadline) {
                break;
            }
            $hash = lx_block_hash_at($net, $h);
            if ($hash === null) {
                continue;
            }
            $m = lx_mweb_block($net, $hash, $h);
            if ($m !== null) {
                $rows[] = $m;
            }
        }
        return $rows;
    });
}

/**
 * Height-range scan backing /api/mweb/blocks. Clamps the span, stops at the tip
 * (partial result, not an error) and returns only blocks with peg activity,
 * mirroring the wallet helper's ?from&to semantics.
 */
function lx_mweb_range(array $net, int $from, int $to): array
{
    // Bound the span up front so BOTH the index fast path and the RPC scan stay
    // capped (an uncapped index BETWEEN + per-row hydrate was a DoS vector).
    if ($to - $from + 1 > LX_MWEB_MAX_RANGE) {
        $to = $from + LX_MWEB_MAX_RANGE - 1;
    }
    // Index fast path: instant when the peg index is fresh.
    if (lx_mweb_index_ready($net) && ($db = lx_mweb_index_pdo($net, false)) !== null) {
        try {
            $st = $db->prepare('SELECT * FROM mweb_blocks WHERE block_height BETWEEN ? AND ? '
                . 'AND (pegin_count > 0 OR pegout_count > 0) ORDER BY block_height LIMIT ?');
            $st->bindValue(1, $from, PDO::PARAM_INT);
            $st->bindValue(2, $to, PDO::PARAM_INT);
            $st->bindValue(3, LX_MWEB_MAX_RANGE, PDO::PARAM_INT);
            $st->execute();
            $blocks = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $blocks[] = lx_mweb_index_hydrate($db, $b);
            }
            return ['from' => $from, 'to' => $to, 'blocks' => $blocks];
        } catch (Throwable $e) {
            // fall through to the bounded RPC scan
        }
    }
    $deadline = microtime(true) + 8.0;
    $blocks = [];
    $scanned = $from - 1;   // last height actually scanned; echoed back as 'to'
    for ($h = $from; $h <= $to; $h++) {
        if (microtime(true) > $deadline) {
            break;   // budget exhausted: return partial, client resumes from 'to'+1
        }
        $hash = lx_block_hash_at($net, $h);
        if ($hash === null) {
            break;   // past tip
        }
        $m = lx_mweb_block($net, $hash, $h);
        if ($m !== null && ($m['pegin_count'] > 0 || $m['pegout_count'] > 0)) {
            $blocks[] = $m;
        }
        $scanned = $h;
    }
    return ['from' => $from, 'to' => max($from, $scanned), 'blocks' => $blocks];
}

// ---- self-contained peg index (SQLite) ------------------------------------
//
// An optional acceleration layer. Seeded once from an mwebscan analytics DB
// (tools/mweb-seed.php) and kept fresh by tools/mweb-index.php, it lets the
// history views, supply chart and peg lists be served from indexed SQLite
// instead of walking blocks over RPC. Every read gates on lx_mweb_index_ready()
// and falls back to the RPC path, so the index is never a correctness dependency
// and can be wiped/rebuilt at will. All amounts are integer satoshis.

const LX_MWEB_INDEX_DDL = <<<'SQL'
CREATE TABLE IF NOT EXISTS mweb_blocks (
  block_height     INTEGER PRIMARY KEY,
  block_time       INTEGER NOT NULL,
  block_hash       TEXT    NOT NULL,
  hogex_txid       TEXT,
  supply_sat       INTEGER,
  pegin_count      INTEGER NOT NULL DEFAULT 0,
  pegin_total_sat  INTEGER NOT NULL DEFAULT 0,
  pegout_count     INTEGER NOT NULL DEFAULT 0,
  pegout_total_sat INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_mweb_blocks_hash ON mweb_blocks(block_hash);
CREATE INDEX IF NOT EXISTS idx_mweb_blocks_time ON mweb_blocks(block_time);
CREATE TABLE IF NOT EXISTS mweb_pegins (
  txid         TEXT    NOT NULL,
  vout         INTEGER NOT NULL,
  block_height INTEGER NOT NULL,
  block_time   INTEGER NOT NULL,
  value_sat    INTEGER NOT NULL,
  PRIMARY KEY (txid, vout)
);
CREATE INDEX IF NOT EXISTS idx_mweb_pegins_height ON mweb_pegins(block_height);
CREATE INDEX IF NOT EXISTS idx_mweb_pegins_amount ON mweb_pegins(value_sat);
CREATE TABLE IF NOT EXISTS mweb_pegouts (
  txid         TEXT    NOT NULL,
  n            INTEGER NOT NULL,
  block_height INTEGER NOT NULL,
  block_time   INTEGER NOT NULL,
  value_sat    INTEGER NOT NULL,
  address      TEXT,
  PRIMARY KEY (txid, n)
);
CREATE INDEX IF NOT EXISTS idx_mweb_pegouts_height  ON mweb_pegouts(block_height);
CREATE INDEX IF NOT EXISTS idx_mweb_pegouts_amount  ON mweb_pegouts(value_sat);
CREATE INDEX IF NOT EXISTS idx_mweb_pegouts_address ON mweb_pegouts(address);
CREATE TABLE IF NOT EXISTS mweb_supply_daily (
  day_ts       INTEGER PRIMARY KEY,
  block_height INTEGER NOT NULL,
  supply_sat   INTEGER NOT NULL,
  pegin_sat    INTEGER NOT NULL DEFAULT 0,
  pegout_sat   INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS mweb_meta (
  id                 INTEGER PRIMARY KEY CHECK (id = 1),
  last_indexed       INTEGER NOT NULL,
  last_hash          TEXT    NOT NULL,
  tip_time           INTEGER NOT NULL DEFAULT 0,
  current_supply_sat INTEGER NOT NULL DEFAULT 0,
  updated_at         INTEGER NOT NULL DEFAULT 0
);
SQL;

/**
 * Open the network's MWEB index DB. Static-cached per slug; never throws (a
 * cache is best-effort). With $create it runs the DDL and makes the directory;
 * without it, a missing file returns null rather than creating an empty DB.
 */
function lx_mweb_index_pdo(array $net, bool $create = false): ?PDO
{
    static $cache = [];
    $slug = $net['slug'];
    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }
    $path = $net['mweb']['index']['db'] ?? null;
    if (!$path) {
        return $cache[$slug] = null;
    }
    if (!$create && !is_file($path)) {
        return $cache[$slug] = null;
    }
    try {
        if ($create) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
        if ($create) {
            $db->exec(LX_MWEB_INDEX_DDL);
        }
        return $cache[$slug] = $db;
    } catch (Throwable $e) {
        return $cache[$slug] = null;
    }
}

/**
 * Whether the index is enabled, present, and within max_lag blocks of the tip.
 * Cached ~5s. When false, every read falls back to the live RPC path.
 */
function lx_mweb_index_ready(array $net): bool
{
    if (empty($net['mweb']['index']['enabled'])) {
        return false;
    }
    return cache_remember('mwebidxok:' . $net['slug'], 5, function () use ($net) {
        $db = lx_mweb_index_pdo($net, false);
        if (!$db) {
            return false;
        }
        try {
            $last = $db->query('SELECT last_indexed FROM mweb_meta WHERE id = 1')->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
        if ($last === false || $last === null) {
            return false;
        }
        $tip = lx_mweb_active($net)['height'];
        if ($tip <= 0) {
            return false;
        }
        $maxLag = (int) ($net['mweb']['index']['max_lag'] ?? 6);
        return ($tip - (int) $last) <= $maxLag;
    });
}

/** Build the lx_mweb_block-shaped array from an indexed mweb_blocks row. */
function lx_mweb_index_hydrate(PDO $db, array $b): array
{
    $h = (int) $b['block_height'];
    $pegins = [];
    $st = $db->prepare('SELECT txid, vout, value_sat FROM mweb_pegins WHERE block_height = ? ORDER BY vout');
    $st->execute([$h]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pegins[] = ['txid' => $r['txid'], 'vout' => (int) $r['vout'], 'value_sat' => (int) $r['value_sat']];
    }
    $pegouts = [];
    $st = $db->prepare('SELECT txid, n, value_sat, address FROM mweb_pegouts WHERE block_height = ? ORDER BY n');
    $st->execute([$h]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pegouts[] = ['txid' => $r['txid'], 'n' => (int) $r['n'], 'value_sat' => (int) $r['value_sat'], 'address' => $r['address']];
    }
    return [
        'height'           => $h,
        'hash'             => $b['block_hash'],
        'block_time'       => (int) ($b['block_time'] ?? 0),
        'hogex_txid'       => $b['hogex_txid'],
        'supply_sat'       => $b['supply_sat'] === null ? null : (int) $b['supply_sat'],
        'pegin_count'      => (int) $b['pegin_count'],
        'pegin_total_sat'  => (int) $b['pegin_total_sat'],
        'pegout_count'     => (int) $b['pegout_count'],
        'pegout_total_sat' => (int) $b['pegout_total_sat'],
        'pegins'           => $pegins,
        'pegouts'          => $pegouts,
    ];
}

/** Indexed block by hash, or null if absent. Same shape as lx_mweb_block. */
function lx_mweb_index_block_by_hash(array $net, string $hash): ?array
{
    $db = lx_mweb_index_pdo($net, false);
    if (!$db) {
        return null;
    }
    try {
        $st = $db->prepare('SELECT * FROM mweb_blocks WHERE block_hash = ? LIMIT 1');
        $st->execute([$hash]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return $b ? lx_mweb_index_hydrate($db, $b) : null;
}

/**
 * Downsampled supply time-series (one point per UTC day), oldest-first, for the
 * chart. Index-only: returns [] when the index is not ready.
 */
function lx_mweb_supply_series(array $net, int $limit = 400): array
{
    if (!lx_mweb_index_ready($net) || ($db = lx_mweb_index_pdo($net, false)) === null) {
        return [];
    }
    $limit = max(1, min(2000, $limit));
    try {
        $st = $db->prepare('SELECT day_ts, block_height, supply_sat, pegin_sat, pegout_sat '
            . 'FROM mweb_supply_daily ORDER BY day_ts DESC LIMIT ?');
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach (array_reverse($rows) as $r) {
        $out[] = [
            'day_ts'     => (int) $r['day_ts'],
            'height'     => (int) $r['block_height'],
            'supply_sat' => (int) $r['supply_sat'],
            'pegin_sat'  => (int) $r['pegin_sat'],
            'pegout_sat' => (int) $r['pegout_sat'],
        ];
    }
    return $out;
}

/**
 * Parse a "{height}:{txid}:{seq}" keyset cursor into [height, txid, seq], or
 * null if absent/malformed. Strict validation (values are also bound as params,
 * so this is defence in depth). $seq is the vout (peg-in) or n (peg-out).
 */
function lx_mweb_cursor_parse(?string $tok): ?array
{
    if ($tok === null || $tok === '') {
        return null;
    }
    $p = explode(':', $tok);
    if (count($p) !== 3 || !ctype_digit($p[0]) || !ctype_digit($p[2])
        || !preg_match('/^[0-9a-f]{1,64}$/i', $p[1])) {
        return null;
    }
    return [(int) $p[0], strtolower($p[1]), (int) $p[2]];
}

/**
 * A page of peg-ins newest-first. $before is an opaque keyset cursor token
 * ("{height}:{txid}:{vout}") over the full ORDER BY tuple, so pagination is
 * exact even when a single block carries more peg rows than the page limit
 * (a plain height-exclusive cursor would silently drop the rest of that block).
 * 'next' is the token to pass as the next ?before=, or null at the end.
 */
function lx_mweb_pegins_page(array $net, ?string $before = null, int $limit = 50): array
{
    $empty = ['pegins' => [], 'next' => null];
    if (!lx_mweb_index_ready($net) || ($db = lx_mweb_index_pdo($net, false)) === null) {
        return $empty;
    }
    $limit = max(1, min(100, $limit));
    $cur = lx_mweb_cursor_parse($before);
    try {
        $sql = 'SELECT txid, vout, block_height, block_time, value_sat FROM mweb_pegins '
            . ($cur !== null ? 'WHERE (block_height, txid, vout) < (?, ?, ?) ' : '')
            . 'ORDER BY block_height DESC, txid DESC, vout DESC LIMIT ?';
        $st = $db->prepare($sql);
        $i = 1;
        if ($cur !== null) {
            $st->bindValue($i++, $cur[0], PDO::PARAM_INT);
            $st->bindValue($i++, $cur[1], PDO::PARAM_STR);
            $st->bindValue($i++, $cur[2], PDO::PARAM_INT);
        }
        $st->bindValue($i, $limit + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $empty;
    }
    $next = null;
    if (count($rows) > $limit) {
        array_pop($rows);   // drop the lookahead row
        $last = $rows[count($rows) - 1];
        $next = $last['block_height'] . ':' . $last['txid'] . ':' . (int) $last['vout'];
    }
    $pegins = [];
    foreach ($rows as $r) {
        $pegins[] = [
            'txid'         => $r['txid'],
            'vout'         => (int) $r['vout'],
            'block_height' => (int) $r['block_height'],
            'block_time'   => (int) $r['block_time'],
            'value_sat'    => (int) $r['value_sat'],
        ];
    }
    return ['pegins' => $pegins, 'next' => $next];
}

/** A page of peg-outs newest-first. Same keyset-cursor semantics as lx_mweb_pegins_page. */
function lx_mweb_pegouts_page(array $net, ?string $before = null, int $limit = 50): array
{
    $empty = ['pegouts' => [], 'next' => null];
    if (!lx_mweb_index_ready($net) || ($db = lx_mweb_index_pdo($net, false)) === null) {
        return $empty;
    }
    $limit = max(1, min(100, $limit));
    $cur = lx_mweb_cursor_parse($before);
    try {
        $sql = 'SELECT txid, n, block_height, block_time, value_sat, address FROM mweb_pegouts '
            . ($cur !== null ? 'WHERE (block_height, txid, n) < (?, ?, ?) ' : '')
            . 'ORDER BY block_height DESC, txid DESC, n DESC LIMIT ?';
        $st = $db->prepare($sql);
        $i = 1;
        if ($cur !== null) {
            $st->bindValue($i++, $cur[0], PDO::PARAM_INT);
            $st->bindValue($i++, $cur[1], PDO::PARAM_STR);
            $st->bindValue($i++, $cur[2], PDO::PARAM_INT);
        }
        $st->bindValue($i, $limit + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $empty;
    }
    $next = null;
    if (count($rows) > $limit) {
        array_pop($rows);   // drop the lookahead row
        $last = $rows[count($rows) - 1];
        $next = $last['block_height'] . ':' . $last['txid'] . ':' . (int) $last['n'];
    }
    $pegouts = [];
    foreach ($rows as $r) {
        $pegouts[] = [
            'txid'         => $r['txid'],
            'n'            => (int) $r['n'],
            'block_height' => (int) $r['block_height'],
            'block_time'   => (int) $r['block_time'],
            'value_sat'    => (int) $r['value_sat'],
            'address'      => $r['address'],
        ];
    }
    return ['pegouts' => $pegouts, 'next' => $next];
}


/**
 * Aggregate peg totals + shielded supply for the privacy analytics panel.
 * Index-only (returns null when the index is not ready). Cached ~60s.
 */
function lx_mweb_peg_totals(array $net): ?array
{
    if (!lx_mweb_index_ready($net)) {
        return null;
    }
    return cache_remember('mwebtotals:' . $net['slug'], 60, function () use ($net) {
        $db = lx_mweb_index_pdo($net, false);
        if (!$db) {
            return null;
        }
        try {
            $pi  = $db->query('SELECT COUNT(*) c, COALESCE(SUM(value_sat),0) s FROM mweb_pegins')->fetch(PDO::FETCH_ASSOC);
            $po  = $db->query('SELECT COUNT(*) c, COALESCE(SUM(value_sat),0) s FROM mweb_pegouts')->fetch(PDO::FETCH_ASSOC);
            $sup = $db->query('SELECT current_supply_sat FROM mweb_meta WHERE id = 1')->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
        return [
            'pegin_count'      => (int) $pi['c'],
            'pegin_total_sat'  => (int) $pi['s'],
            'pegout_count'     => (int) $po['c'],
            'pegout_total_sat' => (int) $po['s'],
            'supply_sat'       => $sup !== false ? (int) $sup : 0,
        ];
    });
}

/**
 * One-poll snapshot for the live peg-flow visualization (/api/mweb/live). It just
 * assembles the already-cached producers so the hero can animate from a single
 * fetch: current shielded supply (+ last-block delta), kernel/output counts,
 * all-time totals (index only), the recent MWEB blocks with their individual peg
 * amounts to draw, and best-effort MWEBscan round-trip links. Works RPC-only
 * (totals/links simply come back null/[]). Cheap: every sub-call is memoized.
 */
function lx_mweb_live_snapshot(array $net): array
{
    $st     = lx_mweb_active($net);
    $recent = lx_mweb_recent($net, 12);
    // Newest two supply-bearing blocks give the absolute supply + last-block delta.
    $supply = null; $supplyPrev = null;
    foreach ($recent as $b) {
        if (!isset($b['supply_sat']) || $b['supply_sat'] === null) { continue; }
        if ($supply === null) { $supply = (int) $b['supply_sat']; }
        elseif ($supplyPrev === null) { $supplyPrev = (int) $b['supply_sat']; break; }
    }
    $kern    = lx_mweb_kernels($net);
    $kernels = is_array($kern) ? ($kern['kernels'] ?? null) : null;
    $outputs = is_array($kern) ? ($kern['outputs'] ?? null) : null;

    // Slim per-block summary (counts + supply). The individual peg detail the viz
    // draws comes from the rolling windows below, not per-block.
    $blocks = [];
    foreach ($recent as $b) {
        $blocks[] = [
            'height'           => (int) ($b['height'] ?? 0),
            'hash'             => (string) ($b['hash'] ?? ''),
            'block_time'       => (int) ($b['block_time'] ?? 0),
            'pegin_count'      => (int) ($b['pegin_count'] ?? 0),
            'pegin_total_sat'  => (int) ($b['pegin_total_sat'] ?? 0),
            'pegout_count'     => (int) ($b['pegout_count'] ?? 0),
            'pegout_total_sat' => (int) ($b['pegout_total_sat'] ?? 0),
            'supply_sat'       => (isset($b['supply_sat']) && $b['supply_sat'] !== null) ? (int) $b['supply_sat'] : null,
        ];
    }

    // Rolling windows of the most recent peg-ins / peg-outs, so the flow stays
    // populated across the many blocks that carry no pegs (per-block would be empty
    // most of the time). Prefer the index (newest N regardless of age); fall back to
    // flattening the recent blocks when the index is off.
    $rin = []; $rout = [];
    if (lx_mweb_index_ready($net)) {
        foreach (lx_mweb_pegins_page($net, null, 8)['pegins'] as $p) {
            $rin[] = ['value_sat' => (int) $p['value_sat'], 'height' => (int) $p['block_height'], 'txid' => (string) $p['txid']];
        }
        foreach (lx_mweb_pegouts_page($net, null, 8)['pegouts'] as $p) {
            $rout[] = ['value_sat' => (int) $p['value_sat'], 'height' => (int) $p['block_height'], 'txid' => (string) $p['txid']];
        }
    } else {
        foreach ($recent as $b) {
            foreach (($b['pegins'] ?? []) as $x) {
                if (count($rin) >= 8) { break; }
                $rin[] = ['value_sat' => (int) ($x['value_sat'] ?? 0), 'height' => (int) ($b['height'] ?? 0), 'txid' => (string) ($x['txid'] ?? '')];
            }
            foreach (($b['pegouts'] ?? []) as $x) {
                if (count($rout) >= 8) { break; }
                $rout[] = ['value_sat' => (int) ($x['value_sat'] ?? 0), 'height' => (int) ($b['height'] ?? 0), 'txid' => (string) ($x['txid'] ?? '')];
            }
            if (count($rin) >= 8 && count($rout) >= 8) { break; }
        }
    }

    // Best-effort round-trip links (external MWEBscan; [] when dormant/unreachable).
    $links = [];
    if (function_exists('lx_mwebscan_enabled') && lx_mwebscan_enabled($net)) {
        $lr = lx_mwebscan_api($net, 'links', ['limit' => '6', 'min_confidence' => '0.6'], 120);
        if (is_array($lr) && is_array($lr['links'] ?? null)) {
            foreach ($lr['links'] as $L) {
                $links[] = [
                    'pegout_txid'   => (string) ($L['pegout_txid'] ?? ''),
                    'pegin_txid'    => (string) ($L['pegin_txid'] ?? ''),
                    'pegout_amount' => (float) ($L['pegout_amount'] ?? 0),
                    'confidence'    => (float) ($L['confidence'] ?? 0),
                    'risk_score'    => (int) ($L['risk_score'] ?? 0),
                ];
            }
        }
    }

    return [
        'height'          => (int) ($st['height'] ?? 0),
        'active'          => (bool) ($st['active'] ?? false),
        'supply_sat'      => $supply,
        'supply_prev_sat' => $supplyPrev,
        'kernels'         => $kernels,
        'outputs'         => $outputs,
        'totals'          => lx_mweb_index_ready($net) ? lx_mweb_peg_totals($net) : null,
        'blocks'          => $blocks,
        'pegins'          => $rin,
        'pegouts'         => $rout,
        'links'           => $links,
    ];
}

/**
 * Parse the MWEB extension-block header counts for one block from `getblock
 * <hash> 2` -> `.mweb`. Note the field semantics observed on litecoind: num_txos
 * is CUMULATIVE (the append-only confidential output set, non-zero even when this
 * block adds none) while num_kernels is PER-BLOCK (the count of transaction
 * kernels in this block; 0 when the block carries no MWEB activity).
 *
 * The `.mweb` object only appears at verbosity 2, which is heavy and 500s on
 * some builds, so this is best-effort: any RPC failure or unrecognised shape
 * returns null and the caller hides the stat. Returns
 * ['kernels' => int, 'outputs' => int|null] or null.
 */
function lx_mweb_ext_counts(array $net, string $hash): ?array
{
    // getblock verbosity 2 is heavy and 500s on some litecoind builds; a transport
    // error or non-JSON 500 can propagate out of lx_rpc_soft, so guard it here and
    // hide the stat rather than take down the whole block/MWEB page.
    try {
        $b = lx_rpc_soft($net, 'getblock', [$hash, 2]);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($b) || empty($b['mweb']) || !is_array($b['mweb'])) {
        return null;   // pre-activation, or a build without the verbosity-2 .mweb object
    }
    $m = $b['mweb'];
    // Field names differ slightly across builds; accept the known spellings and
    // only trust a numeric scalar (the bare `kernels`/`outputs` keys are arrays).
    $pick = function (array $src, array $keys) {
        foreach ($keys as $k) {
            if (isset($src[$k]) && is_numeric($src[$k])) {
                return (int) $src[$k];
            }
        }
        return null;
    };
    $kernels = $pick($m, ['num_kernels', 'n_kernels', 'kernel_count']);
    if ($kernels === null) {
        return null;   // shape we can't read -> hide rather than show a wrong number
    }
    return [
        'kernels' => $kernels,
        'outputs' => $pick($m, ['num_txos', 'n_txos', 'num_outputs', 'output_count']),
    ];
}

/**
 * The chain tip's MWEB header counts (cumulative output set + this-block kernels).
 * Cached ~90s. Returns ['kernels' => int, 'outputs' => int|null] or null.
 */
function lx_mweb_kernels(array $net): ?array
{
    if (!lx_mweb_enabled($net)) {
        return null;
    }
    // Cache a sentinel for the null/unavailable case too (cache_remember would drop
    // a null return and re-run this heavy getblock-2 on every hit against a slow or
    // verbosity-2-less node).
    $got = cache_remember('mwebkernels:' . $net['slug'], 90, function () use ($net) {
        $hash = lx_rpc_soft($net, 'getbestblockhash');
        $c = is_string($hash) ? lx_mweb_ext_counts($net, $hash) : null;
        return $c === null ? ['k' => -1] : $c;
    });
    return (is_array($got) && !isset($got['k'])) ? $got : null;
}

/**
 * The number of MWEB transaction kernels in one block (its confidential
 * transaction count), or null if unavailable. Cached long by hash since a
 * confirmed block is immutable.
 */
function lx_mweb_block_kernels(array $net, string $hash): ?int
{
    if (!lx_mweb_enabled($net)) {
        return null;
    }
    $key = 'mwebblkkern:' . $net['slug'] . ':' . $hash;
    $hit = cache_get($key);
    if ($hit !== null) {
        $v = (int) $hit;
        return $v >= 0 ? $v : null;   // -1 is the cached "unavailable" sentinel
    }
    $c = lx_mweb_ext_counts($net, $hash);
    if ($c === null) {
        cache_set($key, '-1', 1800);   // node lacks verbosity-2 or a transient miss: retry in ~30 min
        return null;
    }
    cache_set($key, (string) $c['kernels'], 2592000);   // immutable block: cache long
    return (int) $c['kernels'];
}

/**
 * Deep link to the same MWEB block on MWEBscan (the sister explorer), or null
 * when not configured. Set a URL template with {hash}/{height} placeholders in
 * config.php as 'mwebscan_block' (global) or per-network $net['mwebscan_block'],
 * e.g. 'https://testnet.mwebscan.com/block/{hash}'. Dormant until then, so no
 * broken links appear if MWEBscan has no block pages yet.
 */
function lx_mwebscan_block_url(array $net, string $hash, int $height): ?string
{
    $tpl = $net['mwebscan_block'] ?? (lx_config()['mwebscan_block'] ?? null);
    if (!is_string($tpl) || strpos($tpl, '{') === false) {
        return null;
    }
    return str_replace(['{hash}', '{height}'], [$hash, (string) $height], $tpl);
}

/**
 * Reused peg-out destination addresses (a deanonymization signal): addresses
 * that received more than one MWEB peg-out, ranked by count then value. Each:
 * ['address','count','total_sat','first_h','last_h']. Index-only; empty without
 * it. Cached ~2 min.
 */
function lx_mweb_pegout_clusters(array $net, int $limit = 15): array
{
    if (!lx_mweb_index_ready($net)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    return cache_remember('mwebclusters:' . $net['slug'] . ':' . $limit, 120, function () use ($net, $limit) {
        $db = lx_mweb_index_pdo($net, false);
        if (!$db) {
            return [];
        }
        try {
            $st = $db->prepare('SELECT address, COUNT(*) AS n, COALESCE(SUM(value_sat),0) AS total, '
                . 'MIN(block_height) AS first_h, MAX(block_height) AS last_h '
                . 'FROM mweb_pegouts WHERE address IS NOT NULL AND address != \'\' '
                . 'GROUP BY address HAVING n > 1 ORDER BY n DESC, total DESC LIMIT ?');
            $st->bindValue(1, $limit, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'address'   => (string) $r['address'],
                'count'     => (int) $r['n'],
                'total_sat' => (int) $r['total'],
                'first_h'   => (int) $r['first_h'],
                'last_h'    => (int) $r['last_h'],
            ];
        }
        return $out;
    });
}
