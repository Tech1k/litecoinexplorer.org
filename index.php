<?php
/**
 * Litecoin Explorer front controller.
 *
 * Single Litecoin mainnet chain, served at the site root:
 *   /                             explorer home
 *   /block|tx|address|...         HTML views
 *   /api/...                      Esplora / mempool.space REST API (drop-in)
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Litecoin Explorer © 2026 Tech1k. https://github.com/Tech1k/litecoinexplorer.org
 */

require __DIR__ . '/lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Split on '/' FIRST, then decode each segment, so an encoded %2F inside a
// single segment (e.g. a pool label) survives instead of being split apart.
$segs = array_values(array_filter(explode('/', trim($path, '/')), function ($s) {
    return $s !== '';
}));
$segs = array_map('rawurldecode', $segs);

// ---- top-level, non-chain-scoped pages ------------------------------------

// XML sitemap.
if (count($segs) === 1 && $segs[0] === 'sitemap.xml') {
    require __DIR__ . '/views/sitemap.php';
    exit;
}

// RFC 9116 security contact (.well-known/security.txt, with a root alias).
if ($segs === ['.well-known', 'security.txt'] || $segs === ['security.txt']) {
    require __DIR__ . '/views/security.php';
    exit;
}

// Top-level info pages. These now share the site's nav/footer, so they need the
// default network resolved (single-chain: the sole enabled net).
if (count($segs) === 1 && in_array($segs[0], ['docs', 'donate', 'status'], true)) {
    $net = lx_net_default();
    if ($net === null) {   // degenerate config: these pages need $net for the shared chrome
        lx_not_found($method);
    }
    require __DIR__ . '/views/' . $segs[0] . '.php';
    exit;
}

// Dynamic Open Graph card image: /og/<type>/<id>.png. Best-effort - it serves
// the static banner when GD/FreeType, a font, or the data is unavailable.
if (isset($segs[0]) && $segs[0] === 'og') {
    lx_route_og(array_slice($segs, 1), $method);
    exit;
}

// ---- resolve the single Litecoin mainnet chain ----------------------------

$net = lx_net_default();
if ($net === null) {
    // Degenerate (no network enabled): still answer /api requests as an API error
    // (text) rather than the HTML 404 page.
    if (($segs[0] ?? '') === 'api') {
        define('LX_WANTS_JSON', true);
    }
    lx_not_found($method);
}

// Root-level routing: every path segment is part of the resource path (there is
// no network slug prefix), so `/` -> home, `/block/…`, `/api/…`, etc.
$rest = $segs;

// ---- API vs HTML ----------------------------------------------------------

if (isset($rest[0]) && $rest[0] === 'api') {
    define('LX_WANTS_JSON', true);
    // CORS preflight must always succeed.
    if ($method === 'OPTIONS') {
        http_response_code(204);
        lx_cors();
        exit;
    }
    lx_route_api($net, array_slice($rest, 1), $method);
    exit;
}

lx_route_html($net, $rest, $method);
exit;


// ===========================================================================
//  HTML routing
// ===========================================================================

