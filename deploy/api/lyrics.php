<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$artist = isset($_GET['artist']) ? trim($_GET['artist']) : '';
$title = isset($_GET['title']) ? trim($_GET['title']) : '';
$album = isset($_GET['album']) ? trim($_GET['album']) : '';

if (!$artist || !$title) {
    http_response_code(400);
    echo json_encode(['error' => 'Artist and title required']);
    exit;
}

$cacheDir = '/var/www/music-player/cache';
$cacheKey = 'lrc_' . md5(strtolower($artist) . '_' . strtolower($title)) . '.json';
$cacheFile = $cacheDir . '/' . $cacheKey;

if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 86400) {
    echo file_get_contents($cacheFile);
    exit;
}

$result = tryLrcLib($artist, $title, $album);

if (!$result) {
    $result = tryLyricsOvh($artist, $title);
}

if ($result) {
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 755, true);
    file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    echo json_encode(['lines' => [], 'synced' => false, 'error' => 'Lyrics not found']);
}

function tryLrcLib($artist, $title, $album) {
    $url = 'https://lrclib.net/api/get?artist_name=' . rawurlencode($artist) . '&track_name=' . rawurlencode($title);
    if ($album) $url .= '&album_name=' . rawurlencode($album);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'MusicPlayer/1.0 (github.com/user)',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $data = json_decode($response, true);
    if (!$data) return null;

    $syncedText = $data['syncedLyrics'] ?? '';

    if ($syncedText) {
        $timedLines = parseLrc($syncedText);
        if (count($timedLines) > 2) {
            return ['lines' => $timedLines, 'synced' => true];
        }
    }

    $plainText = $data['plainLyrics'] ?? '';
    if ($plainText) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $plainText)), fn($l) => $l !== ''));
        if (count($lines) > 2) {
            return ['lines' => $lines, 'synced' => false];
        }
    }

    return null;
}

function tryLyricsOvh($artist, $title) {
    $url = 'https://api.lyrics.ovh/v1/' . rawurlencode($artist) . '/' . rawurlencode($title);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'MusicPlayer/1.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $data = json_decode($response, true);
    $text = $data['lyrics'] ?? '';
    if (!$text) return null;

    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
    if (count($lines) < 2) return null;

    return ['lines' => $lines, 'synced' => false];
}

function parseLrc($lrcText) {
    $lines = [];
    $parts = explode("\n", $lrcText);

    foreach ($parts as $part) {
        $part = trim($part);
        if (!$part) continue;

        preg_match_all('/\[(\d{2}):(\d{2})(?:[\.:](\d{2,3}))?\]/', $part, $matches, PREG_SET_ORDER);

        if (empty($matches)) continue;

        $text = preg_replace('/\[(\d{2}):(\d{2})(?:[\.:](\d{2,3}))?\]/', '', $part);
        $text = trim($text);

        if ($text === '') continue;

        foreach ($matches as $m) {
            $mins = (int)$m[1];
            $secs = (int)$m[2];
            $millis = isset($m[3]) ? (int)$m[3] : 0;
            if (strlen($m[3] ?? '') === 3) $millis = (int)$m[3];
            else $millis = $millis * 10;

            $time = $mins * 60 + $secs + $millis / 1000;
            $lines[] = ['time' => round($time, 2), 'text' => $text];
        }
    }

    usort($lines, fn($a, $b) => $a['time'] <=> $b['time']);
    return $lines;
}
