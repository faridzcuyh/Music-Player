<?php
header('Access-Control-Allow-Origin: *');

$musicDir = '/home/faridz/Music';
$cacheDir = '/var/www/music-player/cache';
$filename = isset($_GET['file']) ? basename($_GET['file']) : '';

if (!$filename) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No file specified']);
    exit;
}

$filepath = $musicDir . '/' . $filename;
if (!file_exists($filepath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File not found']);
    exit;
}

$cacheKey = md5($filename) . '.jpg';
$cachePath = $cacheDir . '/' . $cacheKey;

if (file_exists($cachePath)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    readfile($cachePath);
    exit;
}

$cover = extractCover($filepath);

if ($cover) {
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 755, true);
    }
    file_put_contents($cachePath, $cover);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    echo $cover;
    exit;
}

header('Content-Type: application/json');
echo json_encode(['cover' => null]);

function extractCover($filepath) {
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

    if ($ext === 'mp3') {
        return extractMp3Cover($filepath);
    }
    if (in_array($ext, ['flac', 'ogg'])) {
        return extractFlacCover($filepath);
    }
    return null;
}

function extractMp3Cover($filepath) {
    $fh = fopen($filepath, 'rb');
    if (!$fh) return null;

    $header = fread($fh, 10);
    if (substr($header, 0, 3) !== 'ID3') {
        fclose($fh);
        return null;
    }

    $size = (ord($header[6]) << 21) | (ord($header[7]) << 14) | (ord($header[8]) << 7) | ord($header[9]);
    $end = $size - 10;

    while (ftell($fh) < $end + 10) {
        $frameHeader = fread($fh, 10);
        if (strlen($frameHeader) < 10 || $frameHeader[0] === "\x00") break;

        $frameId = substr($frameHeader, 0, 4);
        $frameSize = (ord($frameHeader[4]) << 24) | (ord($frameHeader[5]) << 16) | (ord($frameHeader[6]) << 8) | ord($frameHeader[7]);

        if ($frameSize === 0) break;

        if ($frameId === 'APIC') {
            $data = fread($fh, $frameSize);
            fclose($fh);

            $pos = 1;
            $encoding = ord($data[0]);

            if ($encoding === 0 || $encoding === 3) {
                $endPos = strpos($data, "\x00", $pos);
                if ($endPos === false) return null;
                $mime = substr($data, $pos, $endPos - $pos);
                $pos = $endPos + 1;
            } elseif ($encoding === 1 || $encoding === 2) {
                $endPos = strpos($data, "\x00\x00", $pos);
                if ($endPos === false) return null;
                $mime = substr($data, $pos, $endPos - $pos);
                $pos = $endPos + 2;
            } else {
                return null;
            }

            $pos++;
            if ($encoding === 0 || $encoding === 3) {
                $endPos = strpos($data, "\x00", $pos);
                $pos = ($endPos === false) ? strlen($data) : $endPos + 1;
            } else {
                $endPos = strpos($data, "\x00\x00", $pos);
                $pos = ($endPos === false) ? strlen($data) : $endPos + 2;
            }

            $imageData = substr($data, $pos);
            return $imageData;
        }

        fseek($fh, $frameSize, SEEK_CUR);
    }

    fclose($fh);
    return null;
}

function extractFlacCover($filepath) {
    $fh = fopen($filepath, 'rb');
    if (!$fh) return null;

    $header = fread($fh, 4);
    if (substr($header, 0, 4) !== 'fLaC') {
        fclose($fh);
        return null;
    }

    $isLast = false;
    while (!$isLast) {
        $block = fread($fh, 4);
        if (strlen($block) < 4) break;

        $isLast = (ord($block[0]) & 0x80) !== 0;
        $type = ord($block[0]) & 0x7f;
        $size = (ord($block[1]) << 16) | (ord($block[2]) << 8) | ord($block[3]);

        if ($type === 6) {
            $data = fread($fh, $size);
            fclose($fh);

            $pos = 0;
            $type = unpack('N', substr($data, $pos, 4))[1];
            $pos += 4;

            $mimeLen = unpack('N', substr($data, $pos, 4))[1];
            $pos += 4;
            $pos += $mimeLen;

            $descLen = unpack('N', substr($data, $pos, 4))[1];
            $pos += 4;
            $pos += $descLen + 1;

            return substr($data, $pos);
        }

        fseek($fh, $size, SEEK_CUR);
    }

    fclose($fh);
    return null;
}
