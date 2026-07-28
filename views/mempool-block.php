<?php
/**
 * Detail page for one projected mempool block (mempool.space-style): its fee
 * stats, fee-shaded goggles, and the transactions packed into it. Reached by
 * clicking a block in the /mempool projection. $net in scope; the 0-based index
 * is in $GLOBALS['mempool_block_index']. A live snapshot of the current mempool.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$index   = (int) ($GLOBALS['mempool_block_index'] ?? 0);
$proj    = lx_projected_blocks($net, 8);
$base    = lx_u($net);
$spacing = $net['coin'] === 'ltc' ? 150 : 600;

if (!$proj || $index >= count($proj)) {
    lx_head($net, ['title' => 'Projected block - ' . $net['label'] . ' Explorer']);
    echo '<h1>Projected block</h1>';
    echo '<div class="card"><div class="card-b"><p class="muted">'
       . ($proj ? 'No projected block at that position.' : 'The mempool is empty, so there is no projected block right now.')
       . ' <a href="' . h($base) . '/mempool">Back to the mempool</a>.</p></div></div>';
    lx_foot($net);
    return;
}

$nb    = $proj[$index];
$nbVs  = (int) $nb['vsize'];
$cap   = min(100, $nbVs / 1000000 * 100);
$avgR  = $nbVs > 0 ? $nb['fee'] / $nbVs : 0.0;
$fr    = function ($r) { return $r >= 10 ? number_format($r, 0) : number_format($r, 1); };
$fcells = array_reverse($nb['cells']);
$txlist = lx_projected_block_txlist($net, $index, 60);
$label = $index === 0 ? 'Next block' : 'Projected block +' . ($index + 1);
$eta   = $nb['partial'] ? 'this block' : '~' . max(1, (int) round(($index + 1) * $spacing / 60)) . ' min';

lx_head($net, ['title' => $label . ' - ' . $net['label'] . ' Explorer']);
?>
<div class="section-h"><?= h($net['label']) ?> &middot; Mempool</div>
<div class="row"><h1><?php if ($index > 0): ?><a href="<?= h($base) ?>/mempool-block/<?= $index - 1 ?>" title="Previous projected block" rel="prev" aria-label="Previous projected block" class="blk-step">&lsaquo;</a> <?php endif; ?><?= h($label) ?><?php if ($index < count($proj) - 1): ?> <a href="<?= h($base) ?>/mempool-block/<?= $index + 1 ?>" title="Next projected block" rel="next" aria-label="Next projected block" class="blk-step">&rsaquo;</a><?php endif; ?></h1> <span class="badge soft"><?= h($eta) ?></span></div>

<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Projection</span> <span class="sub">fee-shaded, high&nbsp;&rarr;&nbsp;low</span></div>
    <div class="card-b" style="display:flex;justify-content:center"><?= lx_goggles_block($nb) ?></div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('activity') ?>Summary</span></div>
    <div class="card-b">
      <div class="stat-grid">
        <div class="stat"><div class="muted sub">Median fee</div><div class="big-num sm" style="color:<?= h(lx_feerate_color($nb['med'])) ?>"><?= h(number_format($nb['med'], 1)) ?> <span class="blk-unit">sat/vB</span></div></div>
        <div class="stat"><div class="muted sub">Fee range</div><div><?= h($fr($nb['min'])) ?> &ndash; <?= h($fr($nb['max'])) ?> <span class="muted sub">sat/vB</span></div><div class="muted sub">avg <?= h(number_format($avgR, 1)) ?></div></div>
        <div class="stat"><div class="muted sub">Transactions</div><div class="big-num sm"><?= commas($nb['count']) ?></div></div>
        <div class="stat"><div class="muted sub">Total fees</div><div><?= lx_amount_el($net, (int) $nb['fee']) ?></div></div>
        <div class="stat"><div class="muted sub">Virtual size</div><div><?= h(lx_size_str($nbVs, 'vB')) ?> <span class="muted sub"><?= h(number_format($cap, $cap >= 99.5 ? 0 : 1)) ?>%</span></div></div>
        <div class="stat"><div class="muted sub">Expected in</div><div><?= h($eta) ?></div></div>
      </div>
      <?php if ($fcells): ?>
      <div style="display:flex;height:10px;border-radius:5px;overflow:hidden;margin-top:14px" role="img" aria-label="Fee-rate distribution, low to high">
        <?php foreach ($fcells as $c): $w = $nbVs > 0 ? (int) $c['vsize'] / $nbVs * 100 : 0; if ($w <= 0) { continue; } ?><span style="width:<?= round($w, 3) ?>%;background:<?= h(lx_feerate_color((float) $c['rate'])) ?>" title="<?= h(number_format((float) $c['rate'], 1)) ?> sat/vB"></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h"><span><?= lx_icon('list') ?>Transactions</span> <span class="sub">top <?= commas(count($txlist)) ?> by fee rate</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Transaction</th><th class="amt">Fee rate</th><th class="amt">Virtual size</th></tr></thead>
      <tbody>
      <?php foreach ($txlist as $t): ?>
        <tr>
          <td class="mono"><a class="addr" href="<?= h(lx_tx_href($net, $t['txid'])) ?>"><?= h(shorten($t['txid'])) ?></a></td>
          <td class="amt"><span class="mono" style="color:<?= h(lx_feerate_color((float) $t['rate'])) ?>"><?= h(number_format((float) $t['rate'], 1)) ?></span> <span class="muted">sat/vB</span></td>
          <td class="amt"><?= h(lx_size_str((int) $t['vsize'], 'vB')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$txlist): ?><tr><td colspan="3"><div class="empty"><?= lx_icon('inbox') ?><span>No transactions in this projection.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-b"><p class="muted sub">A live projection from the current mempool, packed highest-fee-first; the block a miner actually finds may differ. <a href="<?= h($base) ?>/mempool">All projected blocks &rarr;</a></p></div>
</div>
<?php lx_foot($net); ?>
