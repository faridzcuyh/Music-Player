<?php
header('Access-Control-Allow-Origin: *');

$artist = isset($_GET['artist']) ? trim($_GET['artist']) : '';
if (!$artist) {
    header('Content-Type: application/json');
    echo json_encode(['image' => null]);
    exit;
}

$cacheDir = '/var/www/music-player/cache/artists';
$cacheFile = $cacheDir . '/' . md5($artist) . '.json';

if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 86400) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    header('Content-Type: application/json');
    echo json_encode($cached);
    exit;
}

$image = fetchDeezerImage($artist);

if (!$image) {
    $image = fetchItunesImage($artist);
}

$result = ['image' => $image, 'artist' => $artist];
if (!is_dir($cacheDir)) @mkdir($cacheDir, 755, true);
file_put_contents($cacheFile, json_encode($result));

header('Content-Type: application/json');
echo json_encode($result);

function fetchDeezerImage($artist) {
    $url = 'https://api.deezer.com/search/artist?q=' . urlencode($artist) . '&limit=1';
    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
    if ($data) {
        $json = json_decode($data, true);
        if (!empty($json['data'][0]['picture_medium'])) {
            return $json['data'][0]['picture_medium'];
        }
    }
    return null;
}

function fetchItunesImage($artist) {
    $url = 'https://itunes.apple.com/search?term=' . urlencode($artist) . '&entity=musicArtist&limit=1';
    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
    if ($data) {
        $json = json_decode($data, true);
        if (!empty($json['results'][0]['artistLinkUrl'])) {
            $artwork = @file_get_contents('https://itunes.apple.com/lookup?amgArtistId=' . urlencode($json['results'][0]['amgArtistId'] ?? '') . '&entity=album&limit=1', false, stream_context_create(['http' => ['timeout' => 5]]));
        }
    }
    return null;
}
