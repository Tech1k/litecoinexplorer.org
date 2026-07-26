<?php
/**
 * Litecoin Explorer bootstrap: loads config, sets the error handler, and pulls in
 * the library. Included once by index.php before any routing.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Litecoin Explorer © 2026 Tech1k
 */

if (!defined('LX_ROOT')) {
    define('LX_ROOT', dirname(__DIR__));
}

// Satoshi amounts (max supply 84,000,000 LTC = 8.4e15 sat) exceed a 32-bit int, where
// they would silently promote to float and lose precision. Require a 64-bit PHP build.
if (PHP_INT_SIZE < 8) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Litecoin Explorer requires a 64-bit PHP build (satoshi amounts exceed 32-bit integers).\n");
}

// ---- config ---------------------------------------------------------------

$lx_config_file = LX_ROOT . '/config.php';
if (!is_file($lx_config_file)) {
    // No config yet: fail loudly but safely (no secrets to leak).
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Litecoin Explorer is not configured. Copy config.example.php to config.php.\n";
    exit;
}

$GLOBALS['LX_CONFIG'] = require $lx_config_file;

function lx_config(): array
{
    return $GLOBALS['LX_CONFIG'];
}

define('LX_DEBUG', !empty($GLOBALS['LX_CONFIG']['debug']));

// Defense-in-depth: emit the security headers from PHP too. .htaccess sets these
// via mod_headers (whose `set` overrides these identically, so no duplicate), but
// if a deploy runs without mod_headers or behind a non-Apache front end this is
// the fallback so the strict CSP can't silently vanish. Web requests only.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

// ---- error handling -------------------------------------------------------

if (LX_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
    // Any uncaught exception (RPC down, electrum unreachable, etc.) becomes a
    // clean 503 rather than a blank 500. API paths get a text body; the front
    // controller decides HTML vs text via LX_WANTS_JSON when it is set.
    set_exception_handler(function (Throwable $e) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 30');
        }
        if (defined('LX_WANTS_JSON') && LX_WANTS_JSON) {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
                lx_cors();
            }
            echo "Backend temporarily unavailable.";
        } else {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
               . '<meta name="viewport" content="width=device-width, initial-scale=1">'
               . '<title>Temporarily unavailable | Litecoin Explorer</title>'
               . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
               . 'background:#14161b;color:#e6e8ec;text-align:center;padding-top:72px;line-height:1.6}'
               . 'a{color:#6b86ff}</style></head><body>'
               . '<h1>Temporarily unavailable</h1>'
               . '<p style="color:#9aa0ab">A node or index this explorer depends on is unreachable right now. Please try again shortly.</p>'
               . '</body></html>';
        }
        exit;
    });
}

// ---- library --------------------------------------------------------------

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/net.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/rpc.php';
require_once __DIR__ . '/electrum.php';
require_once __DIR__ . '/address.php';
require_once __DIR__ . '/bip32.php';
require_once __DIR__ . '/esplora.php';
require_once __DIR__ . '/pools.php';
require_once __DIR__ . '/mweb.php';
require_once __DIR__ . '/mwebscan.php';
require_once __DIR__ . '/price.php';
require_once __DIR__ . '/rbf.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/blockindex.php';
require_once __DIR__ . '/ws.php';
require_once __DIR__ . '/api_v1.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/og.php';
