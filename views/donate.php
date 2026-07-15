<?php
/**
 * Donate: support Litecoin Explorer. Top-level page (standalone chrome).
 * OpenAlias (donate@litecoinexplorer.org) + Litecoin addresses.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, s-maxage=60, max-age=0');   // static; edge-cacheable
$base = ts_base_url();

$openalias = 'donate@litecoinexplorer.org';

$coins = [
    ['coin' => 'ltc', 'name' => 'Litecoin', 'ticker' => 'LTC', 'scheme' => 'litecoin',
     'addr' => 'ltc1qesqzfuwfsdx8t3dmgnh2f79eflhav7qyrs7urn'],
    ['coin' => 'ltc', 'name' => 'Litecoin MWEB', 'ticker' => 'LTC', 'scheme' => 'litecoin', 'mweb' => true,
     'addr' => 'ltcmweb1qqv2z6c6gu0csd454rlx6xp4rgu8dxska3wxypcr9m7cqu08h39rccqj4pr3j0e0lamu3358quqh84vlst7v9xa3q6h3u3u0mlj6uv0w6ag5mcucl'],
];
?>
<?php ts_head($net, [
    'title' => 'Donate - Litecoin Explorer',
    'desc'  => 'Support Litecoin Explorer, a free, open-source Litecoin (with MWEB) block explorer. Donate via OpenAlias or LTC.',
]); ?>
<h1>Donate</h1>
<div class="card hero"><div class="card-b">
  <div class="hero-eyebrow"><?= ts_icon('gift') ?>Support the project</div>
  <p class="mt-2">Litecoin Explorer is free, open-source (<a class="ext" href="https://github.com/Tech1k/litecoinexplorer.org/blob/HEAD/LICENSE" target="_blank" rel="noopener">AGPL-3.0</a>)
  and self-funded. Running the node and index isn't. If it's useful to you, a tip toward server
  costs is hugely appreciated, but never required.</p>
</div></div>

<div class="card">
  <div class="card-h"><span class="coin-name"><?= ts_icon('at-sign') ?>OpenAlias</span> <span class="sub">one name, any coin</span></div>
  <div class="card-b">
    <p class="muted">Wallets with OpenAlias support resolve a single human-readable name to the
    right Litecoin address automatically:</p>
    <div class="addr-main mt-2">
      <div class="addr break donate-uri"><?= h($openalias) ?></div>
      <div class="row mt-3">
        <button class="btn ghost sm copybtn" type="button" data-copy="<?= h($openalias) ?>">Copy</button>
      </div>
    </div>
  </div>
</div>

<div class="net-grid">
<?php foreach ($coins as $c): $uri = $c['scheme'] . ':' . $c['addr']; ?>
  <div class="card brand-top" style="--brand:<?= h(ts_brand_color($c['coin'])) ?>">
    <div class="card-h"><span class="coin-name"><img class="coin-ico" src="/assets/coins/<?= h($c['coin']) ?>.svg" alt="" width="22" height="22"><?= h($c['name']) ?></span>
      <?php if (!empty($c['mweb'])): ?><span class="badge mweb">private</span><?php else: ?><span class="sub"><?= h($c['ticker']) ?></span><?php endif; ?></div>
    <div class="card-b addr-top">
      <div class="qr-wrap">
        <div class="qr" data-qr="<?= h($uri) ?>" data-qr-ec="H" role="img" aria-label="<?= h($c['name']) ?> donation QR"></div>
        <img class="qr-logo" src="/assets/coins/<?= h($c['coin']) ?>.svg" alt="">
      </div>
      <div class="addr-main">
        <div class="addr break donate-uri"><?= h($c['addr']) ?></div>
        <div class="row mt-3">
          <button class="btn ghost sm copybtn" type="button" data-copy="<?= h($c['addr']) ?>">Copy</button>
          <a class="btn ghost sm" href="<?= h($uri) ?>">Open in wallet</a>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="card"><div class="card-b">
  <p class="muted sub">These are Litecoin donation addresses. Tips just keep the node and explorer
  running. Thank you.</p>
</div></div>
<?php ts_foot($net, ['qr' => true]); ?>
