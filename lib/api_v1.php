<?php
/**
 * mempool.space-compatible /api/v1/* extension endpoints that reuse the explorer's
 * existing data (CPFP package, reward stats, per-pool detail, difficulty-adjustment
 * history). Shapes mirror mempool.space so wallets/tools stay drop-in. Big-number
 * fields (sats over many blocks, hashrate) are emitted as STRINGS where mempool.space
 * does, to survive JSON 2^53.
 *
 * RBF (/v1/tx/:txid/rbf, /v1/replacements) lives in lib/rbf.php; historical price
 * (/v1/historical-price) lives in lib/price.php.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Decimal coin amount (e.g. getmempoolentry fees.base) -> integer sats. */
function lx_v1_sat($coin): int
{
    return (int) round(((float) $coin) * 100000000);
}

/**
 * GET /api/v1/cpfp/:txid - ancestors/descendants + effective (package) fee rate.
 * mempool.space shape: {ancestors:[{txid,fee,weight}], bestDescendant, descendants,
 * effectiveFeePerVsize, sigops, fee, adjustedVsize}. A confirmed / non-mempool tx
 * returns {ancestors:[]}. Fees are sats, weight is weight units (vsize = weight/4).
 */
function lx_cpfp_api(array $net, string $txid): array
{
    $e = lx_mempool_entry($net, $txid);
    if (!is_array($e)) {
        return ['ancestors' => []];   // confirmed or not in the mempool
    }
    $ownFee  = isset($e['fees']['base']) ? lx_v1_sat($e['fees']['base']) : (int) ($e['fee'] ?? 0);
    $ancFee  = isset($e['fees']['ancestor']) ? lx_v1_sat($e['fees']['ancestor']) : $ownFee;
    $ancVs   = (int) ($e['ancestorsize'] ?? $e['vsize'] ?? 0);

    $rel = function (string $id) use ($net): array {
        $me = lx_mempool_entry($net, $id);
        if (is_array($me)) {
            $fee = isset($me['fees']['base']) ? lx_v1_sat($me['fees']['base']) : (int) ($me['fee'] ?? 0);
            $wt  = (int) ($me['weight'] ?? ((int) ($me['vsize'] ?? 0) * 4));
            return ['txid' => $id, 'fee' => $fee, 'weight' => $wt];
        }
        return ['txid' => $id, 'fee' => 0, 'weight' => 0];
    };
    $depends = array_slice(is_array($e['depends'] ?? null) ? $e['depends'] : [], 0, 25);
    $spentby = array_slice(is_array($e['spentby'] ?? null) ? $e['spentby'] : [], 0, 25);
    $ancestors   = array_map($rel, $depends);
    $descendants = array_map($rel, $spentby);

    $best = null;
    foreach ($descendants as $d) {
        if ($best === null || $d['fee'] > $best['fee']) { $best = $d; }
    }
    return [
        'ancestors'            => $ancestors,
        'bestDescendant'       => $best,
        'descendants'          => $descendants,
        'effectiveFeePerVsize' => $ancVs > 0 ? $ancFee / $ancVs : 0.0,
        'sigops'               => (int) ($e['sigops'] ?? 0),
        'fee'                  => $ownFee,
        'adjustedVsize'        => (float) ($e['vsize'] ?? $ancVs),   // this tx's own vsize
    ];
}

/**
 * GET /api/v1/mining/reward-stats/:blockCount - subsidy+fees, fees, tx count over
 * the last N blocks. Big-number fields are strings (mempool.space parity).
 */
function lx_reward_stats_api(array $net, int $n): array
{
    $n = max(1, min(500, $n));
    $tip   = lx_tip_height($net);
    $stats = lx_recent_block_stats($net, $n);
    $reward = 0; $fee = 0; $tx = 0;
    foreach ($stats as $b) {
        $reward += (int) ($b['subsidy'] ?? 0) + (int) ($b['total_fee'] ?? 0);
        $fee    += (int) ($b['total_fee'] ?? 0);
        $tx     += (int) ($b['txs'] ?? 0);
    }
    $covered = count($stats);
    return [
        'startBlock'  => max(0, $tip - $covered + 1),
        'endBlock'    => $tip,
        'totalReward' => (string) $reward,
        'totalFee'    => (string) $fee,
        'totalTx'     => (string) $tx,
    ];
}

