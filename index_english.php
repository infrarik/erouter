<?php
/**
 * index.php - abrp with openrouteservice (ors) and gemini optimization
 */

// ============================================================
// configuration and key management
// ============================================================

$homedir = dirname(dirname(__DIR__));
$keydir = $homedir . '/home/';
$orskeyfile = $keydir . 'ors_key.txt';
$geminikeyfile = $keydir . 'gemini_key.txt';

$keysaved = false;
$keysaveerror = '';

if (isset($_POST['save_api_keys'])) {
    $neworskey = trim($_POST['ors_api_key_input'] ?? '');
    $newgeminikey = trim($_POST['gemini_api_key_input'] ?? '');

    if (!is_dir($keydir)) {
        @mkdir($keydir, 0750, true);
    }

    if (!is_dir($keydir)) {
        $keysaveerror = "unable to create the key storage directory. please check permissions (chmod 644).";
    } else {
        if ($neworskey !== '') {
            if (@file_put_contents($orskeyfile, $neworskey) !== false) {
                $keysaved = true;
            } else {
                $keysaveerror = "unable to write the ors key. please check permissions (chmod 644).";
            }
        }
        if ($newgeminikey !== '') {
            if (@file_put_contents($geminikeyfile, $newgeminikey) !== false) {
                $keysaved = true;
            } else {
                $keysaveerror = "unable to write the gemini key. please check permissions (chmod 644).";
            }
        }
    }
}

$orskey = file_exists($orskeyfile) ? trim(file_get_contents($orskeyfile)) : '';
$geminikey = file_exists($geminikeyfile) ? trim(file_get_contents($geminikeyfile)) : '';

define('ORS_API_KEY', $orskey);
define('GEMINI_API_KEY', $geminikey);
define('ORS_API_URL', 'https://api.openrouteservice.org/v2/directions/driving-car');
define('ORS_GEOCODE_URL', 'https://api.openrouteservice.org/geocode/search');

define('SAMPLING_DISTANCE_M_DEFAULT', 20000);
define('SAMPLING_DISTANCE_M_MIN',      5000);
define('SAMPLING_DISTANCE_M_MAX',    100000);
define('MAX_TOTAL_POINTS', 150);

// ============================================================
// calculation and routing functions
// ============================================================

function decodepolyline(string $encoded): array
{
    $points = [];
    $index = 0;
    $len = strlen($encoded);
    $lat = 0;
    $lng = 0;

    while ($index < $len) {
        $result = 1;
        $shift = 0;
        do {
            $b = ord($encoded[$index++]) - 63 - 1;
            $result += $b << $shift;
            $shift += 5;
        } while ($b >= 0x1f);
        $lat += ($result & 1) ? ~($result >> 1) : ($result >> 1);

        $result = 1;
        $shift = 0;
        do {
            $b = ord($encoded[$index++]) - 63 - 1;
            $result += $b << $shift;
            $shift += 5;
        } while ($b >= 0x1f);
        $lng += ($result & 1) ? ~($result >> 1) : ($result >> 1);

        $points[] = ['lat' => $lat * 1e-5, 'lon' => $lng * 1e-5];
    }
    return $points;
}

