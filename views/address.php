<?php
/**
 * Address detail: balance, stats, QR, tx history.
 * $net in scope; $GLOBALS['address']. SPDX-License-Identifier: AGPL-3.0-or-later
 */
$addr = $GLOBALS['address'];
$type = lx_address_type($net, $addr);
$sh = lx_scripthash($net, $addr);
$stats = lx_address_stats($net, $addr);
if ($stats === null) {
    if (lx_address_to_scriptpubkey($net, $addr) === null) {
        http_response_code(404);            // genuinely not a valid address
        $GLOBALS['search_query'] = $addr;
        require __DIR__ . '/notfound.php';
        return;
    }
    // Valid address, but the Electrum index is unreachable / mid-resync. Show a clear
    // degraded page - NEVER a false, confident "0 balance" (this is a money tool).
    http_response_code(503);
    if (!headers_sent()) { header('Retry-After: 30'); header('Cache-Control: no-store'); }
    lx_head($net, ['title' => 'Address - ' . $net['label']]);
    echo '<h1>Address</h1><div class="card"><div class="card-b">'
       . '<div class="break mono addr-lg">' . h($addr) . '</div>'
       . '<div class="empty mt-3">' . lx_icon('clock')
       . '<span>The address index is temporarily unavailable. The Electrum server is unreachable or resyncing, so balance and history aren&rsquo;t available. Try again shortly.</span></div>'
       . '</div></div>';
    lx_foot($net);
    return;
}

$c = $stats['chain_stats'];
$m = $stats['mempool_stats'];
$balance = $c['funded_txo_sum'] - $c['spent_txo_sum'];
$pending = $m['funded_txo_sum'] - $m['spent_txo_sum'];
$txTotal = $c['tx_count'] + $m['tx_count'];

$after = $_GET['after'] ?? null;
$mode = $after ? 'chain' : 'all';
$txs = lx_address_txs($net, $addr, $mode, $after) ?? [];

// Short, finite edge/browser cache so a crawler can't re-hit electrs on every
// load. Never long/immutable: balance + mempool txs are mutable and confirmed
// history can reorg. Chain-only (?after) pages tolerate a slightly longer edge TTL.
if (!headers_sent()) {
    header($after ? 'Cache-Control: public, max-age=10, s-maxage=60'
                  : 'Cache-Control: public, max-age=5, s-maxage=20');
}
$lastConfirmed = null;
$confirmedCount = 0;
foreach ($txs as $tx) {
    if (!empty($tx['status']['confirmed'])) {
        $lastConfirmed = $tx['txid'];
        $confirmedCount++;
    }
}
// Esplora returns exactly 25 confirmed per chain page; a full page means more.
$hasMore = $confirmedCount >= 25 && $lastConfirmed !== null;
$spk = lx_address_to_scriptpubkey($net, $addr);

// Balance history over the loaded (recent) window: reconstruct the confirmed
// balance after each confirmed tx by walking net deltas back from the current
// balance. Chart-only, from data already fetched (no extra electrs calls).
$balSeries = [];
$balLabels = [];
$balTips = [];
$balRun = $balance;
foreach ($txs as $tx) {
    if (empty($tx['status']['confirmed'])) { continue; }
    $d = 0;
    foreach ($tx['vout'] as $o) { if (($o['scriptpubkey'] ?? '') === $spk) { $d += ($o['value'] ?? 0); } }
    foreach ($tx['vin'] as $vi) { $po = $vi['prevout'] ?? null; if ($po && ($po['scriptpubkey'] ?? '') === $spk) { $d -= ($po['value'] ?? 0); } }
    $balSeries[] = [
        'h'   => (int) ($tx['status']['block_height'] ?? 0),
        't'   => (int) ($tx['status']['block_time'] ?? 0),
        'bal' => $balRun,        // balance AFTER this tx confirmed
    ];
    $balRun -= $d;               // step back to the prior balance
}
// Order by chain HEIGHT, not block time: testnet timestamps are not monotonic
// with height, so plotting against time makes the line run backwards. Plot
// against an even chain sequence (x = seq) and label the ticks by date.
usort($balSeries, function ($a, $b) { return $a['h'] <=> $b['h']; });
$prevBal = null;
foreach ($balSeries as $i => $r) {
    $balSeries[$i]['seq'] = $i;
    $stamp = $r['t'] > 0 ? gmdate('M j, Y H:i', $r['t']) : 'block ' . number_format($r['h']);
    $balLabels[] = ($r['t'] > 0 ? gmdate('m-d H:i', $r['t']) : '#' . $r['h']) . ' · ' . lx_coin_compact($r['bal']);
    $balTips[] = lx_tip_json($stamp, [
        ['c' => 'var(--accent)', 'k' => 'Balance', 'v' => lx_amount($net, (int) $r['bal']), 'd' => $prevBal !== null ? lx_pct_delta($r['bal'], $prevBal) : ''],
    ]);
    $prevBal = $r['bal'];
}
$balXticks = [];
$balN = count($balSeries);
if ($balN >= 2) {
    for ($i = 0; $i <= 4; $i++) {
        $idx = (int) round($i / 4 * ($balN - 1));
        $tt  = (int) $balSeries[$idx]['t'];
        $balXticks[] = ['f' => $i / 4, 'label' => $tt > 0 ? gmdate('m-d', $tt) : '#' . $balSeries[$idx]['h']];
    }
}

