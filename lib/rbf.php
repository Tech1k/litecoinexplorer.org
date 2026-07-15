<?php
/**
 * RBF replacement tracking - powers /api/v1/replacements, /api/v1/tx/:txid/rbf and the
 * WebSocket track-rbf stream. litecoind has no "what replaced tx X" index, so we detect
 * replacements by watching the mempool over time: when a NEW tx spends an input outpoint
 * that a now-departed mempool tx also spent, the new tx replaced the old one (BIP125 opt-in
 * or full-RBF). Best-effort and bounded; shared by the snapshot cron and the WS daemon
 * (both call ts_rbf_tick). The first-input outpoint is used as the conflict key - one shared
 * input is enough to conflict, and typical fee-bumps reuse their inputs.
 *
 * Store: db/rbf.sqlite (next to the cache).
 *   rbf_pool   - outpoint -> current spender (rolling live-mempool view, the detection state)
 *   rbf_event  - detected replacements, with both txs' details captured at replace time
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

function ts_rbf_db_path(): ?string
{
    $cache = ts_config()['cache_db'] ?? null;
    return $cache ? dirname($cache) . '/rbf.sqlite' : null;
}

function ts_rbf_db(bool $create = false): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $path = ts_rbf_db_path();
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
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS rbf_pool ('
            . 'net TEXT NOT NULL, outpoint TEXT NOT NULL, txid TEXT NOT NULL, '
            . 'fee INTEGER NOT NULL DEFAULT 0, vsize INTEGER NOT NULL DEFAULT 0, '
            . 'value INTEGER NOT NULL DEFAULT 0, rbf INTEGER NOT NULL DEFAULT 0, '
            . 'ts INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (net, outpoint))');
        $db->exec('CREATE INDEX IF NOT EXISTS rbf_pool_txid ON rbf_pool (net, txid)');
        $db->exec('CREATE TABLE IF NOT EXISTS rbf_event ('
            . 'net TEXT NOT NULL, replacement_txid TEXT NOT NULL, replaced_txid TEXT NOT NULL, '
            . 'ts INTEGER NOT NULL, full_rbf INTEGER NOT NULL DEFAULT 0, '
            . 'r_fee INTEGER, r_vsize INTEGER, r_value INTEGER, r_rbf INTEGER, r_time INTEGER, '
            . 'o_fee INTEGER, o_vsize INTEGER, o_value INTEGER, o_rbf INTEGER, o_time INTEGER, '
            . 'PRIMARY KEY (net, replacement_txid, replaced_txid))');
        $db->exec('CREATE INDEX IF NOT EXISTS rbf_event_ts ON rbf_event (net, ts)');
        $db->exec('CREATE INDEX IF NOT EXISTS rbf_event_replaced ON rbf_event (net, replaced_txid)');
        return $pdo = $db;
    } catch (Throwable $e) {
        return $pdo = null;
    }
}

/** Reduce an Esplora tx to the fields we track. Returns null for coinbase / malformed. */
function ts_rbf_txinfo(array $tx): ?array
{
    $vin = $tx['vin'] ?? [];
    if (!$vin || !empty($vin[0]['is_coinbase'])) {
        return null;
    }
    $op = ($vin[0]['txid'] ?? '') . ':' . (int) ($vin[0]['vout'] ?? 0);
    if (($vin[0]['txid'] ?? '') === '') {
        return null;
    }
    $rbf = false;
    foreach ($vin as $in) {
        if ((int) ($in['sequence'] ?? 0xffffffff) < 0xfffffffe) { $rbf = true; break; }
    }
    $value = 0;
    foreach (($tx['vout'] ?? []) as $vo) { $value += (int) ($vo['value'] ?? 0); }   // Esplora vout value is already sats
    $vsize = (int) ceil(((int) ($tx['weight'] ?? 0)) / 4);
    return [
        'txid'  => (string) $tx['txid'],
        'op'    => $op,
        'fee'   => (int) ($tx['fee'] ?? 0),
        'vsize' => $vsize,
        'value' => $value,
        'rbf'   => $rbf,
        'time'  => (int) ($tx['status']['block_time'] ?? time()),
    ];
}

