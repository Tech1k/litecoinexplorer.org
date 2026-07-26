<?php
/**
 * Extended public key (Litecoin Ltub/Mtub + coin-agnostic xpub/ypub/zpub) lookup:
 * derive the first receive (m/0/i) and change (m/1/i) addresses from a watch-only
 * account key and show their balances/activity. $net in scope; $GLOBALS['xpub'] is
 * the key. Derivation is in lib/bip32.php (secp256k1/BIP32, GMP-gated, vector-pinned).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$xpub = (string) $GLOBALS['xpub'];
$base = lx_u($net);

if (!lx_bip32_available()) {
    lx_head($net, ['title' => 'Extended key - ' . $net['label']]);
    echo '<h1>Extended public key</h1><div class="card"><div class="card-b"><p class="muted">xpub'
       . ' address derivation needs the PHP <code>gmp</code> extension, which is not installed on this'
       . ' server.</p></div></div>';
    lx_foot($net);
    return;
}

$parsed = lx_xpub_parse($xpub);
if ($parsed === null) {
    http_response_code(404);
    $GLOBALS['search_query'] = $xpub;
    require __DIR__ . '/notfound.php';
    return;
}

// Guard against deriving under the wrong network's params, which would silently
// report a funded wallet as empty. This explorer indexes Litecoin mainnet, so a
// testnet key (ttub/tpub/upub/vpub) is rejected, as is a key naming a different
// coin; the coin-agnostic mainnet prefixes (xpub/ypub/zpub) are accepted and
// derived with Litecoin params, since Litecoin wallets commonly export them.
if ($parsed['net'] === 'testnet' || ($parsed['coin'] !== null && $parsed['coin'] !== $net['coin'])) {
    lx_head($net, ['title' => 'Extended key - ' . $net['label']]);
    echo '<div class="section-h">' . h($net['label']) . ' &middot; Extended key</div><h1>Extended public key</h1>'
       . '<div class="card"><div class="card-b"><p class="muted">';
    if ($parsed['net'] === 'testnet') {
        echo 'This looks like a <b>testnet</b> extended key (<code>' . h(substr($xpub, 0, 4)) . '</code>). '
           . 'Litecoin Explorer indexes Litecoin, so its addresses won\'t appear here. '
           . 'Use a Litecoin key (<code>Ltub</code>, <code>Mtub</code> or <code>xpub</code>).';
    } else {
        echo 'This looks like a <b>' . h(strtoupper((string) $parsed['coin'])) . '</b> extended key, but this is the <b>'
           . h($net['label']) . '</b> explorer. Use a Litecoin key to derive the right addresses.';
    }
    echo '</p></div></div>';
    lx_foot($net);
    return;
}

// Address type. The SLIP-132 prefix only pins the type for ypub/zpub/Mtub;
// a generic xpub/Ltub is ambiguous (many wallets export it even for segwit
// accounts), so we default those to native segwit and let the visitor switch
// with ?type=. lx_pub_to_address can encode all three.
$types = ['p2wpkh' => 'Native SegWit', 'p2sh' => 'Nested SegWit', 'p2pkh' => 'Legacy'];
$qtype = isset($_GET['type']) && isset($types[$_GET['type']]) ? $_GET['type'] : null;
$chosen = $qtype !== null ? $qtype : ($parsed['type'] === 'p2pkh' ? 'p2wpkh' : $parsed['type']);

// If the Electrum index is unavailable, every derived-address balance would resolve to a
// false zero - show a degraded page instead of a "wallet is empty" that isn't true.
if (!lx_electrum_reachable($net)) {
    http_response_code(503);
    if (!headers_sent()) { header('Retry-After: 30'); header('Cache-Control: no-store'); }
    lx_head($net, ['title' => 'Extended key - ' . $net['label']]);
    echo '<h1>Extended public key</h1><div class="card"><div class="card-b">'
       . '<div class="break mono">' . h($xpub) . '</div>'
       . '<div class="empty mt-3">' . lx_icon('clock')
       . '<span>The address index is temporarily unavailable. The Electrum server is unreachable or resyncing, so derived-address balances aren&rsquo;t available. Try again shortly.</span></div>'
       . '</div></div>';
    lx_foot($net);
    return;
}

// Derive + look up balances (each address is an Electrum round-trip), cached. $failed is
// set by reference when a per-address lookup returns null (electrs blipped mid-derivation),
// so a transient failure is distinguished from a genuinely underivable key below.
$failed = false;
$data = cache_remember('xpub:' . $net['slug'] . ':' . $chosen . ':' . $xpub, 60, function () use ($net, $xpub, $chosen, &$failed) {
    $d = lx_xpub_addresses($net, $xpub, 10, $chosen);
    if ($d === null) {
        return null;
    }
    $bal = 0; $tx = 0; $used = 0;
    foreach (['receive', 'change'] as $ch) {
        foreach ($d[$ch] as $k => $a) {
            $ab = 0; $at = 0;
            try {
                $st = lx_address_stats($net, $a['address']);
                if ($st === null) {
                    $failed = true;   // electrs unavailable (null) != a genuinely empty address (0)
                } elseif ($st) {
                    $c = $st['chain_stats']; $m = $st['mempool_stats'];
                    $ab = ((int) $c['funded_txo_sum'] - (int) $c['spent_txo_sum'])
                        + ((int) $m['funded_txo_sum'] - (int) $m['spent_txo_sum']);
                    $at = (int) $c['tx_count'] + (int) $m['tx_count'];
                }
            } catch (Throwable $e) {
                $failed = true;
            }
            $d[$ch][$k]['balance'] = $ab;
            $d[$ch][$k]['txs'] = $at;
            $bal += $ab; $tx += $at;
            if ($at > 0) { $used++; }
        }
    }
    if ($failed) {
        return null;   // don't cache a false "empty wallet" - retry on the next request
    }
    $d['total_balance'] = $bal; $d['total_tx'] = $tx; $d['used'] = $used;
    return $d;
});

if ($data === null) {
    if ($failed) {
        // A lookup failed after the reachability probe passed: degrade, don't show a false empty wallet.
        http_response_code(503);
        if (!headers_sent()) { header('Retry-After: 30'); header('Cache-Control: no-store'); }
        lx_head($net, ['title' => 'Extended key - ' . $net['label']]);
        echo '<h1>Extended public key</h1><div class="card"><div class="card-b">'
           . '<div class="break mono">' . h($xpub) . '</div>'
           . '<div class="empty mt-3">' . lx_icon('clock')
           . '<span>The address index is temporarily unavailable. The Electrum server is unreachable or resyncing, so derived-address balances aren&rsquo;t available. Try again shortly.</span></div>'
           . '</div></div>';
        lx_foot($net);
        return;
    }
    lx_head($net, ['title' => 'Extended key - ' . $net['label']]);
    echo '<h1>Extended public key</h1><div class="card"><div class="card-b"><p class="muted">Could not derive addresses from this key.</p></div></div>';
    lx_foot($net);
    return;
}

// no-store: derived from a key the visitor supplied.
if (!headers_sent()) {
    header('Cache-Control: private, no-store');
}
lx_head($net, ['title' => 'Extended key ' . shorten($xpub) . ' - ' . $net['label']]);
?>
<div class="section-h"><?= h($net['label']) ?> &middot; Extended key</div>
<h1>Extended public key</h1>

<div class="card">
  <div class="card-b">
    <table class="kv">
      <tr><th>Key</th><td class="mono break"><?= h($xpub) ?> <button class="btn ghost sm" type="button" data-copy="<?= h($xpub) ?>" aria-label="Copy extended key">Copy</button></td></tr>
      <tr><th>Address type</th><td><span style="display:inline-flex;gap:6px;flex-wrap:wrap">
        <?php foreach ($types as $tk => $tlabel): ?>
          <?php if ($tk === $chosen): ?><span class="badge soft"><?= h($tlabel) ?></span><?php else: ?><a class="badge" style="text-decoration:none" href="<?= h($base) ?>/xpub/<?= h(rawurlencode($xpub)) ?>?type=<?= h($tk) ?>"><?= h($tlabel) ?></a><?php endif; ?>
        <?php endforeach; ?>
      </span></td></tr>
      <tr><th>Balance</th><td><b><?= h(lx_amount($net, (int) $data['total_balance'])) ?></b> <span class="muted">across the scanned addresses</span></td></tr>
      <tr><th>Activity</th><td><?= commas($data['used']) ?> used · <?= commas($data['total_tx']) ?> transaction<?= $data['total_tx'] === 1 ? '' : 's' ?></td></tr>
    </table>
    <?php if ($qtype === null && $parsed['type'] === 'p2pkh'): ?>
    <p class="pnote"><?= lx_icon('info') ?><span>This key's prefix (<code><?= h(substr($xpub, 0, 4)) ?></code>) doesn't specify a script type, so <b>Native SegWit</b> is shown by default. Switch above if your wallet uses Nested SegWit or Legacy.</span></p>
    <?php endif; ?>
    <p class="pnote"><?= lx_icon('info') ?><span>Watch-only. The first 10 receive (m/0/i) and 10 change (m/1/i) addresses are derived and looked up; a wallet using a larger gap limit may have activity beyond this window.</span></p>
  </div>
</div>

<?php foreach (['receive' => 'Receive addresses', 'change' => 'Change addresses'] as $ch => $heading): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon($ch === 'receive' ? 'log-in' : 'repeat') ?><?= h($heading) ?></span> <span class="sub">m/<?= $ch === 'receive' ? 0 : 1 ?>/i</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>#</th><th>Address</th><th class="amt">Balance</th><th class="amt">Txs</th></tr></thead>
      <tbody>
      <?php foreach ($data[$ch] as $a): ?>
        <tr<?= $a['txs'] > 0 ? '' : ' class="muted"' ?>>
          <td class="mono"><?= (int) $a['index'] ?></td>
          <td class="mono break"><a class="addr" href="<?= h($base) ?>/address/<?= h(rawurlencode($a['address'])) ?>"><?= h($a['address']) ?></a></td>
          <td class="amt"><?= $a['balance'] > 0 ? h(lx_coin((int) $a['balance'])) : '<span class="muted">0</span>' ?></td>
          <td class="amt"><?= $a['txs'] > 0 ? commas($a['txs']) : '<span class="muted">-</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$data[$ch]): ?><tr><td colspan="4"><div class="empty"><?= lx_icon('inbox') ?><span>No addresses derived.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php lx_foot($net); ?>
