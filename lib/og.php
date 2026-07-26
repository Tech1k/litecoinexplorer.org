<?php
/**
 * Dynamic Open Graph card images (1200x630 PNG) for shared links: a dark card
 * with the coin accent, a title and a couple of live stat lines for a block, tx,
 * address or network home. Rendered with GD + FreeType from a system/bundled TTF.
 *
 * Strictly best-effort: if GD, FreeType or a usable font is missing, or the data
 * can't be fetched, the /og route serves the static banner instead, so the
 * og:image URL always resolves. Set 'og_font' / 'og_font_bold' in config.php to
 * point at specific TTFs; otherwise common DejaVu Sans locations are probed.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** "#rrggbb" -> [r,g,b]; falls back to a neutral blue. */
function lx_og_hex(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return [107, 134, 255];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** HSL (h in degrees, s/l in 0..1) -> [r,g,b] 0..255. For the fee-gradient accent. */
function lx_og_hsl(float $h, float $s, float $l): array
{
    $h = fmod(fmod($h, 360) + 360, 360) / 360;
    if ($s <= 0) {
        $v = (int) round($l * 255);
        return [$v, $v, $v];
    }
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $hue = function ($p, $q, $t) {
        if ($t < 0) { $t += 1; }
        if ($t > 1) { $t -= 1; }
        if ($t < 1 / 6) { return $p + ($q - $p) * 6 * $t; }
        if ($t < 1 / 2) { return $q; }
        if ($t < 2 / 3) { return $p + ($q - $p) * (2 / 3 - $t) * 6; }
        return $p;
    };
    return [(int) round($hue($p, $q, $h + 1 / 3) * 255),
            (int) round($hue($p, $q, $h) * 255),
            (int) round($hue($p, $q, $h - 1 / 3) * 255)];
}

/** Locate a usable TTF (bold or regular). Config first, then common DejaVu paths. Cached. */
function lx_og_font(bool $bold = true): ?string
{
    static $cache = [];
    $key = $bold ? 'b' : 'r';
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $cfg = lx_config();
    $candidates = [];
    if ($bold && !empty($cfg['og_font_bold'])) { $candidates[] = $cfg['og_font_bold']; }
    if (!$bold && !empty($cfg['og_font']))      { $candidates[] = $cfg['og_font']; }
    if (!empty($cfg['og_font']))                { $candidates[] = $cfg['og_font']; }       // shared fallback
    if (!empty($cfg['og_font_bold']))           { $candidates[] = $cfg['og_font_bold']; }
    $names = $bold ? ['DejaVuSans-Bold.ttf', 'DejaVuSans.ttf'] : ['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'];
    $dirs = [
        __DIR__ . '/../assets/fonts/',
        '/usr/share/fonts/truetype/dejavu/',
        '/usr/share/fonts/dejavu/',
        '/usr/share/fonts/dejavu-sans-fonts/',
        '/usr/share/fonts/TTF/',
        '/usr/share/fonts/truetype/',
        '/Library/Fonts/',
    ];
    foreach ($dirs as $d) {
        foreach ($names as $nm) { $candidates[] = $d . $nm; }
    }
    foreach ($candidates as $p) {
        if (is_string($p) && $p !== '' && is_file($p)) {
            return $cache[$key] = $p;
        }
    }
    return $cache[$key] = null;
}

/** True when a card can actually be drawn (GD + FreeType + a font). */
function lx_og_available(): bool
{
    return function_exists('imagecreatetruecolor')
        && function_exists('imagettftext')
        && function_exists('imagettfbbox')
        && lx_og_font(true) !== null;
}

/** Trim $text (adding an ellipsis) until it fits within $maxW px at $size/$font. */
function lx_og_fit(string $text, float $size, string $font, int $maxW): string
{
    $bb = imagettfbbox($size, 0, $font, $text);
    if (($bb[2] - $bb[0]) <= $maxW) {
        return $text;
    }
    while (strlen($text) > 1) {
        $text = substr($text, 0, -1);
        $bb = imagettfbbox($size, 0, $font, rtrim($text) . "\xE2\x80\xA6");
        if (($bb[2] - $bb[0]) <= $maxW) {
            return rtrim($text) . "\xE2\x80\xA6";
        }
    }
    return $text;
}

/**
 * Gather the card fields for one page. Returns ['title','lines'=>[..],'accent']
 * or null when the subject can't be resolved. block/tx/address get their own
 * card; anything else uses the network overview card.
 */
function lx_og_data(array $net, string $type, string $id): ?array
{
    $accent = lx_brand_color($net['coin']);
    $utxo = (($net['kind'] ?? 'utxo') === 'utxo');
    try {
        if ($utxo && $type === 'block' && $id !== '') {
            $hash = ctype_digit($id) ? lx_block_hash_at($net, (int) $id) : $id;
            if (!is_string($hash)) { return null; }
            $b = lx_esplora_block($net, $hash);
            if (!$b) { return null; }
            $bs = lx_block_stats($net, $hash, (int) $b['height']);
            $fees = ($bs && $bs['total_fee'] > 0) ? lx_coin((int) $bs['total_fee']) . ' ' . $net['unit'] . ' fees · ' : '';
            // Absolute time, not "N min ago": the PNG is cached, so a relative age
            // would freeze at whatever it was when first rendered.
            // Only cache hard once buried: a shallow block can still be reorged.
            $depth = lx_tip_height($net) - (int) $b['height'];
            return ['title' => 'Block ' . commas($b['height']),
                    'lines' => [commas($b['tx_count']) . ' transactions',
                                $fees . 'mined ' . gmdate('M j, Y H:i', (int) $b['timestamp']) . ' UTC'],
                    'accent' => $accent, 'ttl' => $depth > 100 ? 86400 : 600];
        }
        if ($utxo && $type === 'tx' && $id !== '') {
            $tx = lx_find_tx($net, $id);
            if (!$tx) { return null; }
            $out = 0;
            foreach (($tx['vout'] ?? []) as $vo) { $out += coin_to_sat($vo['value'] ?? 0); }
            $conf = !empty($tx['status']['confirmed']);
            $status = $conf
                ? ('confirmed' . (isset($tx['status']['block_height']) ? ' in block ' . commas((int) $tx['status']['block_height']) : ''))
                : 'in the mempool';
            // Long cache only once buried; a shallow (or mempool) tx can still change.
            $ttl = 60;
            if ($conf) {
                $bh = (int) ($tx['status']['block_height'] ?? 0);
                $ttl = ($bh > 0 && lx_tip_height($net) - $bh > 100) ? 86400 : 600;
            }
            return ['title' => 'Transaction',
                    'lines' => [lx_amount($net, (int) $out),
                                'fee ' . lx_coin((int) ($tx['fee'] ?? 0)) . ' ' . $net['unit'] . ' · ' . $status],
                    'accent' => $accent, 'ttl' => $ttl];
        }
        if ($utxo && $type === 'address' && $id !== '') {
            $st = lx_address_stats($net, $id);
            if (!$st) { return null; }
            $c = $st['chain_stats']; $m = $st['mempool_stats'];
            $bal = (int) $c['funded_txo_sum'] - (int) $c['spent_txo_sum'];
            $tot = (int) $c['tx_count'] + (int) $m['tx_count'];
            // Don't render a fresh PNG for arbitrary checksum-valid but unused
            // addresses (an unbounded set); fall back to the static banner.
            if ($tot === 0) { return null; }
            return ['title' => 'Address',
                    'lines' => [shorten($id, 16, 12),
                                'balance ' . lx_amount($net, $bal) . ' · ' . commas($tot) . ' transactions'],
                    'accent' => $accent, 'ttl' => 120];   // balance is mutable -> short cache
        }
    } catch (Throwable $e) {
        return null;
    }
    // Network overview (home).
    try {
        $tip = lx_tip_height($net);
        $mem = lx_esplora_mempool($net);
        $sub = 'tip #' . commas($tip) . ' · ' . commas((int) ($mem['count'] ?? 0)) . ' mempool txs';
        return ['title' => $net['label'], 'lines' => ['Block explorer', $sub], 'accent' => $accent, 'ttl' => 60];
    } catch (Throwable $e) {
        return ['title' => $net['label'], 'lines' => ['Block explorer', 'live from the node'], 'accent' => $accent, 'ttl' => 60];
    }
}

/** Render a card: ['png' => bytes, 'ttl' => cache seconds], or null if it can't be drawn. */
function lx_og_render(array $net, string $type, string $id): ?array
{
    if (!lx_og_available()) {
        return null;
    }
    $data = lx_og_data($net, $type, $id);
    if (!$data) {
        return null;
    }
    $fontB = lx_og_font(true);
    $fontR = lx_og_font(false);
    if ($fontR === null) { $fontR = $fontB; }

    $W = 1200; $H = 630; $padL = 92; $maxW = $W - $padL - 70;
    try {
        $im = imagecreatetruecolor($W, $H);
        $bg     = imagecolorallocate($im, 13, 17, 23);
        $white  = imagecolorallocate($im, 233, 238, 245);
        $muted  = imagecolorallocate($im, 141, 150, 160);
        $rgb    = lx_og_hex($data['accent']);
        $accent = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);

        imagefilledrectangle($im, 0, 0, $W, $H, $bg);
        imagefilledrectangle($im, 0, 0, 16, $H, $accent);              // brand bar, left edge
        // Right edge: a thin mempool fee-gradient strip (red high-fee at top ->
        // green low-fee at bottom), the site's fee-colour language as a subtle accent.
        for ($yy = 0; $yy < $H; $yy++) {
            $c = lx_og_hsl(145.0 * ($yy / $H), 0.60, 0.47);
            imageline($im, $W - 10, $yy, $W - 1, $yy, imagecolorallocate($im, $c[0], $c[1], $c[2]));
        }

        imagettftext($im, 33, 0, $padL, 112, $white, $fontB, 'Litecoin Explorer');
        // chain label, right-aligned
        $chain = $net['label'];
        $bb = imagettfbbox(26, 0, $fontR, $chain);
        imagettftext($im, 26, 0, $W - 70 - ($bb[2] - $bb[0]), 110, $accent, $fontR, $chain);

        imagettftext($im, 66, 0, $padL - 2, 336, $white, $fontB, lx_og_fit($data['title'], 66, $fontB, $maxW));

        $y = 430;
        foreach ($data['lines'] as $ln) {
            imagettftext($im, 32, 0, $padL, $y, $muted, $fontR, lx_og_fit((string) $ln, 32, $fontR, $maxW));
            $y += 58;
        }
        imagettftext($im, 26, 0, $padL, $H - 58, $muted, $fontR, 'litecoinexplorer.org');

        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);
        if (!is_string($png) || $png === '') {
            return null;
        }
        return ['png' => $png, 'ttl' => (int) ($data['ttl'] ?? 300)];
    } catch (Throwable $e) {
        return null;
    }
}

