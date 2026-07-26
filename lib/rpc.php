<?php
/**
 * Bitcoin/Litecoin Core JSON-RPC client (curl). Answers everything electrs
 * cannot: block bodies, raw/decoded tx (txindex=1), mempool, fee estimates,
 * and transaction broadcast.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

class RpcException extends RuntimeException
{
    public $rpcCode;
    public function __construct(string $message, int $rpcCode = 0)
    {
        parent::__construct($message);
        $this->rpcCode = $rpcCode;
    }
}

/**
 * POST a prepared JSON-RPC body over a PERSISTENT, kept-alive curl handle
 * (one per RPC URL, reused for every call in the request). bitcoind/litecoind
 * speak HTTP/1.1 keep-alive on the RPC port, so this avoids a fresh TCP
 * handshake per call and the TIME_WAIT / ephemeral-port churn that caps
 * throughput under load. Returns [httpCode, responseBody].
 *
 * A kept-alive socket the daemon has since closed makes curl_exec() return
 * false; that only happens on a REUSED handle, so we drop it and retry exactly
 * once with a fresh connection. A fresh handle failing is a real transport
 * error and is not retried. The handle is never closed within the request.
 */
function lx_rpc_exec(array $rpc, string $payload, int $timeout): array
{
    static $handles = [];
    $url  = $rpc['url'];
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/plain', 'Connection: keep-alive'],
        CURLOPT_USERPWD        => ($rpc['user'] ?? '') . ':' . ($rpc['pass'] ?? ''),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_TCP_NODELAY    => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ];
    $tries = 0;
    while (true) {
        $tries++;
        $reused = isset($handles[$url]);
        if (!$reused) {
            $h = curl_init();
            if ($h === false) {
                throw new RpcException('Core RPC unreachable: curl_init failed');
            }
            $handles[$url] = $h;   // never store a falsy handle in the static map
        }
        $ch = $handles[$url];
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        if ($resp !== false) {
            return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $resp];
        }
        $err = curl_error($ch);
        curl_close($ch);
        unset($handles[$url]);
        // Only a reused handle failing looks like a dropped keep-alive socket;
        // retry once. A fresh handle failing is a genuine transport error.
        if (!$reused || $tries >= 2) {
            throw new RpcException('Core RPC unreachable: ' . $err);
        }
    }
    // unreachable
}

/** Single JSON-RPC call. Throws RpcException on transport or RPC error. */
function lx_rpc(array $net, string $method, array $params = [], ?int $timeout = null)
{
    $rpc = $net['rpc'];
    $payload = json_encode([
        'jsonrpc' => '1.0',
        'id'      => 'litecoinexplorer',
        'method'  => $method,
        'params'  => $params,
    ], JSON_UNESCAPED_SLASHES);

    // A per-call $timeout overrides the config default - used by the cron-only
    // gettxoutsetinfo warmer, which needs minutes on a node without coinstatsindex.
    list($http, $resp) = lx_rpc_exec($rpc, $payload, $timeout ?? (int) ($rpc['timeout'] ?? 25));

    if ($http === 401) {
        throw new RpcException('Core RPC auth failed (check rpcuser/rpcpassword).');
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new RpcException('Core RPC bad response (HTTP ' . $http . ').');
    }
    if (isset($json['error']) && $json['error'] !== null) {
        $msg  = $json['error']['message'] ?? 'RPC error';
        $code = (int) ($json['error']['code'] ?? 0);
        throw new RpcException($msg, $code);
    }
    return $json['result'] ?? null;
}

/** Like lx_rpc but returns null instead of throwing on RPC error (not transport). */
function lx_rpc_soft(array $net, string $method, array $params = [], ?int $timeout = null)
{
    try {
        return lx_rpc($net, $method, $params, $timeout);
    } catch (RpcException $e) {
        if ($e->rpcCode !== 0) {
            return null; // RPC-level (e.g. -5 not found): expected, return null
        }
        throw $e;        // transport/auth: real failure
    }
}

/**
 * Batched JSON-RPC. $calls is a list of [method, params]. Returns a parallel
 * array of results (null where that call errored). Used to resolve many
 * prevout txs / enrich block tx lists in one round-trip.
 */
function lx_rpc_batch(array $net, array $calls): array
{
    if (!$calls) {
        return [];
    }
    $rpc = $net['rpc'];
    $batch = [];
    foreach ($calls as $i => $c) {
        $batch[] = [
            'jsonrpc' => '1.0',
            'id'      => $i,
            'method'  => $c[0],
            'params'  => $c[1] ?? [],
        ];
    }
    list(, $resp) = lx_rpc_exec($rpc, json_encode($batch, JSON_UNESCAPED_SLASHES), (int) ($rpc['timeout'] ?? 40));

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new RpcException('Core RPC bad batch response.');
    }
    $out = array_fill(0, count($calls), null);
    foreach ($json as $item) {
        $id = $item['id'] ?? null;
        if ($id === null || !array_key_exists($id, $out)) {
            continue;
        }
        $out[$id] = (isset($item['error']) && $item['error'] !== null)
            ? null
            : ($item['result'] ?? null);
    }
    return $out;
}
