<?php
/**
 * API documentation: the drop-in Esplora / mempool.space surface.
 * Standalone chrome. SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, s-maxage=60, max-age=0');   // static docs; edge-cacheable
$base = lx_base_url();

$groups = [
    'Blocks' => [
        ['GET', '/blocks/tip/height', 'Current tip height (text)'],
        ['GET', '/blocks/tip/hash', 'Current tip hash (text)'],
        ['GET', '/blocks[/:start_height]', '10 block summaries, newest first'],
        ['GET', '/v1/blocks[/:start_height]', '15 blocks with pool/reward/fee extras'],
        ['GET', '/v1/blocks-bulk/:min/:max', 'Blocks in a height range with extras (cap 100)'],
        ['GET', '/block/:hash', 'Block summary'],
        ['GET', '/block/:hash/status', 'Chain-membership status'],
        ['GET', '/block/:hash/txids', 'All txids in the block'],
        ['GET', '/block/:hash/txs[/:start_index]', '25 full transactions'],
        ['GET', '/block/:hash/txid/:index', 'Single txid (text)'],
        ['GET', '/block/:hash/header', 'Block header hex (text)'],
        ['GET', '/block/:hash/raw', 'Raw block (binary)'],
        ['GET', '/block-height/:height', 'Block hash at height (text)'],
    ],
    'Transactions' => [
        ['GET', '/tx/:txid', 'Transaction (Esplora shape, with vin.prevout)'],
        ['GET', '/tx/:txid/hex', 'Raw tx hex (text)'],
        ['GET', '/tx/:txid/raw', 'Raw tx (binary)'],
        ['GET', '/tx/:txid/status', 'Confirmation status'],
        ['GET', '/tx/:txid/outspends', 'Spend status per output (spending txid/vin/status)'],
        ['GET', '/tx/:txid/outspend/:vout', 'Spend status for one output (spending txid/vin/status)'],
        ['GET', '/tx/:txid/merkle-proof', 'Merkle inclusion proof (electrum)'],
        ['GET', '/tx/:txid/merkleblock-proof', 'Merkle block proof (litecoind hex)'],
        ['GET', '/v1/cpfp/:txid', 'CPFP ancestors/descendants + effective fee rate'],
        ['GET', '/v1/tx/:txid/rbf', 'RBF replacement tree / timeline'],
        ['POST', '/tx', 'Broadcast raw tx hex (body) → txid (text)'],
    ],
    'Addresses' => [
        ['GET', '/address/:address', 'chain_stats + mempool_stats'],
        ['GET', '/address/:address/txs', 'Mempool + newest 25 confirmed txs'],
        ['GET', '/address/:address/txs/chain[/:last_txid]', 'Next 25 confirmed (pagination)'],
        ['GET', '/address/:address/txs/mempool', 'Unconfirmed txs'],
        ['GET', '/address/:address/utxo', 'Unspent outputs'],
        ['GET', '/scripthash/:hash[/...]', 'Same set, keyed by scripthash'],
    ],
    'Mempool & fees' => [
        ['GET', '/mempool', 'count, vsize, total_fee, fee_histogram'],
        ['GET', '/mempool/txids', 'All mempool txids'],
        ['GET', '/mempool/recent', 'Recent mempool entries'],
        ['GET', '/fee-estimates', 'Confirm-target → sat/vB map'],
        ['GET', '/v1/fees/recommended', 'mempool.space fee recommendation'],
        ['GET', '/v1/fees/mempool-blocks', 'Projected mempool blocks (mempool.space shape)'],
        ['GET', '/v1/prices', 'Current LTC price ({"time":..,"USD":..,...})'],
        ['GET', '/v1/historical-price[?currency=&timestamp=]', 'Historical price ({prices,exchangeRates})'],
        ['GET', '/v1/replacements', 'Recent mempool RBF chains'],
        ['GET', '/v1/fullrbf/replacements', 'Recent full-RBF replacements'],
        ['GET', '/v1/transaction-times[?txId[]=]', 'First-seen time per txid (batch, ≤50)'],
        ['GET', '/v1/backend-info', 'Instance info (version, lightning flag)'],
        ['GET', '/v1/validate-address/:address', 'Address validation (Core shape)'],
        ['GET', '/v1/difficulty-adjustment', 'Retarget progress & estimate'],
        ['GET', '/v1/difficulty-history', 'Difficulty at recent retarget boundaries'],
        ['GET', '/v1/statistics', 'Mempool + fee-rate history (snapshot store)'],
        ['GET', '/health', 'RPC + electrum reachability, tip height'],
    ],
    'Mining' => [
        ['GET', '/v1/mining/pools', 'Coinbase-tag pool distribution'],
        ['GET', '/v1/mining/pool/:slug', 'Per-pool detail (recent window)'],
        ['GET', '/v1/mining/pool/:slug/hashrate', 'Per-pool daily hashrate series'],
        ['GET', '/v1/mining/pool/:slug/blocks[/:before]', 'Pool blocks, keyset-paged (10/page)'],
        ['GET', '/v1/mining/hashrate[/:period]', 'Estimated hashrate & difficulty (24h…all)'],
        ['GET', '/v1/mining/hashrate/pools/:period', 'Per-pool hashrate share over a period'],
        ['GET', '/v1/mining/blocks/fees/:period', 'Avg fees per block, bucketed'],
        ['GET', '/v1/mining/blocks/rewards/:period', 'Avg reward per block, bucketed'],
        ['GET', '/v1/mining/blocks/fee-rates/:period', 'Fee-rate percentiles per block, bucketed'],
        ['GET', '/v1/mining/blocks/sizes-weights/:period', 'Avg size & weight per block, bucketed'],
        ['GET', '/v1/mining/blocks/timestamp/:timestamp', 'Nearest block to a unix time'],
        ['GET', '/v1/mining/reward-stats/:blockCount', 'Reward / fees / tx over the last N blocks'],
        ['GET', '/v1/mining/difficulty-adjustments/:interval', 'Difficulty-adjustment history (1m|3m|6m|1y|all)'],
        ['GET', '/v1/block/:hash/audit-summary', 'Template-vs-mined block audit + health'],
    ],
    'MWEB' => [
        ['GET', '/mweb/tip', 'Current MWEB HogEx tip'],
        ['GET', '/mweb/blocks[?from=&to=]', 'MWEB blocks in a height range'],
        ['GET', '/mweb/block/:hash', 'MWEB peg activity for one block'],
        ['GET', '/mweb/pegins[?before=&limit=]', 'Peg-in history (keyset paged)'],
        ['GET', '/mweb/pegouts[?before=&limit=]', 'Peg-out history (keyset paged)'],
        ['GET', '/mweb/supply[?limit=]', 'MWEB supply series'],
        ['GET', '/mweb/clusters[?limit=]', 'Reused peg-out destination addresses'],
    ],
];

$groupIcons = [
    'Blocks' => 'box',
    'Transactions' => 'repeat',
    'Addresses' => 'at-sign',
    'Mempool & fees' => 'activity',
    'Mining' => 'cpu',
    'MWEB' => 'shield',
];
?>
<?php lx_head($net, [
    'title' => 'API - Litecoin Explorer',
    'desc'  => 'Litecoin Explorer exposes a drop-in Esplora / mempool.space compatible REST API for Litecoin.',
]); ?>
<h1>Esplora-compatible API</h1>
<div class="card hero"><div class="card-b">
  <div class="hero-eyebrow"><span class="pulse-dot"></span>REST API &middot; Esplora-compatible</div>
  <p>Litecoin Explorer serves the same REST API as
  <a class="ext" href="https://github.com/Blockstream/esplora/blob/master/API.md" target="_blank" rel="noopener">Blockstream Esplora</a>
  / <a class="ext" href="https://mempool.space/docs/api/rest" target="_blank" rel="noopener">mempool.space</a>,
  so existing Litecoin wallets and tools are a drop-in. The base URL is:</p>
  <table class="kv">
    <tr><th>Litecoin</th><td class="mono break"><?= h($base) ?>/api</td></tr>
  </table>
  <p class="muted sub">Example: <span class="mono"><?= h($base) ?>/api/blocks/tip/height</span></p>
  <p class="muted sub">Works with wallets, self-hosted apps, bots, and dashboards that speak Esplora or mempool.space. Point them here; no key, no sign-up, permissive CORS on every endpoint.</p>
</div></div>

<?php foreach ($groups as $title => $rows): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon($groupIcons[$title] ?? 'box') ?><?= h($title) ?></span></div>
  <div class="card-b nopad">
    <div class="table-wrap">
    <table>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="badge <?= $r[0] === 'POST' ? 'warn' : 'soft' ?>"><?= h($r[0]) ?></span></td>
          <td class="mono break"><?= h($r[1]) ?></td>
          <td class="muted"><?= h($r[2]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endforeach; ?>

<div class="card"><div class="card-b">
  <h3>Point your wallet at it</h3>
  <p class="muted">Any Esplora / mempool.space-compatible Litecoin wallet or tool can use this API as a
  drop-in backend. Set its Esplora endpoint to:</p>
  <table class="kv">
    <tr><th>Litecoin</th><td class="mono break"><?= h($base) ?>/api</td></tr>
  </table>
  <p class="muted sub">Notes: amounts are integer satoshis; <span class="mono">/tx/:txid/hex</span>,
  <span class="mono">/blocks/tip/height</span> and <span class="mono">POST /tx</span> return
  <span class="mono">text/plain</span>; all endpoints send permissive CORS.</p>
  <p class="muted sub mt-2"><b>Live WebSocket</b> (mempool.space-compatible) at
  <span class="mono"><?= h(str_replace(['https://', 'http://'], 'wss://', $base)) ?>/api/v1/ws</span>.
  Send <span class="mono">{"action":"want","data":["blocks","stats","mempool-blocks","live-2h-chart"]}</span> for live blocks,
  mempool stats, fees and projected blocks; <span class="mono">{"track-tx":"&lt;txid&gt;"}</span> /
  <span class="mono">{"track-address":"&lt;addr&gt;"}</span> for a single tx/address, or the batch forms
  <span class="mono">{"track-txs":[…]}</span>, <span class="mono">{"track-addresses":[…]}</span>,
  <span class="mono">{"track-mempool-txids":true}</span> (added/removed txid deltas) and
  <span class="mono">{"track-rbf":"all"}</span>.</p>
</div></div>
<?php lx_foot($net); ?>