/**
 * GET /api/v1/mining/difficulty-adjustments/:interval - newest-first array of
 * positional tuples [timestamp, height, difficulty, adjustmentMultiplier].
 * :interval is 1m|3m|6m|1y|2y|3y|all (mapped to a count of 2016-block epochs).
 */
function lx_difficulty_adjustments_api(array $net, string $interval): array
{
    $map = ['1m' => 9, '3m' => 26, '6m' => 52, '1y' => 104, '2y' => 208, '3y' => 312, 'all' => 400];
    $n = $map[$interval] ?? 104;
    $out = [];
    foreach (lx_difficulty_epochs($net, $n) as $e) {   // newest-first: height,time,difficulty,pct_change
        $out[] = [
            (int) ($e['time'] ?? 0),
            (int) ($e['height'] ?? 0),
            (float) ($e['difficulty'] ?? 0),
            1 + ((float) ($e['pct_change'] ?? 0)) / 100,
        ];
    }
    return $out;
}

/** URL-safe slug for a pool label (kept reversible-ish for display matching). */
function lx_pool_slug(string $label): string
{
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $label));
    return $s !== '' ? $s : 'unknown';
}

/**
 * GET /api/v1/mining/pool/:slug - per-pool detail over a recent window. No historical
 * pool DB, so this is window-scoped (default ~1 day of blocks). Returns pool name/slug,
 * block count + share, estimated hashrate, all-window reward (string sats) and the
 * pool's recent blocks. :slug matches either the raw pool label or its slug.
 */
function lx_mining_pool_api(array $net, string $slug, int $window = 144): array
{
    $window = max(10, min(200, $window));   // same cap both helpers clamp to, so counts + blocks[] agree
    $dist = lx_mining_distribution($net, $window);          // {window, pools:[{name,count,pct}]}
    $total = max(1, (int) ($dist['window'] ?? $window));
    // Resolve the requested slug to a pool label present in the distribution.
    $label = null; $count = 0; $pct = 0.0;
    foreach (($dist['pools'] ?? []) as $p) {
        if ($p['name'] === $slug || lx_pool_slug($p['name']) === strtolower($slug)) {
            $label = $p['name']; $count = (int) $p['count']; $pct = (float) $p['pct'];
            break;
        }
    }
    if ($label === null) {
        return ['error' => 'pool not found in the recent window'];
    }
    $share = $pct / 100.0;
    $hashrate = lx_network_hashrate($net);
    // Sum reward for this pool's blocks (bounded) so totalReward is real.
    $blocks = lx_pool_blocks($net, $label, $window);
    $reward = 0; $rows = [];
    foreach (array_slice($blocks, 0, 60) as $b) {
        $bs = lx_block_stats($net, $b['hash'], (int) $b['height']);
        $r  = $bs ? (int) ($bs['subsidy'] ?? 0) + (int) ($bs['total_fee'] ?? 0) : 0;
        $reward += $r;
        $rows[] = ['height' => (int) $b['height'], 'hash' => $b['hash'], 'reward' => $r];
    }
    return [
        // mempool.space shapes blockCount/blockShare as {all,24h,1w} objects. We have a
        // single recent window rather than distinct timeframes, so report it under all
        // three keys so drop-in clients reading e.g. blockCount['24h'] don't get undefined.
        'pool'              => ['id' => null, 'name' => $label, 'slug' => lx_pool_slug($label), 'link' => null],
        'window'            => $total,
        'blockCount'        => ['all' => $count, '24h' => $count, '1w' => $count],
        'blockShare'        => ['all' => $share, '24h' => $share, '1w' => $share],
        'estimatedHashrate' => $hashrate > 0 ? $hashrate * $share : 0.0,
        'reportedHashrate'  => null,
        'avgBlockHealth'    => null,
        'totalReward'       => (string) $reward,
        'blocks'            => $rows,
    ];
}

