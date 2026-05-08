# Topper Coin (TPC)

Showcase site for [Topper Coin](https://topperharleycoin.com), a community token on the [Supra](https://supra.com) blockchain.

## What's here

- **`index.html`** — single-page site with live price, 24h change, market cap, holder count, and a 4-frame OHLC chart (15m / 1h / 12h / 24h).
- **`history.php`** — same-origin proxy that pulls historical OHLC bars from the [Atmos](https://atmos.ag) `pricing_ohlc` GraphQL table. 30-second cache.
- **`holders.php`** — same-origin proxy that pulls the TPC holder count from Suprascan's GraphQL API. 30-second cache.

## Live data sources

| What | Where from |
|---|---|
| Spot price (USD) | Atmos `pricing` GraphQL table |
| Spot price (SUPRA) | On-chain `simulate_swap_exact_in_weighted` against the Atmos TPC↔SUPRA pool |
| Total supply | `0x1::fungible_asset::supply` view function on Supra mainnet RPC |
| Holder count | Suprascan `getFaHolders` (proxied — Suprascan sends no CORS headers) |
| Historical OHLC | Atmos `pricing_ohlc` table (proxied — same-origin only) |

## Deploy

```sh
cp .ftp-credentials.example .ftp-credentials
# edit .ftp-credentials with your real FTP values
chmod 600 .ftp-credentials
./deploy.sh
```

`deploy.sh` uploads the static files to a Forpsi web hosting account via FTP. The credentials file is gitignored.

## Contract

```
0x99f84c4fda663bf3baf3a1b0980386ca084c3e9340a4d3f8713cd54ec85f4cea
```

[View on Supra Explorer](https://suprascan.io/fa/0x99f84c4fda663bf3baf3a1b0980386ca084c3e9340a4d3f8713cd54ec85f4cea/f) · [Trade on Atmos DEX](https://app.atmos.ag) · [@toppercoin1965 on X](https://x.com/toppercoin1965)