function lx_route_html(array $net, array $rest, string $method): void
{
    $page = $rest[0] ?? '';

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    // HTML routes are GET/HEAD; only the search + tool forms accept POST.
    if ($method !== 'GET' && $method !== 'HEAD'
        && !($method === 'POST' && in_array($page, ['search', 'broadcast', 'test', 'decode', 'script', 'psbt', 'opreturn', 'verifymsg'], true))) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        echo 'Method not allowed';
        exit;
    }

    switch ($page) {
        case '':
            $view = 'home';
            break;

        case 'blocks':
            $start = isset($rest[1]) && ctype_digit($rest[1]) ? (int) $rest[1] : null;
            $GLOBALS['start_height'] = $start;
            $view = 'blocks';
            break;

        case 'block':
            $ref = $rest[1] ?? '';
            // Accept a bare height at /block/<n> too (mempool.space parity), not
            // just a 64-hex hash; resolve it and redirect to the canonical hash URL.
            if (ctype_digit($ref)) {
                $hash = lx_block_hash_at($net, (int) $ref);
                if ($hash === null) {
                    lx_not_found($method);
                }
                header('Location: ' . lx_net_url($net) . '/block/' . $hash, true, 302);
                return;
            }
            if (!is_txid($ref)) {
                lx_not_found($method);
            }
            $GLOBALS['block_hash'] = $ref;
            $GLOBALS['block_page'] = isset($rest[2]) && ctype_digit($rest[2]) ? (int) $rest[2] : 0;
            $view = 'block';
            break;

        case 'block-height':
            $h = $rest[1] ?? '';
            if (!ctype_digit($h)) {
                lx_not_found($method);
            }
            $hash = lx_block_hash_at($net, (int) $h);
            if ($hash === null) {
                lx_not_found($method);
            }
            header('Location: ' . lx_net_url($net) . '/block/' . $hash, true, 302);
            return;

        case 'tx':
            $txid = $rest[1] ?? '';
            if (!is_txid($txid)) {
                lx_not_found($method);
            }
            $GLOBALS['txid'] = $txid;
            $view = 'tx';
            break;

        case 'address':
            $addr = $rest[1] ?? '';
            if ($addr === '' || !lx_address_valid($net, $addr)) {
                lx_not_found($method);
            }
            $GLOBALS['address'] = $addr;
            $view = 'address';
            break;

        case 'xpub':
            $xp = $rest[1] ?? '';
            if ($xp === '' || !lx_is_xpub($xp)) {
                lx_not_found($method);
            }
            // Each unique key is ~22 EC mults + up to 20 Electrum round-trips and
            // always misses the per-key cache, so throttle per IP (anti-amplification).
            if (!lx_rate_limit('xpub', 20, 60)) {
                http_response_code(429);
                header('Retry-After: 30');
                header('Cache-Control: no-store');
                exit("Rate limit exceeded - please slow down.\n");
            }
            $GLOBALS['xpub'] = $xp;
            $view = 'xpub';
            break;

        case 'mempool':
            $view = 'mempool';
            break;

        case 'charts':
            $view = 'charts';
            break;

        case 'tv':
            // TV / wall-display mode was removed - no real audience, and even
            // mempool.space retired its /tv. Redirect old links home instead of 404ing.
            header('Location: ' . lx_net_url($net) . '/', true, 302);
            return;

        case 'mempool-block':
            $mb = $rest[1] ?? '';
            if (!ctype_digit($mb)) {
                lx_not_found($method);
            }
            $GLOBALS['mempool_block_index'] = (int) $mb;
            $view = 'mempool-block';
            break;

        case 'mining':
            // /{net}/mining or /{net}/mining/{pool label} (URL-decoded already).
            $GLOBALS['mining_pool'] = isset($rest[1]) && $rest[1] !== '' ? $rest[1] : null;
            $view = 'mining';
            break;

        case 'node':
            $view = 'node';
            break;

        case 'tools':
            $view = 'tools';
            break;

        case 'broadcast':
            $GLOBALS['tool_action'] = 'broadcast';
            $view = 'tools';
            break;

        case 'decode':
            $GLOBALS['tool_action'] = 'decode';
            $view = 'tools';
            break;

        case 'test':
        case 'script':
        case 'psbt':
        case 'opreturn':
        case 'verifymsg':
            $GLOBALS['tool_action'] = $page;
            $view = 'tools';
            break;

        case 'search':
            lx_handle_search($net, $method);
            return;

        case 'mweb':
            $view = 'mweb';
            break;

        default:
            lx_not_found($method);
    }

    require __DIR__ . '/views/' . $view . '.php';
}