/** Route handler for /og/<type>/<id>.png. Serves PNG or 302s to the banner. */
function lx_route_og(array $segs, string $method): void
{
    $fallback = lx_base_url() . '/assets/og-banner.png';
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Location: ' . $fallback, true, 302);
        return;
    }
    $type = isset($segs[0]) ? preg_replace('/\.png$/', '', $segs[0]) : 'home';
    $id   = isset($segs[1]) ? preg_replace('/\.png$/', '', $segs[1]) : '';
    if (strlen($id) > 130 || !preg_match('/^[a-z]+$/', (string) $type)) {   // guard: ids are hashes/heights/addresses
        header('Location: ' . $fallback, true, 302);
        return;
    }
    $net = lx_net_default();
    // Rendering is GD/FreeType + an electrs lookup; throttle per IP so a flood of
    // unique ids can't pin the origin. Over the limit -> the cheap static banner.
    if (function_exists('lx_rate_limit') && !lx_rate_limit('og', 60, 60)) {
        header('Location: ' . $fallback, true, 302);
        return;
    }
    $r = ($net && function_exists('lx_og_render')) ? lx_og_render($net, (string) $type, (string) $id) : null;
    if ($r === null) {
        header('Location: ' . $fallback, true, 302);
        return;
    }
    // s-maxage matches the content's mutability (blocks/confirmed txs long, address/
    // home short); browsers cache briefly. Avoids a relative time or a live balance
    // freezing in the CDN cache.
    $ttl = max(30, (int) $r['ttl']);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . min(300, $ttl) . ', s-maxage=' . $ttl);
    header('Content-Length: ' . strlen($r['png']));
    if ($method === 'HEAD') {
        return;
    }
    echo $r['png'];
}
