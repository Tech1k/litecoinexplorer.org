<?php
/**
 * Status: at-a-glance health of the Litecoin backend (node RPC, Electrum index,
 * MWEB). Standalone chrome (top-level page).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Cache-Control: public, s-maxage=5, max-age=0');   // live health; brief edge window
header('X-Robots-Tag: noindex');                          // live status, keep it out of the index
$base = lx_base_url();
$nets = lx_networks();

// Build a health row per network.
$rows = [];
foreach ($nets as $n) {
    $r = ['net' => $n, 'up' => false, 'items' => []];
    $h = lx_health($n);
    $r['up'] = !empty($h['rpc']['ok']) && !empty($h['electrum']['ok']);
    $r['items'][] = ['label' => 'Core RPC', 'ok' => !empty($h['rpc']['ok']), 'note' => !empty($h['rpc']['ok']) ? '#' . commas((int) $h['rpc']['height']) : 'unreachable'];
    $r['items'][] = ['label' => 'Electrum index', 'ok' => !empty($h['electrum']['ok']), 'note' => !empty($h['electrum']['ok']) ? 'reachable' : 'unreachable'];
    $r['items'][] = ['label' => 'Mempool', 'ok' => true, 'note' => commas((int) ($h['mempool'] ?? 0)) . ' tx'];
    if (lx_mweb_enabled($n)) {
        if (!empty($n['mweb']['index']['enabled'])) {
            $ready = lx_mweb_index_ready($n);
            $r['items'][] = ['label' => 'MWEB index', 'ok' => $ready, 'note' => $ready ? 'fresh' : 'catching up'];
        } else {
            $r['items'][] = ['label' => 'MWEB', 'ok' => true, 'note' => 'RPC mode'];
        }
    }
    $rows[] = $r;
}
$allUp = $rows ? array_reduce($rows, function ($c, $r) { return $c && $r['up']; }, true) : false;
?>
<?php lx_head($net, [
    'title' => 'Status - Litecoin Explorer',
    'desc'  => 'Live backend health for Litecoin Explorer: node RPC, Electrum index and MWEB index.',
]); ?>
<h1>Status</h1>
<div class="card hero"><div class="card-b between">
  <div><div class="muted sub">All backends</div><div class="big-num sm"><?= $allUp ? 'Operational' : 'Degraded' ?></div></div>
  <span class="hero-eyebrow"><span class="pulse-dot<?= $allUp ? '' : ' off' ?>"></span><?= $allUp ? 'All backends operational' : 'Degraded' ?></span>
</div></div>

<?php foreach ($rows as $r): $n = $r['net']; ?>
<div class="card brand-top" style="--brand:<?= h(lx_brand_color($n['coin'])) ?>">
  <div class="card-h"><span class="coin-name"><img class="coin-ico" src="/assets/coins/<?= h($n['coin']) ?>.svg" alt="" width="20" height="20"><a href="/"><?= h($n['label']) ?></a></span>
    <span class="badge <?= $r['up'] ? 'ok' : 'bad' ?>"><?= $r['up'] ? 'online' : 'offline' ?></span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <tbody>
      <?php foreach ($r['items'] as $it): ?>
        <tr>
          <td><?= h($it['label']) ?></td>
          <td class="amt"><span class="badge <?= $it['ok'] ? 'ok' : 'bad' ?>"><?= $it['ok'] ? 'ok' : 'down' ?></span></td>
          <td class="amt muted"><?= h($it['note']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card"><div class="card-b muted">No networks enabled.</div></div><?php endif; ?>
<?php lx_foot($net); ?>
