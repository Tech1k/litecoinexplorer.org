<?php
/**
 * Charts hub: every server-rendered analytics chart in one place - mempool/fee
 * time-series, difficulty/hashrate, and per-block economics. The same charts also
 * appear contextually on the Mempool and Mining dashboards; this is the browse-all
 * destination. $net in scope (UTXO). SPDX-License-Identifier: AGPL-3.0-or-later
 */
$base = lx_u($net);

// Fetch everything BEFORE lx_head so a node outage yields a clean 503 rather than
// a half-rendered page.
$hist   = function_exists('lx_stats_series') ? lx_stats_series($net, 48) : [];  // mempool/fee history (snapshot cron)
$dser   = lx_difficulty_series($net, 24, 12000);   // oldest-first
$stats  = lx_recent_block_stats($net, 30);         // newest-first
$epochs = lx_difficulty_epochs($net, 12);          // retarget history

lx_head($net, ['title' => 'Charts - ' . $net['label'] . ' Explorer']);

// ---- per-block bar/stack series (Blocks section) ----------------------------
// A ?period= (24h..all) sources these from the bucketed block index; otherwise the
// last 30 blocks straight from the node. Falls back to the 30-block path if the index
// has no coverage yet for the chosen period.
$period   = (isset($_GET['period']) && lx_period_valid((string) $_GET['period'])) ? (string) $_GET['period'] : '';
$biSeries = ($period !== '' && function_exists('lx_blockindex_series')) ? lx_blockindex_series($net, $period) : [];
$feeBars = []; $sizeBars = []; $frBars = []; $wtBars = []; $fsRows = []; $fsTips = [];
if ($biSeries) {
    foreach ($biSeries as $b) {
        $lab = 'Block ~#' . number_format((int) $b['avgHeight']);
        $feeBars[]  = ['value' => $b['avgFees'],   'title' => $lab . ' · avg ' . lx_coin((int) round($b['avgFees'])) . ' ' . $net['unit'] . ' fees'];
        $sizeBars[] = ['value' => $b['avgSize'],   'title' => $lab . ' · avg ' . lx_size_str((int) round($b['avgSize']), 'B')];
        $frBars[]   = ['value' => $b['p50'],       'title' => $lab . ' · median ' . number_format((float) $b['p50'], 2) . ' sat/vB'];
        $wtBars[]   = ['value' => $b['avgWeight'], 'title' => $lab . ' · avg ' . commas((int) round($b['avgWeight'])) . ' WU'];
        $fsRows[]   = ['height' => (int) $b['avgHeight'], 'subsidy' => max(0, $b['avgRewards'] - $b['avgFees']), 'total_fee' => $b['avgFees']];
        $fsTips[]   = lx_tip_json('Block ~#' . number_format((int) $b['avgHeight']), [
            ['c' => '#3b82f6', 'k' => 'Subsidy', 'v' => lx_coin_compact(max(0, $b['avgRewards'] - $b['avgFees'])) . ' ' . $net['unit']],
            ['c' => '#f59e0b', 'k' => 'Fees',    'v' => lx_coin_compact((float) $b['avgFees']) . ' ' . $net['unit']],
        ]);
    }
} else {
    foreach (array_reverse($stats) as $b) {
        $bh = number_format($b['height']);
        $feeBars[]  = ['value' => $b['total_fee'], 'title' => 'Block ' . $bh . ' · ' . lx_coin((int) $b['total_fee']) . ' ' . $net['unit'] . ' fees · ' . commas($b['txs']) . ' tx'];
        $sizeBars[] = ['value' => $b['size'],      'title' => 'Block ' . $bh . ' · ' . lx_size_str((int) $b['size'], 'B') . ' · ' . commas($b['txs']) . ' tx'];
        $frBars[]   = ['value' => $b['med_feerate'], 'title' => 'Block ' . $bh . ' · median ' . number_format((float) $b['med_feerate'], 2) . ' sat/vB · range ' . number_format((float) $b['min_feerate'], 2) . '–' . number_format((float) $b['max_feerate'], 2)];
        $wtBars[]   = ['value' => $b['weight'],    'title' => 'Block ' . $bh . ' · ' . commas($b['weight']) . ' WU · ' . commas($b['txs']) . ' tx'];
        $fsRows[]   = ['height' => $b['height'], 'subsidy' => $b['subsidy'], 'total_fee' => $b['total_fee']];
        $fsTips[]   = lx_tip_json('Block #' . $bh, [
            ['c' => '#3b82f6', 'k' => 'Subsidy', 'v' => lx_coin((int) $b['subsidy']) . ' ' . $net['unit']],
            ['c' => '#f59e0b', 'k' => 'Fees',    'v' => lx_coin((int) $b['total_fee']) . ' ' . $net['unit']],
        ]);
    }
}
$blkSub = $biSeries ? (count($biSeries) . ' pts · ' . strtoupper($period)) : ('last ' . count($stats));

