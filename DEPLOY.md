# Deploying Litecoin Explorer

Pure PHP + SQLite behind Apache. The only moving parts you run yourself are `litecoind` and
its Electrum server (spesmilo/ElectrumX); everything in this repo is stateless except a local
SQLite response cache.

## 1. Litecoin node (`txindex=1`)

**litecoind, mainnet** (`~/.litecoin/litecoin.conf`):

```ini
txindex=1
server=1
rpcbind=127.0.0.1
rpcport=9332
rpcuser=CHANGE_ME
rpcpassword=CHANGE_ME
```

`txindex=1` is required. Litecoin Explorer resolves `/tx/:txid` straight from Core, and
ElectrumX itself declares `txindex` a required daemon index, so keep it on. Let it fully sync
(a full mainnet chain, past MWEB activation at height 2,265,984) before indexing.

## 2. The Electrum address index

Litecoin Explorer needs an Electrum-protocol server for the address/scripthash index
(`get_history` / `get_balance` / `listunspent`). It's indexer-agnostic; it only needs the
protocol on the host:port set in `config.php`. Bind it to localhost.

Two indexers work: **spesmilo/ElectrumX** (`COIN=Litecoin NET=mainnet`) and the Litecoin
Foundation's **`rust-litecoin/electrs-ltc`** (the Rust indexer behind litecoinspace.org). The
setup below is for ElectrumX, which is what this instance runs; electrs-ltc is a drop-in
alternative on the same protocol (the client only uses standard scripthash methods). If you use
electrs-ltc, take a current build (its `mempool` branch): MWEB parsing was added in its May-2026
switch to the `rust-litecoin` crate, so an older build - or the archived `electrs-ltc-archive`,
which used stock `rust-bitcoin` - panics on MWEB blocks. That older build is what earlier versions
of these docs warned about. MWEB itself is served by a separate wallet-side daemon
(`ltcmweb/mwebd`), not needed here; the Electrum server only indexes the transparent chain.

```sh
sudo apt install -y python3-venv python3-dev build-essential libleveldb-dev
sudo git clone https://github.com/spesmilo/electrumx /opt/electrumx && cd /opt/electrumx
sudo python3 -m venv venv
# Upgrade pip first (ElectrumX is pyproject-only; PEP 660 editable installs need pip >= 21.3),
# then install ElectrumX (which pulls in the pinned aiorpcX[ws]) and plyvel SEPARATELY. Do NOT
# combine them: plyvel is a C-extension, and if its build fails, a combined `pip install -e . plyvel`
# aborts the whole transaction and silently leaves aiorpcX uninstalled (-> ModuleNotFoundError).
sudo ./venv/bin/python -m pip install --upgrade pip setuptools wheel
sudo ./venv/bin/python -m pip install -e .
sudo ./venv/bin/python -m pip install plyvel
sudo ./venv/bin/python -c "import aiorpcx, plyvel; print('deps ok')"
```

Run it as a service so it survives reboots - `/etc/systemd/system/electrumx-ltc.service`:

```ini
[Unit]
Description=ElectrumX (Litecoin mainnet)
After=network.target litecoind.service

[Service]
User=electrumx
Environment=COIN=Litecoin NET=mainnet DB_ENGINE=leveldb
# The user:pass here MUST match rpcuser/rpcpassword in litecoin.conf AND rpc.user/pass
# in config.php - a mismatch makes ElectrumX 401 and silently never build the index.
Environment=DAEMON_URL=http://CHANGE_ME:CHANGE_ME@127.0.0.1:9332/
Environment=DB_DIRECTORY=/var/lib/electrumx-ltc-mainnet
Environment=SERVICES=tcp://127.0.0.1:50001,rpc://127.0.0.1:8000
ExecStart=/opt/electrumx/venv/bin/electrumx_server
Restart=always
RestartSec=10
LimitNOFILE=8192

[Install]
WantedBy=multi-user.target
```

Create the service user + data dir, then start it:

```sh
sudo useradd -r -s /usr/sbin/nologin electrumx
sudo mkdir -p /var/lib/electrumx-ltc-mainnet && sudo chown electrumx: /var/lib/electrumx-ltc-mainnet
sudo systemctl enable --now electrumx-ltc
journalctl -fu electrumx-ltc            # watch it build the index
```

