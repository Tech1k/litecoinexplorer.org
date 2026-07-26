<?php
/**
 * Small request/response helpers shared by the API and the HTML views.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** HTML-escape for output in views. */
function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Emit permissive CORS headers (drop-in clients call /api from the browser). */
function lx_cors(): void
{
    static $sent = false;
    if ($sent) {
        return;
    }
    $sent = true;
    $origin = lx_config()['cors_origin'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}

/**
 * Send a JSON response (Esplora bodies) and exit. $cache > 0 adds a
 * Cache-Control max-age (seconds) so an edge/proxy can absorb polling bursts on
 * hot, cheap endpoints; 0 leaves the response uncacheable (the default).
 */
function json_out($data, int $status = 200, int $cache = 0): void
{
    if (!headers_sent()) {
        http_response_code($status);
        lx_cors();
        header('Content-Type: application/json; charset=utf-8');
        if ($cache > 0) {
            header('Cache-Control: public, max-age=' . $cache);
        }
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Send a text/plain (or other) body and exit. Used for hex, tip height, txid. */
function text_out(string $body, int $status = 200, string $ctype = 'text/plain', int $cache = 0): void
{
    if (!headers_sent()) {
        http_response_code($status);
        lx_cors();
        // charset only applies to text/*; binary (/raw) is application/octet-stream.
        $charset = stripos($ctype, 'text/') === 0 ? '; charset=utf-8' : '';
        header('Content-Type: ' . $ctype . $charset);
        if ($cache > 0) {
            header('Cache-Control: public, max-age=' . $cache);
        }
    }
    echo $body;
    exit;
}

/**
 * Esplora-style API error: plain-text body + status code. mempool.space and
 * blockstream/esplora both return the error message as the response body.
 */
function api_error(string $message, int $status = 400): void
{
    text_out($message, $status);
}

/** True if a string is valid lowercase/uppercase hex of an optional exact length. */
function is_hex(string $s, ?int $len = null): bool
{
    if ($len !== null && strlen($s) !== $len) {
        return false;
    }
    return $s !== '' && ctype_xdigit($s);
}

/** A 64-char hex txid/blockhash. */
function is_txid(string $s): bool
{
    return is_hex($s, 64);
}

/** Convert a BTC/LTC decimal-string amount (from Core) to integer satoshis. */
function coin_to_sat($amount): int
{
    // Amounts arrive as JSON numbers/strings like 0.00012340. Format to 8 dp
    // and work on the decimal string to avoid float-rounding the satoshis.
    if (!is_numeric($amount)) {
        return 0;
    }
    $s = sprintf('%.8f', (float) $amount);
    [$whole, $frac] = explode('.', $s, 2);
    $neg = ($whole[0] ?? '') === '-';
    $whole = ltrim($whole, '-');
    $frac = substr(str_pad($frac, 8, '0'), 0, 8);
    $sat = (int) $whole * 100000000 + (int) $frac;
    return $neg ? -$sat : $sat;
}

/** Format integer satoshis as a decimal coin string (8 dp, trimmed). */
function sat_to_coin(int $sat): string
{
    // Fixed 8 decimals, the block-explorer convention (e.g. 0.00000000),
    // so headline balances/fees don't render as a bare "0" or "12".
    $neg  = $sat < 0;
    $sat  = abs($sat);
    $whole = intdiv($sat, 100000000);
    $frac  = str_pad((string) ($sat % 100000000), 8, '0', STR_PAD_LEFT);
    return ($neg ? '-' : '') . $whole . '.' . $frac;
}

/** Read the raw POST body (for broadcast). */
function request_body(): string
{
    return file_get_contents('php://input') ?: '';
}

/** Human relative time, e.g. "3 min ago". */
function time_ago(int $ts): string
{
    $d = time() - $ts;
    if ($d < 0) {
        $d = 0;
    }
    if ($d < 60) {
        return $d . ' sec ago';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' min ago';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' hr ago';
    }
    return floor($d / 86400) . ' day' . ($d < 172800 ? '' : 's') . ' ago';
}

/** Compact duration: 45 -> "45s", 150 -> "2m", 3720 -> "1h 2m", 90000 -> "1d". */
function lx_dur_short(int $s): string
{
    $s = abs($s);
    if ($s < 60) {
        return $s . 's';
    }
    if ($s < 3600) {
        return intdiv($s, 60) . 'm';
    }
    if ($s < 86400) {
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        return $m ? $h . 'h ' . $m . 'm' : $h . 'h';
    }
    return intdiv($s, 86400) . 'd';
}

/** Thousands-separated integer. */
function commas($n): string
{
    return number_format((float) $n, 0, '.', ',');
}

/** Shorten a hash for display: first8...last8. */
function shorten(string $s, int $head = 10, int $tail = 8): string
{
    if (strlen($s) <= $head + $tail + 1) {
        return $s;
    }
    return substr($s, 0, $head) . '...' . substr($s, -$tail);
}

/** Human-readable hashrate from hashes/second. */
/**
 * Decimal places needed so two axis ticks a $step apart don't round to the same
 * label, never fewer than $base (so step-unaware callers keep their precision).
 * $step must already be in the same scaled unit as the value being formatted.
 * Returns $base when $step is 0/unknown, giving byte-identical legacy output.
 */
function lx_step_dec(float $step, int $base = 0): int
{
    if ($step > 0 && $step < 1) {
        $need = (int) ceil(-log10($step));
        if ($need > $base) {
            return $need > 6 ? 6 : $need;
        }
    }
    return $base;
}

function lx_hashrate(float $hs, float $step = 0.0): string
{
    if ($hs <= 0) {
        return '0 H/s';
    }
    $units = ['H/s', 'kH/s', 'MH/s', 'GH/s', 'TH/s', 'PH/s', 'EH/s'];
    $i = 0;
    while ($hs >= 1000 && $i < count($units) - 1) {
        $hs /= 1000;
        $step /= 1000;
        $i++;
    }
    // $step=0 -> lx_step_dec returns the base (0 above 100, else 2): unchanged.
    return number_format($hs, lx_step_dec($step, $hs >= 100 ? 0 : 2)) . ' ' . $units[$i];
}

/**
 * Parse an OP_RETURN scriptPubKey (hex) into its data pushes. Each entry is
 * ['hex' => raw hex, 'text' => decoded string or null, 'label' => marker or null].
 * Text is filled when the push is valid UTF-8 (or printable ASCII) with no
 * control bytes; the witness-commitment marker (aa21a9ed) is recognised.
 */
function lx_parse_op_return(string $hex): array
{
    $bin = @hex2bin($hex);
    if ($bin === false || $bin === '' || ord($bin[0]) !== 0x6a) {
        return [];
    }
    $pushes = [];
    $i = 1;
    $len = strlen($bin);
    while ($i < $len) {
        $op = ord($bin[$i]);
        $i++;
        if ($op >= 0x01 && $op <= 0x4b) {
            $dlen = $op;
        } elseif ($op === 0x4c) {                 // OP_PUSHDATA1
            if ($i >= $len) { break; }
            $dlen = ord($bin[$i]); $i += 1;
        } elseif ($op === 0x4d) {                 // OP_PUSHDATA2
            if ($i + 1 >= $len) { break; }
            $dlen = ord($bin[$i]) | (ord($bin[$i + 1]) << 8); $i += 2;
        } elseif ($op === 0x4e) {                 // OP_PUSHDATA4
            if ($i + 3 >= $len) { break; }
            $dlen = ord($bin[$i]) | (ord($bin[$i + 1]) << 8)
                  | (ord($bin[$i + 2]) << 16) | (ord($bin[$i + 3]) << 24); $i += 4;
        } else {
            continue;                              // OP_0 / OP_1..16 / other: no payload
        }
        if ($dlen < 0 || $i + $dlen > $len) {
            $dlen = $len - $i;                     // truncated push, take the rest
        }
        $data = substr($bin, $i, $dlen);
        $i += $dlen;
        $dhex = bin2hex($data);

        $text = null;
        if ($data !== '' && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $data)) {
            if (function_exists('mb_check_encoding')) {
                if (mb_check_encoding($data, 'UTF-8')) { $text = $data; }
            } elseif (ctype_print($data)) {
                $text = $data;
            }
        }
        $label = strncmp($dhex, 'aa21a9ed', 8) === 0 ? 'witness commitment' : null;
        $pushes[] = ['hex' => $dhex, 'text' => $text, 'label' => $label];
    }
    return $pushes;
}