lx_head($net, [
    'title' => 'Address ' . shorten($addr) . ' - ' . $net['label'],
    'desc'  => $net['label'] . ' address · balance ' . lx_amount($net, (int) $balance)
             . ' · ' . commas($txTotal) . ' transaction' . ($txTotal === 1 ? '' : 's') . '.',
    'og_image' => '/og/address/' . rawurlencode($addr) . '.png',
]);
?>
<h1>Address</h1>
<div class="card">
  <div class="card-b">
    <div class="addr-top">
      <div class="qr" data-qr="<?= h($addr) ?>" role="img" aria-label="QR code for this address"></div>
      <div class="addr-main">
        <div class="hero-eyebrow">Address &middot; <?= h(lx_spk_label($type)) ?></div>
        <div class="break mono addr-lg"><?= h($addr) ?></div>
        <div class="row mt-2"><button class="btn ghost sm" type="button" data-copy="<?= h($addr) ?>" aria-label="Copy address">Copy address</button></div>
        <div class="row mt-2"><span class="badge soft"><?= h(lx_spk_label($type)) ?></span></div>
      </div>
    </div>
    <div class="stat-grid mt-3">
      <div class="stat<?= $balance > 0 ? ' hot' : '' ?>"><div class="muted sub">Balance</div><div class="big-num sm"><?= h(lx_amount($net, $balance)) ?></div>
        <?php if (($balFiat = lx_fiat_el((int) $balance, lx_price_now($net))) !== ''): ?><div class="muted sub">&asymp; <?= $balFiat ?></div><?php endif; ?>
        <?php if ($pending): ?><div class="muted sub"><?= h(lx_coin($pending)) ?> pending</div><?php endif; ?></div>
      <div class="stat"><div class="muted sub">Received</div><div><?= h(lx_amount($net, $c['funded_txo_sum'])) ?></div></div>
      <div class="stat"><div class="muted sub">Sent</div><div><?= h(lx_amount($net, $c['spent_txo_sum'])) ?></div></div>
      <div class="stat"><div class="muted sub">Transactions</div><div><?= commas($txTotal) ?></div></div>
    </div>
    <table class="kv mt-3">
      <tr><th>Scripthash</th><td class="mono break"><?= h($sh ?? '-') ?></td></tr>
      <tr><th>Outputs</th><td><?= commas($c['funded_txo_count']) ?> received · <?= commas($c['spent_txo_count']) ?> spent</td></tr>
    </table>
    <?php if ($balance === 0 && $txTotal === 0): ?>
      <div class="note">This address has no confirmed or pending transactions.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($after === null && count($balSeries) >= 2): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('trending-up') ?>Balance history</span> <span class="sub">recent &middot; <?= h($net['unit']) ?></span></div>
  <div class="card-b">
    <?= lx_chart_area($balSeries, 'seq', 'bal', 'Confirmed balance over recent transactions', $balLabels, [
        'yfmt'   => 'lx_coin_compact',
        'xticks' => $balXticks,
        'tips'   => $balTips,
    ]) ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h">Transactions</div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Txid</th><th>Status</th><th class="amt">Value</th></tr></thead>
      <tbody>
      <?php foreach ($txs as $tx): ?>
        <?php
          $delta = 0;
          foreach ($tx['vout'] as $o) { if (($o['scriptpubkey'] ?? '') === $spk) { $delta += ($o['value'] ?? 0); } }
          foreach ($tx['vin'] as $vi) { $po = $vi['prevout'] ?? null; if ($po && ($po['scriptpubkey'] ?? '') === $spk) { $delta -= ($po['value'] ?? 0); } }
        ?>
        <tr>
          <td class="mono"><a class="addr" href="<?= h(lx_tx_href($net, $tx['txid'])) ?>"><?= h(shorten($tx['txid'])) ?></a></td>
          <td data-sort="<?= (int) ($tx['status']['block_time'] ?? 0) ?>"><?php if (empty($tx['status']['confirmed'])): ?><span class="badge warn">pending</span><?php else: ?>
            <a href="<?= h(lx_block_href($net, $tx['status']['block_hash'])) ?>"><?= commas($tx['status']['block_height']) ?></a>
            <span class="muted sub"><?= h(time_ago((int) ($tx['status']['block_time'] ?? 0))) ?></span><?php endif; ?></td>
          <td class="amt <?= $delta >= 0 ? 'pos' : 'neg' ?>"><span class="delta"><?= ($delta >= 0 ? '+' : '') . h(lx_coin($delta)) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$txs): ?><tr><td colspan="3"><div class="empty"><?= lx_icon('inbox') ?><span>No transactions.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($hasMore): ?>
<div class="pagination">
  <a class="btn ghost sm" href="<?= h(lx_addr_href($net, $addr)) ?>">&larr; Newest</a>
  <a class="btn ghost sm" href="<?= h(lx_addr_href($net, $addr)) ?>?after=<?= h($lastConfirmed) ?>">Older transactions &rarr;</a>
</div>
<?php endif; ?>
<?php lx_foot($net, ['qr' => true]); ?>
