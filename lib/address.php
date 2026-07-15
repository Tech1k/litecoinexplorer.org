<?php
/**
 * Address codec: decode base58check + bech32/bech32m addresses to a
 * scriptPubKey (hex), so we can compute the Electrum scripthash and validate
 * user input. Pure PHP, no gmp/bcmath dependency (base58 uses byte-array
 * long division).
 *
 * We only need address -> scriptPubKey here; the reverse (scriptPubKey ->
 * address, with type/asm) comes from Core's verbose tx decoding.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// ---- base58check ----------------------------------------------------------

const TS_B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

/** Decode base58 to a binary string, or null on invalid character. */
function ts_base58_decode(string $s): ?string
{
    $map = [];
    for ($i = 0, $n = strlen(TS_B58_ALPHABET); $i < $n; $i++) {
        $map[TS_B58_ALPHABET[$i]] = $i;
    }
    $bytes = [0];
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if (!isset($map[$c])) {
            return null;
        }
        $carry = $map[$c];
        for ($j = 0; $j < count($bytes); $j++) {
            $carry += $bytes[$j] * 58;
            $bytes[$j] = $carry & 0xff;
            $carry >>= 8;
        }
        while ($carry > 0) {
            $bytes[] = $carry & 0xff;
            $carry >>= 8;
        }
    }
    // Leading '1's are leading zero bytes.
    for ($i = 0; $i < $len && $s[$i] === '1'; $i++) {
        $bytes[] = 0;
    }
    $bin = '';
    for ($i = count($bytes) - 1; $i >= 0; $i--) {
        $bin .= chr($bytes[$i]);
    }
    return $bin;
}

/** Decode base58check; returns [versionByte, payloadBinary] or null. */
function ts_base58check_decode(string $s): ?array
{
    $raw = ts_base58_decode($s);
    if ($raw === null || strlen($raw) < 5) {
        return null;
    }
    $data = substr($raw, 0, -4);
    $check = substr($raw, -4);
    $calc = substr(hash('sha256', hash('sha256', $data, true), true), 0, 4);
    if (!hash_equals($calc, $check)) {
        return null;
    }
    return [ord($data[0]), substr($data, 1)];
}

// ---- bech32 / bech32m -----------------------------------------------------

const TS_BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
const TS_BECH32_CONST  = 1;
const TS_BECH32M_CONST = 0x2bc830a3;

function ts_bech32_polymod(array $values): int
{
    $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    $chk = 1;
    foreach ($values as $v) {
        $top = $chk >> 25;
        $chk = (($chk & 0x1ffffff) << 5) ^ $v;
        for ($i = 0; $i < 5; $i++) {
            if (($top >> $i) & 1) {
                $chk ^= $gen[$i];
            }
        }
    }
    return $chk;
}

function ts_bech32_hrp_expand(string $hrp): array
{
    $out = [];
    $len = strlen($hrp);
    for ($i = 0; $i < $len; $i++) {
        $out[] = ord($hrp[$i]) >> 5;
    }
    $out[] = 0;
    for ($i = 0; $i < $len; $i++) {
        $out[] = ord($hrp[$i]) & 31;
    }
    return $out;
}

/** Decode a bech32 string; returns [hrp, data5bit[], spec] or null. */
function ts_bech32_decode(string $s): ?array
{
    $slen = strlen($s);
    if ($slen < 8 || $slen > 110) {
        return null;
    }
    $lower = strtolower($s);
    $upper = strtoupper($s);
    if ($s !== $lower && $s !== $upper) {
        return null; // mixed case
    }
    $s = $lower;
    $pos = strrpos($s, '1');
    if ($pos === false || $pos < 1 || $pos + 7 > strlen($s)) {
        return null;
    }
    $hrp = substr($s, 0, $pos);
    $dataPart = substr($s, $pos + 1);
    $data = [];
    $len = strlen($dataPart);
    for ($i = 0; $i < $len; $i++) {
        $idx = strpos(TS_BECH32_CHARSET, $dataPart[$i]);
        if ($idx === false) {
            return null;
        }
        $data[] = $idx;
    }
    $values = array_merge(ts_bech32_hrp_expand($hrp), $data);
    $chk = ts_bech32_polymod($values);
    if ($chk === TS_BECH32_CONST) {
        $spec = 'bech32';
    } elseif ($chk === TS_BECH32M_CONST) {
        $spec = 'bech32m';
    } else {
        return null;
    }
    // strip the 6-symbol checksum
    return [$hrp, array_slice($data, 0, -6), $spec];
}

