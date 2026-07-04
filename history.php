<?php
// Same-origin proxy for TPC historical OHLC price data.
//
// Returns USD close + SUPRA-denominated close for each historical bar at the
// requested granularity. Calls Atmos GraphQL `pricing_ohlc` with two aliases
// (TPC + SUPRA) ordered by created_at desc, then joins them on a bucket key
// (timestamp floored to the granularity boundary). Joining by bucket — not
// by row index — survives gaps where Atmos indexed one fa_address but not
// the other for a given bar.
//
// Returns JSON: {"granularity": "1h", "points": [{"t": "...", "usd": ..., "supra": ...}, ...]}
//        or:    {"error": "<reason>"} with a 502 if the upstream call fails
//               and there's no stale cache to fall back to.

declare(strict_types=1);

date_default_timezone_set('UTC');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
// No Access-Control-Allow-Origin header: index.html calls this same-origin,
// and a wildcard would only invite other sites to hotlink the proxy.

const TPC_FA   = '0x99f84c4fda663bf3baf3a1b0980386ca084c3e9340a4d3f8713cd54ec85f4cea';
const SUPRA_FA = '0x000000000000000000000000000000000000000000000000000000000000000a';
const UPSTREAM = 'https://api.atmos.ag/graphql';
const ALLOWED_GRANULARITIES = ['15m', '1h', '12h', '24h'];
const DEFAULT_GRANULARITY = '1h';
const POINT_LIMIT = 2000;
const CACHE_TTL   = 30;
const STALE_MAX   = 6 * 3600;  // stale cache older than 6 h is worse than an honest error

// --- Validate input ---
$granularity = $_GET['granularity'] ?? DEFAULT_GRANULARITY;
if (!in_array($granularity, ALLOWED_GRANULARITIES, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid granularity']);
    exit;
}

// Cache lives in a per-site directory, not the host-wide temp dir — on shared
// hosting sys_get_temp_dir() can be shared across tenants, and we serve these
// bytes verbatim to visitors.
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/tpc-history-' . $granularity . '.json';

// Only ever echo a cache file whose shape matches what we would have produced.
function readValidCache(string $file): ?string {
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['points']) || !is_array($decoded['points'])) {
        return null;
    }
    return $raw;
}

// --- Fresh cache served as-is ---
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
    $cached = readValidCache($cacheFile);
    if ($cached !== null) {
        echo $cached;
        exit;
    }
}

// --- Build GraphQL query ---
$query = 'query ($g: String!, $tpc: String!, $supra: String!, $limit: Int!) {'
       . ' tpc: pricing_ohlc('
       .   ' where: {fa_address: {_eq: $tpc}, granularity: {_eq: $g}},'
       .   ' order_by: {created_at: desc},'
       .   ' limit: $limit'
       . ') { created_at close_price }'
       . ' supra: pricing_ohlc('
       .   ' where: {fa_address: {_eq: $supra}, granularity: {_eq: $g}},'
       .   ' order_by: {created_at: desc},'
       .   ' limit: $limit'
       . ') { created_at close_price }'
       . '}';

$payload = json_encode([
    'query' => $query,
    'variables' => [
        'g'     => $granularity,
        'tpc'   => TPC_FA,
        'supra' => SUPRA_FA,
        'limit' => POINT_LIMIT,
    ],
]);

// --- Upstream call ---
$ch = curl_init(UPSTREAM);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'User-Agent: topperharleycoin.com history-proxy',
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
            $decoded['updated_at'] = filemtime($cacheFile);
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
$tpcRows   = $decoded['data']['tpc']   ?? null;
$supraRows = $decoded['data']['supra'] ?? null;

if (!is_array($tpcRows) || !is_array($supraRows)) {
    fail('bad upstream response');
}

if (count($tpcRows) === 0 || count($supraRows) === 0) {
    fail('upstream returned no rows');
}

// --- Bucket-key join ---
// Floor each row's timestamp to the granularity boundary so paired bars match
// even if the two rows differ by tens of milliseconds (Atmos indexes both
// fa_addresses in the same cron run, but not at the exact same instant).
$bucketSecs = [
    '15m' => 900,
    '1h'  => 3600,
    '12h' => 43200,
    '24h' => 86400,
][$granularity];

$bucketKey = static function (string $iso) use ($bucketSecs): int {
    $ts = strtotime($iso);
    return intdiv($ts, $bucketSecs) * $bucketSecs;
};

$supraByBucket = [];
foreach ($supraRows as $row) {
    $key = $bucketKey($row['created_at']);
    $supraByBucket[$key] = (float) $row['close_price'];
}

// Walk TPC rows oldest-first so the output is in ascending time.
$points = [];
for ($i = count($tpcRows) - 1; $i >= 0; $i--) {
    $row = $tpcRows[$i];
    $key = $bucketKey($row['created_at']);
    $supraClose = $supraByBucket[$key] ?? null;
    if ($supraClose === null || $supraClose <= 0) {
        continue;
    }
    $tpcClose = (float) $row['close_price'];
    // Atmos timestamps are UTC wall time WITHOUT a zone suffix. Left as-is,
    // JavaScript's Date.parse would interpret them as the visitor's LOCAL
    // time, shifting every chart label by the viewer's UTC offset. Strip the
    // microseconds (Safari chokes on 6-digit fractions) and append 'Z'.
    $t = $row['created_at'];
    if (!preg_match('/(Z|[+-]\d\d:?\d\d)$/', $t)) {
        $t = preg_replace('/\.\d+$/', '', $t) . 'Z';
    }
    $points[] = [
        't'     => $t,
        'usd'   => $tpcClose,
        'supra' => $tpcClose / $supraClose,
    ];
}

if (count($points) === 0) {
    fail('no overlapping bars between TPC and SUPRA');
}

$out = json_encode([
    'granularity' => $granularity,
    'points'      => $points,
]);

@file_put_contents($cacheFile, $out, LOCK_EX);
echo $out;
