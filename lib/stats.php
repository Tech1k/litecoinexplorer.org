<?php
/**
 * Time-series stat snapshots (mempool size, fees, tip height) for the /mining
 * history charts. A cron (tools/snapshot.php) appends one row per network every
 * few minutes; the mining page reads recent rows. Best-effort: a missing or
 * locked DB just means "no history yet". The store lives next to the cache DB.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

function ts_stats_db_path(): ?string
{
    $cache = ts_config()['cache_db'] ?? null;
    return $cache ? dirname($cache) . '/stats.sqlite' : null;
}

function ts_stats_pdo(bool $create = false): ?PDO
{
    static $pdo = null;            // memoize only a real handle; a null return re-attempts next
    if ($pdo instanceof PDO) {     // call, so the long-lived WS daemon self-heals once the cron
        return $pdo;               // creates stats.sqlite (was: permanently cached the null).
    }
    $path = ts_stats_db_path();
    if (!$path) {
        return null;
    }
    if (!$create && !is_file($path)) {
        return null;
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
        $db->exec('PRAGMA busy_timeout = 2000');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS stats ('
            . 'net TEXT NOT NULL, ts INTEGER NOT NULL, '
            . 'mempool_count INTEGER NOT NULL DEFAULT 0, mempool_vsize INTEGER NOT NULL DEFAULT 0, '
            . 'mempool_fee INTEGER NOT NULL DEFAULT 0, fast_fee INTEGER NOT NULL DEFAULT 0, '
            . 'tip_height INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (net, ts))');
        // Fee-tier vsize columns (added idempotently so an existing store upgrades).
        foreach (['t0', 't1', 't2', 't3', 't4', 't5'] as $col) {
            try {
                $db->exec("ALTER TABLE stats ADD COLUMN $col INTEGER NOT NULL DEFAULT 0");
            } catch (Throwable $e) {
                // column already exists
            }
        }
        return $pdo = $db;
    } catch (Throwable $e) {
        return null;
    }
}

/** Append one snapshot for $net (called by the cron). Returns true on success. */
function ts_stats_snapshot(array $net): bool
{
    $db = ts_stats_pdo(true);
    if (!$db) {
        return false;
    }
    $count = 0; $vsize = 0; $fee = 0; $fast = 0; $tip = 0;
    $tiers = [0, 0, 0, 0, 0, 0];   // vsize by fee band: <1,1-2,2-5,5-10,10-20,20+
    try {
        $tip = ts_tip_height($net);
        $mem = ts_esplora_mempool($net);
        $count = (int) ($mem['count'] ?? 0);
        $vsize = (int) ($mem['vsize'] ?? 0);
        $fee   = (int) ($mem['total_fee'] ?? 0);
        foreach (($mem['fee_histogram'] ?? []) as $band) {
            $rate = (float) ($band[0] ?? 0);
            $bvs  = (int) ($band[1] ?? 0);
            $idx = $rate < 1 ? 0 : ($rate < 2 ? 1 : ($rate < 5 ? 2 : ($rate < 10 ? 3 : ($rate < 20 ? 4 : 5))));
            $tiers[$idx] += $bvs;
        }
        try {
            $fees = ts_fees_recommended($net);
            $fast = (int) ($fees['fastestFee'] ?? 0);
        } catch (Throwable $e) {
            // fee estimate optional
        }
    } catch (Throwable $e) {
        return false;
    }
    try {
        $db->prepare('INSERT OR REPLACE INTO stats (net, ts, mempool_count, mempool_vsize, mempool_fee, fast_fee, tip_height, t0, t1, t2, t3, t4, t5) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$net['slug'], time(), $count, $vsize, $fee, $fast, (int) $tip,
                      $tiers[0], $tiers[1], $tiers[2], $tiers[3], $tiers[4], $tiers[5]]);
        // keep ~30 days
        $db->prepare('DELETE FROM stats WHERE net = ? AND ts < ?')->execute([$net['slug'], time() - 2592000]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Snapshots for $net over the last $hours, oldest-first (for the charts). */
function ts_stats_series(array $net, int $hours = 48): array
{
    $db = ts_stats_pdo(false);
    if (!$db) {
        return [];
    }
    $hours = max(1, min(720, $hours));
    try {
        $st = $db->prepare('SELECT ts, mempool_count, mempool_vsize, mempool_fee, fast_fee, tip_height, t0, t1, t2, t3, t4, t5 '
            . 'FROM stats WHERE net = ? AND ts >= ? ORDER BY ts ASC');
        $st->execute([$net['slug'], time() - $hours * 3600]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'ts'            => (int) $r['ts'],
            'mempool_count' => (int) $r['mempool_count'],
            'mempool_vsize' => (int) $r['mempool_vsize'],
            'mempool_fee'   => (int) $r['mempool_fee'],
            'fast_fee'      => (int) $r['fast_fee'],
            'tip_height'    => (int) $r['tip_height'],
            't0' => (int) ($r['t0'] ?? 0), 't1' => (int) ($r['t1'] ?? 0), 't2' => (int) ($r['t2'] ?? 0),
            't3' => (int) ($r['t3'] ?? 0), 't4' => (int) ($r['t4'] ?? 0), 't5' => (int) ($r['t5'] ?? 0),
        ];
    }
    return $out;
}
