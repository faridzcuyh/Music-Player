<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$musicDir = '/home/faridz/Music';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCodes = [
        UPLOAD_ERR_INI_SIZE => 'File terlalu besar (max: ' . ini_get('upload_max_filesize') . ')',
        UPLOAD_ERR_FORM_SIZE => 'File terlalu besar',
        UPLOAD_ERR_PARTIAL => 'Upload tidak lengkap',
        UPLOAD_ERR_NO_FILE => 'Pilih file terlebih dahulu',
        UPLOAD_ERR_NO_TMP_DIR => 'Server error: tmp dir',
        UPLOAD_ERR_CANT_WRITE => 'Server error: write gagal',
        UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi',
    ];
    $errMsg = $errCodes[$_FILES['file']['error']] ?? 'Unknown error';
    http_response_code(400);
    echo json_encode(['error' => $errMsg]);
    exit;
}

$file = $_FILES['file'];
$origName = $file['name'];
$tmpPath = $file['tmp_name'];
$fileSize = $file['size'];

// Validate type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

$allowedTypes = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/flac', 'audio/wav', 'audio/ogg', 'audio/x-m4a', 'audio/aac', 'audio/webm'];
if (!in_array($mime, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Hanya file audio yang diizinkan (MP3, FLAC, WAV, OGG, M4A, AAC)']);
    exit;
}

// Limit size (50MB)
$maxSize = 50 * 1024 * 1024;
if ($fileSize > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File maksimal 50MB']);
    exit;
}

// Clean filename
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$name = pathinfo($origName, PATHINFO_FILENAME);
$name = preg_replace('/[\\\\\\/:*?"<>|]/', '_', $name);
$name = substr($name, 0, 200);

$dest = $musicDir . '/' . $name . '.' . $ext;
$counter = 1;
while (file_exists($dest)) {
    $dest = $musicDir . '/' . $name . "_$counter." . $ext;
    $counter++;
}

if (move_uploaded_file($tmpPath, $dest)) {
    // Clear cache
    $cacheFile = '/var/www/music-player/cache/tracks.json';
    if (file_exists($cacheFile)) @unlink($cacheFile);

    echo json_encode([
        'success' => true,
        'filename' => basename($dest),
        'title' => pathinfo($dest, PATHINFO_FILENAME)
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menyimpan file']);
}
