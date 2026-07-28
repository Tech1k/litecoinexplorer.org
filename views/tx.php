<?php
/**
 * Transaction detail: inputs, outputs, fee, status, RBF/CPFP, spent-status.
 * $net in scope; $GLOBALS['txid']. SPDX-License-Identifier: AGPL-3.0-or-later
 */
$txid = $GLOBALS['txid'];
$tx = lx_find_tx($net, $txid);
if ($tx === null) {
    http_response_code(404);
    $GLOBALS['search_query'] = $txid;
    require __DIR__ . '/notfound.php';
    return;
}

$isCoinbase = $tx['vin'][0]['is_coinbase'] ?? false;
$confirmed  = !empty($tx['status']['confirmed']);

$inSum = 0;
$inKnown = true;
foreach ($tx['vin'] as $vi) {
    if (!empty($vi['is_coinbase'])) { continue; }
    if (($vi['prevout'] ?? null) === null) { $inKnown = false; continue; }
    $inSum += $vi['prevout']['value'];
}
$outSum = 0;
foreach ($tx['vout'] as $vo) { $outSum += ($vo['value'] ?? 0); }
$vsize = (int) ceil(($tx['weight'] ?? 0) / 4);
$feeRate = $vsize > 0 ? ($tx['fee'] ?? 0) / $vsize : 0;
$txPrice = lx_price_now($net);            // LTC price for fiat display (null if unavailable)
// Historical fiat: the LTC price at the block's time, so a confirmed tx can show value "then".
$histPrice = ($confirmed && !empty($tx['status']['block_time']) && function_exists('lx_price_at'))
    ? lx_price_at($net, (int) $tx['status']['block_time']) : null;

// RBF + SegWit/Taproot feature flags, from the inputs the tx actually spends.
// The coinbase input is skipped: its witness is only the commitment reserved
// value (present in every SegWit-active block), not a real segwit/taproot spend,
// so flagging it would be misleading - mempool.space doesn't flag it either.
$rbf = false;
$hasWitness = false;
$hasTaproot = false;
foreach ($tx['vin'] as $vi) {
    if (!empty($vi['is_coinbase'])) { continue; }
    if ((int) ($vi['sequence'] ?? 0xffffffff) < 0xfffffffe) { $rbf = true; }
    if (!empty($vi['witness'])) { $hasWitness = true; }
    if (($vi['prevout']['scriptpubkey_type'] ?? '') === 'v1_p2tr') { $hasTaproot = true; }
}
// Mempool ancestry (CPFP / package fee-rate) for unconfirmed txs.
$mentry = $confirmed ? null : lx_mempool_entry($net, $txid);
$pkgRate = null;
if ($mentry && isset($mentry['fees']['ancestor']) && ($mentry['ancestorsize'] ?? 0) > 0) {
    $pkgRate = coin_to_sat($mentry['fees']['ancestor']) / (int) $mentry['ancestorsize'];
}
// Output spent-status + spender txid: badges need the flag, the value-flow diagram links each
// spent output to the tx that spent it (forward navigation), so resolve the spender here.
$outspends = lx_tx_outspends($net, $tx, true);
// MWEB role (LTC only): peg-in tx, or the block's HogEx integration tx.
$mwebInfo = lx_mweb_enabled($net) ? lx_mweb_tx_info($tx) : null;
// RBF replacement chain + CPFP package (unconfirmed only).
$rbfChain = $confirmed ? [] : lx_rbf_chain($net, $tx);
$cpfp     = $confirmed ? null : lx_cpfp_package($net, $txid);
// Explicit position in the projected mempool blocks (unconfirmed, not replaced).
$position = (!$confirmed && !$rbfChain) ? lx_mempool_position($net, $feeRate) : null;

// Edge/browser caching. A confirmed, past-reorg-depth tx is effectively static;
// give the CDN a short window to absorb repeats/crawlers. The window stays
// modest (not hours) because the page also renders per-output spent-status,
// which is mutable; unconfirmed/shallow txs stay near-live for reorg safety.
if (!headers_sent()) {
    $depth = $confirmed && isset($tx['status']['block_height'])
        ? lx_tip_height($net) - (int) $tx['status']['block_height'] : -1;
    if ($depth >= 6) {
        // Browser-only short cache; NO s-maxage, because the page renders per-output
        // spent-status which is mutable (an output can be spent at any time) and the
        // shared CDN cache must not pin a stale spent/unspent badge for minutes.
        header('Cache-Control: public, max-age=15');
    } else {
        header('Cache-Control: public, max-age=2');
    }
}