(To try it by hand first, run the same env inline: `COIN=Litecoin NET=mainnet DB_ENGINE=leveldb
DAEMON_URL="http://CHANGE_ME:CHANGE_ME@127.0.0.1:9332/" DB_DIRECTORY=/var/lib/electrumx-ltc-mainnet
SERVICES="tcp://127.0.0.1:50001,rpc://127.0.0.1:8000" /opt/electrumx/venv/bin/electrumx_server`.)

spesmilo's `Litecoin` class encodes the mainnet params the explorer expects (P2PKH `0x30`,
P2SH `[0x32, 0x05]`, bech32 `ltc`, RPC 9332), matching `lib/net.php`.

Notes:
- **A full mainnet index is large and takes a while** to build - give it a fast disk and let it
  finish before pointing the explorer at it.
- **ElectrumX requires a `server.version` handshake** before any call (romanz/electrs does not).
  The explorer's Electrum client (`lib/electrum.php`) already sends it, so no action is needed;
  just don't be surprised if a raw `nc` test gets `"use server.version to identify client"`.
- Building the leveldb index on a beefy box and `rsync`-ing `DB_DIRECTORY` to a smaller server
  is fine (leveldb is portable). Keep the **same ElectrumX version** on both ends.

If the server terminates TLS, set `'tls' => true` (and `'verify' => false` for self-signed) in
`config.php`.

### Alternative: rust-litecoin/electrs-ltc

`rust-litecoin/electrs-ltc` (default branch `mempool`, a fork of `mempool/electrs`) is a
drop-in alternative to spesmilo/ElectrumX for serving the address index over the Electrum
protocol - it is the Rust indexer behind litecoinspace.org. It builds its own index from
litecoind's block files and exposes a plaintext Electrum-RPC endpoint on `127.0.0.1:50001`,
matching our client's `electrum.port=50001`, `tls=false`. Package `mempool-electrs` (v3.4.0-dev);
the produced binary is named `electrs`. The explorer only uses standard scripthash methods, so it
is a drop-in either way.

MWEB note: build from a current `mempool`-branch checkout. MWEB block/HogEx parsing comes through
the `litecoin` (rust-litecoin) crate this fork pins in `Cargo.toml`
(`bitcoin = { package = "litecoin", version = "0.32.8-rc.1", features = ["serde"] }`), wired in by
its 2026-05-18 switch to that crate; it is automatic, with no MWEB opt-in flag. Older builds - or
the archived `electrs-ltc-archive`, which used stock `rust-bitcoin` - panic on MWEB blocks; that is
the panic the earlier note in this section warns about.

Index compatibility: this fork's DB is incompatible with both `romanz/electrs` and upstream
`mempool/electrs`, and must be reindexed from scratch.

#### Build

Prerequisites: Rust toolchain, a running litecoind, and `clang` + `cmake` (to build the bundled
rust-rocksdb). No `txindex` is required by electrs itself; our litecoind runs `txindex=1`, which is
harmless.

```sh
# Debian/Ubuntu build deps
sudo apt install clang cmake

git clone https://github.com/rust-litecoin/electrs-ltc && cd electrs-ltc
git checkout mempool        # 'mempool' is already the default branch

# compile-only build (first clean build takes a while); binary at target/release/electrs
cargo build --release
```

#### Run (wired to litecoind)

Network defaults to `mainnet`, so on LTC mainnet the Electrum port (`50001`) and daemon RPC port
(`9332`) are already the per-network defaults and could be omitted; they are shown explicitly below
for clarity. The DB is written under `<db-dir>/mainnet`.

```sh
target/release/electrs -vvvv --network mainnet \
  --daemon-dir /home/<ltc-user>/.litecoin \
  --daemon-rpc-addr 127.0.0.1:9332 \
  --electrum-rpc-addr 127.0.0.1:50001 \
  --http-addr 127.0.0.1:3000 \
  --db-dir /var/lib/electrs-ltc/db \
  --cookie "USER:PASSWORD"     # omit to auto-read <daemon-dir>/.cookie
```