/**
 * GET /api/v1/block/:hash/audit-summary - template-vs-mined block audit
 * (mempool.space "block health"). Reuses the stored per-block audit; template detail
 * beyond counts isn't retained, so template[] is the expected txid list and unused
 * mempool-only categories are empty arrays. Returns null when no audit exists.
 */
function lx_audit_summary_api(array $net, string $hash): ?array
{
    $h = lx_block_height_for_hash($net, $hash);
    if ($h === null) {
        return null;
    }
    $a = lx_audit_get($net, (int) $h);
    if ($a === null) {
        return null;
    }
    $template = [];
    foreach (($a['template_txids'] ?? []) as $tid) {
        $template[] = ['txid' => $tid];
    }
    return [
        'version'          => 1,
        'templateAlgorithm'=> 0,
        'height'           => (int) $a['height'],
        'id'               => (string) $a['hash'],
        'timestamp'        => (int) $a['block_time'],
        'template'         => $template,
        'addedTxs'         => $a['added_txids'] ?? [],
        'missingTxs'       => $a['missing_txids'] ?? [],
        'freshTxs'         => [],
        'sigopTxs'         => [],
        'fullrbfTxs'       => [],
        'acceleratedTxs'   => [],
        'prioritizedTxs'   => [],
        'unseenTxs'        => [],
        'matchRate'        => round((float) ($a['match_pct'] ?? 0), 2),
        'health'           => round((float) ($a['health_pct'] ?? 100), 2),   // n/(n+excluded), mempool.space "block health"
        'expectedFees'     => (int) ($a['expected_fees'] ?? 0),
        'expectedWeight'   => (int) ($a['expected_weight'] ?? 0),
    ];
}

// ---- mining block-analytics timeseries (backed by the block index) ----------
// mempool.space serves /api/v1/mining/blocks/{fees,rewards,fee-rates,sizes-weights}/:timePeriod
// as bucketed per-block series. We read lib/blockindex.php; coverage grows as the index backfills.

/** Valid mempool.space :timePeriod tokens. */
function lx_period_valid(string $p): bool
{
    return in_array($p, ['24h', '3d', '1w', '1m', '3m', '6m', '1y', '2y', '3y', 'all'], true);
}

/** GET /api/v1/mining/blocks/fees/:timePeriod -> [{avgHeight, timestamp, avgFees}]. */
function lx_mining_blocks_fees_api(array $net, string $period): array
{
    $out = [];
    foreach (lx_blockindex_series($net, $period) as $b) {
        $out[] = ['avgHeight' => $b['avgHeight'], 'timestamp' => $b['timestamp'], 'avgFees' => round($b['avgFees'])];
    }
    return $out;
}

/** GET /api/v1/mining/blocks/rewards/:timePeriod -> [{avgHeight, timestamp, avgRewards}]. */
function lx_mining_blocks_rewards_api(array $net, string $period): array
{
    $out = [];
    foreach (lx_blockindex_series($net, $period) as $b) {
        $out[] = ['avgHeight' => $b['avgHeight'], 'timestamp' => $b['timestamp'], 'avgRewards' => round($b['avgRewards'])];
    }
    return $out;
}

/**
 * GET /api/v1/mining/blocks/fee-rates/:timePeriod -> [{avgHeight, timestamp, avgFee_0..avgFee_100}].
 * We store p10/p25/p50/p75/p90; avgFee_0/_100 mirror the p10/p90 bounds.
 */
function lx_mining_blocks_feerates_api(array $net, string $period): array
{
    $out = [];
    foreach (lx_blockindex_series($net, $period) as $b) {
        $out[] = ['avgHeight' => $b['avgHeight'], 'timestamp' => $b['timestamp'],
            'avgFee_0' => round($b['p10'], 2), 'avgFee_10' => round($b['p10'], 2), 'avgFee_25' => round($b['p25'], 2),
            'avgFee_50' => round($b['p50'], 2), 'avgFee_75' => round($b['p75'], 2), 'avgFee_90' => round($b['p90'], 2),
            'avgFee_100' => round($b['p90'], 2)];
    }
    return $out;
}