$ogStatus = $confirmed
    ? ('confirmed' . (isset($tx['status']['block_height']) ? ' in block ' . commas((int) $tx['status']['block_height']) : ''))
    : 'in the mempool';
lx_head($net, [
    'title' => 'Transaction ' . shorten($txid) . ' - ' . $net['label'],
    'desc'  => $net['label'] . ' transaction · ' . lx_amount($net, (int) $outSum)
             . ($isCoinbase ? ' (coinbase)' : ' · fee ' . lx_coin((int) ($tx['fee'] ?? 0)) . ' ' . $net['unit'])
             . ' · ' . $ogStatus . '.',
    'og_image' => '/og/tx/' . $txid . '.png',
]);
?>
<h1>Transaction</h1>
<div class="card">
  <div class="card-b">
    <div class="break mono addr-lg"><?= h($txid) ?></div>
    <div class="row mt-2"><button class="btn ghost sm" type="button" data-copy="<?= h($txid) ?>" aria-label="Copy transaction ID">Copy txid</button></div>
    <div class="row mt-3" id="tx-status" data-poll="<?= h(lx_u($net)) ?>/api/tx/<?= h($txid) ?>/status">
      <span class="pulse-dot<?= $rbfChain ? ' off' : '' ?>"></span>
      <?= lx_status_badge($net, $tx['status']) ?>
      <?php if ($confirmed): ?>
        <span class="muted">in block <a href="<?= h(lx_block_href($net, $tx['status']['block_hash'])) ?>"><?= commas($tx['status']['block_height']) ?></a>
        · <?= h(gmdate('Y-m-d H:i', (int) $tx['status']['block_time'])) ?> UTC <span class="faint">(<?= h(time_ago((int) $tx['status']['block_time'])) ?>)</span></span>
      <?php else: ?>
        <?php if ($rbf): ?><span class="badge warn" title="Signals BIP125 replace-by-fee">RBF</span><?php endif; ?>
        <?php if ($rbfChain): ?>
          <span class="badge bad" title="This transaction was replaced (RBF)">Replaced</span>
        <?php else: ?>
          <span class="badge soft" title="Estimated confirmation, from the projected mempool blocks"><?= h(lx_tx_eta($net, $feeRate)) ?></span>
          <?php if ($position): ?><span class="muted" title="Position in the projected mempool blocks">block <?= (int) $position['block'] ?> of <?= (int) $position['blocks_total'] ?><?php if ($position['vsize_ahead'] > 0): ?> · ~<?= h(lx_size_str((int) $position['vsize_ahead'], 'vB')) ?> ahead<?php endif; ?></span><?php endif; ?>
        <?php endif; ?>
        <?php if ($mentry && !empty($mentry['time'])): ?><span class="muted">first seen <?= h(time_ago((int) $mentry['time'])) ?></span><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if ($rbfChain): ?>
    <div class="note bad mt-3">
      <b>Replaced by fee (RBF)</b>. This transaction was superseded in the mempool:
      <div class="mono mt-2" style="line-height:1.9">
        <span class="muted">this tx</span> <?= h(number_format($feeRate, 1)) ?> sat/vB
        <?php foreach ($rbfChain as $c): ?>
          &rarr; <a class="addr" href="<?= h(lx_tx_href($net, $c['txid'])) ?>"><?= h(shorten($c['txid'])) ?></a><?php if ($c['feerate'] !== null): ?> <span class="muted">(<?= h(number_format($c['feerate'], 1)) ?> sat/vB<?= $c['confirmed'] ? ', confirmed' : '' ?>)</span><?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($cpfp): ?>
    <div class="note mt-3">
      <b>CPFP package</b>: <?= commas($cpfp['ancestors']) ?> ancestor(s) &amp; <?= commas($cpfp['descendants']) ?> descendant(s) in the mempool; the effective package fee-rate drives confirmation.
      <?php if ($cpfp['depends']): ?>
      <div class="sub muted mt-2">Spends unconfirmed:</div>
      <?php foreach ($cpfp['depends'] as $d): ?>
      <div class="mono sub"><a class="addr" href="<?= h(lx_tx_href($net, $d['txid'])) ?>"><?= h(shorten($d['txid'])) ?></a><?php if ($d['feerate'] !== null): ?> <span class="muted">(<?= h(number_format($d['feerate'], 1)) ?> sat/vB)</span><?php endif; ?></div>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($cpfp['spentby']): ?>
      <div class="sub muted mt-2">Spent by unconfirmed:</div>
      <?php foreach ($cpfp['spentby'] as $d): ?>
      <div class="mono sub"><a class="addr" href="<?= h(lx_tx_href($net, $d['txid'])) ?>"><?= h(shorten($d['txid'])) ?></a><?php if ($d['feerate'] !== null): ?> <span class="muted">(<?= h(number_format($d['feerate'], 1)) ?> sat/vB)</span><?php endif; ?></div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($mwebInfo): ?>
    <div class="row mt-3">
      <?php if ($mwebInfo['is_hogex']): ?>
        <span class="badge mweb">HogEx</span>
        <span class="muted">MWEB integration transaction. Committed supply <?= lx_amount_el($net, (int) $mwebInfo['supply_sat']) ?><?php if ($mwebInfo['pegout_total_sat'] > 0): ?>, with <?= commas(count($mwebInfo['pegouts'])) ?> peg-out(s) totalling <?= lx_amount_el($net, (int) $mwebInfo['pegout_total_sat']) ?> back to the public chain<?php endif; ?>.</span>
      <?php elseif ($mwebInfo['pegin_total_sat'] > 0): ?>
        <span class="badge mweb">Peg-in</span>
        <span class="muted"><?= lx_amount_el($net, (int) $mwebInfo['pegin_total_sat']) ?> moved into the MWEB extension block.</span>
      <?php endif; ?>
    </div>
      <?php if ($mwebInfo['is_hogex'] && $mwebInfo['pegout_total_sat'] > 0): ?>
      <p class="mweb-coach"><?= lx_icon('eye-off') ?><span><b>Privacy note:</b> peg-out amounts and destination addresses are public. A peg-out that matches the amount and timing of an earlier peg-in can be linked back across the MWEB boundary. See the <a href="<?= h(lx_u($net)) ?>/mweb">MWEB privacy guide</a>.</span></p>
      <?php elseif (!$mwebInfo['is_hogex'] && $mwebInfo['pegin_total_sat'] > 0): ?>
      <p class="mweb-coach"><?= lx_icon('eye-off') ?><span><b>Privacy note:</b> this peg-in's amount and funding address are public. Coins become confidential only once inside MWEB, so let them rest and avoid later pegging out the same round amount, which links the two ends. See the <a href="<?= h(lx_u($net)) ?>/mweb">MWEB privacy guide</a>.</span></p>
      <?php endif; ?>
      <?php
      // MWEBscan trace overlay: role + round-trip links + privacy score for this MWEB
      // tx. Confirmed-tx analysis is stable so it's cached; degrades silently.
      $mwU     = lx_u($net);
      $mwTrace = lx_mwebscan_enabled($net) ? lx_mwebscan_api($net, 'trace', ['q' => $txid], $confirmed ? 900 : 60) : null;
      $mwTr    = (is_array($mwTrace) && is_array($mwTrace['trace'] ?? null)) ? $mwTrace['trace'] : null;
      $mwTpi   = ($mwTr && is_array($mwTr['pegins'] ?? null)) ? $mwTr['pegins'] : [];
      $mwTpo   = ($mwTr && is_array($mwTr['pegouts'] ?? null)) ? $mwTr['pegouts'] : [];
      if ($mwTpi || $mwTpo):
      ?>
      <div class="note mt-3">
        <div class="row" style="gap:8px"><?= lx_icon('shield') ?><b>MWEB analysis</b> <span class="muted sub">via MWEBscan<?php $frT = lx_mwebscan_freshness($mwTrace); if ($frT !== ''): ?> &middot; <?= h($frT) ?><?php endif; ?></span></div>
        <?php foreach ($mwTpi as $pi): $sc = is_array($pi['score'] ?? null) ? $pi['score'] : []; $links = is_array($pi['links'] ?? null) ? $pi['links'] : []; ?>
        <div class="sub mt-2"><span class="badge mweb">Peg-in</span> <?= h(rtrim(rtrim(number_format((float) ($pi['amount'] ?? 0), 8), '0'), '.')) ?> <?= h($net['unit']) ?><?php if (isset($sc['privacy_score'])): $ps = (int) $sc['privacy_score']; ?> &middot; privacy <span class="badge <?= $ps >= 80 ? 'ok' : ($ps >= 50 ? 'warn' : 'bad') ?>"><?= $ps ?></span><?php endif; ?><?php if (isset($sc['anonymity_set'])): ?> &middot; anon set <?= commas((int) $sc['anonymity_set']) ?><?php endif; ?></div>
        <?php foreach ($links as $lk): $c = (float) ($lk['confidence'] ?? 0); $pt = (string) ($lk['pegout_txid'] ?? ''); if ($pt === '') { continue; } ?>
        <div class="mono sub muted" style="margin-left:10px">&rarr; peg-out <a class="addr" href="<?= h($mwU) ?>/tx/<?= h($pt) ?>"><?= h(shorten($pt)) ?></a> <span class="badge <?= $c >= 0.9 ? 'bad' : ($c >= 0.7 ? 'warn' : 'soft') ?>"><?= h(number_format($c * 100, 0)) ?>% linked</span></div>
        <?php endforeach; ?>
        <?php endforeach; ?>
        <?php foreach ($mwTpo as $po): $c = $po['confidence'] ?? null; $lp = (string) ($po['linked_pegin'] ?? ($po['pegin_txid'] ?? '')); ?>
        <div class="sub mt-2"><span class="badge mweb">Peg-out</span> <?= h(rtrim(rtrim(number_format((float) ($po['amount'] ?? ($po['pegout_amount'] ?? 0)), 8), '0'), '.')) ?> <?= h($net['unit']) ?><?php if ($lp !== ''): ?> &middot; &larr; peg-in <a class="addr" href="<?= h($mwU) ?>/tx/<?= h($lp) ?>"><?= h(shorten($lp)) ?></a><?php endif; ?><?php if ($c !== null): $cf = (float) $c; ?> <span class="badge <?= $cf >= 0.9 ? 'bad' : ($cf >= 0.7 ? 'warn' : 'soft') ?>"><?= h(number_format($cf * 100, 0)) ?>% linked</span><?php endif; ?></div>
        <?php endforeach; ?>
        <div class="muted sub mt-2">Inferences, not proof. Data from <a class="ext" href="<?= h(lx_mwebscan_site($net)) ?>" target="_blank" rel="noopener">MWEBscan</a>.</div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
    <table class="kv mt-3">
      <tr><th>Features</th><td>
        <?php foreach ([['SegWit', $hasWitness, 'ok'], ['Taproot', $hasTaproot, 'ok'], ['RBF', $rbf, 'warn']] as $ft): ?>
          <?php if ($ft[1]): ?><span class="badge <?= $ft[2] ?>"><?= $ft[0] ?></span><?php else: ?><span class="badge off" title="Not used by this transaction">no <?= $ft[0] ?></span><?php endif; ?>
        <?php endforeach; ?>
      </td></tr>
      <tr><th>Fee</th><td><?php if ($isCoinbase): ?>newly minted (coinbase)<?php else: ?>
        <?= lx_amount_el($net, (int) ($tx['fee'] ?? 0)) ?><?php if (($ff = lx_fiat_el((int) ($tx['fee'] ?? 0), $txPrice)) !== ''): ?> <span class="muted sub">&asymp; <?= $ff ?></span><?php endif; ?> <span class="badge soft"><?= h(number_format($feeRate, 2)) ?> sat/vB</span>
        <?php if ($pkgRate !== null && abs($pkgRate - $feeRate) > 0.01): ?>
          <span class="badge soft" title="Effective package fee-rate incl. ancestors">CPFP <?= h(number_format($pkgRate, 2)) ?> sat/vB</span>
        <?php endif; ?><?php endif; ?></td></tr>
      <tr><th>Size / vsize / weight</th><td><?= commas($tx['size'] ?? 0) ?> B · <?= commas($vsize) ?> vB · <?= commas($tx['weight'] ?? 0) ?> WU</td></tr>
      <tr><th>Version / locktime</th><td><?= (int) ($tx['version'] ?? 1) ?> / <?= commas($tx['locktime'] ?? 0) ?></td></tr>
      <tr><th>Transaction hex</th><td><a class="mono" href="<?= h(lx_u($net)) ?>/api/tx/<?= h(rawurlencode($txid)) ?>/hex" target="_blank" rel="noopener">view raw &rarr;</a></td></tr>
      <?php if ($mentry): ?>
      <tr><th>Mempool</th><td><?= (int) ($mentry['ancestorcount'] ?? 1) ?> ancestor(s) · <?= (int) ($mentry['descendantcount'] ?? 1) ?> descendant(s)</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('log-in') ?>Inputs</span> <span class="sub"><?= count($tx['vin']) ?></span></div>
    <div class="card-b nopad">
      <?php $ioCap = 25; $vinN = count($tx['vin']); ?>
      <?php foreach ($tx['vin'] as $ii => $vi): ?>
        <?php if ($ii === $ioCap): ?><details class="io-more"><summary>Show <?= commas($vinN - $ioCap) ?> more inputs</summary><?php endif; ?>
        <div class="io-row">
          <?php if (!empty($vi['is_coinbase'])): ?>
            <?php
              // Decode the coinbase: BIP34 height push + printable tag.
              $cbBin = @hex2bin($vi['scriptsig'] ?? '');
              $cbHeight = null;
              $cbText = '';
              if ($cbBin !== false && strlen($cbBin) > 0) {
                  $l = ord($cbBin[0]);
                  if ($l >= 1 && $l <= 8 && strlen($cbBin) > $l) {
                      $hv = 0;
                      for ($i = 0; $i < $l; $i++) { $hv |= ord($cbBin[1 + $i]) << (8 * $i); }
                      $cbHeight = $hv;
                      $cbText = substr($cbBin, 1 + $l);
                  } else {
                      $cbText = $cbBin;
                  }
                  $cbText = trim(preg_replace('/[^\x20-\x7e]+/', ' ', $cbText));
              }
            ?>
            <div class="io-addr"><span class="badge">Coinbase</span> <span class="muted">newly generated coins</span></div>
            <?php if ($cbHeight !== null || $cbText !== ''): ?>
              <div class="io-script mono muted break"><?php if ($cbHeight !== null): ?>height <?= commas($cbHeight) ?><?php endif; ?><?= $cbText !== '' ? ' · ' . h($cbText) : '' ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="io-addr"><?= lx_addr_cell($net, $vi['prevout']['scriptpubkey_address'] ?? null, $vi['prevout']['scriptpubkey_type'] ?? '', true) ?></div>
            <div class="io-meta">
              <a class="muted mono" href="<?= h(lx_tx_href($net, $vi['txid'])) ?>#out-<?= (int) $vi['vout'] ?>"><?= h(shorten($vi['txid'], 8, 6)) ?>:<?= (int) $vi['vout'] ?></a>
              <span class="io-val"><?= ($vi['prevout'] ?? null) ? lx_amount_el($net, (int) $vi['prevout']['value'], false) : '?' ?></span>
            </div>
            <?php if (($vi['scriptsig'] ?? '') !== '' || !empty($vi['witness'])): ?>
              <details class="io-script">
                <summary>script</summary>
                <?php if (($vi['scriptsig_asm'] ?? '') !== ''): ?><div class="mono break faint">scriptSig: <?= h($vi['scriptsig_asm']) ?></div><?php endif; ?>
                <?php if (!empty($vi['witness'])): ?>
                  <div class="faint">witness (<?= count($vi['witness']) ?> item<?= count($vi['witness']) === 1 ? '' : 's' ?>):</div>
                  <?php foreach ($vi['witness'] as $wi => $w): ?><div class="mono break faint"><?= $wi ?>: <span class="wlen"><?= (int) (strlen($w) / 2) ?>B</span> <?= h($w) ?></div><?php endforeach; ?>
                <?php endif; ?>
                <?php if (($vi['prevout']['scriptpubkey_asm'] ?? '') !== ''): ?><div class="mono break faint">prevout: <?= h($vi['prevout']['scriptpubkey_asm']) ?></div><?php endif; ?>
              </details>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($vinN > $ioCap): ?></details><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span><?= lx_icon('log-out') ?>Outputs</span> <span class="sub"><?= count($tx['vout']) ?></span></div>
    <div class="card-b nopad">
      <?php $voutN = count($tx['vout']); ?>
      <?php foreach ($tx['vout'] as $n => $vo): ?>
        <?php if ($n === $ioCap): ?><details class="io-more"><summary>Show <?= commas($voutN - $ioCap) ?> more outputs</summary><?php endif; ?>
        <?php $type = $vo['scriptpubkey_type'] ?? 'unknown'; $spent = $outspends[$n]['spent'] ?? null; $mwebKind = $mwebInfo ? lx_mweb_spk_kind($vo) : null; ?>
        <div class="io-row" id="out-<?= $n ?>">
          <div class="io-addr"><?= lx_addr_cell($net, $vo['scriptpubkey_address'] ?? null, $type, true) ?>
            <span class="badge soft"><?= h(lx_spk_label($type)) ?></span>
            <?php if ($spent === true): $spTxid = $outspends[$n]['txid'] ?? null; ?>
              <?php if ($spTxid): ?><a class="badge bad" href="<?= h(lx_tx_href($net, $spTxid)) ?>" title="Spending transaction">spent &rarr;</a><?php else: ?><span class="badge bad">spent</span><?php endif; ?>
            <?php elseif ($spent === false): ?><span class="badge ok">unspent</span><?php endif; ?>
            <?php if ($mwebKind === 'pegin'): ?><span class="badge mweb">Peg-in (MWEB)</span><?php elseif ($mwebKind === 'hogaddr'): ?><span class="badge mweb">HogEx supply</span><?php endif; ?></div>
          <div class="io-meta">
            <span class="muted mono">#<?= $n ?></span>
            <span class="io-val"><?= lx_amount_el($net, (int) ($vo['value'] ?? 0), false) ?></span>
          </div>
          <?php if ($type === 'op_return'): ?>
            <?php foreach (lx_parse_op_return($vo['scriptpubkey'] ?? '') as $p): ?>
              <div class="io-script opreturn break">
                <?php if ($p['label']): ?><span class="badge soft"><?= h($p['label']) ?></span> <?php endif; ?>
                <?php if ($p['text'] !== null): ?><span class="mono">"<?= h($p['text']) ?>"</span> <?php endif; ?>
                <span class="mono muted"><?= h($p['hex']) ?></span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <details class="io-script">
              <summary>script</summary>
              <div class="mono break faint"><?= h($vo['scriptpubkey_asm'] ?? '') ?></div>
              <div class="mono break faint">hex: <?= h($vo['scriptpubkey'] ?? '') ?></div>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($voutN > $ioCap): ?></details><?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-b between">
    <span class="muted"><?= $isCoinbase ? 'Coinbase' : ($inKnown ? lx_amount_el($net, (int) $inSum) . ' in' : 'inputs') ?></span>
    <span class="muted" style="color:var(--brand,var(--accent))">→</span>
    <span><span class="big-num sm"><?= lx_amount_el($net, (int) $outSum) ?></span> out<?php if (($of = lx_fiat_el((int) $outSum, $txPrice)) !== ''): ?> <span class="muted sub">&asymp; <?= $of ?><?php if ($histPrice !== null && ($ot = lx_fiat_str((int) $outSum, $histPrice)) !== null): ?> <span title="value at the time this block was mined">(<?= h($ot) ?> then)</span><?php endif; ?></span><?php endif; ?></span>
    <?php if (lx_extern_links()): ?><a class="btn ghost sm ext" href="<?= h($net['extern_tx'] . $txid) ?>" target="_blank" rel="noopener">View on <?= h($net['extern_name']) ?></a><?php endif; ?>
  </div>
</div>

<?php if (!empty($tx['vout'])): ?>
<div class="card">
  <div class="card-h">
    <span><?= lx_icon('share-2') ?>Transaction diagram</span>
    <div class="txviz-seg" role="group" aria-label="Diagram style">
      <button type="button" class="txviz-btn" data-txviz-set="graph" aria-pressed="true">Graph</button>
      <button type="button" class="txviz-btn" data-txviz-set="flow" aria-pressed="false">Flow</button>
    </div>
  </div>
  <div class="card-b">
    <div data-txviz-pane="graph"><?= lx_tx_graph($net, $tx, $outspends) ?></div>
    <div data-txviz-pane="flow"><?= lx_tx_flow($net, $tx, $outspends) ?></div>
    <p class="sub txviz-cap" data-txviz-pane="graph">funding txs &rarr; this tx &rarr; spending txs &middot; click a node to follow the chain</p>
    <p class="sub txviz-cap" data-txviz-pane="flow">click an input or spent output to follow the chain</p>
  </div>
</div>
<?php endif; ?>
<?php lx_foot($net); ?>
