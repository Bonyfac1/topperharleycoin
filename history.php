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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('Access-Control-Allow-Origin: *');

const TPC_FA   = '0x99f84c4fda663bf3baf3a1b0980386ca084c3e9340a4d3f8713cd54ec85f4cea';
const SUPRA_FA = '0x000000000000000000000000000000000000000000000000000000000000000a';
const UPSTREAM = 'https://api.atmos.ag/graphql';
const ALLOWED_GRANULARITIES = ['15m', '1h', '12h', '24h'];
const DEFAULT_GRANULARITY = '1h';
const POINT_LIMIT = 2000;
const CACHE_TTL   = 30;

// --- Validate input ---
$granularity = $_GET['granularity'] ?? DEFAULT_GRANULARITY;
if (!in_array($granularity, ALLOWED_GRANULARITIES, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid granularity']);
    exit;
}

$cacheFile = sys_get_temp_dir() . '/tpc-history-' . $granularity . '.json';

// --- Fresh cache served as-is ---
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL) {
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false) {
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
    // Prefer serving a stale cache over erroring out.
    if (is_file($cacheFile)) {
        $stale = @file_get_contents($cacheFile);
        if ($stale !== false) {
            echo $stale;
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
    $points[] = [
        't'     => $row['created_at'],
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
