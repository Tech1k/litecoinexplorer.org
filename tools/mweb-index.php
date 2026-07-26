<?php
/**
 * MWEB index incremental updater. Advances the self-contained peg index from
 * its cursor up to the chain tip, reusing the same lx_mweb_block extraction the
 * web layer uses, and rolls back cleanly on reorgs. Reads only litecoind RPC.
 *
 * Run on a timer (LTC blocks ~2.5 min, so every ~2 min is plenty):
 *   php tools/mweb-index.php ltc-mainnet
 *   php tools/mweb-index.php ltc-mainnet --seed   # unbounded (full catch-up)
 *
 * A one-shot run is time-budgeted (90s) so a timer never overlaps itself; use a
 * flock wrapper in cron/systemd as well. Safe to run repeatedly.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
require dirname(__DIR__) . '/lib/bootstrap.php';
error_reporting(E_ALL);
ini_set('display_errors', '1');

const MWEB_REORG_MARGIN = 20;
const MWEB_COMMIT_EVERY  = 100;
const MWEB_ONESHOT_SECS  = 90.0;

$slug = $argv[1] ?? 'ltc-mainnet';
$seed = in_array('--seed', $argv, true);

$net = lx_net($slug);
if (!$net || !lx_mweb_enabled($net)) {
    fwrite(STDERR, "network '$slug' unknown or MWEB not enabled\n");
    exit(1);
}
if (empty($net['mweb']['index']['enabled'])) {
    fwrite(STDERR, "mweb.index is disabled in config for '$slug'\n");
    exit(1);
}

$db = lx_mweb_index_pdo($net, true);
if (!$db) {
    fwrite(STDERR, "could not open index DB\n");
    exit(1);
}

// Single-writer lock (a flock(1) wrapper in the timer is a second layer).
$lockPath = $net['mweb']['index']['db'] . '.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "another indexer run holds the lock; skipping\n");
    exit(0);
}

$budget = $seed ? PHP_FLOAT_MAX : microtime(true) + MWEB_ONESHOT_SECS;

mweb_index_check_reorg($db, $net);
$done = mweb_index_scan($db, $net, $budget);
mweb_index_refresh_meta($db, $net);

[$last] = mweb_meta_cursor($db, $net);
$tip = lx_tip_height($net);
echo "indexed $done block(s); last_indexed=$last tip=$tip lag=" . ($tip - $last) . "\n";

flock($lock, LOCK_UN);
fclose($lock);

// ---- helpers --------------------------------------------------------------

function mweb_meta_cursor(PDO $db, array $net): array
{
    $row = $db->query('SELECT last_indexed, last_hash FROM mweb_meta WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return [(int) $row['last_indexed'], (string) $row['last_hash']];
    }
    return [lx_mweb_activation($net) - 1, ''];
}

function mweb_meta_set_cursor(PDO $db, int $height, string $hash): void
{
    $db->prepare(
        'INSERT INTO mweb_meta (id, last_indexed, last_hash) VALUES (1, ?, ?)
         ON CONFLICT(id) DO UPDATE SET last_indexed = excluded.last_indexed, last_hash = excluded.last_hash'
    )->execute([$height, $hash]);
}

/** Advance the cursor to the tip, indexing each block. Returns blocks written. */
function mweb_index_scan(PDO $db, array $net, float $budget): int
{
    [$last] = mweb_meta_cursor($db, $net);
    $start = max($last + 1, lx_mweb_activation($net));
    $tip = lx_tip_height($net);
    if ($tip < $start) {
        return 0;
    }
    $done = 0;
    $sinceCommit = 0;
    $db->beginTransaction();
    for ($h = $start; $h <= $tip; $h++) {
        if (microtime(true) > $budget) {
            break;
        }
        $hash = lx_block_hash_at($net, $h);
        if ($hash === null) {
            break;   // RPC gap: stop, resume next run
        }
        $m = lx_mweb_block($net, $hash, $h);          // existing builder (hash-cached)
        if ($m === null) {
            break;   // unresolved (transient RPC error): resume this height next run, never skip past it
        }
        mweb_upsert_block_and_pegs($db, $net, $h, $hash, $m);
        mweb_meta_set_cursor($db, $h, $hash);
        $done++;
        if (++$sinceCommit >= MWEB_COMMIT_EVERY) {
            $db->commit();
            $db->beginTransaction();
            $sinceCommit = 0;
        }
    }
    if ($db->inTransaction()) {
        $db->commit();
    }
    return $done;
}

/** Upsert one block's summary + its peg rows. $m===null (no HogEx) writes nothing. */
function mweb_upsert_block_and_pegs(PDO $db, array $net, int $height, string $hash, ?array $m): void
{
    if ($m === null) {
        return;   // cursor still advances via mweb_meta_set_cursor
    }
    $bt = lx_block_time_at($net, $hash) ?? 0;
    $db->prepare(
        'INSERT INTO mweb_blocks
           (block_height, block_time, block_hash, hogex_txid, supply_sat,
            pegin_count, pegin_total_sat, pegout_count, pegout_total_sat)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(block_height) DO UPDATE SET
           block_time = excluded.block_time, block_hash = excluded.block_hash,
           hogex_txid = excluded.hogex_txid, supply_sat = excluded.supply_sat,
           pegin_count = excluded.pegin_count, pegin_total_sat = excluded.pegin_total_sat,
           pegout_count = excluded.pegout_count, pegout_total_sat = excluded.pegout_total_sat'
    )->execute([
        $height, $bt, $hash, $m['hogex_txid'], $m['supply_sat'],
        $m['pegin_count'], $m['pegin_total_sat'], $m['pegout_count'], $m['pegout_total_sat'],
    ]);
    $insPi = $db->prepare(
        'INSERT OR IGNORE INTO mweb_pegins (txid, vout, block_height, block_time, value_sat) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($m['pegins'] as $p) {
        $insPi->execute([$p['txid'], $p['vout'], $height, $bt, $p['value_sat']]);
    }
    $insPo = $db->prepare(
        'INSERT OR IGNORE INTO mweb_pegouts (txid, n, block_height, block_time, value_sat, address) VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($m['pegouts'] as $p) {
        $insPo->execute([$p['txid'], $p['n'], $height, $bt, $p['value_sat'], $p['address']]);
    }
}

