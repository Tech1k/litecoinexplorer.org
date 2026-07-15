<?php
/**
 * One-time MWEB index seed importer.
 *
 * Populates the explorer's self-contained peg index (SQLite) from an mwebscan
 * analytics DB. Run once, before enabling the incremental indexer. Amounts in
 * the source are REAL (float LTC) with FP tails; we store integer satoshis via
 * ROUND(ROUND(x,8)*1e8) so 1792932.25521623 -> 179293225521623 exactly.
 *
 * Usage:
 *   php tools/mweb-seed.php <net-slug> </path/to/mwebscan.db>
 *   php tools/mweb-seed.php ltc-mainnet /opt/mwebscan/mwebscan.db
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

$slug = $argv[1] ?? 'ltc-mainnet';
$src  = $argv[2] ?? '';
if ($src === '' || !is_file($src)) {
    fwrite(STDERR, "usage: php tools/mweb-seed.php <net-slug> <mwebscan.db path>\n");
    exit(1);
}
$net = ts_net($slug);
if (!$net || !ts_mweb_enabled($net)) {
    fwrite(STDERR, "network '$slug' unknown or MWEB not enabled for it\n");
    exit(1);
}

$db = ts_mweb_index_pdo($net, true);   // create schema
if (!$db) {
    fwrite(STDERR, "could not open index DB (check mweb.index.db in config)\n");
    exit(1);
}

$srcQuoted = "'" . str_replace("'", "''", $src) . "'";
$db->exec("ATTACH DATABASE $srcQuoted AS src");

$SATS = 'CAST(ROUND(ROUND(%s, 8) * 1e8) AS INTEGER)';   // sats conversion snippet
$pi = sprintf($SATS, 'amount');
$po = sprintf($SATS, 'amount');

echo "seeding MWEB index for $slug from $src ...\n";
$db->beginTransaction();

// Fresh seed: clear any prior contents so re-runs are clean.
foreach (['mweb_pegins', 'mweb_pegouts', 'mweb_blocks', 'mweb_supply_daily', 'mweb_meta'] as $t) {
    $db->exec("DELETE FROM $t");
}

// Peg-ins (source vout -> our vout).
$db->exec(
    "INSERT OR REPLACE INTO mweb_pegins (txid, vout, block_height, block_time, value_sat)
     SELECT txid, vout, block_height, block_time, $pi FROM src.mweb_pegins"
);
// Peg-outs (source 'vout' -> our 'n').
$db->exec(
    "INSERT OR REPLACE INTO mweb_pegouts (txid, n, block_height, block_time, value_sat, address)
     SELECT txid, vout, block_height, block_time, $po, address FROM src.mweb_pegouts"
);
// Per-block rows: every activity block (for range/history) plus a dense recent
// tail (last 60 blocks) so the incremental indexer's reorg check has a solid
// starting window at the seed boundary.
$db->exec(
    "INSERT OR REPLACE INTO mweb_blocks
       (block_height, block_time, block_hash, hogex_txid, supply_sat,
        pegin_count, pegin_total_sat, pegout_count, pegout_total_sat)
     SELECT block_height, block_time, block_hash, hogex_txid,
            " . sprintf($SATS, 'supply') . ",
            pegin_count,  " . sprintf($SATS, 'pegin_amount') . ",
            pegout_count, " . sprintf($SATS, 'pegout_amount') . "
     FROM src.mweb_blocks
     WHERE pegin_count > 0 OR pegout_count > 0
        OR block_height > (SELECT MAX(block_height) - 60 FROM src.mweb_blocks)"
);
// Downsampled supply series: one point per UTC day (end-of-day / last block),
// with that day's total peg-in/peg-out flow.
$db->exec(
    "INSERT OR REPLACE INTO mweb_supply_daily (day_ts, block_height, supply_sat, pegin_sat, pegout_sat)
     SELECT (b.block_time / 86400) * 86400, b.block_height,
            " . sprintf($SATS, 'b.supply') . ", agg.pegin_sat, agg.pegout_sat
     FROM (
        SELECT block_height, block_time, supply,
               ROW_NUMBER() OVER (PARTITION BY block_time / 86400 ORDER BY block_height DESC) rn
        FROM src.mweb_blocks
     ) b
     JOIN (
        SELECT block_time / 86400 AS day,
               " . sprintf($SATS, 'SUM(pegin_amount)') . "  AS pegin_sat,
               " . sprintf($SATS, 'SUM(pegout_amount)') . " AS pegout_sat
        FROM src.mweb_blocks GROUP BY block_time / 86400
     ) agg ON agg.day = b.block_time / 86400
     WHERE b.rn = 1"
);

// Meta cursor: resume the incremental indexer at the source scan tip.
$tip = $db->query(
    "SELECT block_height, block_time, block_hash, supply
     FROM src.mweb_blocks ORDER BY block_height DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$tip) {
    $db->rollBack();
    fwrite(STDERR, "source mweb_blocks is empty; nothing to seed\n");
    exit(1);
}
$supplySat = (int) round(round((float) $tip['supply'], 8) * 1e8);
$db->prepare(
    "INSERT OR REPLACE INTO mweb_meta
       (id, last_indexed, last_hash, tip_time, current_supply_sat, updated_at)
     VALUES (1, ?, ?, ?, ?, ?)"
)->execute([
    (int) $tip['block_height'], (string) $tip['block_hash'],
    (int) $tip['block_time'], $supplySat, time(),
]);

$db->commit();
$db->exec('DETACH DATABASE src');

$pegins  = (int) $db->query('SELECT count(*) FROM mweb_pegins')->fetchColumn();
$pegouts = (int) $db->query('SELECT count(*) FROM mweb_pegouts')->fetchColumn();
$blocks  = (int) $db->query('SELECT count(*) FROM mweb_blocks')->fetchColumn();
$days    = (int) $db->query('SELECT count(*) FROM mweb_supply_daily')->fetchColumn();
echo "done. pegins=$pegins pegouts=$pegouts blocks=$blocks supply_days=$days "
   . "last_indexed={$tip['block_height']} current_supply_sat=$supplySat\n";
echo "next: enable mweb.index in config.php and run tools/mweb-index.php $slug on a cron/timer.\n";
