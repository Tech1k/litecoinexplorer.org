<?php
/**
 * XML sitemap generated from the enabled networks.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Content-Type: application/xml; charset=utf-8');
$base = ts_base_url();

// /status is intentionally excluded - it is noindex, so listing it here would be
// a mixed signal.
$urls = [$base . '/', $base . '/docs', $base . '/donate'];
$net = ts_net_default();
if ($net) {
    foreach (['/blocks', '/mempool', '/mining', '/charts', '/node', '/tools'] as $p) {
        $urls[] = $base . $p;
    }
    if (ts_mweb_enabled($net)) {
        $urls[] = $base . '/mweb';
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . h($u) . '</loc></url>' . "\n";
}
echo '</urlset>' . "\n";
