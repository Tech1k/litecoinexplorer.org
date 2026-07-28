<?php
/**
 * Block detail + paginated transactions.
 * $net in scope; $GLOBALS['block_hash'], $GLOBALS['block_page'].
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$hash = $GLOBALS['block_hash'];
$page = (int) ($GLOBALS['block_page'] ?? 0);
$blk = lx_esplora_block($net, $hash);
if ($blk === null) {
    http_response_code(404);
    $GLOBALS['search_query'] = $hash;
    require __DIR__ . '/notfound.php';
    return;
}
$status = lx_block_status($net, $hash);
$pages = max(1, (int) ceil($blk['tx_count'] / 25));
$page = max(0, min($page, $pages - 1));
$txs = lx_block_txs($net, $hash, $page * 25) ?? [];
$base = lx_u($net);
$mwebEnabled = lx_mweb_enabled($net);   // tag MWEB txs inline in the tx list
$bstats = lx_block_stats($net, $hash, $blk['height']);   // fee/reward economics (getblockstats)

// Edge/browser caching: a buried in-chain block barely changes (only its baked
// confirmation count drifts), so let the CDN absorb repeats for a few minutes;
// near-tip or orphaned blocks stay near-live in case of a reorg. Finite TTL
// only, never "immutable" (the conf count is not refreshed client-side).
if (!headers_sent()) {
    $inChain = ($status !== null && !empty($status['in_best_chain']));
    if ($inChain && (lx_tip_height($net) - $blk['height']) >= 10) {
        header('Cache-Control: public, max-age=30, s-maxage=300');
    } else {
        header('Cache-Control: public, max-age=2');
    }
}

// Compute tip / confirmations / pool BEFORE lx_head so a backend failure throws a
// clean 503 rather than appending a second <!DOCTYPE> onto a half-rendered page.
$tip = lx_tip_height($net);
$conf = $tip - $blk['height'] + 1;
$confBadge = ($status && empty($status['in_best_chain']))
    ? '<span class="badge bad">Orphaned</span>'
    : '<span class="badge ok">' . commas(max(1, $conf)) . '</span>';
$pool = lx_block_pool($net, $hash);
// Raw 80-byte header hex (immutable; cached long). Empty if the node errors.
$hdrHex = cache_remember('blkhdrhex:' . $net['slug'] . ':' . $hash, 2592000, function () use ($net, $hash) {
    $r = lx_rpc_soft($net, 'getblockheader', [$hash, false]);
    return is_string($r) ? $r : '';
});

$ogFees = ($bstats !== null && $bstats['total_fee'] > 0) ? ' · ' . lx_coin((int) $bstats['total_fee']) . ' ' . $net['unit'] . ' in fees' : '';
lx_head($net, [
    'title' => 'Block ' . commas($blk['height']) . ' - ' . $net['label'],
    'desc'  => $net['label'] . ' block ' . commas($blk['height']) . ' · ' . commas($blk['tx_count']) . ' transactions' . $ogFees . ' · mined ' . time_ago($blk['timestamp']) . '.',
    'og_image' => '/og/block/' . (int) $blk['height'] . '.png',
]);
$bh = (int) $blk['height'];
?>
<div class="section-h"><?= h($net['label']) ?> &middot; Block</div>
<div class="row"><h1>Block <?php if ($bh > 0): ?><a class="blk-step" href="<?= h(lx_u($net)) ?>/block-height/<?= $bh - 1 ?>" title="Previous block" rel="prev" aria-label="Previous block">&lsaquo;</a> <?php endif; ?><?= commas($bh) ?><?php if ($bh < $tip): ?> <a class="blk-step" href="<?= h(lx_u($net)) ?>/block-height/<?= $bh + 1 ?>" title="Next block" rel="next" aria-label="Next block">&rsaquo;</a><?php else: ?> <a class="blk-step" href="<?= h(lx_u($net)) ?>/mempool-block/0" title="Projected next block" rel="next" aria-label="Projected next block">&rsaquo;</a><?php endif; ?></h1><?= $confBadge ?></div>
<div class="card">
  <div class="card-b">
    <table class="kv">
      <tr><th>Hash</th><td class="mono break"><?= h($blk['id']) ?> <button class="btn ghost sm" type="button" data-copy="<?= h($blk['id']) ?>" aria-label="Copy block hash">Copy</button></td></tr>
      <tr><th>Confirmations</th><td><?= $confBadge ?></td></tr>
      <tr><th>Timestamp</th><td><?= h(gmdate('Y-m-d H:i:s', $blk['timestamp'])) ?> UTC <span class="muted">(<?= h(time_ago($blk['timestamp'])) ?>)</span></td></tr>
      <tr><th>Mined by</th><td><?php if ($pool['pool'] !== null): ?><a class="badge soft" href="<?= h(lx_u($net)) ?>/mining"><?= h($pool['label']) ?></a><?php elseif ($pool['label'] !== 'Unknown'): ?><span class="mono break" title="coinbase tag"><?= h($pool['label']) ?></span> <a class="sub" href="<?= h(lx_u($net)) ?>/mining">distribution &rarr;</a><?php else: ?><span class="muted">Unknown</span> <a class="sub" href="<?= h(lx_u($net)) ?>/mining">distribution &rarr;</a><?php endif; ?></td></tr>
      <tr><th>Transactions</th><td><?= commas($blk['tx_count']) ?></td></tr>
      <tr><th>Size</th><td><?= commas($blk['size']) ?> bytes</td></tr>
      <tr><th>Weight</th><td><?= commas($blk['weight']) ?> WU</td></tr>
      <tr><th>Merkle root</th><td class="mono break"><?= h($blk['merkle_root']) ?></td></tr>
      <tr><th>Difficulty</th><td><?php $d = (float) $blk['difficulty']; echo h($d >= 1 ? number_format($d, 3) : rtrim(rtrim(sprintf('%.8f', $d), '0'), '.')); ?></td></tr>
      <tr><th>Nonce</th><td class="mono"><?= commas($blk['nonce']) ?></td></tr>
      <tr><th>Bits</th><td class="mono">0x<?= h(dechex($blk['bits'])) ?></td></tr>
      <tr><th>Version</th><td class="mono">0x<?= h(sprintf('%08x', (int) $blk['version'])) ?></td></tr>
      <?php if (is_string($hdrHex) && $hdrHex !== ''): ?>
      <tr><th>Header hex</th><td><details><summary style="cursor:pointer;color:var(--muted)">80-byte header</summary><div class="mono break" style="margin-top:6px"><?= h($hdrHex) ?> <button class="btn ghost sm" type="button" data-copy="<?= h($hdrHex) ?>" aria-label="Copy block header hex">Copy</button></div></details></td></tr>
      <?php endif; ?>
      <?php if (!empty($blk['previousblockhash'])): ?>
      <tr><th>Previous</th><td class="mono break"><a class="addr" href="<?= h(lx_block_href($net, $blk['previousblockhash'])) ?>"><?= h($blk['previousblockhash']) ?></a></td></tr>
      <?php endif; ?>
      <?php if ($status && !empty($status['next_best'])): ?>
      <tr><th>Next</th><td class="mono break"><a class="addr" href="<?= h(lx_block_href($net, $status['next_best'])) ?>"><?= h($status['next_best']) ?></a></td></tr>
      <?php endif; ?>
    </table>
    <div class="row mt-3">
      <a class="btn ghost sm" href="<?= h($base) ?>/block-height/<?= $blk['height'] ?>">Link by height</a>
      <?php if (lx_extern_links()): ?><a class="btn ghost sm ext" href="<?= h($net['extern_block'] . $blk['id']) ?>" target="_blank" rel="noopener">View on <?= h($net['extern_name']) ?></a><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($bstats !== null && ($bstats['subsidy'] > 0 || $bstats['total_fee'] > 0)): $reward = $bstats['subsidy'] + $bstats['total_fee']; ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('trending-up') ?>Fees &amp; reward</span> <span class="sub"><?= commas($bstats['txs']) ?> tx</span></div>
  <div class="card-b nopad">
    <table class="kv">
      <tr><th>Block reward</th><td><b><?= lx_amount_el($net, (int) $reward, 'ext') ?></b> <span class="denom-unit"><?= h($net['unit']) ?></span> <span class="muted">(subsidy + fees)</span></td></tr>
      <tr><th>Subsidy</th><td><?= lx_amount_el($net, (int) $bstats['subsidy']) ?></td></tr>
      <tr><th>Total fees</th><td><?= lx_amount_el($net, (int) $bstats['total_fee']) ?><?php if ($reward > 0): ?> <span class="muted">(<?= h(number_format($bstats['total_fee'] / $reward * 100, 1)) ?>% of reward)</span><?php endif; ?></td></tr>
      <?php if ($bstats['med_feerate'] > 0 || $bstats['max_feerate'] > 0): ?>
      <tr><th>Fee rate</th><td><span class="mono" style="color:<?= h(lx_feerate_color((float) $bstats['med_feerate'])) ?>"><?= h(number_format($bstats['med_feerate'], 2)) ?></span> sat/vB median <span class="muted">· <?= h(number_format($bstats['min_feerate'], 2)) ?>&ndash;<?= h(number_format($bstats['max_feerate'], 2)) ?> range</span></td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
// Template audit: how the mined block compared to the fee-ordered next-block
// prediction we snapshotted while it was pending (present only when the audit
// cron captured a snapshot in that window).
$audit = lx_audit_get($net, (int) $blk['height']);
if ($audit !== null):
    $pct  = $audit['match_pct'];
    $pcls = $pct >= 90 ? 'ok' : ($pct >= 70 ? 'soft' : 'bad');
    $health = $audit['health_pct'];
    $hcls = $health >= 99 ? 'ok' : ($health >= 90 ? 'soft' : ($health >= 75 ? 'warn' : 'bad'));
    $lead = ($audit['block_time'] > 0 && $audit['snap_ts'] > 0) ? $audit['block_time'] - $audit['snap_ts'] : null;
    $showMiss = array_slice($audit['missing_txids'], 0, 18);
    $showAdd  = array_slice($audit['added_txids'], 0, 18);
?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('target') ?>Block health &amp; template audit</span> <span class="sub">projected vs mined</span></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub">Block health</div><div class="big-num sm"><span class="badge <?= $hcls ?>"><?= h(number_format($health, $health >= 99.95 ? 0 : 1)) ?>%</span></div><div class="muted sub">n / (n + excluded)</div></div>
      <div class="stat"><div class="muted sub">Match</div><div class="big-num sm"><span class="badge <?= $pcls ?>"><?= h(number_format($pct, 0)) ?>%</span></div><div class="muted sub"><?= commas($audit['matched']) ?> of <?= commas($audit['mined']) ?> mined</div></div>
      <div class="stat"><div class="muted sub">Predicted</div><div class="big-num sm"><?= commas($audit['expected']) ?></div><div class="muted sub">for the next block</div></div>
      <div class="stat"><div class="muted sub">Missing</div><div class="big-num sm neg"><?= commas($audit['missing']) ?></div><div class="muted sub">predicted, not mined</div></div>
      <div class="stat"><div class="muted sub">Added</div><div class="big-num sm" style="color:var(--accent-2)"><?= commas($audit['added']) ?></div><div class="muted sub">mined, not predicted</div></div>
    </div>
    <?php if ($showMiss): ?>
    <div class="mt-3"><div class="muted sub" style="display:inline-flex;align-items:center;gap:6px"><?= lx_icon('log-out') ?>Missing <span class="mono">(<?= commas($audit['missing']) ?>)</span></div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px"><?php foreach ($showMiss as $t): ?><a class="badge soft mono" href="<?= h(lx_tx_href($net, $t)) ?>"><?= h(shorten($t)) ?></a><?php endforeach; ?><?php if ($audit['missing'] > count($showMiss)): ?><span class="muted sub">+<?= commas($audit['missing'] - count($showMiss)) ?> more</span><?php endif; ?></div>
    </div>
    <?php endif; ?>
    <?php if ($showAdd): ?>
    <div class="mt-3"><div class="muted sub" style="display:inline-flex;align-items:center;gap:6px"><?= lx_icon('log-in') ?>Added <span class="mono">(<?= commas($audit['added']) ?>)</span></div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px"><?php foreach ($showAdd as $t): ?><a class="badge soft mono" href="<?= h(lx_tx_href($net, $t)) ?>"><?= h(shorten($t)) ?></a><?php endforeach; ?><?php if ($audit['added'] > count($showAdd)): ?><span class="muted sub">+<?= commas($audit['added'] - count($showAdd)) ?> more</span><?php endif; ?></div>
    </div>
    <?php endif; ?>
    <p class="pnote"><?= lx_icon('info') ?><span><b>Block health</b> = n / (n + excluded), the share of the high-fee transactions we predicted that the miner actually included (100% = nothing predicted was left out), measured against the fee-ordered ~1 vMB template we snapshotted<?php if ($lead !== null && $lead > 0): ?> ~<?= h(lx_dur_short($lead)) ?> before this block<?php endif; ?>.</span></p>
    <details class="audit-more">
      <summary>How match, missing &amp; added are counted</summary>
      <div><b>Match</b> is the transactions in both our template and the block. <b>Missing</b> is high-fee transactions we predicted but the miner excluded (fee-bumped, evicted, or deprioritised). <b>Added</b> is transactions mined that weren&rsquo;t in our prediction. They arrived after the snapshot or were included out-of-band. Because our template is a single snapshot this is a <i>strict</i> health: it reads marginally conservative, since it doesn&rsquo;t retroactively forgive transactions that landed in the final seconds before the block or were low-fee.</div>
    </details>
  </div>
</div>
<?php endif; ?>

<?php if (lx_mweb_enabled($net) && ($mweb = lx_mweb_block($net, $hash)) !== null): $mwebK = lx_mweb_block_kernels($net, $hash); ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('mweb') ?>MWEB</span> <span class="sub">MimbleWimble Extension Block</span></div>
  <div class="card-b">
    <table class="kv">
      <?php if ($mwebK !== null): ?><tr><th>Confidential txs</th><td><?= commas($mwebK) ?> <span class="muted sub">MWEB kernel<?= $mwebK === 1 ? '' : 's' ?> in this block</span></td></tr><?php endif; ?>
      <tr><th>MWEB supply</th><td><?= lx_amount_el($net, (int) $mweb['supply_sat']) ?></td></tr>
      <tr><th>HogEx tx</th><td class="mono break"><a class="addr" href="<?= h(lx_tx_href($net, $mweb['hogex_txid'])) ?>"><?= h($mweb['hogex_txid']) ?></a></td></tr>
      <tr><th>Peg-ins</th><td><?= commas($mweb['pegin_count']) ?><?php if ($mweb['pegin_count'] > 0): ?> <span class="muted">·</span> <?= lx_amount_el($net, (int) $mweb['pegin_total_sat']) ?><?php endif; ?></td></tr>
      <tr><th>Peg-outs</th><td><?= commas($mweb['pegout_count']) ?><?php if ($mweb['pegout_count'] > 0): ?> <span class="muted">·</span> <?= lx_amount_el($net, (int) $mweb['pegout_total_sat']) ?><?php endif; ?></td></tr>
    </table>
    <?php if ($mweb['pegouts']): ?>
    <div class="table-wrap mt-3"><table>
      <thead><tr><th>Peg-out destination</th><th class="amt">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($mweb['pegouts'] as $po): ?>
        <tr>
          <td class="mono break"><?php if (!empty($po['address'])): ?><a class="addr" href="<?= h(lx_addr_href($net, $po['address'])) ?>"><?= h($po['address']) ?></a><?php else: ?><span class="muted">unparsed</span><?php endif; ?></td>
          <td class="amt"><?= lx_amount_el($net, (int) $po['value_sat']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($mweb['pegins']): ?>
    <div class="table-wrap mt-3"><table>
      <thead><tr><th>Peg-in source output</th><th class="amt">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($mweb['pegins'] as $pi): ?>
        <tr>
          <td class="mono break"><a class="addr" href="<?= h(lx_tx_href($net, $pi['txid'])) ?>#out-<?= (int) $pi['vout'] ?>"><?= h(shorten($pi['txid'])) ?>:<?= (int) $pi['vout'] ?></a></td>
          <td class="amt"><?= lx_amount_el($net, (int) $pi['value_sat']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php
    // MWEBscan analysis overlay for this block (round-trip linkage + privacy scores),
    // keyed by txid:vout. Fetched only when the block has peg activity; long-cached
    // once buried. Degrades silently to the boundary tables above if dormant/down.
    $mwBlk = (lx_mwebscan_enabled($net) && ((int) $mweb['pegin_count'] > 0 || (int) $mweb['pegout_count'] > 0))
        ? lx_mwebscan_api($net, 'block', ['q' => $hash], ($tip - $bh) > 100 ? 86400 : 600) : null;
    $mwPO = (is_array($mwBlk) && is_array($mwBlk['pegouts'] ?? null)) ? $mwBlk['pegouts'] : [];
    $mwPI = (is_array($mwBlk) && is_array($mwBlk['pegins'] ?? null)) ? $mwBlk['pegins'] : [];
    if ($mwPO || $mwPI):
    ?>
    <div class="mt-3" style="border-top:1px solid var(--border);padding-top:14px">
      <div class="muted sub"><?= lx_icon('shield') ?>MWEB analysis <span class="sub">MWEBscan<?php $frB = lx_mwebscan_freshness($mwBlk); if ($frB !== ''): ?> &middot; <?= h($frB) ?><?php endif; ?></span></div>
      <?php if ($mwPO): ?>
      <div class="table-wrap mt-2"><table>
        <thead><tr><th>Peg-out</th><th class="amt">Amount</th><th>Linked peg-in</th><th class="amt">Conf.</th><th class="amt">Risk</th></tr></thead>
        <tbody>
        <?php foreach ($mwPO as $po): $lk = $po['linked_pegin'] ?? null; $cf = $po['confidence'] ?? null; ?>
          <tr>
            <td class="mono break"><?php if (!empty($po['address'])): ?><a class="addr" href="<?= h($base) ?>/address/<?= h(rawurlencode((string) $po['address'])) ?>"><?= h(shorten((string) $po['address'], 12, 8)) ?></a><?php else: ?><span class="muted">&ndash;</span><?php endif; ?><?php if (!empty($po['entity'])): ?> <span class="badge soft"><?= h((string) $po['entity']) ?></span><?php endif; ?></td>
            <td class="amt"><?= h(rtrim(rtrim(number_format((float) ($po['amount_ltc'] ?? 0), 8), '0'), '.')) ?></td>
            <td class="mono"><?php if ($lk): ?><a class="addr" href="<?= h($base) ?>/tx/<?= h((string) $lk) ?>"><?= h(shorten((string) $lk)) ?></a><?php else: ?><span class="muted">unlinked</span><?php endif; ?></td>
            <td class="amt"><?php if ($cf !== null): $c = (float) $cf; $cc = $c >= 0.9 ? 'bad' : ($c >= 0.7 ? 'warn' : 'soft'); ?><span class="badge <?= $cc ?>"><?= h(number_format($c * 100, 0)) ?>%</span><?php else: ?><span class="muted">&ndash;</span><?php endif; ?></td>
            <td class="amt mono"><?= (isset($po['aml_risk']) && $po['aml_risk'] !== null) ? (int) $po['aml_risk'] : '&ndash;' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <?php if ($mwPI): ?>
      <div class="table-wrap mt-2"><table>
        <thead><tr><th>Peg-in source</th><th class="amt">Amount</th><th class="amt">Privacy</th><th class="amt">Anon set</th></tr></thead>
        <tbody>
        <?php foreach ($mwPI as $pi): $ps = (int) ($pi['privacy_score'] ?? 0); $psc = $ps >= 80 ? 'ok' : ($ps >= 50 ? 'warn' : 'bad'); ?>
          <tr>
            <td class="mono break"><?php if (!empty($pi['source_address'])): ?><a class="addr" href="<?= h($base) ?>/address/<?= h(rawurlencode((string) $pi['source_address'])) ?>"><?= h(shorten((string) $pi['source_address'], 12, 8)) ?></a><?php else: ?><span class="muted">&ndash;</span><?php endif; ?><?php if (!empty($pi['source_entity'])): ?> <span class="badge soft"><?= h((string) $pi['source_entity']) ?></span><?php endif; ?></td>
            <td class="amt"><?= h(rtrim(rtrim(number_format((float) ($pi['amount_ltc'] ?? 0), 8), '0'), '.')) ?></td>
            <td class="amt"><span class="badge <?= $psc ?>"><?= $ps ?></span></td>
            <td class="amt mono"><?= commas((int) ($pi['anonymity_set'] ?? 0)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <p class="pnote"><?= lx_icon('eye-off') ?><span>Round-trip links &amp; privacy scores are inferences from public-chain data, not proof. Data from <a class="ext" href="<?= h(lx_mwebscan_site($net)) ?>" target="_blank" rel="noopener">MWEBscan</a>.</span></p>
    </div>
    <?php endif; ?>
    <?php $mwebscanUrl = lx_mwebscan_block_url($net, $mweb['hash'], (int) $mweb['height']); if ($mwebscanUrl !== null): ?>
    <div class="row mt-3"><a class="btn ghost sm ext" href="<?= h($mwebscanUrl) ?>" target="_blank" rel="noopener"><?= lx_icon('mweb') ?>View on MWEBscan</a></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
// Fee-shaded treemap of this page's transactions (sized by vsize), a visual companion
// to the table below. Scoped to the loaded page - the whole block can be thousands of
// txs and fetching them all per view is too costly - so the label names the page.
if (count($txs) >= 2):
    $tmItems = [];
    foreach ($txs as $t) {
        $wt = (int) ($t['weight'] ?? 0);
        $vs = $wt > 0 ? $wt / 4 : (float) ($t['size'] ?? 0);
        if ($vs <= 0) { continue; }
        $fee = (int) ($t['fee'] ?? 0);
        $tmItems[] = ['v' => $vs, 'rate' => $vs > 0 ? $fee / $vs : 0.0, 'txid' => $t['txid']];
    }
?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('grid') ?>Transaction map</span> <span class="sub">page <?= $page + 1 ?> / <?= max(1, $pages) ?> · sized by vsize, shaded by fee rate</span></div>
  <div class="card-b"><?= lx_block_treemap($tmItems, 'Block ' . commas($bh) . ' transactions, page ' . ($page + 1)) ?>
    <div class="tm-legend"><span class="muted sub">Fee rate</span><?php foreach ([1, 4, 10, 25, 50, 100] as $r): ?><span class="tm-key" style="background:<?= h(lx_feerate_color((float) $r)) ?>"><?= $r >= 100 ? '100+' : $r ?></span><?php endforeach; ?><span class="muted sub">sat/vB · hover a cell for its txid</span></div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><span><?= lx_icon('repeat') ?>Transactions</span> <span class="sub">page <?= $page + 1 ?> / <?= max(1, $pages) ?></span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Txid</th><th class="amt">Value out</th><th class="amt">Fee</th><th class="amt">Size</th></tr></thead>
      <tbody>
      <?php foreach ($txs as $tx): ?>
        <?php $out = 0; foreach ($tx['vout'] as $o) { $out += ($o['value'] ?? 0); } ?>
        <tr>
          <td class="mono"><a class="addr" href="<?= h(lx_tx_href($net, $tx['txid'])) ?>"><?= h(shorten($tx['txid'])) ?></a>
            <?= !empty($tx['vin'][0]['is_coinbase']) ? '<span class="badge">coinbase</span>' : '' ?>
            <?php if ($mwebEnabled) { $mi = lx_mweb_tx_info($tx); if ($mi) { echo $mi['is_hogex'] ? '<span class="badge mweb">HogEx</span>' : ($mi['pegin_total_sat'] > 0 ? '<span class="badge mweb">Peg-in</span>' : ''); } } ?></td>
          <td class="amt"><?= lx_amount_el($net, (int) $out, false) ?></td>
          <td class="amt"><?= !empty($tx['fee']) ? lx_amount_el($net, (int) $tx['fee'], false) : '-' ?></td>
          <td class="amt"><?= commas($tx['size'] ?? 0) ?> B</td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$txs): ?><tr><td colspan="4"><div class="empty"><?= lx_icon('inbox') ?><span><?= (int) $blk['height'] === 0 ? 'The genesis coinbase cannot be retrieved over RPC (a consensus rule), so it is not listed here.' : 'No transactions on this page.' ?></span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($pages > 1): ?>
<div class="pagination">
  <?php if ($page > 0): ?><a class="btn ghost sm" href="<?= h($base) ?>/block/<?= $hash ?>/<?= $page - 1 ?>">← Prev</a><?php endif; ?>
  <span class="muted sub">page <?= $page + 1 ?> of <?= $pages ?></span>
  <?php if ($page + 1 < $pages): ?><a class="btn ghost sm" href="<?= h($base) ?>/block/<?= $hash ?>/<?= $page + 1 ?>">Next →</a><?php endif; ?>
</div>
<?php endif; ?>
<?php lx_foot($net); ?>
