<?php
/**
 * Mempool overview: counts, fee histogram, recent txs.
 * $net in scope. SPDX-License-Identifier: AGPL-3.0-or-later
 */
$mem = lx_esplora_mempool($net);
$recent = lx_mempool_recent($net);
try {
    $fees = lx_fees_recommended($net);
} catch (Throwable $e) {
    $fees = null;
}
// Build fee-band totals from the histogram for the bars.
$maxVsize = 1;
foreach ($mem['fee_histogram'] as $band) {
    if (($band[1] ?? 0) > $maxVsize) { $maxVsize = $band[1]; }
}
$proj    = lx_projected_blocks($net, 8);
$hist    = function_exists('lx_stats_series') ? lx_stats_series($net, 48) : [];   // mempool/fee time-series (snapshot cron)
$spacing = $net['coin'] === 'ltc' ? 150 : 600;   // target block time, seconds

$ogNext = $fees ? ' · next block ' . commas($fees['fastestFee']) . ' sat/vB' : '';
lx_head($net, [
    'title' => 'Mempool - ' . $net['label'] . ' Explorer',
    'desc'  => $net['label'] . ' mempool · ' . commas($mem['count']) . ' pending transactions · '
             . commas((int) round($mem['vsize'] / 1000)) . ' kvB' . $ogNext . '.',
    'og_image' => '/og/home.png',
]);
?>
<h1>Mempool</h1>
<div class="card">
  <div class="card-h"><span><?= lx_icon('activity') ?>Mempool</span> <span class="hero-eyebrow"><span class="pulse-dot"></span>live</span></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub">Transactions</div><div class="big-num sm"><?= commas($mem['count']) ?></div></div>
      <div class="stat"><div class="muted sub">Virtual size</div><div><?= commas(round($mem['vsize'] / 1000)) ?> kvB</div></div>
      <div class="stat"><div class="muted sub">Total fees</div><div><?= lx_amount_el($net, (int) $mem['total_fee']) ?></div></div>
      <?php if ($fees): ?>
      <div class="stat"><div class="muted sub">Next block</div><div><?= commas($fees['fastestFee']) ?> sat/vB</div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
// Detailed spotlight on the next projected block ($proj[0]).
if ($proj):
    $nb    = $proj[0];
    $nbVs  = (int) $nb['vsize'];
    $cap   = min(100, $nbVs / 1000000 * 100);              // vs the ~1 vMB target
    $avgR  = $nbVs > 0 ? $nb['fee'] / $nbVs : 0.0;          // mean sat/vB across the block
    $fr    = function ($r) { return $r >= 10 ? number_format($r, 0) : number_format($r, 1); };
    // Fee-distribution strip: cells are fee-ordered high->low; render low->high so
    // the bar reads green (cheap) -> red (dear) left to right. Widths sum to $nbVs.
    $fcells = array_reverse($nb['cells']);
?>
<div class="card brand-top" style="--brand:<?= h(lx_brand_color($net['coin'])) ?>">
  <div class="card-h"><span><?= lx_icon('layers') ?>Next block</span> <span class="hero-eyebrow"><span class="pulse-dot"></span>projected</span></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub">Median fee</div><div class="big-num sm" style="color:<?= h(lx_feerate_color($nb['med'])) ?>"><?= h(number_format($nb['med'], 1)) ?> <span class="blk-unit">sat/vB</span></div></div>
      <div class="stat"><div class="muted sub">Fee range</div><div><?= h($fr($nb['min'])) ?> &ndash; <?= h($fr($nb['max'])) ?> <span class="muted sub">sat/vB</span></div><div class="muted sub">avg <?= h(number_format($avgR, 1)) ?></div></div>
      <div class="stat"><div class="muted sub">Transactions</div><div class="big-num sm"><?= commas($nb['count']) ?></div></div>
      <div class="stat"><div class="muted sub">Total fees</div><div><?= lx_amount_el($net, (int) $nb['fee']) ?></div></div>
      <div class="stat"><div class="muted sub">Virtual size</div><div><?= h(lx_size_str($nbVs, 'vB')) ?> <span class="muted sub"><?= h(number_format($cap, $cap >= 99.5 ? 0 : 1)) ?>%</span></div></div>
      <div class="stat"><div class="muted sub">Expected in</div><div><?= $nb['partial'] ? 'this block' : '~' . max(1, round($spacing / 60)) . ' min' ?></div></div>
    </div>
    <?php if ($fcells): ?>
    <div style="display:flex;height:10px;border-radius:5px;overflow:hidden;margin-top:14px" role="img" aria-label="Fee-rate distribution of the next block, low to high">
      <?php foreach ($fcells as $c): $w = $nbVs > 0 ? (int) $c['vsize'] / $nbVs * 100 : 0; if ($w <= 0) { continue; } ?><span style="width:<?= round($w, 3) ?>%;background:<?= h(lx_feerate_color((float) $c['rate'])) ?>" title="<?= h(number_format((float) $c['rate'], 1)) ?> sat/vB"></span><?php endforeach; ?>
    </div>
    <div class="feaxis muted sub" style="display:flex;justify-content:space-between;margin-top:5px"><span><?= h($fr($nb['min'])) ?> sat/vB</span><?php if ($nb['partial']): ?><span>mempool smaller than one block</span><?php endif; ?><span><?= h($fr($nb['max'])) ?> sat/vB</span></div>
    <?php endif; ?>
    <div style="margin-top:14px"><a class="btn ghost sm" href="<?= h(lx_u($net)) ?>/mempool-block/0"><?= lx_icon('list') ?>Transactions in this block</a></div>
  </div>