// ---- difficulty / hashrate labels + tips (Mining section) -------------------
$diffLabels = []; $hashLabels = []; $diffTips = []; $hashTips = [];
$prevD = null; $prevHr = null;
foreach ($dser as $r) {
    $d = (float) $r['difficulty']; $hr = (float) $r['hashrate'];
    $hdr = 'Block #' . number_format($r['height']);
    $diffLabels[] = '#' . number_format($r['height']) . ' · diff ' . lx_diff_str($d);
    $hashLabels[] = '#' . number_format($r['height']) . ' · ' . lx_hashrate($hr);
    $diffTips[] = lx_tip_json($hdr, [['c' => 'var(--accent)', 'k' => 'Difficulty', 'v' => lx_diff_str($d), 'd' => $prevD !== null ? lx_pct_delta($d, $prevD) : '']]);
    $hashTips[] = lx_tip_json($hdr, [['c' => 'var(--accent)', 'k' => 'Hashrate', 'v' => lx_hashrate($hr), 'd' => $prevHr !== null ? lx_pct_delta($hr, $prevHr) : '']]);
    $prevD = $d; $prevHr = $hr;
}
$dXticks = [];
if (count($dser) >= 2) {
    $hh = array_column($dser, 'height');
    $minH = min($hh); $spanH = max(1, max($hh) - $minH);
    for ($i = 0; $i <= 4; $i++) { $dXticks[] = ['f' => $i / 4, 'label' => '#' . lx_num_compact($minH + $spanH * $i / 4)]; }
}

// ---- mempool / fee labels + tips (Mempool section) --------------------------
$memLabels = []; $feeLabels = []; $memTips = []; $feeTips = [];
$prevM = null; $prevF = null;
foreach ($hist as $r) {
    $mv = (int) $r['mempool_vsize']; $ff = (int) $r['fast_fee'];
    $hdr = gmdate('M j, H:i', (int) $r['ts']);
    $memLabels[] = gmdate('m-d H:i', (int) $r['ts']) . ' · ' . lx_size_str($mv, 'vB');
    $feeLabels[] = gmdate('m-d H:i', (int) $r['ts']) . ' · ' . number_format($ff) . ' sat/vB';
    $memTips[] = lx_tip_json($hdr, [['c' => 'var(--accent)', 'k' => 'Mempool', 'v' => lx_size_str($mv, 'vB'), 'd' => $prevM !== null ? lx_pct_delta($mv, $prevM) : '']]);
    $feeTips[] = lx_tip_json($hdr, [['c' => 'var(--accent)', 'k' => 'Fast fee', 'v' => number_format($ff) . ' sat/vB', 'd' => $prevF !== null ? lx_pct_delta($ff, $prevF) : '']]);
    $prevM = $mv; $prevF = $ff;
}
$hXticks = [];
if (count($hist) >= 2) {
    $tt = array_column($hist, 'ts');
    $minTs = min($tt); $spanTs = max(1, max($tt) - $minTs);
    for ($i = 0; $i <= 4; $i++) { $hXticks[] = ['f' => $i / 4, 'label' => gmdate('m-d H:i', (int) ($minTs + $spanTs * $i / 4))]; }
}
// Mempool-by-fee-tier (stacked) - only when there's tier data.
$tierKeys = ['t0', 't1', 't2', 't3', 't4', 't5'];
$tierColors = [lx_feerate_color(0.5), lx_feerate_color(1.5), lx_feerate_color(3.5), lx_feerate_color(7), lx_feerate_color(15), lx_feerate_color(40)];
$tierLabels = ['<1', '1–2', '2–5', '5–10', '10–20', '20+'];
$hasTiers = false;
foreach ($hist as $r) {
    foreach ($tierKeys as $k) { if (($r[$k] ?? 0) > 0) { $hasTiers = true; break 2; } }
}
$tierLegend = [];
foreach ($tierKeys as $ti => $tk) { $tierLegend[] = ['color' => $tierColors[$ti], 'label' => $tierLabels[$ti] . ' sat/vB']; }
$stackTips = [];
foreach ($hist as $r) {
    $trows = [];
    foreach ($tierKeys as $ti => $tk) {
        $tvs = (int) ($r[$tk] ?? 0);
        if ($tvs > 0) { $trows[] = ['c' => $tierColors[$ti], 'k' => $tierLabels[$ti] . ' sat/vB', 'v' => lx_size_str($tvs, 'vB')]; }
    }
    $stackTips[] = lx_tip_json(gmdate('M j, H:i', (int) $r['ts']), $trows);
}