/** Convert between bit groups (e.g. 5->8). Returns array or null. */
function ts_convertbits(array $data, int $from, int $to, bool $pad): ?array
{
    $acc = 0;
    $bits = 0;
    $out = [];
    $maxv = (1 << $to) - 1;
    foreach ($data as $value) {
        if ($value < 0 || ($value >> $from) !== 0) {
            return null;
        }
        $acc = ($acc << $from) | $value;
        $bits += $from;
        while ($bits >= $to) {
            $bits -= $to;
            $out[] = ($acc >> $bits) & $maxv;
        }
    }
    if ($pad) {
        if ($bits > 0) {
            $out[] = ($acc << ($to - $bits)) & $maxv;
        }
    } elseif ($bits >= $from || (($acc << ($to - $bits)) & $maxv)) {
        return null;
    }
    return $out;
}

/**
 * Decode a segwit address for $hrp. Returns [version, programBinary] or null.
 * Enforces the bech32 (v0) vs bech32m (v1+) split and length rules.
 */
function ts_segwit_decode(string $hrp, string $addr): ?array
{
    $dec = ts_bech32_decode($addr);
    if ($dec === null) {
        return null;
    }
    [$ahrp, $data, $spec] = $dec;
    if ($ahrp !== $hrp || count($data) < 1) {
        return null;
    }
    $version = $data[0];
    if ($version > 16) {
        return null;
    }
    $prog = ts_convertbits(array_slice($data, 1), 5, 8, false);
    if ($prog === null || count($prog) < 2 || count($prog) > 40) {
        return null;
    }
    if ($version === 0 && count($prog) !== 20 && count($prog) !== 32) {
        return null;
    }
    if ($version === 0 && $spec !== 'bech32') {
        return null;
    }
    if ($version >= 1 && $spec !== 'bech32m') {
        return null;
    }
    $bin = '';
    foreach ($prog as $b) {
        $bin .= chr($b);
    }
    return [$version, $bin];
}

// ---- address -> scriptPubKey ----------------------------------------------

/**
 * Build the scriptPubKey (hex) for an address on $net, or null if it isn't a
 * valid address for this network.
 */
function ts_address_to_scriptpubkey(array $net, string $address): ?string
{
    $address = trim($address);
    if ($address === '') {
        return null;
    }
    // No real address exceeds ~110 chars; bail before the O(n^2) base58 divide
    // (an unbounded input would otherwise pin a worker on quadratic long-division).
    if (strlen($address) > 130) {
        return null;
    }

    // Segwit / bech32(m)
    if (!empty($net['bech32'])) {
        $seg = ts_segwit_decode($net['bech32'], $address);
        if ($seg !== null) {
            [$ver, $prog] = $seg;
            $op = $ver === 0 ? 0x00 : (0x50 + $ver);
            return sprintf('%02x%02x', $op, strlen($prog)) . bin2hex($prog);
        }
    }

    // base58check (p2pkh / p2sh)
    $b58 = ts_base58check_decode($address);
    if ($b58 !== null) {
        [$ver, $payload] = $b58;
        if (strlen($payload) !== 20) {
            return null;
        }
        $hex = bin2hex($payload);
        if ($ver === ($net['p2pkh'] ?? -1)) {
            return '76a914' . $hex . '88ac';
        }
        if ($ver === ($net['p2sh'] ?? -1) || $ver === ($net['p2sh_alt'] ?? -2)) {
            return 'a914' . $hex . '87';
        }
    }

    return null;
}

/** Classify an address for display. */
function ts_address_type(array $net, string $address): string
{
    if (!empty($net['bech32'])) {
        $seg = ts_segwit_decode($net['bech32'], $address);
        if ($seg !== null) {
            [$ver, $prog] = $seg;
            if ($ver === 0) {
                return strlen($prog) === 20 ? 'v0_p2wpkh' : 'v0_p2wsh';
            }
            if ($ver === 1 && strlen($prog) === 32) {
                return 'v1_p2tr';
            }
            return 'witness_v' . $ver;
        }
    }
    if (!empty($net['mweb_hrp'])) {
        // MWEB stealth addresses (ltcmweb1...) encode two pubkeys and exceed the
        // bech32 length cap, so match the hrp prefix directly for the display badge.
        // They are not scripthash-indexable (no scriptPubKey), so this is display-only.
        if (stripos($address, $net['mweb_hrp'] . '1') === 0) {
            return 'mweb';
        }
    }
    $b58 = ts_base58check_decode($address);
    if ($b58 !== null) {
        [$ver] = $b58;
        if ($ver === ($net['p2pkh'] ?? -1)) {
            return 'p2pkh';
        }
        if ($ver === ($net['p2sh'] ?? -1) || $ver === ($net['p2sh_alt'] ?? -2)) {
            return 'p2sh';
        }
    }
    return 'unknown';
}

/** True if the address is valid (and indexable) for this network. */
function ts_address_valid(array $net, string $address): bool
{
    return ts_address_to_scriptpubkey($net, $address) !== null;
}
