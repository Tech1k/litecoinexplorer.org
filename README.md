# Litecoin Explorer

**Litecoin, block by block.** A self-hosted **Litecoin block explorer with first-class MWEB support** and an
optional [MWEBscan](https://mwebscan.com) analysis overlay. It serves blocks, transactions,
addresses, the mempool, mining and fees from your own node, plus Litecoin's MWEB peg-in/peg-out
tracking and supply, enriched with MWEBscan's analysis overlay (round-trip linking, privacy
scoring, entity attribution).

It also speaks a **drop-in [Esplora](https://github.com/Blockstream/esplora/blob/master/API.md) /
[mempool.space](https://mempool.space/docs/api/rest)-compatible REST API**, so a compatible
wallet or tool can point at it as a backend.

Pure PHP + SQLite. No Composer, no framework, no build step. Drop the folder into an Apache
docroot (`/var/www/litecoinexplorer.org`) and go.

```
browser · wallet ──Esplora REST──▶ Litecoin Explorer (PHP)
                          ├─ Electrum TCP ─▶ ElectrumX   (LTC address/scripthash index)
                          └─ JSON-RPC ─────▶ litecoind    (blocks, tx, mempool, fees, broadcast, MWEB pegs)
SQLite = response cache + optional MWEB peg index
```

## Why

`litecoind` alone can't answer per-address queries; that needs an index. Litecoin Explorer
combines an **ElectrumX** server (the address/scripthash index) with **litecoind JSON-RPC**
(everything else) and assembles the exact JSON shapes Esplora and mempool.space return.

## Features

- **Explorer UI**: home (fee tiers, difficulty/hashrate, retarget, mempool, latest
  blocks + txs), blocks, block + paginated txs, transaction detail (witness/script stack,
  fee rate, RBF/CPFP, spent status, OP_RETURN decode), address, xpub, mempool, search, tools.
- **Esplora API** at `/api/...`: blocks, tx (with `vin.prevout`), address, scripthash,
  mempool, fee estimates, `POST /tx` broadcast, plus mempool.space extensions
  (`/v1/fees/recommended`, difficulty adjustment, mining pools/hashrate). See `/docs`.
- **MWEB**: per-block peg-in/peg-out + supply from `litecoind` RPC, plus an optional
  self-contained SQLite peg index for a supply chart, full peg history and
  `/api/mweb/{tip,block,blocks,pegins,pegouts,supply}`. An optional
  [MWEBscan](https://mwebscan.com) overlay adds round-trip linking / privacy scoring.
- **`/status`** dashboard (node RPC + Electrum index + MWEB health) and a generated
  `/sitemap.xml`.
- PWA (offline shell), JSON-LD, dark/light theme, permissive CORS, strict CSP.

## Requirements

- PHP 8.x with `pdo_sqlite`, `curl`, `hash`, and sockets/OpenSSL (for the Electrum client).
  `gmp` is required for the xpub address-derivation page (optional; the page hides itself
  if absent).
- `litecoind` (mainnet) with **`txindex=1`**.
- An Electrum-protocol address index: **spesmilo/ElectrumX** (`COIN=Litecoin NET=mainnet`)
  or the Litecoin Foundation's **`rust-litecoin/electrs-ltc`** (the indexer behind
  litecoinspace.org). Either works - the client only uses standard scripthash methods.
  See [DEPLOY.md](DEPLOY.md).

## Quick start

```sh
cp config.example.php config.php   # edit litecoind RPC + ElectrumX endpoints
# point Apache at this directory (AllowOverride All so .htaccess applies)
```

Verify: `curl https://your-host/api/blocks/tip/height` and open `/status`.
Full setup (litecoind, ElectrumX, MWEB seed, vhost, Cloudflare) is in
**[DEPLOY.md](DEPLOY.md)**.

## MWEB peg index (optional)

The `/mweb` page works RPC-only out of the box (current supply + recent blocks). For the
supply chart and full peg history, seed a self-contained SQLite index once from an
[mwebscan](https://mwebscan.com) dataset (`php tools/mweb-seed.php ltc-mainnet <db>`), enable
`mweb.index` in `config.php`, and keep it fresh with `tools/mweb-index.php` on a timer. The
index is a pure accelerator - reads fall back to live RPC when it is absent or stale.

## API

Amounts are **integer satoshis**. `text/plain` for `/tx/:txid/hex`, `/block/:hash/header`,
`/blocks/tip/{height,hash}`, and `POST /tx`. `/v1/fees/recommended` is the mempool.space
extension; `/fee-estimates` is vanilla Esplora. Full list at `/docs`.

## License

AGPL-3.0-or-later. The §13 network-use clause is honored by the visible source link in the
footer.