// ---- long-range: coin-supply curve (deterministic) + price history ----------
$supplyRows = []; $supplyLabels = []; $supplyXticks = [];
$tipH = lx_tip_height($net);
if ($tipH > 0 && ($net['coin'] ?? '') === 'ltc') {
    $interval = 840000; $sub0 = 5000000000;
    $minedAt = function ($h) use ($interval, $sub0) {
        $blocks = $h + 1; $sub = $sub0; $mined = 0;
        while ($blocks > 0 && $sub > 0) {
            $take = $blocks < $interval ? $blocks : $interval;
            $mined += $take * $sub; $blocks -= $take; $sub = intdiv($sub, 2);
        }
        return $mined;
    };
    for ($i = 0; $i <= 40; $i++) {
        $hh = (int) round($tipH * $i / 40);
        $co = $minedAt($hh) / 100000000;
        $supplyRows[]   = ['h' => $hh, 'supply' => $co];
        $supplyLabels[] = '#' . number_format($hh) . ' · ' . number_format($co) . ' LTC';
    }
    for ($i = 0; $i <= 4; $i++) { $supplyXticks[] = ['f' => $i / 4, 'label' => '#' . lx_num_compact($tipH * $i / 4)]; }
}

$priceRows = []; $priceLabels = []; $priceXticks = [];
$pcur = strtoupper(lx_price_config()['display']);
$phist = function_exists('lx_price_history') ? lx_price_history($net) : ['prices' => []];
foreach (($phist['prices'] ?? []) as $pp) {
    if (isset($pp[$pcur])) {
        $priceRows[]   = ['t' => (int) $pp['time'], 'p' => (float) $pp[$pcur]];
        $priceLabels[] = gmdate('M j', (int) $pp['time']) . ' · ' . lx_price_symbol(strtolower($pcur)) . number_format((float) $pp[$pcur], 2);
    }
}
// If the local snapshot history is too sparse (the price cron hasn't accumulated
// points yet), fall back to CoinGecko's historical daily series so the chart works.
if (count($priceRows) < 2 && function_exists('lx_price_market_chart')) {
    $priceLabels = [];
    foreach (lx_price_market_chart($net, $pcur, 365) as $pp) {
        $priceRows[]   = $pp;
        $priceLabels[] = gmdate('M j', $pp['t']) . ' · ' . lx_price_symbol(strtolower($pcur)) . number_format($pp['p'], 2);
    }
}
if (count($priceRows) >= 2) {
    $pt = array_column($priceRows, 't'); $minP = min($pt); $spanP = max(1, max($pt) - $minP);
    for ($i = 0; $i <= 4; $i++) { $priceXticks[] = ['f' => $i / 4, 'label' => gmdate('M j', (int) ($minP + $spanP * $i / 4))]; }
}

