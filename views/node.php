<?php
/**
 * Node page: daemon + chain internals, connected peers (address-masked for
 * privacy), the mempool footprint, and the UTXO-set summary. UTXO lanes only.
 * $net in scope. SPDX-License-Identifier: AGPL-3.0-or-later
 */
$base  = ts_u($net);
$node  = ts_node_report($net);
$peers = ts_node_peers($net);
$utxo  = ts_txoutset_info($net);

ts_head($net, ['title' => 'Node - ' . $net['label'] . ' Explorer']);

// Uptime (seconds) -> "3d 4h" / "12m".
$upStr = '-';
if ($node !== null && $node['uptime'] !== null && $node['uptime'] > 0) {
    $u = (int) $node['uptime'];
    $d = intdiv($u, 86400);
    $hh = intdiv($u % 86400, 3600);
    $mm = intdiv($u % 3600, 60);
    $parts = [];
    if ($d) { $parts[] = $d . 'd'; }
    if ($hh) { $parts[] = $hh . 'h'; }
    if (!$d && $mm) { $parts[] = $mm . 'm'; }
    $upStr = $parts ? implode(' ', $parts) : '<1m';
}
?>
<h1>Node</h1>

<div class="card hero"><div class="card-b">
  <div class="hero-eyebrow"><span class="pulse-dot<?= $node !== null ? '' : ' off' ?>"></span><?= $node !== null ? 'daemon online' : 'daemon unreachable' ?></div>
  <?php if ($node !== null): ?>
    <p class="muted hero-lede">The <?= h($net['label']) ?> node this explorer reads from, live from
    <span class="mono"><?= h($node['subversion'] !== '' ? $node['subversion'] : 'core') ?></span>.</p>
    <table class="kv mt-2">
      <tr><th>Client</th><td class="mono"><?= h($node['subversion'] !== '' ? $node['subversion'] : '-') ?><?php if ($node['protocol']): ?> <span class="muted">(protocol <?= (int) $node['protocol'] ?>)</span><?php endif; ?></td></tr>
      <tr><th>Chain</th><td><?= h($node['chain'] !== '' ? $node['chain'] : '-') ?><?php if ($node['pruned']): ?> <span class="badge warn">pruned</span><?php endif; ?><?php if ($node['ibd']): ?> <span class="badge warn">syncing</span><?php endif; ?></td></tr>
      <tr><th>Blocks</th><td><a href="<?= h($base) ?>/block-height/<?= (int) $node['blocks'] ?>">#<?= commas($node['blocks']) ?></a><?php if ($node['headers'] > $node['blocks']): ?> <span class="muted">of <?= commas($node['headers']) ?> headers</span><?php endif; ?></td></tr>
      <tr><th>Verification</th><td><?= h(number_format(min(100, $node['progress'] * 100), 2)) ?>%</td></tr>
      <tr><th>Difficulty</th><td class="mono"><?= h(ts_diff_str((float) $node['difficulty'])) ?></td></tr>
      <tr><th>Connections</th><td><?= commas($node['connections']) ?><?php if ($peers['ok'] && $peers['total']): ?> <span class="muted"><?= (int) $peers['outbound'] ?> out / <?= (int) $peers['inbound'] ?> in</span><?php endif; ?></td></tr>
      <tr><th>Size on disk</th><td><?= h(ts_size_str((int) $node['size_on_disk'], 'B')) ?></td></tr>
      <tr><th>Uptime</th><td><?= h($upStr) ?></td></tr>
      <?php if ($node['warnings'] !== ''): ?><tr><th>Warnings</th><td><span class="badge warn"><?= h($node['warnings']) ?></span></td></tr><?php endif; ?>
    </table>
  <?php else: ?>
    <p class="muted">The node is not reachable right now. This page will populate once the daemon responds.</p>
  <?php endif; ?>
</div></div>

