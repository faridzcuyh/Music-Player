<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
set_time_limit(0);

$musicDir = '/home/faridz/Music';
$cacheDir = '/var/www/music-player/cache';
$cacheFile = $cacheDir . '/tracks.json';

if (!is_dir($musicDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Music directory not found']);
    exit;
}

if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 3600) {
    echo file_get_contents($cacheFile);
    exit;
}

$allowedExt = ['mp3', 'flac', 'wav', 'ogg', 'm4a', 'aac'];
$fileNames = [];
$iterator = new FilesystemIterator($musicDir, FilesystemIterator::SKIP_DOTS);
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $ext = strtolower($file->getExtension());
        if (in_array($ext, $allowedExt)) {
            $fileNames[] = $file->getFilename();
        }
    }
}
sort($fileNames, SORT_STRING | SORT_FLAG_CASE);

$tracks = [];
foreach ($fileNames as $idx => $filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = preg_replace('/^spotifydown\.com\s*-\s*/i', '', $name);
    $name = preg_replace('/^y2mate\.com\s*-\s*/i', '', $name);
    $name = preg_replace('/^Y2Mate\.is\s*-\s*/i', '', $name);
    $name = preg_replace('/\-fOoSbUoayQE-\d+-\d+$/', '', $name);
    $name = preg_replace('/\-Hh5jEQraXaw-\d+-\d+$/', '', $name);

    $title = $name;
    $artist = null;
    $album = null;
    $duration = 0;
    $filepath = $musicDir . '/' . $filename;

    $meta = @shell_exec("timeout 1 /usr/bin/ffprobe -v quiet -print_format json -show_entries format_tags:format=duration " . escapeshellarg($filepath) . " 2>/dev/null");
    if ($meta) {
        $data = @json_decode($meta, true);
        if (isset($data['format'])) {
            $tags = $data['format']['tags'] ?? [];
            if (!empty($tags['title'])) $title = $tags['title'];
            if (!empty($tags['artist'])) $artist = $tags['artist'];
            if (!empty($tags['album'])) $album = $tags['album'];
            if (isset($data['format']['duration'])) $duration = (int)$data['format']['duration'];
        }
    }

    if (!$artist) {
        if (preg_match('/^(.+?)\s+[-–]\s+(.+)$/u', $name, $m)) {
            $left = trim($m[1]);
            $right = trim($m[2]);
            if (strlen($left) <= strlen($right) * 0.6) {
                $artist = $left;
                $title = $right;
            } elseif (strlen($right) <= strlen($left) * 0.6) {
                $artist = $right;
                $title = $left;
            }
        }
        if (!$artist) $artist = 'Unknown';
    }
    if (!$album) $album = 'Unknown';

    $tracks[] = [
        'id' => $idx,
        'title' => $title,
        'artist' => $artist,
        'album' => $album,
        'file' => $filename,
        'duration' => $duration,
        'cover' => '/api/cover.php?file=' . rawurlencode($filename)
    ];
}

$artistCovers = [];
$albumCovers = [];
foreach ($tracks as $t) {
    if (!isset($artistCovers[$t['artist']])) $artistCovers[$t['artist']] = $t['cover'];
    $key = $t['artist'] . '||' . $t['album'];
    if (!isset($albumCovers[$key])) $albumCovers[$key] = $t['cover'];
}

$artistMap = [];
$albumMap = [];
foreach ($tracks as $t) {
    $artistMap[$t['artist']] = ($artistMap[$t['artist']] ?? 0) + 1;
    $albumKey = $t['artist'] . '||' . $t['album'];
    $albumMap[$albumKey] = ($albumMap[$albumKey] ?? 0) + 1;
}

$artists = [];
foreach ($artistMap as $name => $count) {
    $artists[] = ['name' => $name, 'count' => $count];
}
usort($artists, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$albums = [];
foreach ($albumMap as $key => $count) {
    [$a, $al] = explode('||', $key, 2);
    $albums[] = ['artist' => $a, 'album' => $al, 'count' => $count, 'cover' => $albumCovers[$key] ?? null];
}
usort($albums, fn($a, $b) => strcasecmp($a['artist'], $b['artist']) ?: strcasecmp($a['album'], $b['album']));

$result = json_encode([
    'tracks' => $tracks,
    'artists' => $artists,
    'albums' => $albums
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (!is_dir($cacheDir)) @mkdir($cacheDir, 755, true);
file_put_contents($cacheFile, $result);

echo $result;