/** GET /api/v1/mining/blocks/sizes-weights/:timePeriod -> {sizes:[...], weights:[...]}. */
function lx_mining_blocks_sizesweights_api(array $net, string $period): array
{
    $sizes = []; $weights = [];
    foreach (lx_blockindex_series($net, $period) as $b) {
        $sizes[]   = ['avgHeight' => $b['avgHeight'], 'timestamp' => $b['timestamp'], 'avgSize' => round($b['avgSize'])];
        $weights[] = ['avgHeight' => $b['avgHeight'], 'timestamp' => $b['timestamp'], 'avgWeight' => round($b['avgWeight'])];
    }
    return ['sizes' => $sizes, 'weights' => $weights];
}

// ---- niche drop-in endpoints (mempool.space parity) -------------------------

/** GET /api/v1/backend-info - the instance descriptor wallets probe (lightning flag, version). */
function lx_backend_info_api(array $net): array
{
    return [
        'hostname'  => (string) (lx_config()['canonical_host'] ?? ($_SERVER['SERVER_NAME'] ?? 'litecoinexplorer.org')),
        'version'   => (string) (lx_config()['version'] ?? '1.0.0'),
        'gitCommit' => (string) (lx_config()['git_commit'] ?? ''),
        'lightning' => false,                    // Litecoin instance: Lightning is off
        'backend'   => 'litecoinexplorer',
        'network'   => (string) ($net['network'] ?? 'mainnet'),
    ];
}

/**
 * GET /api/v1/transaction-times?txId[]=..&txId[]=.. - first-seen unix time per txid, aligned to
 * the input order (0 when unknown). Mempool tx -> getmempoolentry.time; confirmed -> block time.
 */
function lx_transaction_times_api(array $net): array
{
    $ids = $_GET['txId'] ?? [];
    if (is_string($ids)) { $ids = [$ids]; }
    if (!is_array($ids)) { $ids = []; }
    $ids = array_slice($ids, 0, 50);            // cap per request (anti-amplification)
    $seen = [];                                 // dedup: one backend lookup per distinct txid
    $out = [];
    foreach ($ids as $id) {
        if (!is_string($id) || !preg_match('/^[0-9a-f]{64}$/i', $id)) { $out[] = 0; continue; }
        $lid = strtolower($id);
        if (!array_key_exists($lid, $seen)) {
            $t = 0;
            $me = lx_mempool_entry($net, $lid);
            if (is_array($me) && isset($me['time'])) {
                $t = (int) $me['time'];
            } else {
                $tx = lx_esplora_tx($net, $lid);
                if (is_array($tx) && !empty($tx['status']['block_time'])) { $t = (int) $tx['status']['block_time']; }
            }
            $seen[$lid] = $t;
        }
        $out[] = $seen[$lid];
    }
    return $out;
}

/** GET /api/v1/blocks[/:startHeight] - 15 BlockExtended (extras: reward/fees/pool), newest-first. */
function lx_blocks_extended_api(array $net, ?int $startHeight = null): array
{
    $out = [];
    foreach (lx_recent_blocks($net, $startHeight, 15) as $b) {
        $hash = (string) ($b['id'] ?? $b['hash'] ?? '');
        $out[] = ($hash !== '' ? lx_ws_block_extended($net, $hash) : null) ?: $b;
    }
    return $out;
}

/** GET /api/v1/blocks-bulk/:minHeight/:maxHeight - BlockExtended for a height range (capped 100). */
function lx_blocks_bulk_api(array $net, int $min, int $max): array
{
    if ($max < $min) { [$min, $max] = [$max, $min]; }
    $max = min($max, lx_tip_height($net));
    $min = max(0, $min);
    if ($max - $min > 99) { $min = $max - 99; }    // cap 100 blocks per call
    if ($max < $min) { return []; }                // requested range is entirely above the tip; range() would count upward and allocate the whole gap
    $out = [];
    $heights = range($max, $min);                  // descending, newest first
    $hashes = lx_block_hashes($net, $heights);     // one getblockhash batch instead of a serial call per height
    lx_warm_block_stats($net, $hashes);            // one getblockstats batch so per-block lx_block_stats hits cache
    foreach ($heights as $h) {
        $hash = $hashes[$h] ?? null;
        if ($hash === null) { continue; }
        $ext = lx_ws_block_extended($net, $hash);
        if ($ext) { $out[] = $ext; }
    }
    return $out;
}

