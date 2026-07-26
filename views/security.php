<?php
/**
 * RFC 9116 security.txt, served at /.well-known/security.txt (and /security.txt).
 * Rendered (not static) so Expires stays perpetually in the future.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
$base    = lx_base_url();
$expires = gmdate('Y-m-d\TH:i:s\Z', time() + 180 * 86400);
echo "Contact: https://github.com/Tech1k/litecoinexplorer.org/security\n";
echo "Expires: " . $expires . "\n";
echo "Preferred-Languages: en\n";
echo "Canonical: " . $base . "/.well-known/security.txt\n";
