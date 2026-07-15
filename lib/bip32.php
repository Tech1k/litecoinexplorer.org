<?php
/**
 * BIP32 extended-public-key address derivation for the xpub/ypub/zpub lookup
 * (incl. testnet tpub/upub/vpub and Litecoin variants). secp256k1 point math is
 * done with ext-gmp, feature-detected; without GMP the feature reports
 * unavailable rather than deriving wrong keys.
 *
 * Public (non-hardened) child derivation only - exactly what a watch-only xpub
 * needs for receive (m/0/i) and change (m/1/i) addresses. Correctness is pinned
 * by tools/xpub-test.php against the official BIP32 test vectors; run it after
 * deploy before trusting the derivations.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** True when the GMP big-integer math secp256k1 needs is present. */
function ts_bip32_available(): bool
{
    return function_exists('gmp_init') && function_exists('gmp_powm')
        && function_exists('gmp_invert') && function_exists('gmp_div_q');
}

// ---- secp256k1 curve constants --------------------------------------------

function ts_secp_p()
{
    static $v = null;
    if ($v === null) { $v = gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16); }
    return $v;
}
function ts_secp_n()
{
    static $v = null;
    if ($v === null) { $v = gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141', 16); }
    return $v;
}
function ts_secp_g()
{
    static $v = null;
    if ($v === null) {
        $v = [gmp_init('79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798', 16),
              gmp_init('483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8', 16)];
    }
    return $v;
}

// ---- point arithmetic (point = [x,y] GMP, or null for infinity) ------------

function ts_ec_double($P)
{
    if ($P === null) { return null; }
    $p = ts_secp_p();
    if (gmp_cmp($P[1], gmp_init(0)) === 0) { return null; }
    // lam = 3x^2 / 2y
    $lam = gmp_mod(gmp_mul(gmp_mul(gmp_init(3), gmp_mul($P[0], $P[0])),
        gmp_invert(gmp_mul(gmp_init(2), $P[1]), $p)), $p);
    $x3 = gmp_mod(gmp_sub(gmp_mul($lam, $lam), gmp_mul(gmp_init(2), $P[0])), $p);
    $y3 = gmp_mod(gmp_sub(gmp_mul($lam, gmp_sub($P[0], $x3)), $P[1]), $p);
    return [$x3, $y3];
}

function ts_ec_add($P, $Q)
{
    if ($P === null) { return $Q; }
    if ($Q === null) { return $P; }
    $p = ts_secp_p();
    if (gmp_cmp($P[0], $Q[0]) === 0) {
        if (gmp_cmp(gmp_mod(gmp_add($P[1], $Q[1]), $p), gmp_init(0)) === 0) {
            return null;                 // P + (-P) = infinity
        }
        return ts_ec_double($P);
    }
    $lam = gmp_mod(gmp_mul(gmp_sub($Q[1], $P[1]), gmp_invert(gmp_sub($Q[0], $P[0]), $p)), $p);
    $x3 = gmp_mod(gmp_sub(gmp_sub(gmp_mul($lam, $lam), $P[0]), $Q[0]), $p);
    $y3 = gmp_mod(gmp_sub(gmp_mul($lam, gmp_sub($P[0], $x3)), $P[1]), $p);
    return [$x3, $y3];
}

/** Scalar multiply k*P by double-and-add (LSB first). */
function ts_ec_mul($k, $P)
{
    $R = null;
    $Q = $P;
    $zero = gmp_init(0);
    while (gmp_cmp($k, $zero) > 0) {
        if (gmp_intval(gmp_and($k, gmp_init(1))) === 1) {
            $R = ts_ec_add($R, $Q);
        }
        $Q = ts_ec_double($Q);
        $k = gmp_div_q($k, gmp_init(2));
    }
    return $R;
}

/** Decompress a 33-byte compressed pubkey to a point (p ≡ 3 mod 4, so y is a power). */
function ts_ec_decompress(string $pub33)
{
    $p = ts_secp_p();
    $x = gmp_init(bin2hex(substr($pub33, 1)), 16);
    $y2 = gmp_mod(gmp_add(gmp_powm($x, gmp_init(3), $p), gmp_init(7)), $p);
    $y = gmp_powm($y2, gmp_div_q(gmp_add($p, gmp_init(1)), gmp_init(4)), $p);
    $odd = gmp_intval(gmp_mod($y, gmp_init(2)));
    $wantOdd = (ord($pub33[0]) === 0x03) ? 1 : 0;
    if ($odd !== $wantOdd) { $y = gmp_sub($p, $y); }
    return [$x, $y];
}

/** Compress a point to a 33-byte pubkey. */
function ts_ec_compress($P): string
{
    $prefix = (gmp_intval(gmp_mod($P[1], gmp_init(2))) === 0) ? "\x02" : "\x03";
    return $prefix . hex2bin(str_pad(gmp_strval($P[0], 16), 64, '0', STR_PAD_LEFT));
}

// ---- BIP32 public child key derivation ------------------------------------

/**
 * Non-hardened CKD-pub: child of ($pub33,$chain32) at $index (< 2^31).
 * Returns [childPub33, childChain32], or null for the ~1-in-2^127 invalid case.
 */
function ts_bip32_ckd_pub(string $pub33, string $chain32, int $index)
{
    $I = hash_hmac('sha512', $pub33 . pack('N', $index), $chain32, true);
    $IL = substr($I, 0, 32);
    $IR = substr($I, 32, 32);
    $il = gmp_init(bin2hex($IL), 16);
    if (gmp_cmp($il, ts_secp_n()) >= 0) {
        return null;
    }
    $point = ts_ec_add(ts_ec_mul($il, ts_secp_g()), ts_ec_decompress($pub33));
    if ($point === null) {
        return null;                     // resulting point at infinity
    }
    return [ts_ec_compress($point), $IR];
}

// ---- base58check + bech32 ENCODE (address.php has the decoders) ------------

function ts_base58_encode(string $bin): string
{
    $zeros = 0;
    $len = strlen($bin);
    while ($zeros < $len && $bin[$zeros] === "\x00") { $zeros++; }
    $num = gmp_init($bin === '' ? '0' : bin2hex($bin), 16);
    $b58 = gmp_init(58);
    $out = '';
    while (gmp_cmp($num, gmp_init(0)) > 0) {
        $out = TS_B58_ALPHABET[gmp_intval(gmp_mod($num, $b58))] . $out;
        $num = gmp_div_q($num, $b58);
    }
    return str_repeat('1', $zeros) . $out;
}

function ts_base58check_encode(string $payload): string
{
    $check = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    return ts_base58_encode($payload . $check);
}

function ts_convertbits_up(array $data): array
{
    $acc = 0; $bits = 0; $ret = [];
    foreach ($data as $val) {
        $acc = ($acc << 8) | ($val & 0xff);
        $bits += 8;
        while ($bits >= 5) { $bits -= 5; $ret[] = ($acc >> $bits) & 31; }
        $acc &= (1 << $bits) - 1;   // drop the bits already emitted so $acc stays within 64-bit
    }
    if ($bits > 0) { $ret[] = ($acc << (5 - $bits)) & 31; }
    return $ret;
}

/** Encode a native segwit (bech32/bech32m) address for $hrp, witver, program. */
function ts_segwit_encode(string $hrp, int $witver, string $program): string
{
    $data = array_merge([$witver], ts_convertbits_up(array_values(unpack('C*', $program))));
    $const = $witver === 0 ? TS_BECH32_CONST : TS_BECH32M_CONST;
    $values = array_merge(ts_bech32_hrp_expand($hrp), $data, [0, 0, 0, 0, 0, 0]);
    $polymod = ts_bech32_polymod($values) ^ $const;
    for ($i = 0; $i < 6; $i++) { $data[] = ($polymod >> (5 * (5 - $i))) & 31; }
    $s = $hrp . '1';
    foreach ($data as $d) { $s .= TS_BECH32_CHARSET[$d]; }
    return $s;
}

// ---- xpub parsing + address derivation ------------------------------------

/** SLIP-132 version byte (hex) -> script type. */
function ts_xpub_script_type(string $versionHex): string
{
    static $m = [
        '0488b21e' => 'p2pkh',  '043587cf' => 'p2pkh',    // xpub, tpub
        '049d7cb2' => 'p2sh',   '044a5262' => 'p2sh',     // ypub, upub
        '04b24746' => 'p2wpkh', '045f1cf6' => 'p2wpkh',   // zpub, vpub
        '019da462' => 'p2pkh',  '0436f6e1' => 'p2pkh',    // Ltub, ttub (Litecoin)
        '01b26ef6' => 'p2sh',                             // Mtub (Litecoin)
    ];
    return isset($m[$versionHex]) ? $m[$versionHex] : 'p2pkh';
}

/**
 * SLIP-132 version byte (hex) -> ['net' => 'mainnet'|'testnet', 'coin' => 'btc'|'ltc'|null].
 * coin is null for the coin-agnostic testnet prefixes (tpub/upub/vpub), which
 * several Litecoin wallets also export; those are accepted on any testnet lane.
 */
function ts_xpub_net_coin(string $versionHex): array
{
    static $m = [
        '0488b21e' => ['mainnet', null],  '049d7cb2' => ['mainnet', null],  '04b24746' => ['mainnet', null],  // xpub/ypub/zpub
        '043587cf' => ['testnet', null],  '044a5262' => ['testnet', null],  '045f1cf6' => ['testnet', null],  // tpub/upub/vpub (coin-agnostic)
        '019da462' => ['mainnet', 'ltc'], '01b26ef6' => ['mainnet', 'ltc'],                                    // Ltub/Mtub
        '0436f6e1' => ['testnet', 'ltc'],                                                                      // ttub
    ];
    $e = isset($m[$versionHex]) ? $m[$versionHex] : ['mainnet', null];
    return ['net' => $e[0], 'coin' => $e[1]];
}

/**
 * Parse an extended PUBLIC key. Returns ['type','chain','key','version'] or null
 * (bad base58/checksum/length, or a private key). 78-byte payload + 4 checksum.
 */
function ts_xpub_parse(string $str)
{
    if (!ts_bip32_available()) {
        return null;
    }
    $raw = ts_base58_decode($str);
    if ($raw === null || strlen($raw) !== 82) {
        return null;
    }
    $data = substr($raw, 0, 78);
    $calc = substr(hash('sha256', hash('sha256', $data, true), true), 0, 4);
    if (!hash_equals($calc, substr($raw, 78, 4))) {
        return null;
    }
    $key = substr($data, 45, 33);
    if (ord($key[0]) !== 0x02 && ord($key[0]) !== 0x03) {
        return null;                     // not a compressed public key
    }
    $version = bin2hex(substr($data, 0, 4));
    $nc = ts_xpub_net_coin($version);
    return ['version' => $version, 'type' => ts_xpub_script_type($version),
            'net' => $nc['net'], 'coin' => $nc['coin'],
            'chain' => substr($data, 13, 32), 'key' => $key];
}

/** Encode a compressed pubkey to an address of $type for $net. */
function ts_pub_to_address(array $net, string $pub33, string $type): ?string
{
    $h160 = hash('ripemd160', hash('sha256', $pub33, true), true);
    if ($type === 'p2wpkh') {
        if (empty($net['bech32'])) { return null; }
        return ts_segwit_encode($net['bech32'], 0, $h160);
    }
    if ($type === 'p2sh') {
        if (!isset($net['p2sh'])) { return null; }
        $sh = hash('ripemd160', hash('sha256', "\x00\x14" . $h160, true), true);
        return ts_base58check_encode(chr((int) $net['p2sh']) . $sh);
    }
    if (!isset($net['p2pkh'])) { return null; }
    return ts_base58check_encode(chr((int) $net['p2pkh']) . $h160);
}

/**
 * Derive receive (m/0/i) and change (m/1/i) addresses from an account xpub.
 * Returns ['type'=>, 'receive'=>[['index','address'],..], 'change'=>[..]] or
 * null if the key can't be parsed / GMP is missing. $perChain is bounded.
 */
function ts_xpub_addresses(array $net, string $str, int $perChain = 20, ?string $forceType = null): ?array
{
    $x = ts_xpub_parse($str);
    if ($x === null) {
        return null;
    }
    // The prefix pins the type only for explicit SLIP-132 keys; a caller may force
    // a type (e.g. from a user toggle) since a compressed pubkey can encode any of
    // legacy / nested-segwit / native-segwit.
    $type = ($forceType !== null && in_array($forceType, ['p2pkh', 'p2sh', 'p2wpkh'], true)) ? $forceType : $x['type'];
    $perChain = max(1, min(50, $perChain));
    $out = ['type' => $type, 'prefix_type' => $x['type'], 'receive' => [], 'change' => []];
    foreach ([0 => 'receive', 1 => 'change'] as $chainIdx => $name) {
        $node = ts_bip32_ckd_pub($x['key'], $x['chain'], $chainIdx);
        if ($node === null) {
            continue;
        }
        for ($i = 0; $i < $perChain; $i++) {
            $child = ts_bip32_ckd_pub($node[0], $node[1], $i);
            if ($child === null) {
                continue;
            }
            $addr = ts_pub_to_address($net, $child[0], $type);
            if ($addr !== null) {
                $out[$name][] = ['index' => $i, 'address' => $addr];
            }
        }
    }
    return $out;
}

/** Loose check that a string looks like an extended pub key (for search routing). */
function ts_is_xpub(string $s): bool
{
    return (bool) preg_match('/^(xpub|ypub|zpub|tpub|upub|vpub|Ltub|Mtub|ttub)[1-9A-HJ-NP-Za-km-z]{100,115}$/', $s);
}