/** Live best-chain hash at $height, bypassing the response cache (reorg-safe). */
function mweb_live_block_hash(array $net, int $height): ?string
{
    $h = lx_rpc_soft($net, 'getblockhash', [$height]);
    return is_string($h) ? $h : null;
}

/** Verify the indexed tail against the chain; roll back past any reorg point. */
function mweb_index_check_reorg(PDO $db, array $net): void
{
    $margin = MWEB_REORG_MARGIN;
    while (true) {
        $rows = $db->query(
            "SELECT block_height, block_hash FROM mweb_blocks ORDER BY block_height DESC LIMIT $margin"
        )->fetchAll(PDO::FETCH_NUM);
        if (!$rows) {
            return;
        }
        $topHeight = (int) $rows[0][0];
        foreach ($rows as $r) {
            $hgt = (int) $r[0];
            if (mweb_live_block_hash($net, $hgt) === (string) $r[1]) {
                if ($hgt < $topHeight) {
                    mweb_rollback_to($db, $net, $hgt);   // heights above $hgt reorged out
                }
                return;
            }
        }
        if (count($rows) < $margin) {
            // whole (short) tail mismatched: roll back below the lowest we have
            mweb_rollback_to($db, $net, (int) $rows[count($rows) - 1][0] - 1);
            return;
        }
        $margin *= 2;   // deeper fork than the window; widen and re-check
    }
}

/** Delete indexed data above $height and reset the cursor to it. */
function mweb_rollback_to(PDO $db, array $net, int $height): void
{
    fwrite(STDERR, "reorg: rolling back index to height $height\n");
    $db->beginTransaction();
    foreach (['mweb_pegins', 'mweb_pegouts', 'mweb_blocks'] as $t) {
        $db->exec("DELETE FROM $t WHERE block_height > $height");
    }
    mweb_meta_set_cursor($db, $height, mweb_live_block_hash($net, $height) ?? '');
    $db->commit();
}

/** Refresh meta (tip/supply) and recompute the most recent daily supply buckets. */
function mweb_index_refresh_meta(PDO $db, array $net): void
{
    $row = $db->query(
        'SELECT block_time, supply_sat FROM mweb_blocks WHERE supply_sat IS NOT NULL ORDER BY block_height DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->prepare('UPDATE mweb_meta SET updated_at = ? WHERE id = 1')->execute([time()]);
        return;
    }
    $tipTime = (int) $row['block_time'];
    $db->prepare('UPDATE mweb_meta SET tip_time = ?, current_supply_sat = ?, updated_at = ? WHERE id = 1')
        ->execute([$tipTime, (int) $row['supply_sat'], time()]);

    // Recompute the 3 most recent (densely indexed) UTC days. As the cursor
    // advances day by day this settles each day's end-of-day supply + flow.
    $tipDay = intdiv($tipTime, 86400) * 86400;
    $eod = $db->prepare(
        'SELECT block_height, supply_sat FROM mweb_blocks
         WHERE supply_sat IS NOT NULL AND block_time >= ? AND block_time < ?
         ORDER BY block_time DESC, block_height DESC LIMIT 1'
    );
    $piQ = $db->prepare('SELECT COALESCE(SUM(value_sat), 0) FROM mweb_pegins  WHERE block_time >= ? AND block_time < ?');
    $poQ = $db->prepare('SELECT COALESCE(SUM(value_sat), 0) FROM mweb_pegouts WHERE block_time >= ? AND block_time < ?');
    $ups = $db->prepare(
        'INSERT INTO mweb_supply_daily (day_ts, block_height, supply_sat, pegin_sat, pegout_sat)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT(day_ts) DO UPDATE SET
           block_height = excluded.block_height, supply_sat = excluded.supply_sat,
           pegin_sat = excluded.pegin_sat, pegout_sat = excluded.pegout_sat'
    );
    // Days to (re)compute: the 3 most recent (settle each run) PLUS any day that
    // has indexed blocks but no supply_daily row yet. The latter backfills the
    // whole history when the index was built purely by scanning (mweb-index --seed)
    // rather than imported from mwebscan, so the supply chart has no gaps.
    $days = [$tipDay => true];
    for ($k = 1; $k < 3; $k++) {
        $days[$tipDay - $k * 86400] = true;
    }
    $gaps = $db->query(
        'SELECT DISTINCT (block_time / 86400) * 86400 AS d FROM mweb_blocks
         WHERE supply_sat IS NOT NULL
           AND (block_time / 86400) * 86400 NOT IN (SELECT day_ts FROM mweb_supply_daily)'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($gaps as $d) {
        $days[(int) $d] = true;
    }
    foreach (array_keys($days) as $lo) {
        $hi = $lo + 86400;
        $eod->execute([$lo, $hi]);
        $last = $eod->fetch(PDO::FETCH_ASSOC);
        if (!$last) {
            continue;
        }
        $piQ->execute([$lo, $hi]);
        $poQ->execute([$lo, $hi]);
        $ups->execute([
            $lo, (int) $last['block_height'], (int) $last['supply_sat'],
            (int) $piQ->fetchColumn(), (int) $poQ->fetchColumn(),
        ]);
    }
}
