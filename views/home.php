<?php
/**
 * Network home: chain tip, fee estimates, network + mempool stats, latest
 * blocks and latest transactions. $net in scope.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$tip     = ts_tip_height($net);
$tipHash = ts_tip_hash($net);
$blocks  = ts_recent_blocks($net, null, 10);
$mem     = ts_esplora_mempool($net);
try {
    $fees = ts_fees_recommended($net);
} catch (Throwable $e) {
    $fees = null;
}
try {
    $da = ts_difficulty_adjustment($net);
} catch (Throwable $e) {
    $da = null;
}
$recentTxs = ts_mempool_recent($net);
$supply    = ts_supply_info($net);
$dist      = ts_mining_distribution($net);   // mempool.space-style pool snapshot for the home
$mwebTot   = ts_mweb_enabled($net) ? ts_mweb_peg_totals($net) : null;

// Mempool congestion bars. The raw histogram is 0.1-sat/vB resolution (dozens of
// bands -> a very long card), so aggregate to whole-sat/vB bands and keep the ~10
// busiest by vsize, shown high-rate first. The largest band is the full-width bar.
$agg = [];
foreach ($mem['fee_histogram'] as $band) {
    $r = (int) floor((float) ($band[0] ?? 0));
    $agg[$r] = ($agg[$r] ?? 0) + (int) ($band[1] ?? 0);
}
krsort($agg);
$feeBands = [];
foreach ($agg as $r => $vs) { $feeBands[] = [$r, $vs]; }
if (count($feeBands) > 10) {
    usort($feeBands, function ($a, $b) { return $b[1] <=> $a[1]; });   // busiest first
    $feeBands = array_slice($feeBands, 0, 10);
    usort($feeBands, function ($a, $b) { return $b[0] <=> $a[0]; });   // back to high-rate first
}
$maxVsize = 1;
foreach ($feeBands as $band) {
    if ($band[1] > $maxVsize) { $maxVsize = $band[1]; }
}

// mempool.space-style blocks strip: projected mempool blocks + recent stats.
$strip = ts_recent_block_stats($net, 12);
$proj  = ts_projected_blocks($net);

$tipBlock = $blocks[0] ?? null;
$diff     = $tipBlock ? (float) $tipBlock['difficulty'] : 0.0;
$spacing  = $net['coin'] === 'ltc' ? 150 : 600;             // target seconds/block
// Core's getnetworkhashps (work / actual time over ~120 blocks) is far steadier
// than a single tip block's difficulty on min-difficulty testnets; fall back to
// the difficulty estimate only if the node can't answer.
$hashrate = ts_network_hashrate($net);
if ($hashrate <= 0 && $diff > 0) {
    $hashrate = $diff * 4294967296 / $spacing;              // fallback: difficulty * 2^32 / target spacing
}
$diffStr  = $diff >= 1 ? number_format($diff, 2) : rtrim(rtrim(sprintf('%.6f', $diff), '0'), '.');

ts_head($net, [
    'title' => $net['label'] . ' Explorer',
    'desc'  => $net['label'] . ' block explorer with MWEB support · chain tip '
             . commas($tip) . ' · ' . commas($mem['count']) . ' transactions in the mempool · blocks, transactions, addresses, mining and fees, live from the node.',
    'og_image' => '/og/home.png',
]);

// Live LTC price (best-effort; hides if the source is unavailable/disabled).
$ltcPriceNow = ts_price_now($net);                       // float|null
$ltcChg      = $ltcPriceNow !== null ? ts_price_change($net) : null;
$priceCode   = strtoupper(ts_price_config()['display']);
$priceSym    = ts_price_symbol(ts_price_config()['display']);
$ltcPriceStr = $ltcPriceNow !== null ? $priceSym . number_format($ltcPriceNow, $ltcPriceNow < 1 ? 4 : 2) : null;
?>
<h1 class="sr-only"><?= h($net['label']) ?> block explorer</h1>
<div class="card hero">
  <div class="card-b between">
    <div>
      <div class="hero-eyebrow"><span class="pulse-dot"></span>chain tip &middot; live</div>
      <div class="big-num" id="live-tip" data-tip="<?= h(ts_u($net)) ?>/api/blocks/tip">#<?= commas($tip) ?></div>
      <div class="muted sub"><a class="addr" id="live-tiphash" href="<?= h(ts_block_href($net, $tipHash)) ?>"><?= h($tipHash) ?></a></div>
    </div>
    <div class="stat-mini">
      <div><span class="muted">Mempool</span><b id="live-mempool"><?= commas($mem['count']) ?> tx</b></div>
      <?php if ($fees): ?><div><span class="muted">Next block</span><b class="accent" id="live-fee"><?= commas($fees['fastestFee']) ?> sat/vB</b></div><?php endif; ?>
      <div><span class="muted">Hashrate</span><b><?= h(ts_hashrate($hashrate)) ?></b></div>
      <div><span class="muted">Difficulty</span><b><?= h($diffStr) ?></b></div>
      <?php if ($ltcPriceStr !== null): ?><div><span class="muted">Price</span><b><span id="live-price" data-cur="<?= h($priceCode) ?>" data-sym="<?= h($priceSym) ?>"><?= h($ltcPriceStr) ?></span><?php if ($ltcChg !== null): ?> <span id="live-change" class="<?= $ltcChg >= 0 ? 'pos' : 'neg' ?>"><?= ($ltcChg >= 0 ? '+' : '') . h(number_format($ltcChg, 2)) ?>%</span><?php endif; ?></b></div><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($strip): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('box') ?>Blocks</span> <span class="sub">projected mempool &amp; recent</span></div>
  <div class="card-b nopad">
    <div class="blocks-strip" id="blocks-strip">
      <?php foreach ($proj as $pi => $p): $pc = ts_feerate_color($p['med']); ?>
      <a class="blk proj" href="<?= h(ts_u($net)) ?>/mempool-block/<?= (int) $pi ?>">
        <div class="blk-tag"><?= $pi === 0 ? 'Next block' : 'In ' . ($pi + 1) ?></div>
        <div class="blk-fee" style="color:<?= h($pc) ?>"><?= h(number_format($p['med'], 1)) ?> <span class="blk-unit">sat/vB</span></div>
        <?php if ($p['max'] > $p['min']): ?><div class="blk-range"><?= h(number_format($p['min'], 1)) ?>-<?= h(number_format($p['max'], 1)) ?></div><?php endif; ?>
        <div class="blk-meta"><?= commas($p['count']) ?> tx</div>
        <div class="blk-meta"><?= h(ts_size_str((int) $p['vsize'], 'vB')) ?></div>
      </a>
      <?php endforeach; ?>
      <?php if ($proj): ?><div class="blk-div" aria-hidden="true"></div><?php endif; ?>
      <?php foreach ($strip as $b): $bc = ts_feerate_color($b['med_feerate']); ?>
      <a class="blk conf" style="border-top-color:<?= h($bc) ?>" href="<?= h(ts_block_href($net, $b['hash'])) ?>">
        <div class="blk-h">#<?= commas($b['height']) ?></div>
        <div class="blk-fee" style="color:<?= h($bc) ?>"><?= h(number_format($b['med_feerate'], 1)) ?> <span class="blk-unit">sat/vB</span></div>
        <?php if ($b['max_feerate'] > $b['min_feerate']): ?><div class="blk-range"><?= h(number_format($b['min_feerate'], 1)) ?>-<?= h(number_format($b['max_feerate'], 1)) ?></div><?php endif; ?>
        <div class="blk-meta"><?= commas($b['txs']) ?> tx · <?= h(ts_size_str((int) $b['size'], 'B')) ?></div>
        <div class="blk-age"><?= h(time_ago($b['time'])) ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($fees): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('trending-up') ?>Fee estimates</span> <span class="sub">sat/vB</span></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat" style="border-left:3px solid <?= h(ts_feerate_color((float) $fees['minimumFee'])) ?>"><div class="muted sub">No priority</div><div class="big-num sm" style="color:<?= h(ts_feerate_color((float) $fees['minimumFee'])) ?>"><?= commas($fees['minimumFee']) ?></div></div>
      <div class="stat" style="border-left:3px solid <?= h(ts_feerate_color((float) $fees['economyFee'])) ?>"><div class="muted sub">Economy</div><div class="big-num sm" style="color:<?= h(ts_feerate_color((float) $fees['economyFee'])) ?>"><?= commas($fees['economyFee']) ?></div></div>
      <div class="stat" style="border-left:3px solid <?= h(ts_feerate_color((float) $fees['hourFee'])) ?>"><div class="muted sub">~1 hour</div><div class="big-num sm" style="color:<?= h(ts_feerate_color((float) $fees['hourFee'])) ?>"><?= commas($fees['hourFee']) ?></div></div>
      <div class="stat" style="border-left:3px solid <?= h(ts_feerate_color((float) $fees['halfHourFee'])) ?>"><div class="muted sub">~30 min</div><div class="big-num sm" style="color:<?= h(ts_feerate_color((float) $fees['halfHourFee'])) ?>"><?= commas($fees['halfHourFee']) ?></div></div>
      <a class="stat hot" href="<?= h(ts_u($net)) ?>/mempool-block/0" style="border-left:3px solid <?= h(ts_feerate_color((float) $fees['fastestFee'])) ?>;text-decoration:none;color:inherit" title="View the projected next block"><div class="muted sub">Next block</div><div class="big-num sm" style="color:<?= h(ts_feerate_color((float) $fees['fastestFee'])) ?>"><?= commas($fees['fastestFee']) ?></div></a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><span><?= ts_icon('activity') ?>Network</span> <a class="sub" href="<?= h(ts_u($net)) ?>/mining">Mining &amp; graphs &rarr;</a></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub"><?= ts_icon('activity') ?>Difficulty</div><div class="big-num sm"><?= h($diffStr) ?></div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('zap') ?>Hashrate</div><div class="big-num sm"><?= h(ts_hashrate($hashrate)) ?></div></div>
      <?php if ($da): ?>
      <div class="stat"><div class="muted sub"><?= ts_icon('clock-sm') ?>Avg block time</div><div class="big-num sm"><?= h(number_format($da['timeAvg'] / 60000, 1)) ?> min</div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('target') ?>Next retarget</div><div class="big-num sm"><?= h(number_format($da['progressPercent'], 0)) ?>%</div><div class="muted sub"><?= commas($da['remainingBlocks']) ?> left &middot; ~<?= h(gmdate('M j', (int) ($da['estimatedRetargetDate'] / 1000))) ?></div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('trending-up') ?>Est. difficulty change</div><div class="big-num sm <?= $da['difficultyChange'] >= 0 ? 'pos' : 'neg' ?>"><?= ($da['difficultyChange'] >= 0 ? '+' : '') . h(number_format($da['difficultyChange'], 2)) ?>%</div><div class="muted sub">prev <?= ($da['previousRetarget'] >= 0 ? '+' : '') . h(number_format($da['previousRetarget'], 2)) ?>%</div></div>
      <?php endif; ?>
      <div class="stat"><div class="muted sub"><?= ts_icon('layers') ?>Mempool</div><div class="big-num sm"><?= commas($mem['count']) ?></div><div class="muted sub">transactions</div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('layers') ?>Mempool size</div><div class="big-num sm"><?= h(number_format($mem['vsize'] / 1000000, 2)) ?></div><div class="muted sub">vMB</div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('trending-up') ?>Mempool fees</div><div class="big-num sm"><?= h(ts_coin($mem['total_fee'])) ?></div><div class="muted sub"><?= h($net['unit']) ?></div></div>
      <?php if (($mem['max'] ?? 0) > 0): ?><div class="stat"><div class="muted sub"><?= ts_icon('database') ?>Mempool memory</div><div class="big-num sm"><?= h(ts_size_str((int) $mem['usage'], 'B')) ?></div><div class="muted sub">of <?= h(ts_size_str((int) $mem['max'], 'B')) ?></div></div><?php endif; ?>
    </div>
  </div>
</div>

<?php $twoCol = ($supply && !empty($dist['pools'])); if ($twoCol): ?><div class="txio"><?php endif; ?>
<?php if ($supply): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('database') ?>Supply &amp; issuance</span> <span class="sub"><?= h($net['unit']) ?></span></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub"><?= ts_icon('layers') ?>Mined supply</div><div class="big-num sm"><?= commas(intdiv($supply['mined_sat'], 100000000)) ?></div><div class="muted sub"><?= h(number_format($supply['pct_mined'], 2)) ?>% of <?= commas(intdiv($supply['max_supply_sat'], 100000000)) ?></div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('gift') ?>Block reward</div><div class="big-num sm"><?= h(ts_coin($supply['reward_sat'])) ?></div><div class="muted sub">next <?= h(ts_coin($supply['next_reward_sat'])) ?></div></div>
      <?php if ($ltcPriceNow !== null): ?><div class="stat"><div class="muted sub"><?= ts_icon('trending-up') ?>Market cap</div><div class="big-num sm"><span class="fiat" data-sat="<?= (int) $supply['mined_sat'] ?>" data-compact="1"><?= h($priceSym . ts_num_compact($supply['mined_sat'] / 100000000 * $ltcPriceNow)) ?></span></div><div class="muted sub ccy-label"><?= h($priceCode) ?></div></div><?php endif; ?>
    </div>
    <div class="progress" role="img" aria-label="Progress to next halving"><span style="width:<?= h(number_format(min(100, max(0, $supply['halving_progress'])), 2)) ?>%"></span></div>
    <p class="muted sub">Next halving at block <?= commas($supply['next_halving']) ?>, <?= commas($supply['blocks_to_halving']) ?> blocks left (~<?= h(gmdate('Y-m-d', $supply['halving_eta'])) ?>), reward &rarr; <?= h(ts_coin($supply['next_reward_sat'])) ?> <?= h($net['unit']) ?></p>
  </div>
</div>
<?php endif; ?>
<?php if (!empty($dist['pools'])):
    $pcols  = ['var(--accent)', '#3b82f6', '#f59e0b', '#a855f7', '#64748b'];
    $ptop   = array_slice($dist['pools'], 0, 5);
    $psum   = 0.0; foreach ($ptop as $pp) { $psum += $pp['pct']; }
    $pother = 100 - $psum;
    $lead   = $dist['pools'][0];
?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('cpu') ?>Mining pools</span> <span class="sub">last <?= (int) $dist['window'] ?> blocks</span></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub"><?= ts_icon('zap') ?>Top miner</div><div class="big-num sm"><?= h($lead['name']) ?></div><div class="muted sub"><?= h(number_format($lead['pct'], 1)) ?>% of last <?= (int) $dist['window'] ?></div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('activity') ?>Miners</div><div class="big-num sm"><?= commas(count($dist['pools'])) ?></div><div class="muted sub">seen in window</div></div>
    </div>
    <div style="display:flex;height:10px;border-radius:5px;overflow:hidden;margin:.6rem 0 .1rem" role="img" aria-label="Pool dominance, last <?= (int) $dist['window'] ?> blocks">
      <?php foreach ($ptop as $i => $p): ?><span style="width:<?= h(number_format($p['pct'], 2)) ?>%;background:<?= $pcols[$i] ?>" title="<?= h($p['name']) ?> &middot; <?= h(number_format($p['pct'], 1)) ?>%"></span><?php endforeach; ?>
      <?php if ($pother > 0.5): ?><span style="width:<?= h(number_format($pother, 2)) ?>%;background:var(--border)" title="Others &middot; <?= h(number_format($pother, 1)) ?>%"></span><?php endif; ?>
    </div>
  </div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>#</th><th>Pool / miner</th><th class="amt">Blocks</th><th class="amt">Share</th></tr></thead>
      <tbody>
      <?php foreach ($ptop as $i => $p): ?>
        <tr>
          <td class="mono"><span style="display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:6px;vertical-align:middle;background:<?= $pcols[$i] ?>"></span><?= $i + 1 ?></td>
          <td><a href="<?= h(ts_u($net)) ?>/mining/<?= h(rawurlencode($p['name'])) ?>"><?= h($p['name']) ?></a></td>
          <td class="amt mono"><?= (int) $p['count'] ?></td>
          <td class="amt mono"><?= h(number_format($p['pct'], 1)) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-b"><a class="sub" style="display:inline-flex;align-items:center;gap:7px" href="<?= h(ts_u($net)) ?>/mining"><?= ts_icon('cpu') ?>All miners &amp; hashrate &rarr;</a></div>
</div>
<?php endif; ?>
<?php if ($twoCol): ?></div><?php endif; ?>

<?php if ($feeBands): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('layers') ?>Mempool congestion</span> <a class="sub" href="<?= h(ts_u($net)) ?>/mempool">sat/vB &rarr; vsize</a></div>
  <div class="card-b">
    <?php foreach ($feeBands as $band): ?>
      <?php $rate = $band[0] ?? 0; $vs = $band[1] ?? 0; $pct = round(100 * $vs / $maxVsize); ?>
      <div class="febar-row">
        <span class="febar-label mono"><?= h(number_format((float) $rate, 1)) ?></span>
        <span class="febar"><span style="width:<?= $pct ?>%"></span></span>
        <span class="febar-val muted mono"><?= commas(round($vs / 1000)) ?> kvB</span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($mwebTot): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('mweb') ?>MWEB</span> <a class="sub" href="<?= h(ts_u($net)) ?>/mweb">Privacy &amp; peg history &rarr;</a></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub"><?= ts_icon('lock') ?>Shielded supply</div><div class="big-num sm"><?= h(ts_coin($mwebTot['supply_sat'])) ?></div><div class="muted sub"><?= h($net['unit']) ?> in MWEB</div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('log-in') ?>Pegged in</div><div class="big-num sm"><?= h(ts_coin($mwebTot['pegin_total_sat'])) ?></div><div class="muted sub"><?= commas($mwebTot['pegin_count']) ?> peg-ins</div></div>
      <div class="stat"><div class="muted sub"><?= ts_icon('log-out') ?>Pegged out</div><div class="big-num sm"><?= h(ts_coin($mwebTot['pegout_total_sat'])) ?></div><div class="muted sub"><?= commas($mwebTot['pegout_count']) ?> peg-outs</div></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= ts_icon('box') ?>Latest blocks</span> <a class="sub" href="<?= h(ts_u($net)) ?>/blocks">View all →</a></div>
    <div class="card-b nopad table-wrap">
      <table>
        <thead><tr><th>Height</th><th>Hash</th><th class="amt">Txs</th><th class="amt">Age</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($blocks, 0, 10) as $b): ?>
          <tr>
            <td><a href="<?= h(ts_block_href($net, $b['id'])) ?>"><?= commas($b['height']) ?></a></td>
            <td class="mono"><a class="addr" href="<?= h(ts_block_href($net, $b['id'])) ?>"><?= h(shorten($b['id'], 8, 6)) ?></a></td>
            <td class="amt"><?= commas($b['tx_count']) ?></td>
            <td class="amt age" data-sort="<?= (int) $b['timestamp'] ?>"><?= h(time_ago($b['timestamp'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span><?= ts_icon('repeat') ?>Latest transactions</span> <a class="sub" href="<?= h(ts_u($net)) ?>/mempool">Mempool →</a></div>
    <div class="card-b nopad table-wrap">
      <table>
        <thead><tr><th>Txid</th><th class="amt">Amount</th><th class="amt">Fee</th></tr></thead>
        <tbody>
        <?php foreach ($recentTxs as $t): ?>
          <tr>
            <td class="mono"><a class="addr" href="<?= h(ts_tx_href($net, $t['txid'])) ?>"><?= h(shorten($t['txid'], 8, 6)) ?></a></td>
            <td class="amt"><?= h(ts_coin($t['value'])) ?></td>
            <td class="amt"><?= commas($t['vsize'] > 0 ? round($t['fee'] / $t['vsize']) : 0) ?> <span class="muted">sat/vB</span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recentTxs): ?><tr><td colspan="3"><div class="empty"><?= ts_icon('inbox') ?><span>Mempool is empty.</span></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php ts_foot($net); ?>