Flag reference (from `src/config.rs`):

- `--network mainnet` - LTC mainnet (internally `Network::Bitcoin`). Accepted: `mainnet`,
  `testnet`, `testnet4`, `regtest`, `signet`.
- `--electrum-rpc-addr 127.0.0.1:50001` - Electrum JSONRPC listen addr; `50001` is the LTC-mainnet
  default. Plaintext, no TLS (matches `electrum.tls=false`).
- `--daemon-rpc-addr 127.0.0.1:9332` - litecoind JSONRPC; `9332` is the LTC-mainnet default.
- `--daemon-dir` - litecoind data directory (default `~/.litecoin/`); used to locate the blocks and
  the cookie file.
- `--cookie "USER:PASSWORD"` - inline JSONRPC auth. If omitted, electrs reads `<daemon-dir>/.cookie`.
- `--blocks-dir` - raw block files (`blk*.dat`) dir; defaults to `<daemon-dir>/blocks/` (derived
  from `--daemon-dir`, so it follows a non-default data dir automatically).
- `--http-addr 127.0.0.1:3000` - optional esplora REST API; not needed by our client, which talks
  Electrum on `50001`.
- `--db-dir` - index DB location (default `./db`).
- `--monitoring-addr` - optional Prometheus endpoint (default `127.0.0.1:4224` on mainnet).

Do not set `--magic` for standard LTC mainnet; the network magic is derived in-crate from the
litecoin genesis.

#### systemd unit

```ini
# /etc/systemd/system/electrs-ltc.service
[Unit]
Description=electrs-ltc (Electrum server for litecoind)
After=network.target litecoind.service
Wants=litecoind.service

[Service]
Type=simple
User=<electrs-user>
ExecStart=/opt/electrs-ltc/target/release/electrs -vvvv --network mainnet \
  --daemon-dir /home/<ltc-user>/.litecoin \
  --daemon-rpc-addr 127.0.0.1:9332 \
  --electrum-rpc-addr 127.0.0.1:50001 \
  --db-dir /var/lib/electrs-ltc/db
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

The `<electrs-user>` must be able to read litecoind's `.cookie` (or pass `--cookie "USER:PASSWORD"`
in `ExecStart`) and, because `--blocks-dir` defaults to `<daemon-dir>/blocks/`, must also be able
to read `/home/<ltc-user>/.litecoin/blocks/`.

#### Storage and sync

The full index needs about 1.3TB after compaction (as of October 2023), with roughly double that
free during compaction; `--lightmode` cuts the on-disk footprint substantially. The README's only
sync-time guidance is that creating the indexes "should take a few hours on a beefy machine with
high speed NVMe SSD(s)", and litecoind must be fully synced first. Once synced, no client change is
needed: the explorer's `electrum.port=50001` / `electrum.tls=false` config points straight at this
endpoint.

## 3. The app

### Server packages (PHP + Apache + extensions)

```sh
sudo apt install -y apache2 php php-cli libapache2-mod-php \
  php-sqlite3 php-curl php-gmp php-gd fonts-dejavu-core
sudo a2enmod rewrite headers
```

What each is for:
- **php-sqlite3** (`pdo_sqlite`) - required. The SQLite response cache + stats/audit/MWEB stores.
- **php-curl** (`curl`) - required. The MWEBscan overlay and the live price feed.
- **php-gmp** (`gmp`) - optional. The xpub address-derivation page (hides itself if absent).
- **php-gd** + **fonts-dejavu-core** - optional. The dynamic OG share cards (falls back to the
  static `assets/og-banner.png` if absent).
- The Electrum client uses PHP's core `stream_socket_client` - **no** extra extension (no
  `php-sockets`). `hash`/`json` are core too.

Verify: `php -m | grep -E 'pdo_sqlite|curl|gmp|gd'`. (On Fedora/RHEL the packages are
`php php-pdo php-sqlite3 php-curl php-gmp php-gd dejavu-sans-fonts` via `dnf`.)

### Deploy the code

```sh
cd /var/www
git clone https://github.com/Tech1k/litecoinexplorer.org
cd litecoinexplorer.org
cp config.example.php config.php
# edit config.php: litecoind rpc user/pass (:9332) + electrum host/port (127.0.0.1:50001)
mkdir -p db && chown www-data:www-data db   # writable SQLite cache dir (Fedora/RHEL: the web
                                            # user is `apache`, not www-data - chown + all the
                                            # systemd `User=` lines accordingly)