/** Resolve a search query (?q=) to the right page and redirect. */
function lx_handle_search(array $net, string $method): void
{
    $q = trim($_GET['q'] ?? $_POST['q'] ?? '');
    if (strlen($q) > 120) { $q = substr($q, 0, 120); }   // no height/txid/address is longer; bounds work
    $base = lx_net_url($net);
    if ($q === '') {
        header('Location: ' . $base . '/', true, 302);
        return;
    }
    // A 64-hex query triggers uncached negative lookups (getblockheader + tx). Throttle so
    // /search can't be used as an RPC/electrs amplifier (the tx path is also negative-cached).
    if (function_exists('lx_rate_limit') && !lx_rate_limit('search', 60, 60)) {
        http_response_code(429);
        header('Retry-After: 10');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Too many searches. Slow down.\n";
        return;
    }
    // height
    if (ctype_digit($q)) {
        $hash = lx_block_hash_at($net, (int) $q);
        if ($hash !== null) {
            header('Location: ' . $base . '/block/' . $hash, true, 302);
            return;
        }
    }
    // 64-hex: block hash or txid (verify it exists before redirecting)
    if (is_txid($q)) {
        if (lx_rpc_soft($net, 'getblockheader', [$q, true]) !== null) {
            header('Location: ' . $base . '/block/' . $q, true, 302);
            return;
        }
        if (lx_find_tx($net, $q) !== null) {
            header('Location: ' . $base . '/tx/' . $q, true, 302);
            return;
        }
        // 64-hex but neither a block nor a known tx -> fall through to not-found
    }
    // extended public key (xpub/ypub/zpub, testnet tpub/upub/vpub, Litecoin) -> derived addresses
    if (lx_is_xpub($q)) {
        header('Location: ' . $base . '/xpub/' . rawurlencode($q), true, 302);
        return;
    }
    // address
    if (lx_address_valid($net, $q)) {
        header('Location: ' . $base . '/address/' . rawurlencode($q), true, 302);
        return;
    }
    // no match
    $GLOBALS['search_query'] = $q;
    require __DIR__ . '/views/notfound.php';
}

function lx_not_found(string $method): void
{
    if (defined('LX_WANTS_JSON') && LX_WANTS_JSON) {
        api_error('Not found', 404);
    }
    http_response_code(404);
    require __DIR__ . '/views/notfound.php';
    exit;
}


// ===========================================================================
//  Esplora / mempool.space API routing
// ===========================================================================

/**
 * HTTP max-age for an Esplora tx body. Mirrors the server-side cache gate in
 * lx_cache_tx_if_confirmed: uncacheable while unconfirmed (or height unknown), a
 * short window while shallow (a reorg could still change it), long-lived once
 * buried past 100 confirmations. Confirmed-but-shallow txs get a bounded TTL so
 * an edge/browser never serves a reorged confirmation as permanent.
 */
function lx_tx_http_ttl(array $net, array $tx): int
{
    if (empty($tx['status']['confirmed'])) {
        return 0;
    }
    $bh = $tx['status']['block_height'] ?? null;
    if ($bh === null) {
        return 0;
    }
    return (lx_tip_height($net) - (int) $bh) > 100 ? 86400 : 600;
}

