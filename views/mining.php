<?php
/**
 * Mining dashboard: pool distribution (coinbase tags), difficulty/hashrate,
 * fees & block sizes over a recent window, an optional mempool/fee history
 * (fed by the snapshot cron), and a per-pool block list. $net in scope (UTXO).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$poolFilter = $GLOBALS['mining_pool'] ?? null;
$base = lx_u($net);

// Fetch all backend data BEFORE lx_head(): it flushes <head>+nav, after which
// the exception handler can no longer set a clean 503 on a node outage.
if ($poolFilter !== null) {
    $pblocks = lx_pool_blocks($net, $poolFilter, 50);   // same window as the distribution (reuses cache)
} else {
    $dist  = lx_mining_distribution($net, 50);
    $stats = lx_recent_block_stats($net, 30);          // newest-first
    $dser  = lx_difficulty_series($net, 24, 12000);    // oldest-first
    $epochs = lx_difficulty_epochs($net, 12);          // retarget-history table
    $proj   = lx_projected_blocks($net);               // projected mempool blocks for the strip
    $hashrate = lx_network_hashrate($net);             // current network hashrate (headline tile)
    try {
        $da = lx_difficulty_adjustment($net);          // retarget progress / ETA
    } catch (Throwable $e) {
        $da = null;
    }
}

lx_head($net, ['title' => ($poolFilter !== null ? $poolFilter . ' - Mining' : 'Mining') . ' - ' . $net['label'] . ' Explorer']);
?>
<?php if ($poolFilter !== null):
    // ---- per-pool detail ----------------------------------------------------
?>
<h1>Mining &middot; <?= h($poolFilter) ?></h1>
<div class="card">
  <div class="card-h"><span><?= lx_icon('cpu') ?><?= h($poolFilter) ?></span> <span class="sub"><?= count($pblocks) ?> of the last 50 blocks</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Height</th><th>Hash</th><th>Coinbase tag</th></tr></thead>
      <tbody>
      <?php foreach ($pblocks as $b): ?>
        <tr>
          <td><a href="<?= h($base) ?>/block/<?= h($b['hash']) ?>"><?= commas($b['height']) ?></a></td>
          <td class="mono"><a class="addr" href="<?= h($base) ?>/block/<?= h($b['hash']) ?>"><?= h(shorten($b['hash'], 8, 6)) ?></a></td>
          <td class="mono"><?php $t = (string) $b['tag']; echo h($t === '' ? '-' : (strlen($t) > 42 ? substr($t, 0, 42) . '…' : $t)); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pblocks): ?><tr><td colspan="3"><div class="empty"><?= lx_icon('inbox') ?><span>No blocks by this miner in the recent window.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination"><a class="btn ghost sm" href="<?= h($base) ?>/mining">&larr; All pools</a></div>
</div>
<?php lx_foot($net); return; endif; ?>
<?php
// ---- dashboard ($dist/$stats/$dser were fetched above lx_head) --------------
$feeBars = [];
$sizeBars = [];
$frBars = [];       // median fee rate per block (min-max in tooltip)
$wtBars = [];       // block weight
$fsRows = []; $fsTips = [];   // fees-vs-subsidy stacked (subsidy + fees) + tooltips
$rwReward = 0; $rwFees = 0; $rwTxs = 0;
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
    $rwReward += (int) $b['subsidy'] + (int) $b['total_fee'];
    $rwFees   += (int) $b['total_fee'];
    $rwTxs    += (int) $b['txs'];
}
$rwN        = count($stats);
$rwAvgFees  = $rwN > 0 ? $rwFees / $rwN : 0.0;      // avg total fees per block (sats)
$rwAvgTxFee = $rwTxs > 0 ? $rwFees / $rwTxs : 0.0;  // avg fee per transaction (sats)
$curDiff    = !empty($dser) ? (float) $dser[count($dser) - 1]['difficulty'] : 0.0;
// Hover labels + rich tooltips for the area charts (per data point, oldest→newest).
$diffLabels = [];
$hashLabels = [];
$diffTips = [];
$hashTips = [];
$prevD = null; $prevHr = null;
foreach ($dser as $r) {
    $d = (float) $r['difficulty']; $hr = (float) $r['hashrate'];
    $hdr = 'Block #' . number_format($r['height']);
    $diffLabels[] = '#' . number_format($r['height']) . ' · diff ' . lx_diff_str($d);
    $hashLabels[] = '#' . number_format($r['height']) . ' · ' . lx_hashrate($hr);
    $diffTips[] = lx_tip_json($hdr, [
        ['c' => 'var(--accent)', 'k' => 'Difficulty', 'v' => lx_diff_str($d), 'd' => $prevD !== null ? lx_pct_delta($d, $prevD) : ''],
    ]);
    $hashTips[] = lx_tip_json($hdr, [
        ['c' => 'var(--accent)', 'k' => 'Hashrate', 'v' => lx_hashrate($hr), 'd' => $prevHr !== null ? lx_pct_delta($hr, $prevHr) : ''],
    ]);
    $prevD = $d; $prevHr = $hr;
}
// Frame x-axis ticks: difficulty/hashrate plot vs block height.
$dXticks = [];
if (count($dser) >= 2) {
    $hh = array_column($dser, 'height');
    $minH = min($hh); $spanH = max(1, max($hh) - $minH);
    for ($i = 0; $i <= 4; $i++) { $dXticks[] = ['f' => $i / 4, 'label' => '#' . lx_num_compact($minH + $spanH * $i / 4)]; }
}
?>
<h1>Mining</h1>

<?php $mstrip = array_slice($stats, 0, 12); if ($mstrip || !empty($proj)): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('box') ?>Blocks</span> <span class="sub">projected mempool &amp; recent</span></div>
  <div class="card-b nopad">
    <div class="blocks-strip">
      <?php foreach ($proj as $pi => $p): $pc = lx_feerate_color($p['med']); ?>
      <a class="blk proj" href="<?= h($base) ?>/mempool-block/<?= (int) $pi ?>">
        <div class="blk-tag"><?= $pi === 0 ? 'Next block' : 'In ' . ($pi + 1) ?></div>
        <div class="blk-fee" style="color:<?= h($pc) ?>"><?= h(number_format($p['med'], 1)) ?> <span class="blk-unit">sat/vB</span></div>
        <?php if ($p['max'] > $p['min']): ?><div class="blk-range"><?= h(number_format($p['min'], 1)) ?>-<?= h(number_format($p['max'], 1)) ?></div><?php endif; ?>
        <div class="blk-meta"><?= commas($p['count']) ?> tx</div>
        <div class="blk-meta"><?= h(lx_size_str((int) $p['vsize'], 'vB')) ?></div>
      </a>
      <?php endforeach; ?>
      <?php if (!empty($proj)): ?><div class="blk-div" aria-hidden="true"></div><?php endif; ?>
      <?php foreach ($mstrip as $b): $bc = lx_feerate_color($b['med_feerate']); ?>
      <a class="blk conf" style="border-top-color:<?= h($bc) ?>" href="<?= h(lx_block_href($net, $b['hash'])) ?>">
        <div class="blk-h">#<?= commas($b['height']) ?></div>
        <div class="blk-fee" style="color:<?= h($bc) ?>"><?= h(number_format($b['med_feerate'], 1)) ?> <span class="blk-unit">sat/vB</span></div>
        <?php if ($b['max_feerate'] > $b['min_feerate']): ?><div class="blk-range"><?= h(number_format($b['min_feerate'], 1)) ?>-<?= h(number_format($b['max_feerate'], 1)) ?></div><?php endif; ?>
        <div class="blk-meta"><?= commas($b['txs']) ?> tx · <?= h(lx_size_str((int) $b['size'], 'B')) ?></div>
        <div class="blk-age"><?= h(time_ago($b['time'])) ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('zap') ?>Reward stats</span> <span class="sub">last <?= commas($rwN) ?> blocks</span></div>
    <div class="card-b">
      <div class="stat-grid">
        <div class="stat"><div class="muted sub"><?= lx_icon('gift') ?>Miners reward</div><div class="big-num sm"><?= h(lx_coin_compact($rwReward)) ?></div><div class="muted sub"><?= h($net['unit']) ?> · subsidy + fees</div></div>
        <div class="stat"><div class="muted sub"><?= lx_icon('trending-up') ?>Avg block fees</div><div class="big-num sm"><?= h(lx_coin((int) round($rwAvgFees))) ?></div><div class="muted sub"><?= h($net['unit']) ?> / block</div></div>
        <div class="stat"><div class="muted sub"><?= lx_icon('layers') ?>Avg tx fee</div><div class="big-num sm"><?= h(lx_num_compact($rwAvgTxFee)) ?></div><div class="muted sub">sats / tx</div></div>
      </div>
    </div>
  </div>
  <?php if ($da !== null): ?>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('target') ?>Difficulty adjustment</span> <span class="sub">current epoch</span></div>
    <div class="card-b">
      <div class="stat-grid">
        <div class="stat"><div class="muted sub"><?= lx_icon('box') ?>Remaining</div><div class="big-num sm"><?= commas((int) $da['remainingBlocks']) ?></div><div class="muted sub">blocks to retarget</div></div>
        <div class="stat"><div class="muted sub"><?= lx_icon('trending-up') ?>Est. change</div><div class="big-num sm <?= $da['difficultyChange'] >= 0 ? 'pos' : 'neg' ?>"><?= ($da['difficultyChange'] >= 0 ? '+' : '') . h(number_format($da['difficultyChange'], 2)) ?>%</div><div class="muted sub">prev <?= ($da['previousRetarget'] >= 0 ? '+' : '') . h(number_format($da['previousRetarget'], 2)) ?>%</div></div>
      </div>
      <div class="progress mt-3" role="img" aria-label="Progress through the current difficulty epoch"><span style="width:<?= h(number_format(min(100, max(0, $da['progressPercent'])), 2)) ?>%"></span></div>
      <p class="muted sub mt-2">Retarget at block <?= commas((int) $da['nextRetargetHeight']) ?> &middot; est. <?= h(gmdate('Y-m-d', (int) ($da['estimatedRetargetDate'] / 1000))) ?> &middot; avg block <?= h(number_format($da['timeAvg'] / 60000, 1)) ?> min</p>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-h"><span><?= lx_icon('cpu') ?>Pool dominance</span> <span class="sub">last <?= (int) $dist['window'] ?> blocks</span></div>
  <?php if (!empty($dist['pools'])): ?>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub"><?= lx_icon('cpu') ?>Pools / miners</div><div class="big-num sm"><?= commas(count($dist['pools'])) ?></div><div class="muted sub">seen in window</div></div>
      <div class="stat"><div class="muted sub"><?= lx_icon('zap') ?>Hashrate</div><div class="big-num sm"><?= h(lx_hashrate($hashrate)) ?></div><div class="muted sub">estimated</div></div>
      <div class="stat"><div class="muted sub"><?= lx_icon('activity') ?>Difficulty</div><div class="big-num sm"><?= h(lx_diff_str($curDiff)) ?></div><div class="muted sub">current</div></div>
    </div>
  </div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>#</th><th>Pool / miner</th><th class="amt">Blocks</th><th>Share</th></tr></thead>
      <tbody>
      <?php foreach ($dist['pools'] as $i => $p): ?>
        <tr>
          <td class="muted mono"><?php if ($i === 0): ?><span class="badge soft">1</span><?php else: ?><?= $i + 1 ?><?php endif; ?></td>
          <td><a href="<?= h($base) ?>/mining/<?= h(rawurlencode($p['name'])) ?>"><?= h($p['name']) ?></a></td>
          <td class="amt mono"><?= (int) $p['count'] ?></td>
          <td><span class="poolbar"><span style="width:<?= round($p['pct']) ?>%"></span></span> <span class="muted mono"><?= h(number_format($p['pct'], 1)) ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-b"><p class="muted sub">Attributed from coinbase-tag markers. Blocks whose coinbase carries no recognisable pool tag are grouped as &ldquo;Unknown&rdquo;.</p></div>
  <?php else: ?>
  <div class="card-b"><p class="muted">No blocks to analyse yet.</p></div>
  <?php endif; ?>
</div>

<?php $recent = array_slice($stats, 0, 15); if ($recent): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('box') ?>Recent blocks</span> <span class="sub">last <?= count($recent) ?> &middot; pool &amp; template health</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Height</th><th>Pool / miner</th><th>Health</th><th class="amt">Reward</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $b):
          $bpool = lx_block_pool($net, $b['hash']);
          $baudit = function_exists('lx_audit_get') ? lx_audit_get($net, (int) $b['height']) : null;
          $breward = (int) $b['subsidy'] + (int) $b['total_fee'];
      ?>
        <tr>
          <td class="mono"><a href="<?= h($base) ?>/block/<?= h($b['hash']) ?>"><?= commas($b['height']) ?></a></td>
          <td><?php if ($bpool['pool'] !== null): ?><a class="badge soft" href="<?= h($base) ?>/mining/<?= h(rawurlencode($bpool['label'])) ?>"><?= h($bpool['label']) ?></a><?php else: ?><span class="muted"><?= h($bpool['label']) ?></span><?php endif; ?></td>
          <td><?php if ($baudit !== null): $hp = (float) $baudit['match_pct']; $hc = $hp >= 90 ? 'ok' : ($hp >= 70 ? 'soft' : 'bad'); ?><span class="badge <?= $hc ?>" title="<?= commas($baudit['matched']) ?> of <?= commas($baudit['mined']) ?> mined tx matched the projected template"><?= h(number_format($hp, 0)) ?>%</span><?php else: ?><span class="muted" title="No template snapshot was captured for this block">&ndash;</span><?php endif; ?></td>
          <td class="amt mono"><?= h(lx_coin($breward)) ?> <span class="muted sub"><?= h($net['unit']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (count($dser) >= 2): ?>
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

<?php if (!empty($epochs)): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('target') ?>Difficulty adjustments</span> <span class="sub">every 2,016 blocks</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Retarget</th><th class="amt">Date</th><th class="amt">Difficulty</th><th class="amt">Change</th></tr></thead>
      <tbody>
      <?php foreach ($epochs as $e): ?>
        <tr>
          <td><a href="<?= h($base) ?>/block-height/<?= (int) $e['height'] ?>">#<?= commas($e['height']) ?></a></td>
          <td class="amt muted"><?= h(gmdate('Y-m-d', (int) $e['time'])) ?></td>
          <td class="amt mono"><?= h(lx_diff_str((float) $e['difficulty'])) ?></td>
          <td class="amt"><span class="<?= $e['pct_change'] >= 0 ? 'pos' : 'neg' ?>"><?= ($e['pct_change'] >= 0 ? '+' : '') . h(number_format($e['pct_change'], 2)) ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($stats): ?>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Fees per block</span> <span class="sub">last <?= count($stats) ?> · <?= h($net['unit']) ?></span></div>
    <div class="card-b"><?= lx_chart_bars($feeBars, 'Total fees per block', ['yfmt' => 'lx_coin_compact']) ?></div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Block size</span> <span class="sub">last <?= count($stats) ?></span></div>
    <div class="card-b"><?= lx_chart_bars($sizeBars, 'Block size, last ' . count($stats) . ' blocks', ['yfmt' => function ($v) { return lx_size_str((int) $v, 'B'); }]) ?></div>
  </div>
</div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Block fee rates</span> <span class="sub">median · sat/vB</span></div>
    <div class="card-b"><?= lx_chart_bars($frBars, 'Median fee rate per block', ['yfmt' => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 1)) . ' s/vB'; }]) ?></div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Block weight</span> <span class="sub">last <?= count($stats) ?> · WU</span></div>
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
<?php lx_foot($net); ?>