```

`config.php` and `db/` are gitignored and denied by `.htaccess`.

### Apache vhost

```apache
<VirtualHost *:80>
    ServerName litecoinexplorer.org
    DocumentRoot /var/www/litecoinexplorer.org
    <Directory /var/www/litecoinexplorer.org>
        AllowOverride All          # required so .htaccess (rewrites + denies) applies
        Require all granted
    </Directory>
</VirtualHost>
```

`AllowOverride All` is required so `.htaccess` (the rewrite front-controller + deny rules) applies;
`mod_php` (installed above) needs no extra config. Reload with `sudo systemctl reload apache2`.

### WebSocket API (optional but recommended)

`/api/v1/ws` (mempool.space-compatible live stream) is served by a small pure-PHP daemon
(`tools/ws-server.php`) - no Node, no extra deps. Run it as a service and proxy `/api/v1/ws`
to it; **without the proxy the site still works**, HTTP-polling for live updates.

`/etc/systemd/system/litecoinexplorer-ws.service`:

```ini
[Service]
User=www-data
ExecStart=/usr/bin/php /var/www/litecoinexplorer.org/tools/ws-server.php ltc-mainnet 8482
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
```
```sh
sudo systemctl enable --now litecoinexplorer-ws
```

Proxy it in the vhost (needs `sudo a2enmod proxy proxy_http proxy_wstunnel`) - put the two
`ProxyPass` lines **inside** the `<VirtualHost>`, before the `<Directory>` block:

```apache
    ProxyPass        /api/v1/ws  ws://127.0.0.1:8482/  keepalive=On
    ProxyPassReverse /api/v1/ws  ws://127.0.0.1:8482/
```

> **Changing the port:** `8482` appears in *three* places - `config.php`'s `ws_port`, the systemd
> `ExecStart` arg above, and both `ProxyPass` lines. The CLI arg wins over `ws_port`, so update all
> three together (or drop the arg from `ExecStart` to let `config.php` be authoritative).

Cloudflare passes WebSockets through transparently (no extra config). Verify:
`curl -s -i -N -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Key: x" -H "Sec-WebSocket-Version: 13" http://127.0.0.1:8482/`
should return `101 Switching Protocols`. (Set `'ws_port'` in `config.php` to change the port.)

Put it behind Cloudflare for TLS/HSTS (the `.htaccess` deliberately leaves HSTS to the CDN). If
behind a proxy, enable `mod_remoteip` so client IPs are real.

## 4. Verify

```sh
php -l index.php                            # syntax
curl -s localhost/api/blocks/tip/height
curl -s localhost/api/fee-estimates | head -c 200
```

Then open `https://litecoinexplorer.org/` in a browser and check `/status`. Any Esplora /
mempool.space-compatible Litecoin wallet or tool can use `https://litecoinexplorer.org/api` as
a drop-in backend.

## 5. MWEB peg index (optional)

Out of the box the `/mweb` pages + `/api/mweb/*` are served live from litecoind RPC. That covers
per-block/tx MWEB, current supply and recent blocks, but deep history (the supply chart, full
peg-in/peg-out lists) needs an index. It is a **self-contained SQLite index** in `db/`, kept
fresh by a PHP cron - no extra daemon.

