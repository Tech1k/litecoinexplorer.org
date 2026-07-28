<?php
/**
 * MWEB section for Litecoin. Live status + recent blocks driven entirely by
 * litecoind RPC (no analytics DB). $net in scope.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Fetch node/index/overlay data BEFORE lx_head so a litecoind outage throws into a
// clean 503 - lx_head flushes the page head, after which set_exception_handler can no
// longer set the status (every other view fetches before lx_head for this reason).
$mwebOn = lx_mweb_enabled($net);
if ($mwebOn) {
    $base   = lx_u($net);
    $idx    = lx_mweb_index_ready($net);
    $st     = lx_mweb_active($net);
    $act    = lx_mweb_activation($net);
    $recent = lx_mweb_recent($net);
    // Newest two blocks that carry supply give the absolute supply + last-block delta.
    $supply = null; $supplyPrev = null;
    foreach ($recent as $b) {
        if ($b['supply_sat'] === null) { continue; }
        if ($supply === null) { $supply = (int) $b['supply_sat']; }
        elseif ($supplyPrev === null) { $supplyPrev = (int) $b['supply_sat']; break; }
    }
    $supplyDelta = ($supply !== null && $supplyPrev !== null) ? $supply - $supplyPrev : null;
    // Cumulative kernel / output counts from the MWEB header (best-effort; null
    // when the node build doesn't expose the getblock-2 .mweb object).
    $kern = lx_mweb_kernels($net);
    $tot  = $idx ? lx_mweb_peg_totals($net) : null;   // all-time peg economics (index only)
    // MWEBscan analysis overlay (best-effort; dormant/unreachable -> boundary-only).
    $mwOn      = lx_mwebscan_enabled($net);
    $mwStats   = $mwOn ? lx_mwebscan_api($net, 'stats', [], 120) : null;
    $mwLinks   = $mwOn ? lx_mwebscan_api($net, 'links', ['limit' => '8', 'min_confidence' => '0.7'], 120) : null;
    $mwPrivAmt = (isset($_GET['privacy_amount']) && is_numeric($_GET['privacy_amount']) && (float) $_GET['privacy_amount'] > 0) ? (string) (float) $_GET['privacy_amount'] : '';
    $mwPriv    = ($mwOn && $mwPrivAmt !== '') ? lx_mwebscan_api($net, 'privacy', ['amount' => $mwPrivAmt], 300) : null;
}
lx_head($net, ['title' => 'MWEB - ' . $net['label'] . ' Explorer']);
?>
<h1>MWEB</h1>
<?php if (!$mwebOn): ?>
<div class="card"><div class="card-b">
  <p>MWEB (MimbleWimble Extension Blocks) support is not enabled on this deployment.</p>
  <p class="muted">Set <code>'mweb' =&gt; ['enabled' =&gt; true]</code> for this network in
  <code>config.php</code> to turn it on.</p>
</div></div>
<?php else: ?>
<?php if (!empty($net['mweb']['index']['enabled']) && !$idx): ?>
<div class="note">The MWEB peg index is catching up; the peg history and charts below may be incomplete until it reaches the tip.</div>
<?php endif; ?>

<div class="card brand-top" style="--brand:#6c5ce7">
  <div class="card-h"><span><?= lx_icon('mweb') ?>MWEB shielded pool</span> <span class="hero-eyebrow"><span class="pulse-dot<?= $st['active'] ? '' : ' off' ?>"></span><?= $st['active'] ? 'active' : 'inactive' ?></span></div>
  <div class="card-b">
    <div class="stat-grid">
      <?php if ($supply !== null): ?>
      <div class="stat"><div class="muted sub"><?= lx_icon('lock') ?>Shielded supply</div><div class="big-num sm"><?= h(lx_coin_compact((float) $supply)) ?> <span class="muted" style="font-size:.6em"><?= h($net['unit']) ?></span></div><div class="muted sub"><?php if ($supplyDelta !== null && $supplyDelta !== 0): ?><span class="<?= $supplyDelta > 0 ? 'pos' : 'neg' ?>"><?= $supplyDelta > 0 ? '+' : '&minus;' ?><?= h(lx_coin(abs($supplyDelta))) ?></span> last block<?php else: ?>confidential in MWEB<?php endif; ?></div></div>
      <?php endif; ?>
      <?php if ($tot): $netShield = $tot['pegin_total_sat'] - $tot['pegout_total_sat']; ?>
      <div class="stat"><div class="muted sub"><?= lx_icon('repeat') ?>Net shielded</div><div class="big-num sm <?= $netShield >= 0 ? 'pos' : 'neg' ?>"><?= ($netShield < 0 ? '&minus;' : '') . h(lx_coin_compact((float) abs($netShield))) ?></div><div class="muted sub">pegged in &minus; out</div></div>
      <div class="stat"><div class="muted sub"><?= lx_icon('log-in') ?>Total pegged in</div><div class="big-num sm"><?= h(lx_coin_compact((float) $tot['pegin_total_sat'])) ?></div><div class="muted sub"><?= commas($tot['pegin_count']) ?> peg-ins</div></div>
      <div class="stat"><div class="muted sub"><?= lx_icon('log-out') ?>Total pegged out</div><div class="big-num sm"><?= h(lx_coin_compact((float) $tot['pegout_total_sat'])) ?></div><div class="muted sub"><?= commas($tot['pegout_count']) ?> peg-outs</div></div>
      <?php endif; ?>
      <?php if ($kern !== null && $kern['outputs'] !== null): ?>
      <div class="stat"><div class="muted sub"><?= lx_icon('layers') ?>Output set</div><div class="big-num sm"><?= h(lx_num_compact((float) $kern['outputs'])) ?></div><div class="muted sub">confidential outputs</div></div>
      <?php endif; ?>
      <?php if ($kern !== null && $kern['kernels'] > 0): ?>
      <div class="stat"><div class="muted sub"><?= lx_icon('box') ?>Kernels</div><div class="big-num sm"><?= commas($kern['kernels']) ?></div><div class="muted sub">in the latest block</div></div>
      <?php endif; ?>
      <div class="stat"><div class="muted sub"><?= lx_icon('activity') ?>Chain tip</div><div class="big-num sm"><a href="<?= h($base) ?>/block-height/<?= (int) $st['height'] ?>">#<?= commas($st['height']) ?></a></div><div class="muted sub">MWEB since <?= commas($act) ?></div></div>
    </div>
    <?php if (!$idx): ?>
    <p class="pnote"><?= lx_icon('info') ?><span>The supply chart, all-time peg economics and peg history come from the local peg index, which is <b>not enabled on this deployment</b>. Turn on <code>mweb.index</code> in <code>config.php</code> (seed once with <code>tools/mweb-seed.php</code>, then keep it fresh with <code>tools/mweb-index.php</code>) to fill them in.</span></p>
    <?php else: ?>
    <p class="pnote"><?= lx_icon('eye-off') ?><span>MWEB amounts are confidential; only peg-ins (public &rarr; MWEB) and peg-outs (MWEB &rarr; public, carried by each block's HogEx) have visible amounts on the canonical chain. The shielded supply is the anonymity set every confidential output blends into.</span></p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-b" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <img src="/assets/mwebscan.png" alt="MWEBscan" style="height:46px;width:46px;border-radius:11px;flex:none">
    <div style="flex:1;min-width:210px">
      <div style="font-weight:600;color:var(--text)">MWEBscan: MWEB chain analysis &amp; privacy scoring</div>
      <p class="muted sub" style="margin:.3rem 0 0;text-align:left">Litecoin Explorer maps the public peg boundary (peg-ins, peg-outs and supply) from its own node. Its sister project <b>MWEBscan</b> adds the analysis layer on top: round-trip linking, privacy scoring and entity attribution.</p>
    </div>
    <a class="btn" href="<?= h(lx_mwebscan_site($net)) ?>" target="_blank" rel="noopener" style="flex:none"><?= lx_icon('mweb') ?>Open MWEBscan</a>
  </div>
</div>

<?php if ($mwOn): ?>
<?php if (is_array($mwStats) && isset($mwStats['stats'])): $S = $mwStats['stats']; ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('shield') ?>MWEB analysis</span> <span class="sub">via MWEBscan<?php $fr = lx_mwebscan_freshness($mwStats); if ($fr !== ''): ?> &middot; <?= h($fr) ?><?php endif; ?></span></div>
  <div class="card-b">
    <div class="stat-grid">
      <div class="stat"><div class="muted sub"><?= lx_icon('log-in') ?>Peg-ins</div><div class="big-num sm"><?= commas((int) ($S['total_pegins'] ?? 0)) ?></div><div class="muted sub">all-time</div></div>
      <div class="stat"><div class="muted sub"><?= lx_icon('log-out') ?>Peg-outs</div><div class="big-num sm"><?= commas((int) ($S['total_pegouts'] ?? 0)) ?></div><div class="muted sub">all-time</div></div>
      <div class="stat"><div class="muted sub"><?= lx_icon('at-sign') ?>Linkable peg-outs</div><div class="big-num sm"><?= commas((int) ($S['linkable_pegouts'] ?? 0)) ?></div><div class="muted sub"><?= commas((int) ($S['high_confidence_links'] ?? 0)) ?> high-confidence</div></div>
      <div class="stat"><div class="muted sub"><?= lx_icon('eye-off') ?>Avg privacy</div><div class="big-num sm"><?= h(number_format((float) ($S['avg_privacy_score'] ?? 0), 0)) ?></div><div class="muted sub">/ 100</div></div>
      <div class="stat"><div class="muted sub"><?= lx_icon('shield') ?>Avg peg-out risk</div><div class="big-num sm"><?= h(number_format((float) ($S['avg_pegout_risk'] ?? 0), 1)) ?></div><div class="muted sub">of 100</div></div>
      <div class="stat"><div class="muted sub"><?= lx_icon('box') ?>Address clusters</div><div class="big-num sm"><?= commas((int) ($S['address_clusters'] ?? 0)) ?></div><div class="muted sub">common-input</div></div>
    </div>
    <p class="pnote"><?= lx_icon('info') ?><span>These are <b>inferences from public-chain data, not proof</b>. Peg-outs are correlated to earlier peg-ins by amount and timing. Data from <a class="ext" href="<?= h(lx_mwebscan_site($net)) ?>" target="_blank" rel="noopener">MWEBscan</a> (CC BY&nbsp;4.0).</span></p>
  </div>
</div>
<?php endif; ?>

<?php if (is_array($mwLinks) && is_array($mwLinks['links'] ?? null) && $mwLinks['links']): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('at-sign') ?>Recent linkable peg-outs</span> <span class="sub">round-trip analysis &middot; &ge;70% confidence</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Peg-out</th><th>Linked peg-in</th><th class="amt">Amount</th><th class="amt">Confidence</th><th class="amt">Risk</th></tr></thead>
      <tbody>
      <?php foreach ($mwLinks['links'] as $L): $reasons = lx_mwebscan_reasons($L['reasons'] ?? []); $c = (float) ($L['confidence'] ?? 0); $cc = $c >= 0.9 ? 'bad' : ($c >= 0.7 ? 'warn' : 'soft'); ?>
        <tr>
          <td class="mono"><a class="addr" href="<?= h($base) ?>/tx/<?= h((string) ($L['pegout_txid'] ?? '')) ?>"><?= h(shorten((string) ($L['pegout_txid'] ?? ''))) ?></a><?php if (!empty($L['pegout_entity'])): ?> <span class="badge soft"><?= h((string) $L['pegout_entity']) ?></span><?php endif; ?></td>
          <td class="mono"><a class="addr" href="<?= h($base) ?>/tx/<?= h((string) ($L['pegin_txid'] ?? '')) ?>"><?= h(shorten((string) ($L['pegin_txid'] ?? ''))) ?></a></td>
          <td class="amt"><?= h(number_format((float) ($L['pegout_amount'] ?? 0), 4)) ?> <?= h($net['unit']) ?></td>
          <td class="amt"><span class="badge <?= $cc ?>"<?php if ($reasons): ?> title="<?= h(implode(' · ', $reasons)) ?>"<?php endif; ?>><?= h(number_format($c * 100, 1)) ?>%</span></td>
          <td class="amt mono"><?= (int) ($L['risk_score'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-b"><a class="sub ext" href="<?= h(lx_mwebscan_site($net)) ?>" target="_blank" rel="noopener">Full round-trip analysis on MWEBscan &rarr;</a></div>
</div>
<?php endif; ?>

<div class="card" id="pegcheck">
  <div class="card-h"><span><?= lx_icon('eye-off') ?>Peg-out privacy check</span> <span class="sub">anonymity-set lookup</span></div>
  <div class="card-b">
    <p class="muted sub">How well does a peg-out amount blend in? A common, round amount hides in a larger anonymity set; an exact or unusual amount is easier to link back to its peg-in.</p>
    <form method="get" action="<?= h($base) ?>/mweb#pegcheck">
      <div class="row">
        <input type="text" name="privacy_amount" inputmode="decimal" placeholder="amount in <?= h($net['unit']) ?> (e.g. 1.5)" value="<?= h($mwPrivAmt) ?>" style="max-width:240px" spellcheck="false" autocomplete="off">
        <button class="btn" type="submit">Check</button>
      </div>
    </form>
    <?php if ($mwPrivAmt !== '' && is_array($mwPriv) && isset($mwPriv['privacy'])): $P = $mwPriv['privacy']; $score = (int) ($P['privacy_score'] ?? 0); $pc = $score >= 80 ? 'ok' : ($score >= 50 ? 'warn' : 'bad'); ?>
    <div class="note<?= $score >= 50 ? '' : ' bad' ?> mt-3">
      <b><?= h(rtrim(rtrim(number_format((float) ($P['amount'] ?? 0), 8), '0'), '.')) ?> <?= h($net['unit']) ?></b>:
      privacy <span class="badge <?= $pc ?>"><?= $score ?></span> <b><?= h((string) ($P['rating'] ?? '')) ?></b>.
      <div class="muted sub mt-2"><?= h((string) ($P['advice'] ?? '')) ?> <span class="mono">(anonymity set <?= commas((int) ($P['rounded_set'] ?? 0)) ?><?php if ((int) ($P['exact_set'] ?? 0) > 0): ?>, <?= commas((int) $P['exact_set']) ?> exact<?php endif; ?>)</span></div>
    </div>
    <?php elseif ($mwPrivAmt !== ''): ?>
    <div class="note muted mt-3">No privacy data available for that amount.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($recent): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('box') ?>MWEB blocks</span> <span class="sub">recent peg activity</span></div>
  <div class="card-b nopad">
    <div class="blocks-strip">
      <?php foreach ($recent as $b):
          $pin  = (int) $b['pegin_total_sat'];
          $pout = (int) $b['pegout_total_sat'];
          $edge = $pin > $pout ? 'var(--ok)' : ($pout > $pin ? 'var(--bad)' : 'var(--accent)');
      ?>
      <a class="blk conf" style="border-top-color:<?= $edge ?>" href="<?= h($base) ?>/block-height/<?= (int) $b['height'] ?>">
        <div class="blk-h">#<?= commas($b['height']) ?></div>
        <?php if ($b['pegin_count'] > 0): ?><div class="blk-meta" style="color:var(--ok)">&#9650; <?= commas($b['pegin_count']) ?> &middot; <?= h(lx_coin($pin)) ?></div><?php endif; ?>
        <?php if ($b['pegout_count'] > 0): ?><div class="blk-meta" style="color:var(--bad)">&#9660; <?= commas($b['pegout_count']) ?> &middot; <?= h(lx_coin($pout)) ?></div><?php endif; ?>
        <?php if ($b['pegin_count'] == 0 && $b['pegout_count'] == 0): ?><div class="blk-meta muted">no pegs</div><?php endif; ?>
        <?php if ($b['supply_sat'] !== null): ?><div class="blk-age" title="<?= h(lx_coin((int) $b['supply_sat']) . ' ' . $net['unit']) ?> MWEB supply"><?= h(lx_num_compact((int) $b['supply_sat'] / 1e8)) ?> supply</div><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
    // Supply-over-time chart (index only), rendered as inline SVG (CSP-safe: it
    // is markup, not script). Server computes the point coordinates in PHP.
    // ?r= selects the window (days); the series is daily so the limit ~= days.
    $ranges   = ['1m' => 30, '3m' => 90, '1y' => 365, 'all' => 2000];
    $rangeSel = (isset($_GET['r']) && is_string($_GET['r']) && isset($ranges[$_GET['r']])) ? $_GET['r'] : 'all';
    $series = $idx ? lx_mweb_supply_series($net, $ranges[$rangeSel]) : [];
    if ($idx):
        $enough = count($series) >= 2;
        $minT = $maxT = $minS = $maxS = 0;
        $supplyLabels = [];
        $supplyTips = [];
        if ($enough):
            $tsv = array_column($series, 'day_ts');
            $spv = array_column($series, 'supply_sat');
            $minT = min($tsv); $maxT = max($tsv);
            $minS = min($spv); $maxS = max($spv);
            $prevS = null;
            foreach ($series as $p) {
                $sv = (int) $p['supply_sat'];
                $supplyLabels[] = gmdate('Y-m-d', (int) $p['day_ts']) . ' · ' . lx_amount($net, $sv);
                $supplyTips[] = lx_tip_json(gmdate('M j, Y', (int) $p['day_ts']), [
                    ['c' => '#6c5ce7', 'k' => 'Supply', 'v' => lx_amount($net, $sv),
                     'd' => $prevS !== null ? lx_pct_delta($sv, $prevS) : ''],
                ]);
                $prevS = $sv;
            }
        endif;
        // Compact coin y-axis label (litoshi -> "1.790M LTC"): scale to k/M and
        // pick decimals from the tick $step so a near-flat supply (~1.79M ± a few
        // thousand) shows the real variation instead of six identical "1.79M"
        // labels. Called with no $step (peg-flow) it keeps the 2-decimal form.
        // Compact coin value for the y-axis, WITHOUT the currency unit (it would
        // repeat on every tick and widen the gutter; the tooltip carries the unit).
        $mwSupplyFmt = function ($sat, $step = 0.0) {
            $c = abs($sat) / 100000000;                 // litoshi -> coin
            $sc = ($step > 0 ? $step : 0.0) / 100000000; // step in coin units
            if ($c >= 1000000) { $u = 1000000.0; $s = 'M'; }
            elseif ($c >= 1000) { $u = 1000.0; $s = 'k'; }
            else { $u = 1.0; $s = ''; }
            $ssu = $u > 0 ? $sc / $u : 0.0;
            if ($ssu > 0 && $ssu < 1) {
                $dec = (int) ceil(-log10($ssu));
                if ($dec > 6) { $dec = 6; }
            } else {
                $dec = $u > 1 ? 2 : ($c > 0 && $c < 1 ? 4 : 2);
            }
            return number_format($sat / 100000000 / $u, $dec) . $s;
        };
?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('trending-up') ?>MWEB supply</span> <span class="range-tog"<?= $enough ? ' title="' . h(gmdate('Y-m-d', $minT)) . ' to ' . h(gmdate('Y-m-d', $maxT)) . '"' : '' ?>><?php foreach (['1m' => '1M', '3m' => '3M', '1y' => '1Y', 'all' => 'All'] as $rk => $rl): ?><a<?= $rangeSel === $rk ? ' class="on"' : '' ?> href="?r=<?= $rk ?>"><?= $rl ?></a><?php endforeach; ?></span></div>
  <div class="card-b">
    <?php if ($enough): ?>
    <?= lx_chart_area($series, 'day_ts', 'supply_sat', 'MWEB supply over time', $supplyLabels, [
        'yfmt'   => $mwSupplyFmt,
        'xticks' => lx_time_ticks($series, 'day_ts'),
        'tips'   => $supplyTips,
    ]) ?>
    <?php else: ?>
    <p class="muted sub">Not enough indexed data in this window yet. Try a wider range.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
    // Peg-flow: peg-in above the centre line, peg-out below (index only). Daily is too
    // dense to read as bars over a long range (~730 points collapse to a sub-pixel band),
    // so bucket to weekly totals once the series is large; short ranges stay daily.
    $weekly = count($series) > 120;
    $flow = $series;
    if ($weekly) {
        $buckets = [];
        foreach ($series as $p) {
            $wk = intdiv((int) $p['day_ts'], 604800);
            if (!isset($buckets[$wk])) { $buckets[$wk] = ['day_ts' => 0, 'pegin_sat' => 0, 'pegout_sat' => 0]; }
            $buckets[$wk]['day_ts']      = (int) $p['day_ts'];   // last day in the week = the bar's date
            $buckets[$wk]['pegin_sat']  += (int) $p['pegin_sat'];
            $buckets[$wk]['pegout_sat'] += (int) $p['pegout_sat'];
        }
        $flow = array_values($buckets);
    }
    $hasFlow = false;
    $pegLabels = [];
    $pegTips = [];
    foreach ($flow as $p) {
        $pin = (int) $p['pegin_sat']; $pout = (int) $p['pegout_sat'];
        if ($pin > 0 || $pout > 0) { $hasFlow = true; }
        $pegLabels[] = gmdate('Y-m-d', (int) $p['day_ts']) . ' · +' . lx_coin($pin) . ' / -' . lx_coin($pout);
        $pegTips[] = lx_tip_json(($weekly ? 'Week ending ' : '') . gmdate('M j, Y', (int) $p['day_ts']), [
            ['c' => 'var(--ok)',  'k' => 'Peg-in',  'v' => lx_amount($net, $pin)],
            ['c' => 'var(--bad)', 'k' => 'Peg-out', 'v' => lx_amount($net, $pout)],
        ]);
    }
    if ($idx && count($flow) >= 2 && $hasFlow):
?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('repeat') ?>Peg flow</span> <span class="sub"><?= $weekly ? 'weekly' : 'daily' ?> peg-in vs peg-out</span></div>
  <div class="card-b">
    <?= lx_chart_diverging($flow, 'pegin_sat', 'pegout_sat', 'MWEB peg-in above centre, peg-out below', $pegLabels, [
        'yfmt'   => $mwSupplyFmt,
        'xticks' => lx_time_ticks($flow, 'day_ts'),
        'tips'   => $pegTips,
        'legend' => [
            ['color' => 'var(--ok)',  'label' => 'Peg-in'],
            ['color' => 'var(--bad)', 'label' => 'Peg-out'],
        ],
    ]) ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><span><?= lx_icon('box') ?>Recent blocks</span> <span class="sub">last <?= count($recent) ?></span></div>
  <div class="card-b nopad table-wrap">
    <table class="mweb-recent">
      <thead><tr><th>Height</th><th class="amt">Age</th><th class="amt">Peg-in</th><th class="amt">Peg-out</th><th class="amt">MWEB supply</th><th>HogEx</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $b): ?>
        <tr>
          <td><a href="<?= h($base) ?>/block-height/<?= (int) $b['height'] ?>"><?= commas($b['height']) ?></a></td>
          <td class="amt" data-sort="<?= (int) ($b['block_time'] ?? 0) ?>"<?= !empty($b['block_time']) ? ' title="' . h(gmdate('Y-m-d H:i', (int) $b['block_time'])) . ' UTC"' : '' ?>><?= !empty($b['block_time']) ? h(time_ago((int) $b['block_time'])) : '<span class="muted">-</span>' ?></td>
          <td class="amt"><?php if ($b['pegin_count'] > 0): ?><?= commas($b['pegin_count']) ?> <span class="muted">·</span> <?= lx_amount_el($net, (int) $b['pegin_total_sat'], false) ?><?php else: ?><span class="muted">-</span><?php endif; ?></td>
          <td class="amt"><?php if ($b['pegout_count'] > 0): ?><?= commas($b['pegout_count']) ?> <span class="muted">·</span> <?= lx_amount_el($net, (int) $b['pegout_total_sat'], false) ?><?php else: ?><span class="muted">-</span><?php endif; ?></td>
          <td class="amt"><?= $b['supply_sat'] !== null ? lx_amount_el($net, (int) $b['supply_sat'], false) : '<span class="muted">-</span>' ?></td>
          <td class="mono"><?php if (!empty($b['hogex_txid'])): ?><a class="addr" href="<?= h(lx_tx_href($net, $b['hogex_txid'])) ?>"><?= h(shorten($b['hogex_txid'])) ?></a><?php else: ?><span class="muted">-</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="6"><div class="empty"><?= lx_icon('inbox') ?><span>No MWEB blocks found near the tip.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($idx):
    $pb = isset($_GET['pb']) && is_string($_GET['pb']) ? $_GET['pb'] : null;
    $po = isset($_GET['po']) && is_string($_GET['po']) ? $_GET['po'] : null;
    $pegins  = lx_mweb_pegins_page($net, $pb, 50);
    $pegouts = lx_mweb_pegouts_page($net, $po, 50);
?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('log-in') ?>Peg-ins</span> <span class="sub">public into MWEB</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Height</th><th>Tx</th><th class="amt">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($pegins['pegins'] as $p): ?>
        <tr>
          <td><a href="<?= h($base) ?>/block-height/<?= (int) $p['block_height'] ?>"><?= commas($p['block_height']) ?></a></td>
          <td class="mono"><a class="addr" href="<?= h(lx_tx_href($net, $p['txid'])) ?>#out-<?= (int) $p['vout'] ?>"><?= h(shorten($p['txid'])) ?></a></td>
          <td class="amt"><?= lx_amount_el($net, (int) $p['value_sat'], false) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pegins['pegins']): ?><tr><td colspan="3"><div class="empty"><?= lx_icon('inbox') ?><span>No peg-ins indexed.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pegins['next'] !== null): ?>
  <div class="pagination"><a class="btn ghost sm" href="<?= h($base) ?>/mweb?pb=<?= h(rawurlencode($pegins['next'])) ?><?= $po !== null ? '&amp;po=' . h(rawurlencode($po)) : '' ?>">Older peg-ins &rarr;</a></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-h"><span><?= lx_icon('log-out') ?>Peg-outs</span> <span class="sub">MWEB back to public</span></div>
  <div class="card-b nopad table-wrap">
    <table class="peg-tbl">
      <thead><tr><th>Height</th><th>HogEx</th><th>Destination</th><th class="amt">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($pegouts['pegouts'] as $p): ?>
        <tr>
          <td><a href="<?= h($base) ?>/block-height/<?= (int) $p['block_height'] ?>"><?= commas($p['block_height']) ?></a></td>
          <td class="mono"><a class="addr" href="<?= h(lx_tx_href($net, $p['txid'])) ?>"><?= h(shorten($p['txid'])) ?></a></td>
          <td class="mono"><?php if (!empty($p['address'])): ?><a class="addr" href="<?= h(lx_addr_href($net, $p['address'])) ?>"><?= h(shorten($p['address'], 12, 6)) ?></a><?php else: ?><span class="muted">-</span><?php endif; ?></td>
          <td class="amt"><?= lx_amount_el($net, (int) $p['value_sat'], false) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pegouts['pegouts']): ?><tr><td colspan="4"><div class="empty"><?= lx_icon('inbox') ?><span>No peg-outs indexed.</span></div></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pegouts['next'] !== null): ?>
  <div class="pagination"><a class="btn ghost sm" href="<?= h($base) ?>/mweb?po=<?= h(rawurlencode($pegouts['next'])) ?><?= $pb !== null ? '&amp;pb=' . h(rawurlencode($pb)) : '' ?>">Older peg-outs &rarr;</a></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php $clusters = $idx ? lx_mweb_pegout_clusters($net, 12) : []; if ($clusters): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('repeat') ?>Peg-out address reuse</span> <span class="sub">clusterable destinations</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Destination</th><th class="amt">Peg-outs</th><th class="amt">Total</th><th class="amt">Blocks</th></tr></thead>
      <tbody>
      <?php foreach ($clusters as $c): ?>
        <tr>
          <td class="mono"><a class="addr" href="<?= h(lx_addr_href($net, $c['address'])) ?>"><?= h(shorten($c['address'], 12, 6)) ?></a></td>
          <td class="amt"><?= commas($c['count']) ?></td>
          <td class="amt"><?= lx_amount_el($net, (int) $c['total_sat'], false) ?></td>
          <td class="amt mono" data-sort="<?= (int) $c['last_h'] ?>"><?= commas($c['first_h']) ?><span class="muted">-</span><?= commas($c['last_h']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-b"><p class="muted sub">Public addresses that received more than one MWEB peg-out. Reusing an address for multiple exits links those withdrawals to a single identity and undoes much of MWEB's privacy. Use a fresh peg-out address each time (see the guide below).</p></div>
</div>
<?php endif; ?>

<div class="card guide">
  <div class="card-h"><span><?= lx_icon('book-open') ?>MWEB privacy guide</span> <span class="sub">how the peg boundary leaks, and how to use it well</span></div>
  <div class="card-b">
    <details open>
      <summary><?= lx_icon('eye-off') ?>What is public and what is private</summary>
      <div>
        <ul>
          <li><b>Public on the canonical chain:</b> a peg-in's amount and the ordinary Litecoin address that funded it; a peg-out's amount and destination address; and the block's absolute MWEB supply (each HogEx <code>vout[0]</code>).</li>
          <li><b>Confidential inside MWEB:</b> individual amounts (Pedersen commitments), ownership, and the transaction graph. Once value crosses the peg-in boundary, on-chain observers no longer see who pays whom or how much.</li>
          <li><b>The boundary is the weak point.</b> Privacy comes from what happens <em>between</em> a peg-in and a peg-out. The two public endpoints are where amounts and timing can still be correlated.</li>
        </ul>
      </div>
    </details>
    <details>
      <summary><?= lx_icon('shield') ?>Privacy best practices</summary>
      <div>
        <ul>
          <li><b>Let coins rest.</b> Time between peg-in and peg-out grows the set of MWEB activity you could be confused with. Immediate round-trips link the two ends by timing.</li>
          <li><b>Avoid round numbers.</b> Pegging in exactly 10.00 and later out exactly 10.00 (or 9.99 after fees) is a giant fingerprint. Vary amounts.</li>
          <li><b>Split and recombine.</b> Break a large peg-in into several unrelated peg-outs at different times, and use fresh addresses for each.</li>
          <li><b>Use a fresh peg-out address</b> that has never touched your public, doxxed, or exchange addresses; reuse re-links your shielded funds to a known identity.</li>
          <li><b>Prefer spending inside MWEB</b> over pegging back out whenever the recipient supports it; every peg-out is a public event.</li>
        </ul>
      </div>
    </details>
    <details>
      <summary><?= lx_icon('target') ?>Common mistakes that deanonymise you</summary>
      <div>
        <ul>
          <li><b>Round-trip linkage:</b> peg in <em>X</em>, then peg out ~<em>X</em> a few blocks later; amount plus timing ties the two public transactions together.</li>
          <li><b>Amount fingerprinting:</b> an unusual, precise value (e.g. 3.14159) that appears as both a peg-in and a later peg-out is trivially matched.</li>
          <li><b>Small anonymity set:</b> in a quiet window with few peg events, even coarse timing correlation often uniquely identifies a flow.</li>
          <li><b>Address reuse:</b> pegging out to an address that also appears in your public chain history collapses the privacy MWEB just gave you.</li>
        </ul>
      </div>
    </details>
    <p class="pnote"><?= lx_icon('lock') ?><span>These are educational heuristics, not financial or security advice. MWEB hides amounts and ownership <em>inside</em> the extension block; it cannot un-see the public amounts you peg across the boundary.</span></p>
  </div>
</div>

<div class="card"><div class="card-b">
  <p class="muted sub">Read-only JSON under <code><?= h($base) ?>/api/mweb/</code>: <code>tip</code>, <code>block/:hash</code>, <code>blocks</code><?php if ($idx): ?>, <code>pegins</code>, <code>pegouts</code>, <code>supply</code>, <code>clusters</code><?php endif; ?>.</p>
  <p class="muted sub mt-2">Send and receive MWEB with <a class="ext" href="https://litewallet.io" target="_blank" rel="noopener">Litewallet</a>.</p>
</div></div>
<?php endif; ?>
<?php lx_foot($net); ?>
