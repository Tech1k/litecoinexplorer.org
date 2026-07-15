<?php
/**
 * Tools: broadcast, decode raw tx, decode script, inspect PSBT, encode
 * OP_RETURN, verify a signed message. $net in scope; $GLOBALS['tool_action'].
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$action = $GLOBALS['tool_action'] ?? null;
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$base = ts_u($net);
$rawtx = trim($_POST['rawtx'] ?? '');

$bcTxid = $bcError = $decoded = $decError = null;
$testRes = $testErr = null;
$scriptRes = $scriptErr = null;
$psbtRes = $psbtErr = null;
$orRes = $orErr = null;
$vm = null; $vmDone = false;

// Node-hitting POST actions (broadcast/test/decode forward hex to litecoind for a full
// parse). Throttle per IP so the tools page can't be scripted into an amplification flood.
if ($isPost && in_array($action, ['broadcast', 'test', 'decode'], true)
    && function_exists('ts_rate_limit') && !ts_rate_limit('tools_tx', 30, 60)) {
    http_response_code(429);
    $isPost = false;   // skip the node calls; surface a friendly notice instead
    $rlMsg = 'Rate limit reached. Please wait a minute and try again.';
    if ($action === 'broadcast') { $bcError = $rlMsg; }
    elseif ($action === 'test')  { $testErr = $rlMsg; }
    else                         { $decError = $rlMsg; }
}

if ($isPost && $action === 'broadcast') {
    if ($rawtx === '') {
        $bcError = 'Paste a signed raw transaction (hex) first.';
    } else {
        [$bcTxid, $bcError] = ts_broadcast($net, $rawtx);
        if (!$bcTxid && !$bcError) { $bcError = 'Broadcast failed.'; }
    }
}
if ($isPost && $action === 'test') {
    if ($rawtx === '') {
        $testErr = 'Paste a signed raw transaction (hex) first.';
    } else {
        $testRes = ts_test_mempool_accept($net, $rawtx, trim($_POST['maxfee'] ?? ''));
        if (isset($testRes['error'])) { $testErr = $testRes['error']; $testRes = null; }
    }
}
if ($isPost && $action === 'decode') {
    if ($rawtx === '') {
        $decError = 'Paste a raw transaction (hex) first.';
    } else {
        $decoded = ts_decode_rawtx($net, $rawtx);
        if ($decoded === null) { $decError = 'Could not decode. That is not valid transaction hex.'; }
    }
}
if ($isPost && $action === 'script') {
    $scriptRes = ts_decode_script($net, $_POST['hex'] ?? '');
    if ($scriptRes === null) { $scriptErr = 'Enter a valid script hex.'; }
}
if ($isPost && $action === 'psbt') {
    $psbtRes = ts_decode_psbt($net, $_POST['psbt'] ?? '');
    if ($psbtRes === null) { $psbtErr = 'Could not decode. Paste a base64 PSBT.'; }
}
if ($isPost && $action === 'opreturn') {
    $orRes = ts_encode_op_return($_POST['data'] ?? '', ($_POST['fmt'] ?? 'text') === 'hex');
    if ($orRes === null) { $orErr = 'Enter some data to encode.'; }
    elseif (isset($orRes['error'])) { $orErr = $orRes['error']; $orRes = null; }
}
if ($isPost && $action === 'verifymsg') {
    $vm = ts_verify_message($net, trim($_POST['addr'] ?? ''), trim($_POST['sig'] ?? ''), $_POST['msg'] ?? '');
    $vmDone = true;
}

ts_head($net, ['title' => 'Tools - ' . $net['label'] . ' Explorer']);
?>
<h1>Tools</h1>

<div class="section-h">Transactions</div>

<div class="card">
  <div class="card-h">Broadcast transaction</div>
  <div class="card-b">
    <p class="muted sub">Push a signed raw transaction (hex) to <?= h($net['label']) ?> via <span class="mono">POST /tx</span>.</p>
    <form method="post" action="<?= h($base) ?>/broadcast">
      <textarea name="rawtx" rows="3" placeholder="0200000001..." spellcheck="false" autocomplete="off"><?= $action === 'broadcast' ? h($rawtx) : '' ?></textarea>
      <div class="row mt-2"><button class="btn" type="submit">Broadcast</button></div>
    </form>
    <?php if ($bcTxid): ?>
      <div class="note ok break">Broadcast accepted. Txid:
        <a class="addr" href="<?= h(ts_tx_href($net, $bcTxid)) ?>"><?= h($bcTxid) ?></a></div>
    <?php elseif ($bcError): ?><div class="note bad break"><?= h($bcError) ?></div><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h">Test transaction <span class="sub">dry-run</span></div>
  <div class="card-b">
    <p class="muted sub">Check whether a signed raw transaction would be accepted into the mempool, via <span class="mono">testmempoolaccept</span>, <b>without</b> broadcasting it.</p>
    <form method="post" action="<?= h($base) ?>/test">
      <textarea name="rawtx" rows="3" placeholder="0200000001..." spellcheck="false" autocomplete="off"><?= $action === 'test' ? h($rawtx) : '' ?></textarea>
      <div class="row mt-2">
        <input type="text" name="maxfee" inputmode="decimal" placeholder="max fee rate sat/vB (optional)" style="max-width:230px" value="<?= $action === 'test' ? h($_POST['maxfee'] ?? '') : '' ?>" spellcheck="false" autocomplete="off">
        <button class="btn" type="submit">Test</button>
      </div>
    </form>
    <?php if ($testRes !== null): ?>
      <?php if ($testRes['allowed']): ?>
      <div class="note ok break">Would be accepted.<?php if ($testRes['vsize'] !== null): ?> <span class="muted">vsize <?= commas($testRes['vsize']) ?> vB<?php if ($testRes['fee'] !== null): ?> &middot; fee <?= h(ts_coin((int) $testRes['fee'])) ?> <?= h($net['unit']) ?><?php endif; ?></span><?php endif; ?><?php if ($testRes['txid'] !== ''): ?><div class="mono sub mt-2"><?= h($testRes['txid']) ?></div><?php endif; ?></div>
      <?php else: ?>
      <div class="note bad break">Would be rejected<?= $testRes['reject'] !== '' ? ': ' . h($testRes['reject']) : '.' ?></div>
      <?php endif; ?>
    <?php elseif ($testErr): ?><div class="note bad break"><?= h($testErr) ?></div><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h">Decode transaction</div>
  <div class="card-b">
    <p class="muted sub">Decode raw transaction hex without broadcasting. Known inputs are resolved to show amounts and fee.</p>
    <form method="post" action="<?= h($base) ?>/decode">
      <textarea name="rawtx" rows="3" placeholder="0200000001..." spellcheck="false" autocomplete="off"><?= $action === 'decode' ? h($rawtx) : '' ?></textarea>
      <div class="row mt-2"><button class="btn" type="submit">Decode</button></div>
    </form>
    <?php if ($decError): ?><div class="note bad"><?= h($decError) ?></div><?php endif; ?>
  </div>
</div>

<?php if ($decoded): ?>
  <?php
    $inKnown = true;
    foreach ($decoded['vin'] as $vi) { if (!$vi['is_coinbase'] && $vi['prevout'] === null) { $inKnown = false; break; } }
    $outSum = 0; foreach ($decoded['vout'] as $vo) { $outSum += ($vo['value'] ?? 0); }
  ?>
  <div class="card">
    <div class="card-h">Decoded <span class="sub"><?= h(shorten($decoded['txid'])) ?></span></div>
    <div class="card-b">
      <div class="break mono addr-lg"><?= h($decoded['txid']) ?></div>
      <table class="kv mt-3">
        <tr><th>Version / locktime</th><td><?= (int) $decoded['version'] ?> / <?= commas($decoded['locktime']) ?></td></tr>
        <tr><th>Size / weight</th><td><?= commas($decoded['size']) ?> B · <?= commas($decoded['weight']) ?> WU</td></tr>
        <tr><th>Total output</th><td><?= h(ts_amount($net, $outSum)) ?></td></tr>
        <?php if ($inKnown && $decoded['fee']): ?><tr><th>Fee</th><td><?= h(ts_amount($net, $decoded['fee'])) ?></td></tr><?php endif; ?>
      </table>
    </div>
  </div>
  <div class="txio">
    <div class="card"><div class="card-h">Inputs <span class="sub"><?= count($decoded['vin']) ?></span></div>
      <div class="card-b nopad">
        <?php foreach ($decoded['vin'] as $vi): ?>
          <div class="io-row">
            <?php if ($vi['is_coinbase']): ?><div class="io-addr"><span class="badge">Coinbase</span></div>
            <?php else: ?>
              <div class="io-addr"><?= ts_addr_cell($net, $vi['prevout']['scriptpubkey_address'] ?? null, $vi['prevout']['scriptpubkey_type'] ?? '') ?></div>
              <div class="io-meta"><a class="muted mono" href="<?= h(ts_tx_href($net, $vi['txid'])) ?>"><?= h(shorten($vi['txid'], 8, 6)) ?>:<?= (int) $vi['vout'] ?></a>
                <span class="io-val"><?= $vi['prevout'] ? h(ts_coin($vi['prevout']['value'])) : '?' ?></span></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card"><div class="card-h">Outputs <span class="sub"><?= count($decoded['vout']) ?></span></div>
      <div class="card-b nopad">
        <?php foreach ($decoded['vout'] as $n => $vo): ?>
          <div class="io-row">
            <div class="io-addr"><?= ts_addr_cell($net, $vo['scriptpubkey_address'] ?? null, $vo['scriptpubkey_type'] ?? '') ?>
              <span class="badge soft"><?= h(ts_spk_label($vo['scriptpubkey_type'] ?? 'unknown')) ?></span></div>
            <div class="io-meta"><span class="muted mono">#<?= $n ?></span><span class="io-val"><?= h(ts_coin($vo['value'] ?? 0)) ?></span></div>
            <?php if (($vo['scriptpubkey_type'] ?? '') === 'op_return'): ?>
              <?php foreach (ts_parse_op_return($vo['scriptpubkey'] ?? '') as $p): ?>
                <div class="io-script break"><?php if ($p['text'] !== null): ?><span class="mono">"<?= h($p['text']) ?>"</span> <?php endif; ?><span class="mono muted"><?= h($p['hex']) ?></span></div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="section-h">Scripts &amp; PSBTs</div>

<div class="card">
  <div class="card-h">Decode script</div>
  <div class="card-b">
    <p class="muted sub">Disassemble a scriptPubKey / redeemScript / witnessScript and see its type and the p2sh/p2wsh addresses it hashes to on this network.</p>
    <form method="post" action="<?= h($base) ?>/script">
      <textarea name="hex" rows="2" placeholder="76a914...88ac" spellcheck="false" autocomplete="off"><?= $action === 'script' ? h($_POST['hex'] ?? '') : '' ?></textarea>
      <div class="row mt-2"><button class="btn" type="submit">Decode script</button></div>
    </form>
    <?php if ($scriptErr): ?><div class="note bad"><?= h($scriptErr) ?></div>
    <?php elseif ($scriptRes): ?>
      <table class="kv mt-3">
        <tr><th>Type</th><td><span class="badge soft"><?= h($scriptRes['type'] ?? 'unknown') ?></span></td></tr>
        <tr><th>ASM</th><td class="mono break"><?= h($scriptRes['asm'] ?? '') ?></td></tr>
        <?php $sa = $scriptRes['address'] ?? ($scriptRes['addresses'][0] ?? null); if ($sa): ?>
          <tr><th>Address</th><td class="mono break"><a href="<?= h(ts_addr_href($net, $sa)) ?>"><?= h($sa) ?></a></td></tr>
        <?php endif; ?>
        <?php if (!empty($scriptRes['p2sh'])): ?><tr><th>Wrapped in P2SH</th><td class="mono break"><a href="<?= h(ts_addr_href($net, $scriptRes['p2sh'])) ?>"><?= h($scriptRes['p2sh']) ?></a></td></tr><?php endif; ?>
        <?php if (!empty($scriptRes['segwit']['address'])): ?><tr><th>Wrapped in P2WSH</th><td class="mono break"><a href="<?= h(ts_addr_href($net, $scriptRes['segwit']['address'])) ?>"><?= h($scriptRes['segwit']['address']) ?></a></td></tr><?php endif; ?>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h">Inspect PSBT</div>
  <div class="card-b">
    <p class="muted sub">Decode a base64 PSBT: per-input prevout amounts, fee, signing progress, and whether it is finalized.</p>
    <form method="post" action="<?= h($base) ?>/psbt">
      <textarea name="psbt" rows="3" placeholder="cHNidP8B..." spellcheck="false" autocomplete="off"><?= $action === 'psbt' ? h($_POST['psbt'] ?? '') : '' ?></textarea>
      <div class="row mt-2"><button class="btn" type="submit">Inspect</button></div>
    </form>
    <?php if ($psbtErr): ?><div class="note bad"><?= h($psbtErr) ?></div>
    <?php elseif ($psbtRes): ?>
      <?php $d = $psbtRes['decoded']; $a = $psbtRes['analysis']; $ptx = $d['tx'] ?? []; ?>
      <table class="kv mt-3">
        <tr><th>Inputs / outputs</th><td><?= count($ptx['vin'] ?? []) ?> / <?= count($ptx['vout'] ?? []) ?></td></tr>
        <?php if (isset($d['fee'])): ?><tr><th>Fee</th><td><?= h(ts_amount($net, coin_to_sat($d['fee']))) ?></td></tr><?php endif; ?>
        <?php if ($a && isset($a['next'])): ?><tr><th>Next step</th><td><span class="badge <?= $a['next'] === 'extractor' ? 'ok' : 'warn' ?>"><?= h($a['next']) ?></span> <?= $a['next'] === 'extractor' ? '(fully signed)' : '' ?></td></tr><?php endif; ?>
        <?php if ($a && !empty($a['error'])): ?><tr><th>Error</th><td class="bad break"><?= h($a['error']) ?></td></tr><?php endif; ?>
      </table>
      <div class="mono break faint mt-2">
        <?php foreach (($d['inputs'] ?? []) as $i => $pin): ?>
          <?php
            $amt = $pin['witness_utxo']['amount'] ?? ($pin['non_witness_utxo']['vout'][$ptx['vin'][$i]['vout'] ?? 0]['value'] ?? null);
            $sigs = isset($pin['partial_signatures']) ? count($pin['partial_signatures']) : 0;
            $fin = !empty($pin['final_scriptSig']) || !empty($pin['final_scriptwitness']);
          ?>
          in #<?= $i ?>: <?= $amt !== null ? h(ts_coin(coin_to_sat($amt))) . ' ' . h($net['unit']) : 'utxo unknown' ?>,
          <?= $fin ? 'finalized' : ($sigs . ' sig' . ($sigs === 1 ? '' : 's')) ?><br>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="section-h">Encoding &amp; messages</div>

<div class="card">
  <div class="card-h">Encode OP_RETURN</div>
  <div class="card-b">
    <p class="muted sub">Turn text or hex into an OP_RETURN scriptPubKey to embed in a transaction.</p>
    <form method="post" action="<?= h($base) ?>/opreturn">
      <textarea name="data" rows="2" placeholder="hello world" spellcheck="false"><?= $action === 'opreturn' ? h($_POST['data'] ?? '') : '' ?></textarea>
      <div class="row mt-2">
        <label class="muted sub"><input type="radio" name="fmt" value="text" <?= ($_POST['fmt'] ?? 'text') !== 'hex' ? 'checked' : '' ?>> text</label>
        <label class="muted sub"><input type="radio" name="fmt" value="hex" <?= ($_POST['fmt'] ?? '') === 'hex' ? 'checked' : '' ?>> hex</label>
        <button class="btn" type="submit">Encode</button>
      </div>
    </form>
    <?php if ($orErr): ?><div class="note bad"><?= h($orErr) ?></div>
    <?php elseif ($orRes): ?>
      <table class="kv mt-3">
        <tr><th>scriptPubKey</th><td class="mono break"><?= h($orRes['scriptpubkey']) ?> <button class="btn ghost sm copybtn" type="button" data-copy="<?= h($orRes['scriptpubkey']) ?>">Copy</button></td></tr>
        <tr><th>Data</th><td><?= commas($orRes['data_len']) ?> bytes</td></tr>
      </table>
      <?php if ($orRes['over_standard']): ?><div class="note bad mt-2">Over 80 bytes: most nodes will not relay this as standard.</div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h">Verify signed message</div>
  <div class="card-b">
    <p class="muted sub">Verify a message signed by a legacy (m/n...) address. BIP-322 (segwit/taproot) is not supported by the node.</p>
    <form method="post" action="<?= h($base) ?>/verifymsg">
      <label class="fld">Address</label>
      <input type="text" name="addr" value="<?= $action === 'verifymsg' ? h($_POST['addr'] ?? '') : '' ?>" spellcheck="false" autocomplete="off">
      <label class="fld mt-2">Signature (base64)</label>
      <input type="text" name="sig" value="<?= $action === 'verifymsg' ? h($_POST['sig'] ?? '') : '' ?>" spellcheck="false" autocomplete="off">
      <label class="fld mt-2">Message</label>
      <textarea name="msg" rows="2"><?= $action === 'verifymsg' ? h($_POST['msg'] ?? '') : '' ?></textarea>
      <div class="row mt-2"><button class="btn" type="submit">Verify</button></div>
    </form>
    <?php if ($vmDone): ?>
      <?php if ($vm === true): ?><div class="note ok">Valid signature.</div>
      <?php elseif ($vm === false): ?><div class="note bad">Invalid signature.</div>
      <?php else: ?><div class="note bad">Could not verify (bad address or signature format).</div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php ts_foot($net); ?>