/**
 * One detection pass: reconcile the stored live-mempool view with the current mempool,
 * recording any replacements. $maxNew bounds per-tick tx fetches so a spam burst can't
 * pin the origin. Returns the number of replacements newly recorded.
 */
function ts_rbf_tick(array $net, int $maxNew = 150): int
{
    $db = ts_rbf_db(true);
    if (!$db) {
        return 0;
    }
    try {
        $current = ts_mempool_txids($net);
        if (!is_array($current)) {
            return 0;
        }
        $curSet = array_flip($current);
        // txids we already have in the pool -> the "new" set is everything else.
        $known = [];
        foreach ($db->query('SELECT DISTINCT txid FROM rbf_pool WHERE net = ' . $db->quote($net['slug'])) as $row) {
            $known[$row['txid']] = true;
        }
        $new = [];
        foreach ($current as $tid) {
            if (!isset($known[$tid])) { $new[] = $tid; }
            if (count($new) >= $maxNew) { break; }
        }

        $selOp = $db->prepare('SELECT txid, fee, vsize, value, rbf, ts FROM rbf_pool WHERE net = ? AND outpoint = ?');
        $upOp  = $db->prepare('INSERT OR REPLACE INTO rbf_pool (net, outpoint, txid, fee, vsize, value, rbf, ts) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insEv = $db->prepare('INSERT OR REPLACE INTO rbf_event '
            . '(net, replacement_txid, replaced_txid, ts, full_rbf, r_fee, r_vsize, r_value, r_rbf, r_time, o_fee, o_vsize, o_value, o_rbf, o_time) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $recorded = 0;
        foreach ($new as $tid) {
            $tx = ts_find_tx($net, $tid);
            if (!is_array($tx)) { continue; }
            $info = ts_rbf_txinfo($tx);
            if ($info === null) { continue; }
            $selOp->execute([$net['slug'], $info['op']]);
            $prev = $selOp->fetch(PDO::FETCH_ASSOC);
            if ($prev && $prev['txid'] !== $tid && !isset($curSet[$prev['txid']])) {
                // Same input, different (now-departed) spender -> a replacement.
                $now = time();
                $fullRbf = ((int) $prev['rbf']) === 0 ? 1 : 0;   // replaced tx did NOT signal RBF -> full-RBF
                $insEv->execute([$net['slug'], $tid, $prev['txid'], $now, $fullRbf,
                    $info['fee'], $info['vsize'], $info['value'], $info['rbf'] ? 1 : 0, $info['time'],
                    (int) $prev['fee'], (int) $prev['vsize'], (int) $prev['value'], (int) $prev['rbf'], (int) $prev['ts']]);
                $recorded++;
            }
            $upOp->execute([$net['slug'], $info['op'], $tid, $info['fee'], $info['vsize'], $info['value'], $info['rbf'] ? 1 : 0, $info['time']]);
        }
        // Prune by LIVENESS: drop pool rows for spenders that have left the mempool
        // (mined/dropped). They already served this tick's replacement detection (which ran
        // above, before this prune), so removing them now keeps the pool bounded while live
        // txs keep their row. A long ts backstop reaps anything orphaned by a crash.
        $departed = array_diff(array_keys($known), array_keys($curSet));
        if ($departed) {
            $del = $db->prepare('DELETE FROM rbf_pool WHERE net = ? AND txid = ?');
            foreach ($departed as $gone) { $del->execute([$net['slug'], $gone]); }
        }
        $db->prepare('DELETE FROM rbf_pool WHERE net = ? AND ts < ?')->execute([$net['slug'], time() - 86400]);
        $db->prepare('DELETE FROM rbf_event WHERE net = ? AND ts < ?')->execute([$net['slug'], time() - 86400]);
        return $recorded;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Build the mempool.space tx object for one side of an event row. */
function ts_rbf_txobj(string $txid, int $fee, int $vsize, int $value, bool $rbf, bool $fullRbf, int $time): array
{
    return [
        'txid'    => $txid,
        'fee'     => $fee,
        'vsize'   => (float) $vsize,
        'value'   => $value,
        'rate'    => $vsize > 0 ? $fee / $vsize : 0.0,
        'time'    => $time,
        'rbf'     => $rbf,
        'fullRbf' => $fullRbf,
    ];
}

/**
 * Recursively build a replacement tree node rooted at $txid (mempool.space shape):
 * { tx, time, fullRbf, replaces:[ ...child nodes each with an extra "interval" ] }.
 */
function ts_rbf_node(PDO $db, array $net, string $txid, array $row, int $depth = 0): array
{
    $node = [
        'tx'       => ts_rbf_txobj($txid, (int) $row['r_fee'], (int) $row['r_vsize'], (int) $row['r_value'], ((int) $row['r_rbf']) === 1, ((int) $row['full_rbf']) === 1, (int) $row['r_time']),
        'time'     => (int) $row['ts'],
        'fullRbf'  => ((int) $row['full_rbf']) === 1,
        'replaces' => [],
    ];
    // The replaced tx becomes a child; if IT was itself a replacement, recurse.
    $childTxid = (string) $row['replaced_txid'];
    $childTx = ts_rbf_txobj($childTxid, (int) $row['o_fee'], (int) $row['o_vsize'], (int) $row['o_value'], ((int) $row['o_rbf']) === 1, false, (int) $row['o_time']);
    $child = ['tx' => $childTx, 'time' => (int) $row['o_time'], 'interval' => max(0, (int) $row['ts'] - (int) $row['o_time']), 'fullRbf' => false, 'replaces' => []];
    if ($depth < 8) {
        $st = $db->prepare('SELECT * FROM rbf_event WHERE net = ? AND replacement_txid = ? LIMIT 1');
        $st->execute([$net['slug'], $childTxid]);
        $sub = $st->fetch(PDO::FETCH_ASSOC);
        if ($sub) {
            $subNode = ts_rbf_node($db, $net, $childTxid, $sub, $depth + 1);
            $subNode['interval'] = max(0, (int) $row['ts'] - (int) $subNode['time']);
            $child = $subNode;
        }
    }
    $node['replaces'][] = $child;
    return $node;
}

/** GET /api/v1/tx/:txid/rbf - {replacements: <tree>, replaces: [txid,...]}. */
function ts_rbf_tx_api(array $net, string $txid): array
{
    $db = ts_rbf_db(false);
    $empty = ['replacements' => [], 'replaces' => []];
    if (!$db) {
        return $empty;
    }
    try {
        // Walk forward to the newest tx in this chain (the tree root).
        $root = $txid;
        for ($i = 0; $i < 12; $i++) {
            $st = $db->prepare('SELECT replacement_txid FROM rbf_event WHERE net = ? AND replaced_txid = ? LIMIT 1');
            $st->execute([$net['slug'], $root]);
            $up = $st->fetchColumn();
            if (!$up) { break; }
            $root = (string) $up;
        }
        $st = $db->prepare('SELECT * FROM rbf_event WHERE net = ? AND replacement_txid = ? LIMIT 1');
        $st->execute([$net['slug'], $root]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $empty;
        }
        // Direct replaces of the requested tx (flat txid list).
        $rp = $db->prepare('SELECT replaced_txid FROM rbf_event WHERE net = ? AND replacement_txid = ?');
        $rp->execute([$net['slug'], $txid]);
        $replaces = array_map('strval', array_column($rp->fetchAll(PDO::FETCH_ASSOC), 'replaced_txid'));
        return ['replacements' => ts_rbf_node($db, $net, $root, $row), 'replaces' => $replaces];
    } catch (Throwable $e) {
        return $empty;
    }
}

/** GET /api/v1/replacements - array of recent RBF chains (newest-first tree nodes). */
function ts_replacements_api(array $net, int $limit = 100): array
{
    $db = ts_rbf_db(false);
    if (!$db) {
        return [];
    }
    try {
        // Only the CHAIN TIPS (a replacement that hasn't itself been replaced) become roots.
        $limit = max(1, min(200, $limit));
        $st = $db->prepare('SELECT e.* FROM rbf_event e WHERE e.net = ? AND NOT EXISTS '
            . '(SELECT 1 FROM rbf_event e2 WHERE e2.net = e.net AND e2.replaced_txid = e.replacement_txid) '
            . 'ORDER BY e.ts DESC LIMIT ?');
        $st->execute([$net['slug'], $limit]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ts_rbf_node($db, $net, (string) $row['replacement_txid'], $row);
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