$hasAny = (count($hist) >= 2) || (count($dser) >= 2) || !empty($stats) || count($supplyRows) >= 2 || count($priceRows) >= 2;
?>
<h1>Charts</h1>
<p class="muted sub">Every server-rendered chart for <?= h($net['label']) ?>. The mempool &amp; fee series are fed by periodic snapshots; block series come straight from the node. The same charts also appear on the <a href="<?= h($base) ?>/mempool">Mempool</a> and <a href="<?= h($base) ?>/mining">Mining</a> dashboards.</p>

<?php if (!$hasAny): ?>
<div class="card"><div class="card-b"><p class="muted">No chart data yet. The snapshot history builds up over time.</p></div></div>
<?php endif; ?>

<?php if (count($hist) >= 2): ?>
<div class="section-h">Mempool &amp; fees</div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Mempool size</span> <span class="sub">over time · vB</span></div>
    <div class="card-b">
      <?= lx_chart_area($hist, 'ts', 'mempool_vsize', 'Mempool virtual size over time', $memLabels, [
          'yfmt'   => function ($v, $step = 0.0) { return lx_size_str((int) $v, 'vB', $step); },
          'xticks' => $hXticks,
          'tips'   => $memTips,
      ]) ?>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Next-block fee</span> <span class="sub">over time · sat/vB</span></div>
    <div class="card-b">
      <?= lx_chart_area($hist, 'ts', 'fast_fee', 'Next-block fee rate over time', $feeLabels, [
          'yfmt'   => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 0)) . ' s/vB'; },
          'xticks' => $hXticks,
          'tips'   => $feeTips,
      ]) ?>
    </div>
  </div>
</div>
<?php if ($hasTiers): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('layers') ?>Mempool by fee rate</span> <span class="sub">over time · vsize, green low &rarr; red high</span></div>
  <div class="card-b">
    <?= lx_chart_stacked($hist, 'ts', $tierKeys, $tierColors, 'Mempool vsize by fee tier over time', [
        'yfmt'   => function ($v) { return lx_size_str((int) $v, 'vB'); },
        'xticks' => $hXticks,
        'legend' => $tierLegend,
        'tips'   => $stackTips,
    ]) ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (count($dser) >= 2): ?>
<div class="section-h">Mining</div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('target') ?>Difficulty</span> <span class="sub">recent blocks</span></div>
    <div class="card-b">
      <?= lx_chart_area($dser, 'height', 'difficulty', 'Difficulty over recent blocks', $diffLabels, [
          'yfmt'   => 'lx_num_compact',
          'xticks' => $dXticks,
          'tips'   => $diffTips,
      ]) ?>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('zap') ?>Hashrate</span> <span class="sub">estimated</span></div>
    <div class="card-b">
      <?= lx_chart_area($dser, 'height', 'hashrate', 'Estimated hashrate over recent blocks', $hashLabels, [
          'yfmt'   => 'lx_hashrate',
          'xticks' => $dXticks,
          'tips'   => $hashTips,
      ]) ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($epochs)):
    // Retarget history as a diverging chart (increase up / decrease down), oldest -> newest.
    $epochRows = []; $epochLabels = []; $epochTips = [];
    foreach (array_reverse($epochs) as $e) {
        $pct = (float) $e['pct_change'];
        $epochRows[]   = ['up' => max(0.0, $pct), 'down' => max(0.0, -$pct)];
        $epochLabels[] = '#' . number_format((int) $e['height']) . ' · ' . ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%';
        $epochTips[]   = lx_tip_json(gmdate('Y-m-d', (int) $e['time']) . ' · retarget #' . number_format((int) $e['height']), [
            ['c' => $pct >= 0 ? 'var(--ok)' : 'var(--bad)', 'k' => 'Change',     'v' => ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%'],
            ['c' => 'var(--muted)',                          'k' => 'Difficulty', 'v' => lx_diff_str((float) $e['difficulty'])],
        ]);
    }
?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('target') ?>Difficulty adjustments</span> <span class="sub">per 2,016-block retarget · %</span></div>
  <div class="card-b"><?= lx_chart_diverging($epochRows, 'up', 'down', 'Difficulty change per retarget (increase above, decrease below)', $epochLabels, [
      'yfmt'   => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 1)) . '%'; },
      'tips'   => $epochTips,
      'legend' => [['color' => 'var(--ok)', 'label' => 'Increase'], ['color' => 'var(--bad)', 'label' => 'Decrease']],
  ]) ?></div>