function lx_route_api(array $net, array $r, string $method): void
{
    // CORS preflight
    if ($method === 'OPTIONS') {
        http_response_code(204);
        lx_cors();
        exit;
    }

    $a = $r[0] ?? '';

    // ---- broadcast --------------------------------------------------------
    if ($a === 'tx' && $method === 'POST' && !isset($r[1])) {
        // Throttle: each broadcast forwards up to ~1 MiB of hex to the node for a full
        // parse/validate, so cap per-IP to blunt a resource-amplification flood.
        if (function_exists('lx_rate_limit') && !lx_rate_limit('broadcast', 30, 60)) {
            api_error('rate limited', 429);
        }
        [$txid, $err] = lx_broadcast($net, request_body());
        if ($txid !== null) {
            text_out($txid);
        }
        api_error($err ?? 'broadcast failed', 400);
    }

    // HEAD is a valid read method (uptime probes, drop-in Esplora parity); treat it like
    // GET - PHP/Apache strips the body. Everything else on /api is unsupported.
    if ($method !== 'GET' && $method !== 'HEAD') {
        api_error('Method not allowed', 405);
    }

    switch ($a) {

        // ---- blocks tip ---------------------------------------------------
        case 'blocks':
            if (($r[1] ?? '') === 'tip') {
                if (($r[2] ?? '') === 'height') {
                    text_out((string) lx_tip_height($net), 200, 'text/plain', 5);
                }
                if (($r[2] ?? '') === 'hash') {
                    text_out(lx_tip_hash($net), 200, 'text/plain', 5);
                }
                if (($r[2] ?? '') === '') {
                    // bare /blocks/tip -> tip block object (mempool.space compat)
                    $blk = lx_esplora_block($net, lx_tip_hash($net));
                    $blk ? json_out($blk) : api_error('Not found', 404);
                }
                api_error('Not found', 404);
            }
            // /blocks or /blocks/:start_height
            $start = isset($r[1]) && ctype_digit($r[1]) ? (int) $r[1] : null;
            json_out(lx_recent_blocks($net, $start));
            // no break (json_out exits)

        // ---- block-height -------------------------------------------------
        case 'block-height':
            $h = $r[1] ?? '';
            if (!ctype_digit($h)) {
                api_error('Invalid height', 400);
            }
            $hash = lx_block_hash_at($net, (int) $h);
            if ($hash === null) {
                api_error('Block not found', 404);
            }
            text_out($hash);

        // ---- block --------------------------------------------------------
        case 'block':
            $hash = $r[1] ?? '';
            if (!is_txid($hash)) {
                api_error('Invalid block hash', 400);
            }
            $sub = $r[2] ?? '';
            // A block's body/txids/header/raw are immutable per hash (chain
            // membership lives only in /status), so they carry a long max-age.
            if ($sub === '') {
                $blk = lx_esplora_block($net, $hash);
                $blk ? json_out($blk, 200, 86400) : api_error('Block not found', 404);
            }
            if ($sub === 'status') {
                $st = lx_block_status($net, $hash);   // mutable: reorg can flip it
                $st ? json_out($st) : api_error('Block not found', 404);
            }
            if ($sub === 'txids') {
                $ids = lx_block_txids($net, $hash);
                $ids !== null ? json_out($ids, 200, 86400) : api_error('Block not found', 404);
            }
            if ($sub === 'txid') {
                $idx = $r[3] ?? '';
                $ids = lx_block_txids($net, $hash);
                if ($ids === null || !ctype_digit($idx) || !isset($ids[(int) $idx])) {
                    api_error('Not found', 404);
                }
                text_out($ids[(int) $idx], 200, 'text/plain', 86400);
            }
            if ($sub === 'txs') {
                $startIdx = isset($r[3]) && ctype_digit($r[3]) ? (int) $r[3] : 0;
                $startIdx -= $startIdx % 25; // Esplora pages on multiples of 25
                $txs = lx_block_txs($net, $hash, $startIdx);
                if ($txs === null) {
                    api_error('Block not found', 404);
                }
                // Embeds per-tx confirmation state, so gate the long TTL on
                // best-chain membership AND burial depth: an orphaned (or
                // unconfirmable) block never gets the immutable 24h max-age.
                $bst = lx_block_status($net, $hash);
                $ttl = ($bst !== null && !empty($bst['in_best_chain'])
                        && (lx_tip_height($net) - (int) $bst['height']) > 100) ? 86400 : 600;
                json_out($txs, 200, $ttl);
            }
            if ($sub === 'header') {
                $hdr = lx_rpc_soft($net, 'getblockheader', [$hash, false]);
                is_string($hdr) ? text_out($hdr, 200, 'text/plain', 86400) : api_error('Block not found', 404);
            }
            if ($sub === 'raw') {
                $raw = lx_rpc_soft($net, 'getblock', [$hash, 0]);
                if (!is_string($raw)) {
                    api_error('Block not found', 404);
                }
                text_out(hex2bin($raw), 200, 'application/octet-stream', 86400);
            }
            api_error('Not found', 404);

        // ---- tx -----------------------------------------------------------
        case 'tx':
            $txid = $r[1] ?? '';
            if (!is_txid($txid)) {
                api_error('Invalid txid', 400);
            }
            $sub = $r[2] ?? '';
            if ($sub === '') {
                $tx = lx_find_tx($net, $txid);
                $tx ? json_out($tx, 200, lx_tx_http_ttl($net, $tx)) : api_error('Transaction not found', 404);
            }
            if ($sub === 'hex') {
                // Raw bytes are immutable per txid regardless of confirmation.
                $hex = lx_find_tx_hex($net, $txid);
                $hex !== null ? text_out($hex, 200, 'text/plain', 86400) : api_error('Transaction not found', 404);
            }
            if ($sub === 'raw') {
                $hex = lx_find_tx_hex($net, $txid);
                $hex !== null
                    ? text_out(hex2bin($hex), 200, 'application/octet-stream', 86400)
                    : api_error('Transaction not found', 404);
            }
            if ($sub === 'status') {
                $tx = lx_find_tx($net, $txid);
                $tx ? json_out($tx['status']) : api_error('Transaction not found', 404);
            }
            if ($sub === 'outspends') {
                // Expensive (resolves the spender of every spent output); throttle hardest.
                if (!lx_rate_limit('outspends_batch', 30, 60)) { api_error('rate limited', 429); }
                $tx = lx_find_tx($net, $txid);
                $tx ? json_out(lx_tx_outspends($net, $tx)) : api_error('Transaction not found', 404);
            }
            if ($sub === 'outspend') {
                // Single output + bounded resolve; generous cap so a swap maker polling its
                // HTLC output isn't throttled (and a 429 just makes it retry - no fund risk).
                if (!lx_rate_limit('outspend_one', 180, 60)) { api_error('rate limited', 429); }
                $tx = lx_find_tx($net, $txid);
                $n = $r[3] ?? '';
                if (!$tx || !ctype_digit($n) || !isset($tx['vout'][(int) $n])) {
                    api_error('Not found', 404);
                }
                json_out(lx_tx_outspend($net, $tx, (int) $n));
            }
            if ($sub === 'merkle-proof') {
                $tx = lx_find_tx($net, $txid);
                if (!$tx || empty($tx['status']['confirmed'])) {
                    api_error('Transaction not confirmed', 404);
                }
                $mp = lx_tx_merkle($net, $txid, (int) $tx['status']['block_height']);
                $mp ? json_out($mp) : api_error('Not available', 404);
            }
            if ($sub === 'merkleblock-proof') {
                $tx = lx_find_tx($net, $txid);
                if (!$tx || empty($tx['status']['confirmed'])) {
                    api_error('Transaction not confirmed', 404);
                }
                $mb = lx_merkleblock_proof($net, $txid, $tx['status']['block_hash'] ?? null);
                $mb !== null ? text_out($mb) : api_error('Not available', 404);
            }
            api_error('Not found', 404);

        // ---- address ------------------------------------------------------
        case 'address':
            $addr = $r[1] ?? '';
            if ($addr === '' || !lx_address_valid($net, $addr)) {
                api_error('Invalid address', 400);
            }
            // Address is already validated above, so a null result here means the Electrum
            // index is unavailable (down / resyncing) - return 503, never a false empty/zero.
            $sub = $r[2] ?? '';
            if ($sub === '') {
                $st = lx_address_stats($net, $addr);
                $st !== null ? json_out($st) : api_error('Address index unavailable', 503);
            }
            if ($sub === 'txs') {
                $kind = $r[3] ?? '';
                if ($kind === 'mempool') {
                    $t = lx_address_txs($net, $addr, 'mempool');
                    $t !== null ? json_out($t) : api_error('Address index unavailable', 503);
                }
                if ($kind === 'chain') {
                    $after = $r[4] ?? null;
                    $t = lx_address_txs($net, $addr, 'chain', $after);
                    $t !== null ? json_out($t) : api_error('Address index unavailable', 503);
                }
                $t = lx_address_txs($net, $addr, 'all');
                $t !== null ? json_out($t) : api_error('Address index unavailable', 503);
            }
            if ($sub === 'utxo') {
                $u = lx_address_utxos($net, $addr);
                $u !== null ? json_out($u, 200, 5) : api_error('Address index unavailable', 503);
            }
            api_error('Not found', 404);

        // ---- scripthash ---------------------------------------------------
        case 'scripthash':
            $sh = $r[1] ?? '';
            if (!is_hex($sh, 64)) {
                api_error('Invalid scripthash', 400);
            }
            $sh = strtolower($sh);
            $sub = $r[2] ?? '';
            // null result = electrs unavailable (down / resyncing) -> 503, never a fake empty.
            if ($sub === '') {
                $st = lx_scripthash_stats($net, $sh);
                $st !== null ? json_out($st) : api_error('Address index unavailable', 503);
            }
            if ($sub === 'txs') {
                $kind = $r[3] ?? '';
                if ($kind === 'mempool') {
                    $t = lx_scripthash_txs($net, $sh, 'mempool');
                    $t !== null ? json_out($t) : api_error('Address index unavailable', 503);
                }
                if ($kind === 'chain') {
                    $t = lx_scripthash_txs($net, $sh, 'chain', $r[4] ?? null);
                    $t !== null ? json_out($t) : api_error('Address index unavailable', 503);
                }
                $t = lx_scripthash_txs($net, $sh, 'all');
                $t !== null ? json_out($t) : api_error('Address index unavailable', 503);
            }
            if ($sub === 'utxo') {
                $u = lx_scripthash_utxos($net, $sh);
                $u !== null ? json_out($u, 200, 5) : api_error('Address index unavailable', 503);
            }
            api_error('Not found', 404);

        // ---- mempool ------------------------------------------------------
        case 'mempool':
            $sub = $r[1] ?? '';
            if ($sub === '') {
                json_out(lx_esplora_mempool($net), 200, 5);
            }
            if ($sub === 'txids') {
                json_out(lx_mempool_txids($net));
            }
            if ($sub === 'recent') {
                json_out(lx_mempool_recent($net), 200, 5);
            }
            api_error('Not found', 404);

        // ---- fees ---------------------------------------------------------
        case 'fee-estimates':
            json_out(lx_fee_estimates($net), 200, 60);

        case 'v1':
            if (($r[1] ?? '') === 'fees' && ($r[2] ?? '') === 'recommended') {
                json_out(lx_fees_recommended($net));
            }
            if (($r[1] ?? '') === 'fees' && ($r[2] ?? '') === 'mempool-blocks') {
                json_out(lx_mempool_blocks_api($net), 200, 5);
            }
            if (($r[1] ?? '') === 'prices') {
                json_out(lx_prices_api($net), 200, 60);
            }
            if (($r[1] ?? '') === 'validate-address') {
                $addr = $r[2] ?? '';
                json_out(lx_validate_address($net, $addr));
            }
            if (($r[1] ?? '') === 'difficulty-adjustment') {
                json_out(lx_difficulty_adjustment($net));
            }
            if (($r[1] ?? '') === 'difficulty-history') {
                json_out(lx_difficulty_epochs($net, 24), 200, 300);
            }
            if (($r[1] ?? '') === 'statistics') {
                json_out(lx_statistics_api($net), 200, 30);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'pools') {
                json_out(lx_mining_pools_api($net), 200, 120);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'hashrate' && ($r[3] ?? '') !== 'pools') {
                $per = (string) ($r[3] ?? '');
                if ($per !== '' && !lx_period_valid($per)) { api_error('Invalid time period', 400); }
                json_out(lx_mining_hashrate_api($net, $per), 200, 120);
            }
            // Mining block-analytics timeseries (bucketed, from the block index).
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'blocks'
                && in_array($r[3] ?? '', ['fees', 'rewards', 'fee-rates', 'sizes-weights'], true)) {
                $per = (string) ($r[4] ?? '1w');
                if (!lx_period_valid($per)) { api_error('Invalid time period', 400); }
                switch ($r[3]) {
                    case 'fees':          json_out(lx_mining_blocks_fees_api($net, $per), 200, 600);
                    case 'rewards':       json_out(lx_mining_blocks_rewards_api($net, $per), 200, 600);
                    case 'fee-rates':     json_out(lx_mining_blocks_feerates_api($net, $per), 200, 600);
                    case 'sizes-weights': json_out(lx_mining_blocks_sizesweights_api($net, $per), 200, 600);
                }
            }
            // Nearest block to a unix timestamp.
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'blocks' && ($r[3] ?? '') === 'timestamp') {
                if (!ctype_digit((string) ($r[4] ?? ''))) { api_error('Invalid timestamp', 400); }
                $bt = lx_block_by_timestamp_api($net, (int) $r[4]);
                $bt ? json_out($bt, 200, 60) : api_error('Not found', 404);
            }
            // BlockExtended range / recent (with pool + reward extras). These walk many blocks
            // (getblockstats + pool per height) on a cold range, so throttle a range-sweep.
            if (($r[1] ?? '') === 'blocks-bulk') {
                if (!ctype_digit((string) ($r[2] ?? '')) || !ctype_digit((string) ($r[3] ?? ''))) { api_error('Invalid range', 400); }
                if (function_exists('lx_rate_limit') && !lx_rate_limit('blocks_bulk', 60, 60)) { api_error('rate limited', 429); }
                json_out(lx_blocks_bulk_api($net, (int) $r[2], (int) $r[3]), 200, 60);
            }
            if (($r[1] ?? '') === 'blocks') {
                if (function_exists('lx_rate_limit') && !lx_rate_limit('v1_blocks', 120, 60)) { api_error('rate limited', 429); }
                $start = (($r[2] ?? '') !== '' && ctype_digit((string) $r[2])) ? (int) $r[2] : null;
                json_out(lx_blocks_extended_api($net, $start), 200, 10);
            }
            if (($r[1] ?? '') === 'backend-info') {
                json_out(lx_backend_info_api($net), 200, 60);
            }
            if (($r[1] ?? '') === 'transaction-times') {
                json_out(lx_transaction_times_api($net), 200, 5);
            }
            if (($r[1] ?? '') === 'fullrbf' && ($r[2] ?? '') === 'replacements') {
                json_out(lx_replacements_api($net), 200, 2);   // we don't segregate fullRBF; return all replacements
            }
            if (($r[1] ?? '') === 'cpfp') {
                if (!is_txid($r[2] ?? '')) { api_error('Invalid txid', 400); }
                json_out(lx_cpfp_api($net, $r[2]), 200, 5);
            }
            if (($r[1] ?? '') === 'tx' && ($r[3] ?? '') === 'rbf') {
                if (!is_txid($r[2] ?? '')) { api_error('Invalid txid', 400); }
                json_out(lx_rbf_tx_api($net, $r[2]), 200, 2);
            }
            if (($r[1] ?? '') === 'replacements') {
                json_out(lx_replacements_api($net), 200, 2);
            }
            if (($r[1] ?? '') === 'block' && ($r[3] ?? '') === 'audit-summary') {
                if (!is_txid($r[2] ?? '')) { api_error('Invalid block hash', 400); }
                $as = lx_audit_summary_api($net, $r[2]);
                $as ? json_out($as, 200, 600) : api_error('Not found', 404);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'hashrate' && ($r[3] ?? '') === 'pools') {
                $per = (string) ($r[4] ?? '1w');
                if (!lx_period_valid($per)) { api_error('Invalid time period', 400); }
                json_out(lx_mining_hashrate_pools_api($net, $per), 200, 300);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'pool') {
                $slug = (string) ($r[3] ?? '');
                if ($slug === '') { api_error('Not found', 404); }
                if (($r[4] ?? '') === 'hashrate') { json_out(lx_mining_pool_hashrate_api($net, $slug), 200, 300); }
                if (($r[4] ?? '') === 'blocks') {
                    $before = (($r[5] ?? '') !== '' && ctype_digit((string) $r[5])) ? (int) $r[5] : null;
                    json_out(lx_mining_pool_blocks_api($net, $slug, $before), 200, 60);
                }
                json_out(lx_mining_pool_api($net, $slug), 200, 120);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'reward-stats') {
                json_out(lx_reward_stats_api($net, (int) ($r[3] ?? 144)), 200, 120);
            }
            if (($r[1] ?? '') === 'mining' && ($r[2] ?? '') === 'difficulty-adjustments') {
                json_out(lx_difficulty_adjustments_api($net, (string) ($r[3] ?? '1y')), 200, 1800);
            }
            if (($r[1] ?? '') === 'historical-price') {
                json_out(lx_historical_price_api($net), 200, 300);
            }
            if (($r[1] ?? '') === 'ws') {
                // Real WS traffic is proxied to the daemon (tools/ws-server.php) by Apache
                // before it reaches PHP; a plain HTTP hit here means no Upgrade was sent.
                api_error('WebSocket endpoint. Connect a WebSocket client to /api/v1/ws (see /docs).', 426);
            }
            api_error('Not found', 404);

        // ---- address prefix search (unsupported on Electrum lanes) --------
        case 'address-prefix':
            // A prefix index isn't available on a scripthash backend; return an
            // empty array (clients treat [] as 'no matches', 404 as an error).
            json_out([]);

        // ---- MWEB (Litecoin only, read-only) ------------------------------
        case 'mweb':
            if (!lx_mweb_enabled($net)) {
                api_error('Not found', 404);
            }
            $sub = $r[1] ?? 'tip';
            if ($sub === 'tip' || $sub === '') {
                json_out(lx_mweb_active($net), 200, 2);
            }
            if ($sub === 'blocks') {
                $from = max(0, (int) ($_GET['from'] ?? 0));
                $to   = (int) ($_GET['to'] ?? $from);
                if ($to < $from) {
                    api_error('to < from', 400);
                }
                json_out(lx_mweb_range($net, $from, $to), 200, 30);
            }
            if ($sub === 'block' && isset($r[2]) && is_txid($r[2])) {
                $m = lx_mweb_block($net, $r[2]);
                $m ? json_out($m) : api_error('Not found', 404);
            }
            // Indexed history (empty payloads when the index is absent, never an error).
            if ($sub === 'pegins') {
                $before = isset($_GET['before']) && is_string($_GET['before']) ? $_GET['before'] : null;
                $limit  = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
                json_out(lx_mweb_pegins_page($net, $before, $limit), 200, 15);
            }
            if ($sub === 'pegouts') {
                $before = isset($_GET['before']) && is_string($_GET['before']) ? $_GET['before'] : null;
                $limit  = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
                json_out(lx_mweb_pegouts_page($net, $before, $limit), 200, 15);
            }
            if ($sub === 'supply') {
                $limit = max(1, min(2000, (int) ($_GET['limit'] ?? 400)));
                json_out(['series' => lx_mweb_supply_series($net, $limit)], 200, 30);
            }
            if ($sub === 'clusters') {
                $limit = max(1, min(100, (int) ($_GET['limit'] ?? 15)));
                json_out(['clusters' => lx_mweb_pegout_clusters($net, $limit)], 200, 60);
            }
            // Composed snapshot for the live peg-flow hero (supply + recent block pegs
            // + round-trip links in one poll). Sub-calls are individually cached.
            if ($sub === 'live') {
                json_out(lx_mweb_live_snapshot($net), 200, 10);
            }
            api_error('Not found', 404);

        // ---- health -------------------------------------------------------
        case 'health':
            $hz = lx_health($net);
            json_out($hz, empty($hz['ok']) ? 503 : 200);

        default:
            api_error('Not found', 404);
    }
}
