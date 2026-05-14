<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$musicDir = '/home/faridz/Music';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$url = isset($input['url']) ? trim($input['url']) : '';

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'URL diperlukan']);
    exit;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'URL tidak valid']);
    exit;
}

set_time_limit(0);

if (isMediaPlatformUrl($url)) {
    $result = tryYtDlp($url, $musicDir);
} else {
    $result = tryDirectDownload($url, $musicDir);
    if (!$result) {
        $result = tryYtDlp($url, $musicDir);
    }
}

if ($result) {
    echo json_encode([
        'success' => true,
        'filename' => $result['filename'],
        'title' => $result['title']
    ]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Gagal mendownload. Cek URL dan coba lagi.']);
}

function isMediaPlatformUrl($url) {
    $patterns = [
        '/youtube\.com/i',
        '/youtu\.be/i',
        '/music\.youtube/i',
        '/y2mate/i',
        '/soundcloud\.com/i',
        '/spotify\.com/i',
        '/bandcamp\.com/i',
        '/vimeo\.com/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $url)) return true;
    }
    return false;
}

function tryDirectDownload($url, $dir) {
    $ch = curl_init();
    $contentType = '';
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$contentType) {
            if (stripos($header, 'content-type:') === 0) {
                $contentType = trim(substr($header, 13));
            }
            return strlen($header);
        }
    ]);

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($httpCode !== 200 || !$data) return null;

    $audioTypes = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/flac', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/aac', 'audio/webm'];
    $isAudio = false;
    foreach ($audioTypes as $at) {
        if (stripos($contentType, $at) === 0) {
            $isAudio = true;
            break;
        }
    }

    if (!$isAudio) return null;

    $ext = 'mp3';
    if (preg_match('#audio/(\w+)#i', $contentType, $m)) {
        $ext = strtolower($m[1]);
        if ($ext === 'mpeg') $ext = 'mp3';
    }

    $filename = basename(parse_url($finalUrl, PHP_URL_PATH));
    if (!$filename || !preg_match('/\.\w+$/', $filename)) {
        $filename = 'download_' . time() . '.' . $ext;
    }
    if (!preg_match('/\.\w+$/', $filename)) {
        $filename .= '.' . $ext;
    }

    $dest = $dir . '/' . $filename;
    $counter = 1;
    while (file_exists($dest)) {
        $info = pathinfo($filename);
        $dest = $dir . '/' . $info['filename'] . "_$counter." . $info['extension'];
        $counter++;
    }

    if (file_put_contents($dest, $data)) {
        return [
            'filename' => basename($dest),
            'title' => pathinfo($dest, PATHINFO_FILENAME)
        ];
    }

    return null;
}

function tryYtDlp($url, $dir) {
    $ytdlp = '/usr/local/bin/yt-dlp';
    $safeUrl = escapeshellarg($url);

    $titleCmd = "timeout 15 $ytdlp --no-update --no-warnings --no-playlist --print title -- $safeUrl 2>/dev/null";
    $title = trim(shell_exec($titleCmd));
    if (!$title) return null;

    $safeTitle = preg_replace('/[\\\\\/:*?"<>|]/', '_', $title);
    $safeTitle = substr($safeTitle, 0, 200);
    $filename = $safeTitle . '.mp3';

    $dest = $dir . '/' . $filename;
    $counter = 1;
    $origDest = $dest;
    while (file_exists($dest)) {
        $dest = $dir . '/' . $safeTitle . "_$counter.mp3";
        $counter++;
    }

    $safeDest = escapeshellarg($dest);
    $cmd = "timeout 180 $ytdlp --no-update --no-warnings --embed-thumbnail --add-metadata -x --audio-format mp3 --audio-quality 0 -o $safeDest --no-playlist -- $safeUrl 2>&1";
    shell_exec($cmd);

    $finalFile = $dest;
    $webmFile = preg_replace('/\.mp3$/', '.webm', $dest);
    $m4aFile = preg_replace('/\.mp3$/', '.m4a', $dest);

    if (file_exists($dest) && filesize($dest) > 10000) {
        return [
            'filename' => basename($dest),
            'title' => $safeTitle
        ];
    }

    $possibleFiles = [$webmFile, $m4aFile];
    foreach ($possibleFiles as $pf) {
        if (file_exists($pf) && filesize($pf) > 10000) {
            return [
                'filename' => basename($pf),
                'title' => $safeTitle
            ];
        }
    }

    if (file_exists($dest)) @unlink($dest);
    return null;
}