function orscomputeroute(array $origin, array $destination, bool $avoidtolls): array
{
    $url = ORS_API_URL . '/json';
    $ch = curl_init($url);

    $body = [
        'coordinates' => [
            [$origin['lon'], $origin['lat']],
            [$destination['lon'], $destination['lat']]
        ],
        'units' => 'm',
        'geometry' => true,
        'instructions' => false,
        'elevation' => false
    ];

    if ($avoidtolls) {
        $body['options'] = ['avoid_features' => ['tollways']];
    }

    $jsonbody = json_encode($body, JSON_UNESCAPED_UNICODE);

    curl_setopt_array($ch, [
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_POST'           => true,
        'CURLOPT_POSTFIELDS'     => $jsonbody,
        'CURLOPT_HTTPHEADER'     => [
            'Content-Type: application/json',
            'Authorization: ' . ORS_API_KEY
        ],
        'CURLOPT_TIMEOUT'        => 30,
        'CURLOPT_SSL_VERIFYPEER' => true,
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlerror = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception("network error: $curlerror");
    }

    $data = json_decode($response, true);

    if ($httpcode !== 200) {
        $msg = $data['error']['message'] ?? $data['message'] ?? 'unknown error';
        $code = $data['error']['code'] ?? '';
        throw new Exception("ors error (http $httpcode): $msg" . ($code ? " (code: $code)" : ""));
    }

    if (empty($data['routes']) || empty($data['routes'][0])) {
        $errormsg = $data['error'] ?? $data['message'] ?? 'no route found';
        if (is_array($errormsg)) $errormsg = json_encode($errormsg);
        throw new Exception("no route found. details: " . $errormsg);
    }

    $route = $data['routes'][0];
    $summary = $route['summary'] ?? [];
    $geometry = $route['geometry'] ?? '';

    if (empty($geometry)) {
        throw new Exception("missing geometry (polyline).");
    }

    $points = decodepolyline($geometry);
    if (empty($points)) {
        throw new Exception("unable to decode polyline.");
    }

    return [
        'distancemeters' => (int) round($summary['distance'] ?? 0),
        'duration'       => round(($summary['duration'] ?? 0)) . 's',
        'coordinates'    => $points,
    ];
}

function orsgeocodewithsuggestions(string $address, int $limit = 10): array
{
    $cleanaddress = preg_replace('/\s*\([^)]*\)/', '', $address);
    $cleanaddress = trim($cleanaddress) ?: $address;

    $url = ORS_GEOCODE_URL . '?' . http_build_query([
        'text' => $cleanaddress,
        'size' => $limit,
        'boundary.country' => 'FR'
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_HTTPHEADER'     => ['Authorization: ' . ORS_API_KEY],
        'CURLOPT_TIMEOUT'        => 15,
        'CURLOPT_SSL_VERIFYPEER' => true,
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        throw new Exception("geocoding error (http $httpcode) for: $address");
    }

    $data = json_decode($response, true);
    $results = [];
    $seen = [];

    if (!empty($data['features'])) {
        foreach ($data['features'] as $feature) {
            $geometry = $feature['geometry'];
            $properties = $feature['properties'] ?? [];
            $coords = $geometry['coordinates'];

            $lat = $coords[1];
            $lon = $coords[0];
            $key = round($lat, 5) . ',' . round($lon, 5);

            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $name = $properties['name'] ?? '';
            $locality = $properties['locality'] ?? '';
            $county = $properties['county'] ?? '';
            $country = $properties['country'] ?? '';
            $type = $properties['type'] ?? '';

            $label = $locality ?: $name;
            if (empty($label)) $label = $address;

            $detail = $county && $county !== $label ? $county : '';
            if ($country && strtolower($country) !== 'france') {
                $detail = $detail ? $detail . ', ' . $country : $country;
            }

            $results[] = [
                'lat'        => $lat,
                'lon'        => $lon,
                'address'    => $label,
                'full_name'  => $properties['label'] ?? $label,
                'detail'     => $detail,
                'type'       => $type,
                'confidence' => $properties['confidence'] ?? 0,
            ];
        }
    }
    return $results;
}

function orsgeocode(string $address): array
{
    $results = orsgeocodewithsuggestions($address, 10);
    if (empty($results)) {
        throw new Exception("no results found for: $address");
    }
    usort($results, function($a, $b) {
        $scorea = ($a['type'] === 'locality' || $a['type'] === 'city') ? 10 : 0;
        $scoreb = ($b['type'] === 'locality' || $b['type'] === 'city') ? 10 : 0;
        $scorea += $a['confidence'] / 10;
        $scoreb += $b['confidence'] / 10;
        return $scoreb <=> $scorea;
    });
    return $results[0];
}

function resolvepoint(string $input, bool $returnsuggestions = false): array
{
    $input = trim($input);
    if (preg_match('/^(-?\d+(\.\d+)?)\s*,\s*(-?\d+(\.\d+)?)$/', $input, $m)) {
        return ['lat' => (float)$m[1], 'lon' => (float)$m[3], 'address' => $input, 'is_coord' => true];
    }
    if ($returnsuggestions) {
        return orsgeocodewithsuggestions($input, 10);
    }
    $result = orsgeocode($input);
    $result['original_query'] = $input;
    return $result;
}

function haversinedistance(array $p1, array $p2): float
{
    $earthradius = 6371000;
    $lat1 = deg2rad($p1['lat']);
    $lat2 = deg2rad($p2['lat']);
    $dlat = deg2rad($p2['lat'] - $p1['lat']);
    $dlon = deg2rad($p2['lon'] - $p1['lon']);
    $a = sin($dlat/2)**2 + cos($lat1)*cos($lat2)*sin($dlon/2)**2;
    return $earthradius * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function samplepoints(array $points, float $targetdistancem): array
{
    if (count($points) <= 2) return $points;
    $sampled = [$points[0]];
    $accumulated = 0.0;
    for ($i = 1; $i < count($points); $i++) {
        $accumulated += haversinedistance($points[$i-1], $points[$i]);
        if ($accumulated >= $targetdistancem) {
            $sampled[] = $points[$i];
            $accumulated = 0.0;
        }
    }
    $last = end($points);
    if (end($sampled) !== $last) $sampled[] = $last;
    return $sampled;
}

function buildabrpdeeplink(array $destinations): string
{
    $json = json_encode($destinations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return 'https://abetterrouteplanner.com/?destinations=' . rawurlencode($json);
}

function formatduration(string $isoduration): string
{
    $seconds = (int) str_replace('s', '', $isoduration);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return ($h > 0 ? "{$h}h " : '') . "{$m}m";
}

// ============================================================
// gemini toll optimization integration
// model: gemini-2.5-flash (free tier AI Studio, no credit card required)
// key from: https://aistudio.google.com/apikey
function demanderoptimisationpeages(array $segmentsatoll): string
{
    if (empty(GEMINI_API_KEY)) {
        return "missing gemini configuration to analyze rates.";
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . GEMINI_API_KEY;

    $listenquetes = "";
    foreach ($segmentsatoll as $idx => $seg) {
        $listenquetes .= "- route " . ($idx + 1) . " : between " . $seg['from'] . " and " . $seg['to'] . "
";
    }

    $prompt = "using autoroute-eco.fr and the various available highway companies, viamichelin, mappy, give me the direct toll rate on highways and the best toll split combinations for a class 1 vehicle on the following trips:
$listenquetes
use only lowercase text, be concise, clearly separate the trips, list the advised exits and the estimated savings for each toll portion.";

    $body = [
        "contents" => [
            ["parts" => [["text" => $prompt]]]
        ],
        "tools" => [
            ["google_search" => (object)[]]  // enables real-time web search
        ],
        "generationConfig" => [
            "thinkingConfig" => ["thinkingBudget" => 8000]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_POST'           => true,
        'CURLOPT_POSTFIELDS'     => json_encode($body),
        'CURLOPT_HTTPHEADER'     => ['Content-Type: application/json'],
        'CURLOPT_TIMEOUT'        => 20,
        'CURLOPT_SSL_VERIFYPEER' => true,
    ]);

    $response = curl_exec($ch);
    $httpcode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        $data = json_decode($response, true);
        $msg = $data['error']['message'] ?? "http error $httpcode";
        return "unable to load rate optimization: $msg";
    }

    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? "no optimization found.";
}

// ============================================================
// form handling and requests processing
// ============================================================

$result = null;
$error = null;
$geminiadvice = null;

$formpoints = $_POST['point'] ?? ['', ''];
$formtolls = $_POST['tolls'] ?? [];
$selectedsuggestion = $_POST['selected_suggestion'] ?? [];

if (isset($_GET['suggest']) && !empty($_GET['suggest'])) {
    header('Content-Type: application/json');
    try {
        if (ORS_API_KEY === '') throw new Exception("missing api key.");
        $suggestions = resolvepoint($_GET['suggest'], true);
        echo json_encode(['success' => true, 'results' => $suggestions]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_api_keys'])) {
    try {
        if (ORS_API_KEY === '') throw new Exception("missing ors api key.");

        $samplingkm = max(5, min(100, (int)($_POST['sampling_km'] ?? 20)));
        $samplingdistm = $samplingkm * 1000;

        $rawpoints = array_map('trim', $formpoints);
        $rawpoints = array_values(array_filter($rawpoints, fn($p) => $p !== ''));
        if (count($rawpoints) < 2) throw new Exception("please provide at least a departure and an arrival location.");

        $nbsegments = count($rawpoints) - 1;
        $resolvedpoints = [];

        foreach ($rawpoints as $i => $p) {
            if (isset($selectedsuggestion[$i]) && !empty($selectedsuggestion[$i])) {
                $sugdata = json_decode($selectedsuggestion[$i], true);
                if ($sugdata) {
                    $resolvedpoints[$i] = $sugdata;
                    continue;
                }
            }
            $resolved = resolvepoint($p, false);
            $resolvedpoints[$i] = $resolved;
        }

        $segments = [];
        $allsampledpoints = [];
        $tollssegmentsforgemini = [];

        for ($i = 0; $i < $nbsegments; $i++) {
            $withtolls = in_array((string)$i, $formtolls, true);
            $avoidtolls = !$withtolls;
            $origin = $resolvedpoints[$i];
            $destination = $resolvedpoints[$i + 1];

            $route = orscomputeroute($origin, $destination, $avoidtolls);
            $points = samplepoints($route['coordinates'], $samplingdistm);

            $fromname = $origin['address'] ?? $rawpoints[$i];
            $toname = $destination['address'] ?? $rawpoints[$i + 1];

            $segments[] = [
                'from'      => $origin,
                'to'        => $destination,
                'withtolls' => $withtolls,
                'distancem' => $route['distancemeters'],
                'duration'  => $route['duration'],
                'nbpoints'  => count($points),
            ];

            if ($withtolls) {
                $tollssegmentsforgemini[] = ['from' => $fromname, 'to' => $toname];
            }

            if ($i > 0) array_shift($points);
            $allsampledpoints = array_merge($allsampledpoints, $points);
        }

        if (count($allsampledpoints) > MAX_TOTAL_POINTS) {
            $factor = (int) ceil(count($allsampledpoints) / MAX_TOTAL_POINTS);
            $reduced = [];
            foreach ($allsampledpoints as $j => $p) {
                if ($j % $factor === 0) $reduced[] = $p;
            }
            $reduced[] = end($allsampledpoints);
            $allsampledpoints = $reduced;
        }

        $destinations = [];
        foreach ($allsampledpoints as $p) {
            $destinations[] = ['lat' => round($p['lat'], 5), 'lon' => round($p['lon'], 5)];
        }
        $destinations[0]['address'] = $resolvedpoints[0]['address'] ?? $rawpoints[0];
        $destinations[count($destinations)-1]['address'] = $resolvedpoints[count($resolvedpoints)-1]['address'] ?? end($rawpoints);

        $deeplink = buildabrpdeeplink($destinations);
        $totaldistancem = array_sum(array_column($segments, 'distancem'));

        $result = [
            'points'           => $resolvedpoints,
            'segments'         => $segments,
            'nbpointstotal'    => count($destinations),
            'totaldistancekm'  => round($totaldistancem / 1000, 1),
            'deeplink'         => $deeplink,
            'destinationsjson' => json_encode($destinations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'samplingkm'       => $samplingkm,
        ];

        if (!empty(GEMINI_API_KEY)) {
            if (!empty($tollssegmentsforgemini)) {
                $geminiadvice = demanderoptimisationpeages($tollssegmentsforgemini);
            } else {
                $geminiadvice = "no toll segment selected — no optimization required.";
            }
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>abrp - ors & gemini</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: arial, sans-serif; max-width: 1000px; margin: 30px auto; padding: 0 15px; background: #f5f5f5; }
        h1, h2 { font-size: 1.4em; }
        .key-setup { background: #fff8e1; border: 2px solid #ffc107; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .key-setup h2 { margin: 0 0 10px; font-size: 1.1em; color: #856404; }
        .key-setup input[type=text] { width: 100%; padding: 9px 12px; border: 1px solid #ccc; border-radius: 5px; margin-bottom: 10px; 
font-family: monospace; }
        form { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
        .error { background: #fdecea; color: #b00020; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
        .result { background: #fff; padding: 20px; border-radius: 8px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
        .advice-box { background: #e8f4f8; padding: 15px; border-left: 4px solid #2c7be5; border-radius: 4px; margin-top: 15px; 
white-space: pre-line; }
        .btn-link { display: inline-block; margin-top: 10px; padding: 10px 18px; background: #28a745; color: #fff; text-decoration: 
none; border-radius: 5px; }
        button { padding: 9px 16px; background: #2c7be5; color: #fff; border: none; border-radius: 5px; cursor: pointer; }
        button.secondary { background: #6c757d; }
        .submit-btn { margin-top: 18px; padding: 10px 20px; font-size: 1em; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        td, th { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
        .point-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; position: relative; }
        .point-row .drag-handle { width: 24px; text-align: center; color: #aaa; font-size: 1.2em; cursor: grab; }
        .point-row .point-label { width: 90px; font-weight: bold; }
        .point-row .input-wrapper { flex: 1; position: relative; }
        .point-row .input-wrapper input { width: 100%; padding: 8px; }
        .point-row .remove-btn { background: #dc3545; color: #fff; border: none; border-radius: 5px; width: 30px; height: 34px; cursor: 
pointer; }
        .segment-row { padding: 4px 0 4px 130px; font-size: 0.9em; }
        .suggestions-box { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-radius: 
4px; max-height: 250px; overflow-y: auto; z-index: 1000; display: none; }
        .suggestions-box.active { display: block; }
        .suggestion-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; }
        .suggestion-item:hover { background: #e8f4f8; }
        .insert-indicator { height: 3px; background: #2196f3; margin: 0; visibility: hidden; }
        .key-save-ok { background: #d4edda; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; }
        .key-save-err { background: #fdecea; color: #b00020; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px; }
    </style>
</head>
<body>
<center>
<img src="erouterlogo.png" alt="eRouter" style="display:block; width:400px; height:auto; margin-bottom:10px;">
<h1>multi-stop itinerary with toll optimization</h1>
</center>

<?php if ($keysaved): ?>
<div class="key-save-ok">✅ keys saved successfully.</div>
<?php endif; ?>

<?php if (!empty($keysaveerror)): ?>
<div class="key-save-err">⚠️ <?= htmlspecialchars($keysaveerror) ?></div>
<?php endif; ?>

<?php if (empty($orskey) || empty($geminikey)): ?>
<div class="key-setup">
    <h2>🔑 api keys configuration</h2>
    <p>
        ors key: register for free on <a href="https://openrouteservice.org/dev/#/signup" target="_blank">openrouteservice.org</a> 
→ dashboard → tokens.<br>
        gemini key: available on <a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com/apikey</a> 
(free, no credit card required).
    </p>
    <form method="post">
        <label>openrouteservice key:</label>
        <input type="text" name="ors_api_key_input" value="<?= htmlspecialchars($orskey) ?>" placeholder="paste ors key here...">
        <label>google gemini key:</label>
        <input type="text" name="gemini_api_key_input" value="<?= htmlspecialchars($geminikey) ?>" placeholder="paste gemini key here...">
        <button type="submit" name="save_api_keys" value="1">save keys</button>
    </form>
</div>
<?php endif; ?>

<?php if (!empty($orskey)): ?>
<form method="post" id="route-form">
    <div id="points-list">
        <div class="insert-indicator" id="insert-indicator"></div>
    </div>
    <button type="button" id="add-step-btn" class="secondary">+ add stop</button>

    <div style="margin-top:18px; padding:12px; background:#f8f9fa; border-radius:6px;">
        <label for="sampling_km" style="font-weight:bold;">
            waypoint density:
            <span id="sampling_label" style="color:#2c7be5; margin-left:6px;"></span>
        </label>
        <input type="range" id="sampling_km" name="sampling_km"
            min="5" max="100" step="5"
            value="<?= (int)($_POST['sampling_km'] ?? $result['samplingkm'] ?? 20) ?>"
            style="width:100%; margin:8px 0; cursor:pointer;"
            oninput="updatesamplinglabel(this.value)">
        <div style="display:flex; justify-content:space-between; font-size:0.8em; color:#888;">
            <span>← more points (precise route)</span>
            <span>(simplified route) fewer points →</span>
        </div>
        <div style="font-size:0.85em; color:#555; margin-top:4px;">
            1 point every <strong id="sampling_km_val"></strong> km —
            on a 300 km route: approximately <strong id="sampling_count_est"></strong> points.
        </div>
    </div>

    <button type="submit" class="submit-btn">calculate route</button>
</form>
<?php endif; ?>

<?php if ($error): ?>
    <div class="error">error: <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
    <div class="result">
        <h2>route details</h2>
        <table>
            <tr><th>segment</th><th>toll</th><th>distance</th><th>duration</th><th>points</th></tr>
            <?php foreach ($result['segments'] as $seg): ?>
            <tr>
                <td><?= htmlspecialchars($seg['from']['address'] ?? '') ?> → <?= htmlspecialchars($seg['to']['address'] ?? '') ?></td>
                <td><?= $seg['withtolls'] ? 'with tolls' : 'no tolls' ?></td>
                <td><?= round($seg['distancem'] / 1000, 1) ?> km</td>
                <td><?= formatduration($seg['duration']) ?></td>
                <td><?= $seg['nbpoints'] ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p>
            total distance: <strong><?= $result['totaldistancekm'] ?> km</strong> —
            points sent to abrp: <strong><?= $result['nbpointstotal'] ?></strong>
            (1 pt / <?= $result['samplingkm'] ?> km)
        </p>
        <a class="btn-link" href="<?= htmlspecialchars($result['deeplink']) ?>" target="_blank">open in abrp →</a>

        <?php if ($geminiadvice): ?>
            <h3>tips and toll optimization — gemini ai</h3>
            <div class="advice-box"><?= htmlspecialchars($geminiadvice) ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
function updatesamplinglabel(val) {
    val = parseInt(val, 10);
    const count = Math.round(300 / val) + 1;
    document.getElementById('sampling_label').textContent  = '1 pt / ' + val + ' km';
    document.getElementById('sampling_km_val').textContent = val;
    document.getElementById('sampling_count_est').textContent = count;
}

const initialpoints = <?= json_encode($formpoints, JSON_UNESCAPED_UNICODE) ?>;
const initialtolls  = <?= json_encode(array_map('strval', $formtolls), JSON_UNESCAPED_UNICODE) ?>;
const pointslist    = document.getElementById('points-list');
const addstepbtn    = document.getElementById('add-step-btn');

let currentpoints = initialpoints.length >= 2 ? [...initialpoints] : ['', ''];
let currenttolls  = new Set(initialtolls);
let suggesttimeout = null;

function syncinputs() {
    const inputs = document.querySelectorAll('#points-list .point-row input[type="text"]');
    if (inputs.length === currentpoints.length) {
        currentpoints = Array.from(inputs).map(i => i.value);
    }
}

function render() {
    document.querySelectorAll('#points-list .point-row, #points-list .segment-row').forEach(r => r.remove());
    const total = currentpoints.length;

    currentpoints.forEach((val, i) => {
        // --- point line ---
        const row = document.createElement('div');
        row.className = 'point-row';

        const handle = document.createElement('div');
        handle.className = 'drag-handle';
        handle.textContent = '⠿';
        row.appendChild(handle);

        const label = document.createElement('div');
        label.className = 'point-label';
        label.textContent = i === 0 ? 'departure' : (i === total - 1 ? 'arrival' : 'stop ' + i);
        row.appendChild(label);

        const wrapper = document.createElement('div');
        wrapper.className = 'input-wrapper';
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'point[]';
        input.value = val;
        input.required = true;
        input.placeholder = 'city or lat,lon';
        wrapper.appendChild(input);
        row.appendChild(wrapper);
        attachsuggestions(input);

        const removebtn = document.createElement('button');
        removebtn.type = 'button';
        removebtn.className = 'remove-btn';
        removebtn.textContent = '✕';
        removebtn.disabled = total <= 2;
        removebtn.addEventListener('click', () => {
            syncinputs();
            currentpoints.splice(i, 1);
            render();
        });
        row.appendChild(removebtn);
        pointslist.appendChild(row);

        // --- segment line ---
        if (i < total - 1) {
            const segrow = document.createElement('div');
            segrow.className = 'segment-row';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'tolls[]';
            cb.value = String(i);
            cb.checked = currenttolls.has(String(i));
            cb.addEventListener('change', () => {
                if (cb.checked) currenttolls.add(String(i));
                else currenttolls.delete(String(i));
            });
            segrow.appendChild(cb);
            segrow.appendChild(document.createTextNode(' include tolls (otherwise avoid)'));
            pointslist.appendChild(segrow);
        }
    });
}

function attachsuggestions(input) {
    const wrapper = input.closest('.input-wrapper');
    const box = document.createElement('div');
    box.className = 'suggestions-box';
    wrapper.appendChild(box);

    input.addEventListener('input', () => {
        const q = input.value.trim();
        if (q.length < 2) { box.classList.remove('active'); return; }
        clearTimeout(suggesttimeout);
        suggesttimeout = setTimeout(async () => {
            try {
                const resp = await fetch('?suggest=' + encodeURIComponent(q));
                const data = await resp.json();
                box.innerHTML = '';
                if (!data.success || !data.results.length) { box.classList.remove('active'); return; }
                data.results.forEach(sug => {
                    const item = document.createElement('div');
                    item.className = 'suggestion-item';
                    item.textContent = sug.full_name || sug.address;
                    item.addEventListener('mousedown', e => {
                        e.preventDefault();
                        input.value = sug.address || sug.full_name;
                        box.classList.remove('active');
                    });
                    box.appendChild(item);
                });
                box.classList.add('active');
            } catch(e) { box.classList.remove('active'); }
        }, 300);
    });
    input.addEventListener('blur', () => setTimeout(() => box.classList.remove('active'), 300));
}

if (addstepbtn) {
    addstepbtn.addEventListener('click', () => {
        syncinputs();
        currentpoints.splice(currentpoints.length - 1, 0, '');
        render();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const sl = document.getElementById('sampling_km');
    if (sl) updatesamplinglabel(sl.value);
    render();
});
</script>
</body>
</html>
