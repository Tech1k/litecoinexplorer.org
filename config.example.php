<?php
/**
 * Litecoin Explorer configuration template.
 *
 * Copy to config.php and fill in your node credentials. config.php is
 * gitignored and denied by .htaccess. Everything here is read once per
 * request by lib/bootstrap.php.
 *
 * Data sources (single Litecoin mainnet lane):
 *   - rpc:      litecoind JSON-RPC (blocks, tx, mempool, fees, broadcast,
 *               MWEB peg data). Requires txindex=1 for arbitrary tx lookup.
 *   - electrum: an Electrum-protocol server (the address index: scripthash
 *               history / balance / utxo). Use spesmilo/ElectrumX
 *               (COIN=Litecoin NET=mainnet) - the Rust electrs-ltc panics on
 *               MWEB blocks. Plaintext TCP on localhost is fine; set tls=true
 *               only if it terminates TLS.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
    // Show PHP errors + verbose JSON errors. NEVER true in production.
    'debug' => false,

    // SQLite response cache (immutable tx/block bodies, short-TTL tips).
    // Kept under db/ which .htaccess denies. Created automatically.
    'cache_db' => __DIR__ . '/db/cache.sqlite',

    // CORS: the wallet/drop-in clients call the /api from the browser, so the
    // API must answer with Access-Control-Allow-Origin. '*' is appropriate for
    // a public explorer; narrow it to your origins if you prefer.
    'cors_origin' => '*',

    // Show cross-links to external explorers (litecoinspace.org) on tx/block
    // pages. Off by default; flip on for the "view elsewhere" convenience
    // (handy for cross-verifying data during setup).
    'extern_links' => false,

    // Safety cap when computing address funded/spent sums by walking history
    // (electrum gives net balance, not per-output sums). Addresses with more
    // confirmed txs than this return accurate balance but capped tx stats.
    'address_tx_cap' => 5000,

    // Above this many txs, skip the exact per-output walk and report stats from
    // the Electrum server's get_balance (balance + tx_count stay exact; the
    // funded/spent breakdown is approximate). Keeps busy addresses fast and
    // defuses walk-amplification abuse.
    'address_walk_limit' => 500,

    // Max scripthash-history candidates scanned when resolving WHO spent an output
    // (/tx/:txid/outspend[s] full Esplora shape). An output on a heavily-reused
    // address has a huge history; this caps the walk (a shared per-tx wall-clock
    // deadline also applies). On budget-exceed the output degrades to a bare
    // {"spent":true}. Raise if legitimate deep spends are missing the spending txid.
    'outspend_walk_limit' => 200,

    // Live LTC price for fiat values (home ticker + tx/address USD, /api/v1/prices).
    // Fetched SERVER-SIDE (the strict CSP forbids browser calls to third-party
    // APIs) and cached ~60s. Best-effort: if disabled or unreachable, fiat simply
    // hides. CoinGecko needs no key at this volume (the cache keeps us well under
    // its rate limit); set 'api_key' for a CoinGecko demo/pro key if you have one.
    'price' => [
        'enabled'    => true,
        'source'     => 'coingecko',
        'base'       => 'https://api.coingecko.com',   // override for a proxy / CoinGecko Pro
        'coin_id'    => 'litecoin',
        'currencies' => ['usd', 'eur', 'gbp', 'cad', 'chf', 'aud', 'jpy'],  // exposed at /api/v1/prices + historical-price
        'display'    => 'usd',                          // primary currency shown in the UI
        // 'api_key' => 'CG-xxxxxxxx',
        'ttl'        => 60,
    ],

    // Localhost port the WebSocket daemon (tools/ws-server.php) binds to; Apache
    // proxies /api/v1/ws to it (see DEPLOY.md). Optional - the site polls over HTTP
    // if the daemon isn't running.
    'ws_port' => 8482,

    // Block-economics index (backs the /api/v1/mining/blocks/* timeseries + the charts
    // time-period selector). Filled incrementally by the snapshot cron - the heaviest
    // cron step: it indexes up to 'blockindex_per_run' blocks per run (forward-fill new
    // blocks, then backfill history) and retains ~'blockindex_retain' recent blocks
    // (~90 days at 150s spacing). Lower per_run if the node is busy; history just fills
    // in more slowly. Rows are tiny (per-block stats, no tx lists), so set
    // 'blockindex_retain' => 0 to keep the FULL chain forever (never prune; backfills
    // toward genesis over many runs - needs a non-pruned node for deep history).
    'blockindex_per_run' => 150,
    'blockindex_retain'  => 52560,   // 0 = retain the whole chain (never prune)
    // 'version'    => '1.0.0',   // reported as `version` by /api/v1/backend-info
    // 'git_commit' => 'abc1234',  // reported as `gitCommit` by /api/v1/backend-info

    // Canonical host for absolute URLs (OG tags, sitemap), and the fallback for
    // an untrusted Host header.
    'canonical_host' => 'litecoinexplorer.org',

    // Extra Host headers to trust for absolute URLs (besides canonical_host),
    // e.g. ['www.litecoinexplorer.org']. Anything else falls back to canonical_host.
    'allowed_hosts' => [],

    // Optionally point the MWEBscan analysis overlay at a keyed / allow-listed
    // instance (string for all, or an array keyed by network slug). The public
    // API base defaults to https://mwebscan.com/api and is rate-limited
    // (60/min/IP); set an API key here if you have one.
    // 'mwebscan_api'     => 'https://mwebscan.com/api',
    // 'mwebscan_api_key' => 'YOUR_KEY',
    // URL template for the external "View on MWEBscan" block link ({hash}/{height}
    // placeholders). Omitted by default (the link simply doesn't render).
    // 'mwebscan_block'   => 'https://mwebscan.com/block/{hash}',

    // OG share-card fonts. Auto-probed from the usual DejaVu paths, so leave unset
    // unless DejaVu isn't where GD can find it; then point these at a .ttf.
    // 'og_font'      => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    // 'og_font_bold' => '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',

    'networks' => [

        'ltc-mainnet' => [
            'enabled'  => true,
            'rpc' => [
                'url'  => 'http://127.0.0.1:9332/',
                'user' => 'CHANGE_ME',
                'pass' => 'CHANGE_ME',
                // litecoind (Core 0.21 base) does NOT support getrawtransaction
                // verbosity 2; leave false so prevouts are resolved by fetching
                // the previous transactions.
                'verbosity2' => false,
            ],
            'electrum' => [
                'host'    => '127.0.0.1',
                'port'    => 50001,   // ElectrumX SERVICES tcp:// port for LTC mainnet
                'tls'     => false,
                'timeout' => 8,
            ],
            // MWEB (MimbleWimble Extension Blocks). Reads ONLY from litecoind's
            // JSON-RPC (the same calls the Esplora builders use); no analytics
            // DB or extra daemon. 'enabled' => true|false, or 'auto' to switch
            // on once the mweb soft fork reports active. 'activation' overrides
            // the built-in mainnet activation height (2265984) if needed.
            'mweb' => [
                'enabled'    => true,
                'activation' => 2265984,
                // Optional self-contained peg index (SQLite). Off = RPC-only.
                // Seed it once from an mwebscan DB (tools/mweb-seed.php), then
                // keep it fresh with tools/mweb-index.php on a cron/timer. When
                // enabled and within 'max_lag' of the tip, /mweb history + the
                // supply chart + peg lists are served from the index; otherwise
                // everything falls back to live RPC.
                'index' => [
                    'enabled' => false,
                    'db'      => __DIR__ . '/db/mweb-ltc.sqlite',
                    'max_lag' => 6,
                ],
            ],
        ],

    ],
];