/**
 * GET /api/v1/mining/blocks/timestamp/:timestamp - the block nearest a unix time.
 * Exact from the block index; falls back to a spacing estimate refined by one header fetch.
 */
function lx_block_by_timestamp_api(array $net, int $ts): ?array
{
    // Trust the index only when the target actually falls within its retained time window
    // (it's a rolling ~90-day window); otherwise the "nearest indexed" block would be the
    // oldest retained one, far from the true answer - so fall through to the header walk.
    $db = lx_blockindex_pdo(false);
    if ($db) {
        try {
            $rng = $db->prepare('SELECT MIN(time) mn, MAX(time) mx FROM block_index WHERE net = ?');
            $rng->execute([$net['slug']]);
            $rr = $rng->fetch(PDO::FETCH_ASSOC);
            if ($rr && $rr['mn'] !== null && $ts >= (int) $rr['mn'] - 86400 && $ts <= (int) $rr['mx'] + 86400) {
                $st = $db->prepare('SELECT height, time FROM block_index WHERE net = ? ORDER BY ABS(time - ?) ASC LIMIT 1');
                $st->execute([$net['slug'], $ts]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $h = (int) $r['height'];
                    return ['height' => $h, 'hash' => (string) lx_block_hash_at($net, $h), 'timestamp' => (int) $r['time']];
                }
            }
        } catch (Throwable $e) {
        }
    }
    // Fallback: estimate height from spacing off the tip, refine once against the real header.
    $tip = lx_tip_height($net);
    if ($tip <= 0) { return null; }
    $tipHash = lx_block_hash_at($net, $tip);
    $tipHdr  = $tipHash ? lx_rpc_soft($net, 'getblockheader', [$tipHash, true]) : null;
    $tipTime = is_array($tipHdr) ? (int) ($tipHdr['time'] ?? time()) : time();
    $spacing = ($net['coin'] ?? '') === 'ltc' ? 150 : 600;
    $est = (int) max(0, min($tip, $tip - intdiv($tipTime - $ts, $spacing)));
    for ($iter = 0; $iter < 3; $iter++) {
        $hash = lx_block_hash_at($net, $est);
        $hdr  = $hash ? lx_rpc_soft($net, 'getblockheader', [$hash, true]) : null;
        if (!is_array($hdr)) { break; }
        $bt = (int) ($hdr['time'] ?? 0);
        $delta = intdiv($bt - $ts, $spacing);
        if ($delta === 0) {
            return ['height' => $est, 'hash' => (string) $hash, 'timestamp' => $bt];
        }
        $next = max(0, min($tip, $est - $delta));
        if ($next === $est) {
            return ['height' => $est, 'hash' => (string) $hash, 'timestamp' => $bt];
        }
        $est = $next;
    }
    $hash = lx_block_hash_at($net, $est);
    return $hash ? ['height' => $est, 'hash' => (string) $hash, 'timestamp' => 0] : null;
}

// ---- per-pool history (backed by the block index's pool column) -------------