</div>
<?php endif; ?>

<?php if ($stats || $biSeries): ?>
<div class="section-h" id="blocks">Blocks</div>
<div class="period-sel"><?php $pers = ['' => 'Recent', '24h' => '24h', '3d' => '3d', '1w' => '1w', '1m' => '1m', '3m' => '3m', '6m' => '6m', '1y' => '1y', 'all' => 'All']; foreach ($pers as $pk => $pl): ?><a class="<?= $period === $pk ? 'on' : '' ?>" href="<?= h($base) ?>/charts<?= $pk !== '' ? '?period=' . h($pk) : '' ?>#blocks"><?= h($pl) ?></a><?php endforeach; ?></div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Fees per block</span> <span class="sub"><?= h($blkSub) ?> · <?= h($net['unit']) ?></span></div>
    <div class="card-b"><?= lx_chart_bars($feeBars, 'Total fees per block', ['yfmt' => 'lx_coin_compact']) ?></div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Block size</span> <span class="sub"><?= h($blkSub) ?></span></div>
    <div class="card-b"><?= lx_chart_bars($sizeBars, 'Block size', ['yfmt' => function ($v) { return lx_size_str((int) $v, 'B'); }]) ?></div>
  </div>
</div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Block fee rates</span> <span class="sub">median · sat/vB</span></div>
    <div class="card-b"><?= lx_chart_bars($frBars, 'Median fee rate per block', ['yfmt' => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 1)) . ' s/vB'; }]) ?></div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Block weight</span> <span class="sub"><?= h($blkSub) ?> · WU</span></div>
    <div class="card-b"><?= lx_chart_bars($wtBars, 'Block weight per block', ['yfmt' => function ($v) { return lx_size_str((int) $v, 'WU'); }]) ?></div>
  </div>
</div>
<div class="card">
  <div class="card-h"><span><?= lx_icon('gift') ?>Block reward composition</span> <span class="sub">subsidy + fees · <?= h($net['unit']) ?></span></div>
  <div class="card-b"><?= lx_chart_stacked($fsRows, 'height', ['subsidy', 'total_fee'], ['#3b82f6', '#f59e0b'], 'Block reward: subsidy and fees per block', [
      'yfmt'   => 'lx_coin_compact',
      'tips'   => $fsTips,
      'legend' => [['color' => '#3b82f6', 'label' => 'Subsidy'], ['color' => '#f59e0b', 'label' => 'Fees']],
  ]) ?></div>
</div>
<?php endif; ?>

<?php if (count($supplyRows) >= 2 || count($priceRows) >= 2): ?>
<div class="section-h">Supply &amp; price</div>
<div class="txio">
  <?php if (count($supplyRows) >= 2): ?>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('database') ?>Coin supply</span> <span class="sub">issued · <?= h($net['unit']) ?></span></div>
    <div class="card-b"><?= lx_chart_area($supplyRows, 'h', 'supply', 'Litecoin supply issued over time', $supplyLabels, [
        'yfmt' => 'lx_num_compact', 'xticks' => $supplyXticks, 'baseline' => 'zero',
    ]) ?></div>
  </div>
  <?php endif; ?>
  <?php if (count($priceRows) >= 2): ?>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Price</span> <span class="sub"><?= h($pcur) ?> · history</span></div>
    <div class="card-b"><?= lx_chart_area($priceRows, 't', 'p', 'Litecoin price over time', $priceLabels, [
        'yfmt' => function ($v, $step = 0.0) { return number_format($v, $v < 1 ? 3 : 2); }, 'xticks' => $priceXticks,
    ]) ?></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php lx_foot($net); ?>
