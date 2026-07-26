<?php
/**
 * Stat snapshot cron. Appends one mempool/fee/tip row for the network to the
 * stats store (for the /mining history charts) and warms the UTXO-set +
 * block-strip caches. Run every ~5 minutes from cron or a systemd timer; the
 * exact crontab and unit are in DEPLOY.md.
 * Optionally pass the slug: php tools/snapshot.php ltc-mainnet
 *
 * --tick runs ONLY the cheap live-mempool passes (block-audit snapshot + diff and
 * RBF detection), so it can run every ~30s. Because a block is only auditable if a
 * template snapshot landed while it was pending, and LTC blocks arrive faster than the
 * 5-minute full run, the frequent tick is what gives near-complete block-health
 * coverage; the heavy warm/index steps stay on the 5-minute run.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$args = array_slice($argv, 1);
$forceUtxo = in_array('--utxo', $args, true);   // force a fresh UTXO-set scan (bypass cache + debounce)
$tickOnly  = in_array('--tick', $args, true);   // lightweight audit + RBF pass only (run every ~30s)
$slug = null;
foreach ($args as $a) { if (strpos($a, '--') !== 0) { $slug = $a; break; } }
$nets = $slug ? [lx_net($slug)] : array_values(lx_networks());

$n = 0;
foreach ($nets as $net) {
    if (!$net) {
        continue;
    }

    // --tick: only the cheap live-mempool passes, run frequently so nearly every block's
    // pending window catches a template snapshot (drives block-health coverage) and RBF
    // replacements are caught promptly even without the WS daemon. Skips the heavy 5-min
    // warm/index steps entirely.
    if ($tickOnly) {
        if (($net['kind'] ?? 'utxo') !== 'utxo') {
            continue;
        }
        $line = 'tick ' . $net['slug'];
        if (function_exists('lx_audit_snapshot')) {
            $snapped = lx_audit_snapshot($net);
            $audited = lx_audit_run($net);
            $line .= '  audit ' . ($snapped ? 'ok' : 'no-snap') . ($audited ? " +$audited blk" : '');
        }
        if (function_exists('lx_rbf_tick')) {
            $rb = lx_rbf_tick($net);
            $line .= '  rbf ' . ($rb ? "+$rb" : '0');
        }
        echo $line . "\n";
        continue;
    }

    $ok = lx_stats_snapshot($net);
    echo ($ok ? 'ok  ' : 'skip') . ' ' . $net['slug'] . "\n";
    if ($ok) {
        $n++;
    }
    // UTXO: warm the UTXO-set summary (gettxoutsetinfo scans the chainstate) so
    // the Node page reads a cached value instead of computing it on request.
    if (($net['kind'] ?? 'utxo') === 'utxo' && function_exists('lx_txoutset_refresh')) {
        $t0 = microtime(true);
        $u = lx_txoutset_refresh($net, $forceUtxo);
        $note = $forceUtxo ? ' in ' . round(microtime(true) - $t0, 1) . 's' : '';
        echo '     utxo-set ' . ($u ? 'ok' . $note : ($forceUtxo ? 'FAILED (check litecoind)' : 'cached/skipped')) . ' ' . $net['slug'] . "\n";
        // Verify it PERSISTED. cache_set() swallows write errors, so a value can be
        // computed yet never stored - which is exactly what happens when this cron runs
        // as a different user than php-fpm and can't write db/cache.sqlite. Then the Node
        // page reads nothing and shows "not available" despite a happy "ok" here.
        if ($u && lx_txoutset_info($net) === null) {
            fwrite(STDERR, "     WARNING: UTXO computed but NOT persisted to db/cache.sqlite - "
                . "this user can't write the cache. Run the cron as your php-fpm user "
                . "(chown -R <php-fpm-user> db/).\n");
        }
    }
    // UTXO: warm the home-page block strip (populates the immutable block/stats
    // caches via batched fetches) so the first visitor never pays the cold path.
    if (($net['kind'] ?? 'utxo') === 'utxo') {
        try {
            lx_recent_blocks($net, null, 10);
            lx_recent_block_stats($net, 12);
        } catch (Throwable $e) {
            // best-effort warm
        }
        // Block audit: snapshot the predicted next block, then diff any blocks
        // that confirmed since the last run (template-vs-mined). Best-effort.
        if (function_exists('lx_audit_snapshot')) {
            $snapped = lx_audit_snapshot($net);
            $audited = lx_audit_run($net);
            echo '     audit    ' . ($snapped ? 'snapshot ok' : 'no snapshot') . ($audited ? ", +$audited block(s)" : '') . ' ' . $net['slug'] . "\n";
        }
        // RBF: one mempool-delta pass to detect replacements (also done live by the WS
        // daemon, if running, at a finer cadence). Record the current price into history.
        if (function_exists('lx_rbf_tick')) {
            $rb = lx_rbf_tick($net);
            echo '     rbf      ' . ($rb ? "+$rb replacement(s)" : 'no new') . ' ' . $net['slug'] . "\n";
        }
        if (function_exists('lx_price_record')) {
            $pr = lx_price_record($net);
            echo '     price    ' . ($pr ? 'recorded' : 'unavailable') . ' ' . $net['slug'] . "\n";
        }
        // Block-economics index: fill new blocks + backfill history (bounded) for the
        // mining timeseries endpoints. Heaviest cron step; bounded by blockindex_per_run.
        if (function_exists('lx_blockindex_tick')) {
            $bi = lx_blockindex_tick($net);
            echo '     blkindex ' . ($bi ? "+$bi block(s)" : 'up to date') . ' ' . $net['slug'] . "\n";
        }
    }
}
echo "snapshotted $n network(s)\n";
