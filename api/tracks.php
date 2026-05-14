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
    $artist = 'Unknown';
    $album = 'Unknown';

    if (preg_match('/^(.+?)\s+[-–]\s+(.+)$/u', $name, $m)) {
        $left = trim($m[1]);
        $right = trim($m[2]);

        $knownArtists = ['Coldplay','Linkin Park','Juice WRLD','Lil Uzi Vert','Kendrick Lamar',
            'Kali Uchis','Post Malone','Travis Scott','Playboi Carti','21 Savage','Lil Tecca',
            'Metro Boomin','Gunna','Roddy Ricch','The Walters','Tay-K','keshi','Avicii',
            'Oliver Tree','Tyler','Rema','Steve Lacy','NEFFEX','Sabrina Carpenter','Troye Sivan',
            'Lil Pump','Denzel Curry','Pamungkas','Armada','JKT48','YOASOBI','Juicy Luicy',
            'TheFatRat','MikkyZia','NIKI','Tears For Fears','Gucci Mane','Bruno Mars',
            'Pharrell Williams','Calvin Harris','Naykilla','Neck Deep','Rex Orange County',
            'Yeat','Tommy Richman','Cochise','Ghostface Playa','Crystal Castles',
            'Charlie Puth','d4vd','bbno$','LeeHi','YOASOBI','Parry Gripp','A-Wall',
            'Mario Judah','Colio','Dj Rendy','Denise Julia','Nicky Youre','Nafeesisboujee',
            'Armut','Cowbell Cult','JaWNY','Adam Oh','Issam Alnajjar'];

        $leftLower = strtolower($left);
        $rightLower = strtolower($right);
        $leftIsArtist = false;
        $rightIsArtist = false;

        foreach ($knownArtists as $ka) {
            if ($leftLower === strtolower($ka) || stripos($leftLower, strtolower($ka)) !== false) $leftIsArtist = true;
            if ($rightLower === strtolower($ka) || stripos($rightLower, strtolower($ka)) !== false) $rightIsArtist = true;
        }

        if ($leftIsArtist && !$rightIsArtist) {
            $artist = $left;
            $title = $right;
        } elseif ($rightIsArtist && !$leftIsArtist) {
            $artist = $right;
            $title = $left;
        } elseif (strlen($left) < strlen($right)) {
            $artist = $left;
            $title = $right;
        } else {
            $artist = $right;
            $title = $left;
        }
    }

    $tracks[] = [
        'id' => $idx,
        'title' => $title,
        'artist' => $artist,
        'album' => $album,
        'file' => $filename,
        'duration' => 0,
        'cover' => '/api/cover.php?file=' . rawurlencode($filename)
    ];
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
    $albums[] = ['artist' => $a, 'album' => $al, 'count' => $count];
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