Build it once on a machine that has an [mwebscan](https://mwebscan.com) analytics DB, then ship
the tiny result, exactly like the Electrum index:

```sh
# seed the explorer index from an mwebscan DB (pegs + a supply series)
php tools/mweb-seed.php ltc-mainnet /path/to/mwebscan.db
# -> db/mweb-ltc.sqlite  ;  ship it to the server db/ dir, chown www-data
```

Turn it on in `config.php` (the LTC network's `mweb` block):

```php
'index' => ['enabled' => true, 'db' => __DIR__ . '/db/mweb-ltc.sqlite', 'max_lag' => 6],
```

Keep it current with the incremental indexer (reorg-safe, resumes from its cursor). systemd
timer (~every 2 min; a `flock` wrapper stops overlap):

```ini
# /etc/systemd/system/litecoinexplorer-mweb.service
[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/flock -n /run/lock/lex-mweb /usr/bin/php /var/www/litecoinexplorer.org/tools/mweb-index.php ltc-mainnet
# /etc/systemd/system/litecoinexplorer-mweb.timer
[Timer]
OnBootSec=90
OnUnitActiveSec=120
[Install]
WantedBy=timers.target
```

`systemctl enable --now litecoinexplorer-mweb.timer`. After the seed the indexer just catches up
the blocks since the seed tip. The index is a pure accelerator: within `max_lag` of the tip the
history views use it; otherwise (stale/absent/disabled) everything falls back to live RPC. Wipe
`db/mweb-ltc.sqlite` any time to rebuild.

## 6. Stats snapshot cron (mining history)

`tools/snapshot.php` records one mempool / fee / tip row for the **Mining** history charts and
warms the UTXO-set + block-strip caches. Run it every ~5 minutes:

```ini
# /etc/systemd/system/litecoinexplorer-snapshot.service
[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/php /var/www/litecoinexplorer.org/tools/snapshot.php
# /etc/systemd/system/litecoinexplorer-snapshot.timer
[Timer]
OnBootSec=120
OnUnitActiveSec=300
[Install]
WantedBy=timers.target
```

`systemctl enable --now litecoinexplorer-snapshot.timer` - or the crontab equivalent
`*/5 * * * * www-data php /var/www/litecoinexplorer.org/tools/snapshot.php >/dev/null 2>&1`.
Without it the mining mempool/fee-history charts stay empty (everything else works).

### The audit tick (every ~30s)

A block can only be health-audited if a next-block template snapshot was captured **while that
block was still pending**. LTC blocks arrive every ~2.5 min, faster than the 5-minute full run, so
the full cron alone catches only about half of blocks - the rest show `-` for health. Run the
lightweight `--tick` on a tight interval to fix this: it does *only* the cheap live-mempool passes
(the block-audit snapshot + diff and RBF detection), skipping all the heavy warm/index steps, so it
is safe to run every ~30s. With it, nearly every block's pending window catches a snapshot and
health coverage becomes near-complete (blocks mined during a genuinely empty mempool still show `-`,
since there is no template to compare).

```ini
# /etc/systemd/system/litecoinexplorer-audit.service
[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/php /var/www/litecoinexplorer.org/tools/snapshot.php --tick
# /etc/systemd/system/litecoinexplorer-audit.timer
[Timer]
OnBootSec=90
OnUnitActiveSec=30
AccuracySec=5
[Install]
WantedBy=timers.target
```

`systemctl enable --now litecoinexplorer-audit.timer`. (Plain cron can't go below 1-minute
granularity, so use the systemd timer - or a `*/1` cron as a coarser fallback.) This tick also
keeps **RBF replacement detection** (`/api/v1/replacements`, `/api/v1/tx/:txid/rbf`) current at
~30s; otherwise those stay sparse at the 5-minute cadence unless the WebSocket daemon (§3) is
running, which ticks it every ~5 s.

The **block audit** (the "Template audit" card on each block: predicted-vs-mined transactions)
snapshots the fee-ordered next-block template into `db/audit.sqlite` (created automatically beside
the cache DB; best-effort, no config) and diffs each newly-confirmed block against the snapshot
taken while it was pending. The `mempool_snap` scratch self-prunes after 48h; audit result rows are
kept indefinitely (tiny per-block counts), and only the bulky ~256 KB template blob is stripped from
rows older than 48h. Both the full 5-minute run and the `--tick` timer drive it; running the tick is
what makes coverage complete.

Finally, the cron fills the **block-economics index** (`db/blockindex.sqlite`) that backs the
mining timeseries endpoints (`/api/v1/mining/blocks/fees|rewards|fee-rates|sizes-weights/:period`,
`/mining/hashrate/pools/:period`, per-pool history) and the **Charts** time-period selector. This
is the heaviest cron step: it indexes up to `blockindex_per_run` blocks per run (config, default
150) - new blocks first, then backfilling history - and retains ~`blockindex_retain` recent blocks
(default 52,560 ≈ 90 days). History fills in over the first hours/days; long-period charts populate
as it backfills. It bails out if the node can't serve `getblockstats` (e.g. a pruned node), so it
won't storm a struggling backend. Lower `blockindex_per_run` if the node is busy.

## Notes

- **Cache**: `db/cache.sqlite` (WAL) memoizes immutable tx/block bodies and short-TTL tips/fees.
  Safe to delete; it just cold-starts. The source of truth is always the node + ElectrumX.
- **Social preview cards**: shared block/tx/address/home links get a dynamic 1200x630 Open Graph
  image at `/og/<type>/<id>.png`, drawn with **php-gd + FreeType** from a TTF (DejaVu Sans is
  auto-probed in the usual `/usr/share/fonts/...` paths; set `og_font` / `og_font_bold` in
  `config.php` to override, or drop a `.ttf` in `assets/fonts/`). If gd, FreeType or a font is
  missing it transparently serves the static `assets/og-banner.png`, so nothing breaks - install
  `php-gd` and `fonts-dejavu-core` to enable the cards. (The bundled `og-banner.png` still shows
  the old branding; regenerate it with your own artwork if you rely on the static fallback.)
- **Rate-limit the heavy lookups at the CDN**: an `/xpub/<key>` lookup derives addresses and
  fires up to ~20 Electrum queries on a cold cache (per-result cached 60s); `/address/<addr>`
  walks a tx history. Add a Cloudflare rate-limit rule (e.g. ~10 req/min/IP on path `/xpub/*`,
  and optionally `/address/*`) so a flood of distinct keys can't amplify load onto ElectrumX.
  Consider a modest CDN limit on `POST /api/tx` (broadcast) too - it relays 1:1 to litecoind (no
  amplification, and the node dedups/enforces mempool policy), but a public unauthenticated relay
  endpoint benefits from a spam ceiling.
  A built-in origin-side per-IP limiter (`lx_rate_limit`, backed by the cache DB) also throttles
  `/xpub/*` (20/min) and `/og/*` (60/min) as defense-in-depth. It keys on `CF-Connecting-IP`, so
  **the origin must only accept Cloudflare traffic** (firewall the origin to Cloudflare's IP
  ranges) - otherwise a client hitting the origin directly can spoof that header to dodge the
  limit. The limiter fails open if the cache DB is unavailable, so the CDN rule is the primary
  defense.
- **Stores** (all beside the cache DB, all safe to delete - they self-rebuild): `stats.sqlite`
  (mempool/fee/tip history for the Mining charts), `audit.sqlite` (block-audit snapshots +
  results), `mweb-*.sqlite` (the optional MWEB peg index). All are best-effort: if the directory
  is unwritable the features simply hide and the rest of the site is unaffected.
- **`address_tx_cap`** in `config.php` bounds how many of an address's txs are walked to compute
  funded/spent sums (ElectrumX returns net balance, not per-output sums). The default (5000) is
  ample; raise it if you index very busy addresses.
- **MWEBscan overlay**: the MWEB page, block MWEB card, and peg-in/out tx pages enrich our
  node-sourced boundary data with MWEBscan's analysis layer (round-trip linking, privacy scoring,
  entity attribution), joined by `txid:vout`. It's best-effort and config-gated - the base URL
  defaults to the **public** API (`https://mwebscan.com/api`, rate-limited 60/min/IP), fine for
  light traffic. For a busy production site, point at an **allow-listed or keyed** instance in
  `config.php`: `'mwebscan_api' => 'https://.../api'` (optionally `'mwebscan_api_key' => '...'`
  for an `X-API-Key` header). Calls are cached, network-asserted, short-timeout, and backed off
  on failure; if unset/unreachable, every MWEB surface degrades to boundary-only. Required
  attribution ("Data from MWEBscan", CC BY 4.0) is rendered on each enriched surface.
- **Scaling**: every request opens one Electrum connection and reuses it for that request. For
  higher load, front ElectrumX with a connection pool or run `php-fpm` with more workers.