</div>
<?php endif; ?>

<?php if ($proj): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('layers') ?>Mempool blocks</span> <span class="sub">projected · fee-shaded</span></div>
  <div class="card-b nopad">
    <div class="goggles-strip">
      <?php foreach ($proj as $i => $b): ?>
      <a class="goggle-blk" href="<?= h(lx_u($net)) ?>/mempool-block/<?= (int) $i ?>" style="text-decoration:none;color:inherit">
        <?php if ($i === 0): ?><div class="blk-tag">NEXT</div><?php endif; ?>
        <?= lx_goggles_block($b) ?>
        <div class="goggle-meta">
          <div class="blk-fee" style="color:<?= h(lx_feerate_color($b['med'])) ?>"><?= h(number_format($b['med'], 1)) ?> <span class="blk-unit">sat/vB</span></div>
          <div class="blk-meta"><?= commas($b['count']) ?> tx</div>
          <div class="blk-meta"><?= h(lx_size_str((int) $b['vsize'], 'vB')) ?></div>
          <div class="blk-age"><?= $i === 0 ? 'next block' : '~' . round(($i + 1) * $spacing / 60) . ' min' ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card-b"><p class="muted sub">Each column is one ~1 vMB block of pending transactions, cells shaded by fee rate (green low &rarr; red high).</p></div>
</div>
<?php endif; ?>

<?php if ($mem['fee_histogram']): ?>
<div class="card">
  <div class="card-h">Fee distribution <span class="sub">sat/vB → vsize</span></div>
  <div class="card-b">
    <?php foreach ($mem['fee_histogram'] as $band): ?>
      <?php $rate = $band[0] ?? 0; $vs = $band[1] ?? 0; $pct = round(100 * $vs / $maxVsize); ?>
      <div class="febar-row">
        <span class="febar-label mono"><?= h(number_format((float) $rate, 1)) ?></span>
        <span class="febar"><span style="width:<?= $pct ?>%;background:<?= h(lx_feerate_color((float) $rate)) ?>"></span></span>
        <span class="febar-val muted mono"><?= commas(round($vs / 1000)) ?> kvB</span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (count($hist) >= 2):
    $memLabels = []; $feeLabels = []; $memTips = []; $feeTips = [];
    $prevM = null; $prevF = null;
    foreach ($hist as $r) {
        $mv = (int) $r['mempool_vsize']; $ff = (int) $r['fast_fee'];
        $hdr = gmdate('M j, H:i', (int) $r['ts']);
        $memLabels[] = gmdate('m-d H:i', (int) $r['ts']) . ' · ' . lx_size_str($mv, 'vB');
        $feeLabels[] = gmdate('m-d H:i', (int) $r['ts']) . ' · ' . number_format($ff) . ' sat/vB';
        $memTips[] = lx_tip_json($hdr, [
            ['c' => 'var(--accent)', 'k' => 'Mempool', 'v' => lx_size_str($mv, 'vB'), 'd' => $prevM !== null ? lx_pct_delta($mv, $prevM) : ''],
        ]);
        $feeTips[] = lx_tip_json($hdr, [
            ['c' => 'var(--accent)', 'k' => 'Fast fee', 'v' => number_format($ff) . ' sat/vB', 'd' => $prevF !== null ? lx_pct_delta($ff, $prevF) : ''],
        ]);
        $prevM = $mv; $prevF = $ff;
    }
    $hXticks = [];
    $tt = array_column($hist, 'ts');
    $minTs = min($tt); $spanTs = max(1, max($tt) - $minTs);
    for ($i = 0; $i <= 4; $i++) { $hXticks[] = ['f' => $i / 4, 'label' => gmdate('m-d H:i', (int) ($minTs + $spanTs * $i / 4))]; }
?>
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
<?php
    $tierKeys = ['t0', 't1', 't2', 't3', 't4', 't5'];
    $tierColors = [lx_feerate_color(0.5), lx_feerate_color(1.5), lx_feerate_color(3.5), lx_feerate_color(7), lx_feerate_color(15), lx_feerate_color(40)];
    $hasTiers = false;
    foreach ($hist as $r) {
        foreach ($tierKeys as $k) { if (($r[$k] ?? 0) > 0) { $hasTiers = true; break 2; } }
    }
    $tierLabels = ['<1', '1–2', '2–5', '5–10', '10–20', '20+'];
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
    if ($hasTiers):
?>
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

<div class="card">
  <div class="card-h">Recent transactions</div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Txid</th><th class="amt">Amount</th><th class="amt">Fee</th><th class="amt">vSize</th><th class="amt">Rate</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $t): ?>
        <?php $vs = (int) ($t['vsize'] ?? 0); $rate = $vs > 0 ? ($t['fee'] ?? 0) / $vs : 0; ?>
        <tr>
          <td class="mono"><a class="addr" href="<?= h(lx_tx_href($net, $t['txid'])) ?>"><?= h(shorten($t['txid'])) ?></a></td>
          <td class="amt"><?= lx_amount_el($net, (int) ($t['value'] ?? 0), false) ?></td>
          <td class="amt"><?= lx_amount_el($net, (int) ($t['fee'] ?? 0), false) ?></td>
          <td class="amt"><?= commas($vs) ?></td>
          <td class="amt"><?= h(number_format($rate, 1)) ?> sat/vB</td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="5"><div class="empty"><?= lx_icon('inbox') ?><span>Mempool is empty.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php lx_foot($net); ?>
