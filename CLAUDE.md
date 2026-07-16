# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A single-page marketing/data site for **Topper Coin (TPC)**, a community token on the
**Supra** blockchain. There is no build step, no framework, no package manager, and no
test suite — it is static HTML/CSS/JS plus two small PHP proxies, deployed by FTPS to
Forpsi shared hosting. Comments in the code are unusually detailed; read them before
changing behavior, because most non-obvious lines encode a deliberate fix.

## Commands

There is nothing to build, lint, or test. Iterate by opening `index.html` in a browser.

- **Local preview with working PHP proxies:** `php -S localhost:8000` from the repo root,
  then open `http://localhost:8000/`. The proxies (`history.php`, `holders.php`) need a
  PHP runtime with cURL; opening the file directly (`file://`) leaves the chart and
  holder count empty because those endpoints won't resolve.
- **Deploy:** `./deploy.sh` — uploads the five whitelisted files (see the `UPLOADS`
  array) to `www/` on Forpsi via FTPS. Requires `.ftp-credentials` (gitignored, copied
  from `.ftp-credentials.example`, `chmod 600`). The script refuses to run if the
  credentials file is group/other-readable or if TLS can't be negotiated.

## Architecture

**Everything the browser does lives in the one `<script>` block at the bottom of
`index.html`.** The two `.php` files exist only to work around CORS — the browser cannot
call the upstream APIs directly, so it calls same-origin PHP that calls them server-side.

### Data flow and sources

- **Live spot price** (`fetchTpcPrices`): primary source is the Atmos GraphQL `pricing`
  table (`api.atmos.ag/graphql`), one round-trip for both TPC and SUPRA USD prices. The
  SUPRA-denominated price is derived as the `tpcUsd / supraUsd` ratio. Fallback (only when
  the Atmos indexer is down) is on-chain `simulate_swap_exact_in_weighted` reads against
  the Atmos liquidity pools via the Supra mainnet RPC.
- **Historical OHLC chart** (`history.php` → `fetchHistory`): proxies the Atmos
  `pricing_ohlc` table with two aliased queries (TPC + SUPRA), joined **by time bucket**
  (timestamp floored to the granularity boundary), not by row index — this survives gaps
  where Atmos indexed one address but not the other for a bar. 30s cache in `cache/`.
- **Total supply / market cap** (`fetchTpcTotalSupply`): `0x1::fungible_asset::supply`
  view function on the Supra RPC. Market cap = USD price × total supply.
- **Holder count** (`holders.php` → `fetchHolderCount`): proxies Suprascan's
  `getFaHolders` GraphQL. 30s cache in `cache/`.

### Invariants that are easy to break

- **Price-basis consistency.** The live SUPRA price and the historical SUPRA bars are both
  the ratio `tpcUsd / supraUsd`. Do **not** feed the post-fee `simulate_swap` quote into
  the live SUPRA display when history is ratio-based — mixing bases painted a fake cliff at
  the newest chart point. That's why the on-chain path is fallback-only.
- **No invented data, ever.** Every number shows `—` until a real value arrives; there are
  no hardcoded seed/fallback prices. If a fetch fails the UI keeps the last real value or
  the em dash. Preserve this — it is the core honesty contract of the page.
- **The two `.ccy-btn` toggle groups share a CSS class** but are distinguished by
  `data-ccy` (USD/SUPRA) vs `data-frame` (15m/1h/12h/24h). Always scope DOM queries with
  the correct attribute selector; an unscoped `.ccy-btn` loop wipes the other group's
  active state.
- **UTC timestamp normalization.** Atmos timestamps are UTC wall-time with no zone suffix.
  Both `history.php` (`preg_replace` + `'Z'`) and `index.html` (`parseUtc`) append `Z`
  before parsing, or JS `Date.parse` reads them as the viewer's local time and shifts the
  whole chart. Keep both sides in sync.
- **SUPRA has a live and a stale FA address.** Use the canonical 32-byte form
  `0x0…0a` (the `SUPRA_FA` constant), not the short `0xa` — Atmos's pricing table has a
  stale row under the short form.
- **Decimals:** TPC = 6, SUPRA = 8, SUP_USDC = 6. These asymmetries are load-bearing in
  the on-chain price math (`fetchPricesFromSupraPool`, `fetchTpcTotalSupply`).
- **Live-tick bucketing.** `applyLivePrices` rewrites the newest bucket's value in place
  and only appends a new point when wall-clock crosses a `FRAME_INTERVAL_MS` boundary — it
  must not reset the reference timestamp each tick, or long-open tabs stop accumulating
  history.

### PHP proxy conventions (both files)

Same shape in `history.php` and `holders.php`: validate input → serve fresh cache (`< 30s`)
→ call upstream via cURL with short timeouts → on failure serve a *flagged* stale cache up
to `STALE_MAX`, else HTTP 502 with `{"error": ...}`. Cache is a per-site `cache/` dir
(never `sys_get_temp_dir()`, which is shared across tenants on this host), and cache files
are shape-validated (`readValidCache`) before being echoed to visitors. No
`Access-Control-Allow-Origin` header — calls are same-origin by design.

## Notable

- `assets/chart.umd.min.js` is Chart.js 4.4.1, vendored deliberately so no third-party CDN
  sits in the trust chain of a page people copy a contract address from. Don't replace it
  with a CDN `<script>`.
- The TPC FA contract address is hardcoded in four places (`index.html` constant + footer,
  `history.php`, `holders.php`). If it ever changes, update all of them.
- Commit messages in this repo are in Czech.