<div class="card">
  <div class="card-h"><span><?= ts_icon('activity') ?>Peers</span> <span class="sub"><?php if ($peers['ok']): ?><?= (int) $peers['total'] ?> connected &middot; <?= (int) $peers['outbound'] ?> out / <?= (int) $peers['inbound'] ?> in<?php else: ?>unavailable<?php endif; ?></span></div>
  <?php if ($peers['ok'] && $peers['total']): ?>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Peer</th><th>Network</th><th>Client</th><th>Dir</th><th>Connected</th><th class="amt">Ping</th></tr></thead>
      <tbody>
      <?php foreach ($peers['peers'] as $p): ?>
        <tr>
          <td class="mono"><?= h($p['addr']) ?></td>
          <td><span class="badge soft"><?= h($p['network']) ?></span></td>
          <td class="mono"><?= $p['subver'] !== '' ? h($p['subver']) : '<span class="muted">-</span>' ?></td>
          <td><span class="badge<?= $p['inbound'] ? '' : ' soft' ?>"><?= $p['inbound'] ? 'in' : 'out' ?></span></td>
          <td class="muted"><?= $p['conntime'] ? h(time_ago($p['conntime'])) : '<span class="muted">-</span>' ?></td>
          <td class="amt mono"><?= $p['pingtime'] !== null ? h(number_format($p['pingtime'] * 1000, 0)) . ' ms' : '<span class="muted">-</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-b"><p class="muted sub"><?= ts_icon('lock') ?> Peer addresses are masked, so the explorer never publishes its node&rsquo;s exact peer IPs.</p></div>
  <?php else: ?>
  <div class="card-b"><div class="empty"><?= ts_icon('activity') ?><span><?= $peers['ok'] ? 'No peers connected.' : 'Peer list is unavailable while the node is unreachable.' ?></span></div></div>
  <?php endif; ?>
</div>

<?php if ($node !== null): ?>
<div class="card">
  <div class="card-h"><span><?= ts_icon('clock') ?>Mempool</span> <span class="sub">this node&rsquo;s view</span></div>
  <div class="card-b nopad">
    <table class="kv">
      <tr><th>Transactions</th><td><?= commas($node['mempool_txs']) ?></td></tr>
      <tr><th>Virtual size</th><td><?= h(ts_size_str((int) $node['mempool_bytes'], 'vB')) ?></td></tr>
      <tr><th>Memory usage</th><td><?= h(ts_size_str((int) $node['mempool_usage'], 'B')) ?><?php if ($node['mempool_max']): ?> <span class="muted">of <?= h(ts_size_str((int) $node['mempool_max'], 'B')) ?></span><?php endif; ?></td></tr>
      <tr><th>Min relay fee</th><td class="mono"><?= h(number_format($node['relayfee'] * 100000, 2)) ?> sat/vB</td></tr>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><span><?= ts_icon('database') ?>UTXO set</span> <span class="sub">from the chainstate</span></div>
  <?php if ($utxo !== null): ?>
  <div class="card-b nopad">
    <table class="kv">
      <tr><th>As of block</th><td><a href="<?= h($base) ?>/block-height/<?= (int) $utxo['height'] ?>">#<?= commas($utxo['height']) ?></a></td></tr>
      <tr><th>Unspent outputs</th><td><?= commas($utxo['txouts']) ?></td></tr>
      <tr><th>Transactions with UTXOs</th><td><?= commas($utxo['transactions']) ?></td></tr>
      <tr><th>Total value</th><td class="mono"><?= h(number_format($utxo['total_amount'], 2)) ?> <?= h($net['unit']) ?></td></tr>
      <tr><th>Chainstate size</th><td><?= h(ts_size_str((int) $utxo['disk_size'], 'B')) ?></td></tr>
    </table>
  </div>
  <?php else: ?>
  <div class="card-b"><div class="empty"><?= ts_icon('database') ?><span>The UTXO-set summary is not available right now.</span></div></div>
  <?php endif; ?>
</div>
<?php ts_foot($net); ?>