/** Resolve a pool :slug back to the label stored in the index (matches slug or exact label). */
function lx_pool_label_for_slug(array $net, string $slug): ?string
{
    $db = lx_blockindex_pdo(false);
    if (!$db) {
        return null;
    }
    try {
        $st = $db->prepare('SELECT DISTINCT pool FROM block_index WHERE net = ?');
        $st->execute([$net['slug']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if ($p === $slug || lx_pool_slug(($p !== '' ? $p : 'unknown')) === strtolower($slug)) { return (string) $p; }
        }
    } catch (Throwable $e) {
    }
    return null;
}

/**
 * GET /api/v1/mining/hashrate/pools/:timePeriod - per-pool block share + estimated hashrate over
 * the indexed period. mempool.space shape: {pools:[{poolName,poolId,blockCount,share,avgHashrate}],
 * blockCount, lastEstimatedHashrate}.
 */
function lx_mining_hashrate_pools_api(array $net, string $period): array
{
    $hr = lx_network_hashrate($net);
    $db = lx_blockindex_pdo(false);
    if (!$db) {
        return ['pools' => [], 'blockCount' => 0, 'lastEstimatedHashrate' => $hr];
    }
    $tip = lx_tip_height($net);
    $span = lx_blockindex_period_blocks($period);
    $floor = $span === PHP_INT_MAX ? 0 : max(0, $tip - $span);
    $counts = []; $total = 0;
    try {
        $st = $db->prepare('SELECT pool, COUNT(*) c FROM block_index WHERE net = ? AND height >= ? GROUP BY pool');
        $st->execute([$net['slug'], $floor]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $counts[(string) $r['pool']] = (int) $r['c']; $total += (int) $r['c'];
        }
    } catch (Throwable $e) {
    }
    if ($total === 0) {
        return ['pools' => [], 'blockCount' => 0, 'lastEstimatedHashrate' => $hr];
    }
    arsort($counts);
    $pools = [];
    foreach ($counts as $name => $c) {
        $share = $c / $total;
        $label = $name !== '' ? $name : 'Unknown';
        $pools[] = ['poolName' => $label, 'poolId' => lx_pool_slug($label), 'blockCount' => $c,
                    'share' => $share, 'avgHashrate' => $hr * $share];
    }
    return ['pools' => $pools, 'blockCount' => $total, 'lastEstimatedHashrate' => $hr];
}

/** GET /api/v1/mining/pool/:slug/blocks[/:beforeHeight] - keyset-paginated pool blocks (10/page). */
function lx_mining_pool_blocks_api(array $net, string $slug, ?int $before = null): array
{
    $db = lx_blockindex_pdo(false);
    $label = lx_pool_label_for_slug($net, $slug);
    if (!$db || $label === null) {
        return [];
    }
    $before = $before ?? (lx_tip_height($net) + 1);
    try {
        $st = $db->prepare('SELECT height FROM block_index WHERE net = ? AND pool = ? AND height < ? ORDER BY height DESC LIMIT 10');
        $st->execute([$net['slug'], $label, $before]);
        $heights = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($heights as $h) {
        $hash = lx_block_hash_at($net, (int) $h);
        if ($hash) { $ext = lx_ws_block_extended($net, $hash); if ($ext) { $out[] = $ext; } }
    }
    return $out;
}

/**
 * GET /api/v1/mining/pool/:slug/hashrate - the pool's daily block-share × network hashrate series
 * over the indexed range: [{timestamp, avgHashrate, share, blockCount}], oldest-first.
 */
function lx_mining_pool_hashrate_api(array $net, string $slug): array
{
    $db = lx_blockindex_pdo(false);
    $label = lx_pool_label_for_slug($net, $slug);
    if (!$db || $label === null) {
        return [];
    }
    // Aggregate per-day in SQL so we don't load the whole index into PHP memory.
    try {
        $st = $db->prepare('SELECT (time / 86400) AS day, COUNT(*) tot, '
            . 'SUM(CASE WHEN pool = ? THEN 1 ELSE 0 END) cnt '
            . 'FROM block_index WHERE net = ? GROUP BY day ORDER BY day ASC');
        $st->execute([$label, $net['slug']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $hr = lx_network_hashrate($net);
    $out = [];
    foreach ($rows as $r) {
        $tot = (int) $r['tot']; $cnt = (int) $r['cnt'];
        $share = $tot > 0 ? $cnt / $tot : 0.0;
        $out[] = ['timestamp' => (int) $r['day'] * 86400, 'avgHashrate' => $hr * $share, 'share' => $share, 'blockCount' => $cnt];
    }
    return $out;
}
