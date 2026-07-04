<?php
// Same-origin proxy for the TPC holder count.
//
// Suprascan's /api/graphql endpoint serves the data we want but returns no
// CORS headers, so the browser can't call it directly from another domain.
// This script runs on the same domain as index.html, so the browser request
// is same-origin and CORS doesn't apply.
//
// Returns JSON: {"holders": <int>, "updated_at": <unix-ts>}
//        or:    {"error": "<reason>"} with a 502 if the upstream call fails
//               and there's no stale cache to fall back to.

declare(strict_types=1);

date_default_timezone_set('UTC');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

const TPC_FA = '0x99f84c4fda663bf3baf3a1b0980386ca084c3e9340a4d3f8713cd54ec85f4cea';
const UPSTREAM = 'https://suprascan.io/api/graphql';
const CACHE_TTL = 30;      // seconds
const STALE_MAX = 3600;    // stale cache older than 1 h is worse than an honest error

// Cache lives in a per-site directory, not the host-wide temp dir — on shared
// hosting sys_get_temp_dir() can be shared across tenants, and we serve these
// bytes verbatim to visitors.
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/tpc-holders-cache.json';

// Only ever echo a cache file whose shape matches what we would have produced.
function readValidCache(string $file): ?string {
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['holders']) || !is_int($decoded['holders'])) {
        return null;
    }
    return $raw;
}

// Serve fresh cache if we have one.
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
    $cached = readValidCache($cacheFile);
    if ($cached !== null) {
        echo $cached;
        exit;
    }
}

$query = 'query GetFaHolders($faAddress: String, $page: Int, $offset: Int, $blockchainEnvironment: BlockchainEnvironment) { '
       . 'getFaHolders(faAddress: $faAddress, page: $page, offset: $offset, blockchainEnvironment: $blockchainEnvironment) { '
       . 'totalItems isError errorType } }';

$payload = json_encode([
    'operationName' => 'GetFaHolders',
    'variables' => [
        'faAddress' => TPC_FA,
        'page' => 1,
        'offset' => 1,                  // we only need the totalItems metadata
        'blockchainEnvironment' => 'mainnet',
    ],
    'query' => $query,
]);

$ch = curl_init(UPSTREAM);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'User-Agent: topperharleycoin.com holders-proxy',
    ],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

function fail(string $msg): void {
    global $cacheFile;
    // Prefer serving a stale cache over erroring out — but only up to
    // STALE_MAX old, and flagged so the payload is honest about its age.
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < STALE_MAX) {
        $stale = readValidCache($cacheFile);
        if ($stale !== null) {
            $decoded = json_decode($stale, true);
            $decoded['stale'] = true;
            echo json_encode($decoded);
            exit;
        }
    }
    http_response_code(502);
    echo json_encode(['error' => $msg]);
    exit;
}

if ($response === false || $httpCode !== 200) {
    fail("upstream HTTP $httpCode: $curlErr");
}

$decoded = json_decode($response, true);
$holders = $decoded['data']['getFaHolders']['totalItems'] ?? null;
$isError = $decoded['data']['getFaHolders']['isError'] ?? false;

if (!is_int($holders) || $isError) {
    fail('bad upstream response');
}

$out = json_encode([
    'holders' => $holders,
    'updated_at' => time(),
]);

@file_put_contents($cacheFile, $out, LOCK_EX);
echo $out;
