<?php
/**
 * WebSocket daemon - a mempool.space-compatible /api/v1/ws server in pure PHP, no
 * Composer/deps. A single long-running CLI process: raw stream_socket_server + a
 * from-scratch RFC6455 handshake/frame codec, an event loop (stream_select for socket
 * I/O + a periodic chain-poll tick), and per-client subscriptions. It reuses the same
 * lib/ the HTTP API uses (litecoind RPC + Electrum). Front it with Apache
 * mod_proxy_wstunnel at /api/v1/ws (see DEPLOY.md). Behind Cloudflare, WebSockets pass
 * through transparently.
 *
 * Usage:  php tools/ws-server.php [net-slug] [port]      (default ltc-mainnet 8482)
 *
 * Protocol (client->server JSON): {"action":"init"} , {"action":"want","data":[...]} ,
 *   {"action":"ping"} , {"track-tx":"<txid>"|"stop"} , {"track-address":"<addr>"|"stop"} ,
 *   {"track-rbf":"all"|"stop"}. Server pushes: block/blocks/mempoolInfo/fees/
 *   mempool-blocks/transactions/vBytesPerSecond/da/conversions on the wanted streams,
 *   {"pong":true}, {"txConfirmed":txid}, address-transactions/block-transactions, rbfLatest.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/ws.php';
error_reporting(E_ALL & ~E_DEPRECATED);

$slug = $argv[1] ?? 'ltc-mainnet';
$net  = lx_net($slug);
if (!$net) {
    fwrite(STDERR, "unknown or disabled network: $slug\n");
    exit(1);
}
$port = (int) ($argv[2] ?? (lx_config()['ws_port'] ?? 8482));
$host = '127.0.0.1';

$server = @stream_socket_server("tcp://$host:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "bind $host:$port failed: $errstr ($errno)\n");
    exit(1);
}
stream_set_blocking($server, false);
fwrite(STDERR, "[ws] listening on $host:$port for {$net['slug']}\n");

// ---- RFC6455 codec --------------------------------------------------------

/** 101 handshake response for an HTTP Upgrade request, or null if not a WS handshake. */
function ws_handshake_response(string $req): ?string
{
    if (!preg_match('/Sec-WebSocket-Key:\s*(.+?)\r\n/i', $req, $m)) {
        return null;
    }
    $accept = base64_encode(sha1(trim($m[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    return "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: $accept\r\n\r\n";
}

/** Encode a server->client frame (unmasked). $op: 0x1 text, 0x8 close, 0xA pong. */
function ws_encode(string $payload, int $op = 0x1): string
{
    $b1  = chr(0x80 | $op);
    $len = strlen($payload);
    if ($len < 126) {
        $hdr = $b1 . chr($len);
    } elseif ($len <= 0xffff) {
        $hdr = $b1 . chr(126) . pack('n', $len);
    } else {
        $hdr = $b1 . chr(127) . pack('J', $len);
    }
    return $hdr . $payload;
}

/**
 * Pull all complete frames out of a client buffer (client->server frames are always
 * masked). Returns [[opcode, payload], ...]; leaves any partial frame in $buf.
 */
function ws_decode(string &$buf): array
{
    $frames = [];
    while (true) {
        $n = strlen($buf);
        if ($n < 2) { break; }
        $b2     = ord($buf[1]);
        $opcode = ord($buf[0]) & 0x0f;
        $masked = ($b2 & 0x80) !== 0;
        $len    = $b2 & 0x7f;
        $off    = 2;
        if ($len === 126) {
            if ($n < 4) { break; }
            $len = unpack('n', substr($buf, 2, 2))[1];
            $off = 4;
        } elseif ($len === 127) {
            if ($n < 10) { break; }
            $len = unpack('J', substr($buf, 2, 8))[1];
            $off = 10;
        }
        if ($len > 2000000) { $frames[] = [0x8, '']; $buf = ''; break; }   // oversized -> signal close (can't resync)
        $mask = '';
        if ($masked) {
            if ($n < $off + 4) { break; }
            $mask = substr($buf, $off, 4);
            $off += 4;
        }
        if ($n < $off + $len) { break; }            // frame not fully arrived yet
        $payload = substr($buf, $off, $len);
        if ($masked) {
            $um = '';
            for ($i = 0; $i < $len; $i++) { $um .= $payload[$i] ^ $mask[$i & 3]; }
            $payload = $um;
        }
        $buf    = substr($buf, $off + $len);
        $frames[] = [$opcode, $payload];
    }
    return $frames;
}

// ---- client registry ------------------------------------------------------

$clients = [];   // (int)$sock => state
function ws_client_new($sock): array
{
    return [
        'sock' => $sock, 'hs' => false, 'buf' => '', 'born' => time(),
        'wants' => [], 'trackTx' => null, 'trackAddr' => null, 'trackRbf' => false,
        'trackTxs' => [], 'trackAddrs' => [], 'trackMpTxids' => false, 'mpNeedsFull' => false,
        'notified' => [], 'seenAddr' => [], 'seenAddrs' => [],
    ];
}

/**
 * Write ALL bytes to a non-blocking socket (fwrite can short-write when the send
 * buffer is full - a partial WS frame would desync the client permanently). Returns
 * false if the socket errors or stalls with no progress (treated as a dead peer).
 */
function ws_write($sock, string $data): bool
{
    $len = strlen($data); $off = 0; $stall = 0;
    while ($off < $len) {
        $w = @fwrite($sock, substr($data, $off, 65535));
        if ($w === false) { return false; }
        if ($w === 0) {
            // Send buffer full (slow/stalled reader). Tolerate only a brief blip so a
            // single stalled client can't freeze the single-threaded loop - bounded to
            // ~5ms, then treat the peer as dead (it will reconnect). A full per-client
            // output queue + stream_select write-set would be the zero-stall design.
            if (++$stall > 10) { return false; }
            usleep(500);
            continue;
        }
        $off += $w; $stall = 0;
    }
    return true;
}

/** Send a JSON object to a client; returns false if the socket died. */
function ws_send($sock, array $obj): bool
{
    return ws_write($sock, ws_encode(json_encode($obj, JSON_UNESCAPED_SLASHES)));
}

// ---- message handling -----------------------------------------------------

function ws_on_message(array &$c, string $text, array $net): void
{
    $msg = json_decode($text, true);
    if (!is_array($msg)) { return; }

    if (isset($msg['action'])) {
        if ($msg['action'] === 'ping') {
            ws_send($c['sock'], ['pong' => true]);
        } elseif ($msg['action'] === 'want' && is_array($msg['data'] ?? null)) {
            // Cap the list: only a handful of stream names are meaningful, so bound it
            // to stop a client pinning per-tick in_array scans / holding a huge array.
            $c['wants'] = array_slice(array_values(array_filter($msg['data'], 'is_string')), 0, 24);
            ws_send($c['sock'], lx_ws_init($net, $c['wants']));
            if (in_array('live-2h-chart', $c['wants'], true)) { ws_send($c['sock'], ['live-2h-chart' => lx_ws_2h_chart($net)]); }
        } elseif ($msg['action'] === 'init') {
            if (!$c['wants']) { $c['wants'] = ['blocks', 'stats', 'mempool-blocks']; }   // persist so ticks keep pushing
            ws_send($c['sock'], lx_ws_init($net, $c['wants']));
        }
    }
    if (array_key_exists('track-txs', $msg)) {                 // batch tx tracking (plural)
        $ids = [];
        foreach ((is_array($msg['track-txs']) ? $msg['track-txs'] : []) as $t) {
            if (is_string($t) && preg_match('/^[0-9a-f]{64}$/i', $t)) { $ids[strtolower($t)] = true; }
        }
        $c['trackTxs'] = array_slice(array_keys($ids), 0, 25);
        $c['notified'] = [];
    }
    if (array_key_exists('track-addresses', $msg)) {           // batch address tracking (plural)
        $addrs = [];
        foreach ((is_array($msg['track-addresses']) ? $msg['track-addresses'] : []) as $a) {
            if (count($addrs) >= 20) { break; }
            if (is_string($a) && lx_address_valid($net, $a)) { $addrs[$a] = true; }
        }
        $c['trackAddrs'] = array_keys($addrs);
        $c['seenAddrs'] = [];
    }
    if (array_key_exists('track-mempool', $msg) || array_key_exists('track-mempool-txids', $msg)) {
        $v = $msg['track-mempool-txids'] ?? $msg['track-mempool'] ?? false;
        $c['trackMpTxids'] = ($v === true || $v === 'true');
        $c['mpNeedsFull'] = $c['trackMpTxids'];               // first tick sends the full set, then deltas
    }
    if (array_key_exists('track-tx', $msg)) {
        $v = $msg['track-tx'];
        $c['trackTx'] = (is_string($v) && $v !== 'stop' && preg_match('/^[0-9a-f]{64}$/i', $v)) ? strtolower($v) : null;
        $c['notified'] = [];
    }
    if (array_key_exists('track-address', $msg)) {
        // Validate before accepting so a client can't pin the daemon on a per-tick
        // history walk for a garbage/non-indexable string.
        $v = $msg['track-address'];
        $c['trackAddr'] = (is_string($v) && $v !== 'stop' && $v !== '' && lx_address_valid($net, $v)) ? $v : null;
        $c['seenAddr'] = [];
    }
    if (array_key_exists('track-rbf', $msg)) {
        $v = $msg['track-rbf'];
        $c['trackRbf'] = ($v === 'all' || $v === 'fullRbf');
    }
}

// ---- event loop -----------------------------------------------------------

$last      = ['tip' => -1, 'mvsize' => 0, 'mtime' => 0, 'rbf_ts' => 0];
$mpPrev    = null;    // previous mempool txid set, for track-mempool-txids deltas (shared across clients)
$lastChain = 0.0;
$lastConv  = 0.0;
$last2h    = 0.0;
$CHAIN_SEC = 5.0;      // poll the chain every ~5s (matches the app's 5s tip cache)
$CONV_SEC  = 60.0;     // push price ~once a minute
$TWOH_SEC  = 60.0;     // refresh the live-2h-chart ~once a minute (independent of price)
$HS_SEC    = 15;       // reap a connection that never completes the WS handshake within this

while (true) {
    $read = [$server];
    foreach ($clients as $c) { $read[] = $c['sock']; }
    $w = null; $e = null;
    $n = @stream_select($read, $w, $e, 1, 0);
    // Reap slot-squatters: connections that opened but never finished the WS handshake
    // (silent sockets are never readable, so they'd otherwise hold a slot forever).
    $nowT = time();
    foreach ($clients as $id => $c) {
        if (!$c['hs'] && ($nowT - ($c['born'] ?? $nowT)) > $HS_SEC) { @fclose($c['sock']); unset($clients[$id]); }
    }

    if ($n) {
        foreach ($read as $sock) {
            if ($sock === $server) {
                $cs = @stream_socket_accept($server, 0);
                if ($cs) {
                    if (count($clients) >= 512) { @fclose($cs); continue; }   // cap total connections
                    stream_set_blocking($cs, false);
                    $clients[(int) $cs] = ws_client_new($cs);
                }
                continue;
            }
            $id = (int) $sock;
            if (!isset($clients[$id])) { continue; }
            $chunk = @fread($sock, 65535);
            if ($chunk === '' || $chunk === false) {
                // peer closed / errored
                @fclose($sock);
                unset($clients[$id]);
                continue;
            }
            $clients[$id]['buf'] .= $chunk;

            if (!$clients[$id]['hs']) {
                // Guard the pre-handshake buffer: a peer streaming bytes that never
                // contain the header terminator must not grow memory without bound.
                if (strlen($clients[$id]['buf']) > 16384) { @fclose($sock); unset($clients[$id]); continue; }
                if (strpos($clients[$id]['buf'], "\r\n\r\n") !== false) {
                    $resp = ws_handshake_response($clients[$id]['buf']);
                    if ($resp === null || !ws_write($sock, $resp)) { @fclose($sock); unset($clients[$id]); continue; }
                    $clients[$id]['hs']  = true;
                    $clients[$id]['buf'] = '';
                }
                continue;
            }
            foreach (ws_decode($clients[$id]['buf']) as [$op, $payload]) {
                if ($op === 0x8) {                       // close
                    @ws_write($sock, ws_encode('', 0x8));
                    @fclose($sock); unset($clients[$id]);
                    break;
                }
                if ($op === 0x9) { ws_write($sock, ws_encode($payload, 0xA)); continue; } // ping->pong
                if ($op === 0xA) { continue; }            // pong
                if ($op === 0x1 && isset($clients[$id])) {
                    // A single malformed/hostile message must never take down the whole daemon.
                    try { ws_on_message($clients[$id], $payload, $net); }
                    catch (Throwable $e) { fwrite(STDERR, "[ws] on_message: " . $e->getMessage() . "\n"); }
                }
            }
        }
    }

    // ---- chain tick -------------------------------------------------------
    $now = microtime(true);
    if ($now - $lastChain < $CHAIN_SEC || !$clients) {
        continue;
    }
    $lastChain = $now;
    try {
        $tip = lx_tip_height($net);
        $mem = lx_esplora_mempool($net);
        $t   = time();
        $mvsize = (int) ($mem['vsize'] ?? 0);
        $vbps = 0.0;
        if ($last['mtime'] > 0 && $t > $last['mtime']) {
            $d = $mvsize - $last['mvsize'];
            if ($d > 0) { $vbps = $d / ($t - $last['mtime']); }
        }
        $newBlock = ($tip !== $last['tip'] && $last['tip'] >= 0);
        $firstTip = ($last['tip'] < 0);
        // Advance the baseline NOW (before any heavy work / per-client push) so a later
        // failure can't stall new-block or vBytesPerSecond detection.
        $last['tip'] = $tip; $last['mvsize'] = $mvsize; $last['mtime'] = $t;

        $blockExt = null; $da = null;
        if ($newBlock || $firstTip) {
            $h = lx_block_hash_at($net, $tip);
            if ($h) { $blockExt = lx_ws_block_extended($net, $h); }
            $da = lx_difficulty_adjustment($net);
        }

        // RBF detection pass; collect events newer than what we last pushed.
        $newRbf = [];
        if (function_exists('lx_rbf_tick')) {
            lx_rbf_tick($net, 120);
            foreach (lx_replacements_api($net, 25) as $node) {
                if ((int) ($node['time'] ?? 0) > $last['rbf_ts']) { $newRbf[] = $node; }
            }
            if ($newRbf) { $last['rbf_ts'] = (int) ($newRbf[0]['time'] ?? $last['rbf_ts']); }
        }

        $conv = null;
        if ($now - $lastConv >= $CONV_SEC) {
            $conv = lx_ws_conversions($net);
            $lastConv = $now;
        }

        // Shared state computed ONCE per tick (not per client) - one set of RPC calls
        // regardless of how many clients are subscribed.
        $shInfo = lx_ws_mempoolinfo($net);
        $shFees = null; try { $shFees = lx_fees_recommended($net); } catch (Throwable $e) {}
        $shTxs  = lx_ws_recent_txs($net);
        $shMB   = lx_mempool_blocks_api($net);
        $vbpsInt = (int) round($vbps);

        // Mempool-txid delta (shared): compute the current set once + the added/removed vs the
        // previous tick, so every track-mempool-txids client reuses one diff. Only when needed.
        $anyMp = false; $any2h = false;
        foreach ($clients as $cc) {
            if ($cc['trackMpTxids']) { $anyMp = true; }
            if (in_array('live-2h-chart', $cc['wants'], true)) { $any2h = true; }
        }
        $mpTxids = $anyMp ? lx_ws_mempool_txids($net) : null;
        $mpAdded = []; $mpRemoved = [];
        if ($mpTxids !== null && $mpPrev !== null) {
            $mpAdded   = array_keys(array_diff_key($mpTxids, $mpPrev));
            $mpRemoved = array_keys(array_diff_key($mpPrev, $mpTxids));
        }
        // Refresh the 2h chart on its OWN cadence, not tied to price availability (else it
        // stops updating whenever fiat is unavailable).
        $sh2h = null;
        if ($any2h && $now - $last2h >= $TWOH_SEC) { $sh2h = lx_ws_2h_chart($net); $last2h = $now; }

        foreach ($clients as $id => &$c) {
            if (!$c['hs']) { continue; }
            try {
                $push = [];
                $wStats  = in_array('stats', $c['wants'], true);
                $wMB     = in_array('mempool-blocks', $c['wants'], true);
                $wBlocks = in_array('blocks', $c['wants'], true);
                if ($wStats) {
                    $push['mempoolInfo']     = $shInfo;
                    $push['vBytesPerSecond'] = $vbpsInt;
                    if ($shFees) { $push['fees'] = $shFees; }
                    $push['transactions']    = $shTxs;
                }
                if ($wMB) { $push['mempool-blocks'] = $shMB; }
                if (($newBlock || $firstTip) && $blockExt && $wBlocks) { $push['block'] = $blockExt; }
                if ($da && $wStats) { $push['da'] = $da; }
                if ($conv) { $push['conversions'] = $conv; }
                if ($c['trackRbf'] && $newRbf) { $push['rbfLatest'] = $newRbf; }
                if ($c['trackTx'] && empty($c['notified'][$c['trackTx']])) {   // stop looking up once confirmed
                    $tx = lx_find_tx($net, $c['trackTx']);
                    if (is_array($tx) && !empty($tx['status']['confirmed'])) {
                        $push['txConfirmed'] = $c['trackTx'];
                        $c['notified'][$c['trackTx']] = true;
                    }
                }
                if ($c['trackAddr']) {
                    $txs = lx_address_txs($net, $c['trackAddr'], 'all');
                    $memNew = []; $blkNew = [];
                    foreach ((is_array($txs) ? $txs : []) as $atx) {
                        $tid = $atx['txid'] ?? '';
                        if ($tid === '' || isset($c['seenAddr'][$tid])) { continue; }
                        $c['seenAddr'][$tid] = true;
                        if (!empty($atx['status']['confirmed'])) { $blkNew[] = $atx; } else { $memNew[] = $atx; }
                    }
                    if (count($c['seenAddr']) > 600) { $c['seenAddr'] = array_slice($c['seenAddr'], -300, null, true); }
                    if ($memNew) { $push['address-transactions'] = $memNew; }
                    if ($blkNew) { $push['block-transactions'] = $blkNew; }
                }
                if ($c['trackTxs']) {                              // batch tx tracking -> {txsConfirmed:[...]}
                    $confd = [];
                    foreach ($c['trackTxs'] as $tid) {
                        if (!empty($c['notified'][$tid])) { continue; }
                        $tx = lx_find_tx($net, $tid);
                        if (is_array($tx) && !empty($tx['status']['confirmed'])) { $confd[] = $tid; $c['notified'][$tid] = true; }
                    }
                    if ($confd) { $push['txsConfirmed'] = $confd; }
                }
                if ($c['trackAddrs']) {                            // batch address tracking -> {multi-address-transactions}
                    $mAll = []; $bAll = [];
                    foreach ($c['trackAddrs'] as $addr) {
                        $atxs = lx_address_txs($net, $addr, 'all');
                        foreach ((is_array($atxs) ? $atxs : []) as $atx) {
                            $tid = $atx['txid'] ?? ''; $k = $addr . ':' . $tid;
                            if ($tid === '' || isset($c['seenAddrs'][$k])) { continue; }
                            $c['seenAddrs'][$k] = true;
                            if (!empty($atx['status']['confirmed'])) { $bAll[] = $atx; } else { $mAll[] = $atx; }
                        }
                    }
                    if (count($c['seenAddrs']) > 2000) { $c['seenAddrs'] = array_slice($c['seenAddrs'], -1000, null, true); }
                    if ($mAll || $bAll) { $push['multi-address-transactions'] = ['mempool' => $mAll, 'confirmed' => $bAll]; }
                }
                if ($c['trackMpTxids'] && $mpTxids !== null) {     // mempool txid delta stream
                    if (!empty($c['mpNeedsFull'])) { $push['mempool-txids'] = ['added' => array_keys($mpTxids), 'removed' => []]; $c['mpNeedsFull'] = false; }
                    elseif ($mpAdded || $mpRemoved) { $push['mempool-txids'] = ['added' => $mpAdded, 'removed' => $mpRemoved]; }
                }
                if ($sh2h !== null && in_array('live-2h-chart', $c['wants'], true)) { $push['live-2h-chart'] = $sh2h; }
                // Push, or send a WS ping as a keepalive so dead/half-open sockets are reaped.
                $ok = $push ? ws_send($c['sock'], $push) : ws_write($c['sock'], ws_encode('', 0x9));
                if (!$ok) { @fclose($c['sock']); unset($clients[$id]); }
            } catch (Throwable $ce) {
                // one client's failure must not starve the others
            }
        }
        unset($c);
        if ($mpTxids !== null) { $mpPrev = $mpTxids; }   // advance the shared mempool-txid baseline
    } catch (Throwable $ex) {
        fwrite(STDERR, "[ws] tick error: " . $ex->getMessage() . "\n");
    }
}
