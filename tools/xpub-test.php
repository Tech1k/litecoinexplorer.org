<?php
/**
 * Self-test for the BIP32 xpub derivation (lib/bip32.php) against official test
 * vectors. Run after deploy to PROVE the secp256k1 + BIP32 + address encoding is
 * correct before trusting the xpub lookup:  php tools/xpub-test.php
 *
 * Checks 1-3 pin the primitives (base58check, bech32, CKD-pub); 4-6 pin the full
 * account-xpub -> address pipeline against the canonical BIP84 vectors.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
require dirname(__DIR__) . '/lib/bootstrap.php';

if (!lx_bip32_available()) {
    fwrite(STDERR, "GMP extension not available - xpub derivation is disabled on this PHP build.\n");
    exit(2);
}

$fail = 0;
$check = function (string $name, string $got, string $want) use (&$fail) {
    $ok = ($got === $want);
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        echo '       got:  ' . $got . "\n";
        echo '       want: ' . $want . "\n";
        $fail++;
    }
};

// 1) base58check P2PKH: the canonical hash160 -> address worked example.
$check('base58check P2PKH',
    lx_base58check_encode("\x00" . hex2bin('010966776006953D5567439E5E39F86A0D273BEE')),
    '16UwLL9Risc3QfPqBUvKofHmBQ7wMtjvM');

// 2) bech32 P2WPKH: the BIP173 witness-program test vector.
$check('bech32 P2WPKH',
    lx_segwit_encode('bc', 0, hex2bin('751e76e8199196d454941c45d1b3a323f1433bd6')),
    'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4');

// 3) BIP32 vector 1: CKD-pub m/0' -> m/0'/1, derived pubkey must equal the
//    pubkey embedded in the published m/0'/1 xpub.
$parent = lx_xpub_parse('xpub68Gmy5EdvgibQVfPdqkBBCHxA5htiqg55crXYuXoQRKfDBFA1WEjWgP6LHhwBZeNK1VTsfTFUHCdrfp1bgwQ9xv5ski8PX9rL2dZXvgGDnw');
$childRaw = lx_base58_decode('xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ');
$derived  = ($parent !== null) ? lx_bip32_ckd_pub($parent['key'], $parent['chain'], 1) : null;
$check('BIP32 CKD-pub m/0\'/1',
    $derived !== null ? bin2hex($derived[0]) : 'null',
    $childRaw !== null ? bin2hex(substr($childRaw, 45, 33)) : 'bad-vector');

// 4-6) BIP84 canonical account zpub -> first receive/change addresses.
$btc = ['bech32' => 'bc', 'p2pkh' => 0x00, 'p2sh' => 0x05];
$zpub = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';
$a = lx_xpub_addresses($btc, $zpub, 2);
$check('BIP84 receive[0]', isset($a['receive'][0]) ? $a['receive'][0]['address'] : 'none', 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu');
$check('BIP84 receive[1]', isset($a['receive'][1]) ? $a['receive'][1]['address'] : 'none', 'bc1qnjg0jd8228aq7egyzacy8cys3knf9xvrerkf9g');
$check('BIP84 change[0]',  isset($a['change'][0])  ? $a['change'][0]['address']  : 'none', 'bc1q8c6fshw2dlwun7ekn9qwf37cu2rn755upcp6el');

echo $fail ? "\n$fail check(s) FAILED - do not trust xpub derivation until fixed.\n"
           : "\nall checks passed - xpub derivation is correct.\n";
exit($fail ? 1 : 0);
