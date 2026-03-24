<?php
/**
 * proxy.php — Proxy de streaming IPTV
 * Desplegado en Render.com (Docker + PHP/Apache)
 *
 * Retransmite streams http:// desde un sitio HTTPS,
 * evitando el bloqueo mixed-content del navegador.
 * En Render no hay restricciones de puertos salientes.
 *
 * v2 fixes:
 *  - CURLOPT_TIMEOUT => 0        (sin límite para VOD grande, evita error 28)
 *  - HTTP/1.0                    (evita error 18 con servidores que cierran conexión)
 *  - IGNORE_CONTENT_LENGTH       (tolera Content-Length incorrecto)
 *  - CONNECTTIMEOUT => 12        (más tiempo para puertos no estándar: 8080, 2052…)
 *  - No reenvía Content-Length   (evita que el browser espere bytes que no llegan)
 */

// ── SEGURIDAD (opcional) ──
// Descomenta y agrega hosts si quieres restringir el proxy a ciertos servidores.
// Ejemplo: $ALLOWED_HOSTS = ['latinotvplus.online', 'ultrapremium.cloud'];
$ALLOWED_HOSTS = [];

// Desactivar output buffering
while (ob_get_level()) ob_end_clean();

// Cabeceras CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── VALIDAR URL ──
$url = isset($_GET['url']) ? trim($_GET['url']) : '';
if (!$url) {
    http_response_code(400);
    echo 'Error: parámetro url requerido';
    exit;
}

if (!preg_match('#^https?://#i', $url)) {
    http_response_code(403);
    echo 'Error: solo se permiten URLs http/https';
    exit;
}

if (!empty($ALLOWED_HOSTS)) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!in_array($host, $ALLOWED_HOSTS)) {
        http_response_code(403);
        echo 'Error: host no permitido';
        exit;
    }
}

// ── DETECTAR TIPO POR EXTENSIÓN ──
$urlPath   = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
$extIsM3U8 = str_contains($urlPath, '.m3u8');
$extIsM3U  = str_contains($urlPath, '.m3u') && !$extIsM3U8;
$extIsTS   = str_contains($urlPath, '.ts');
$extIsVOD  = (bool) preg_match('/\.(mp4|mkv|avi|webm|flv)(\?|$)/i', $urlPath);
$noExt     = !$extIsM3U8 && !$extIsM3U && !$extIsTS && !$extIsVOD
             && !preg_match('/\.(aac|mp3)(\?|$)/i', $urlPath);

// ── CABECERAS A REENVIAR ──
function buildForwardHeaders(): array {
    $skip    = ['host', 'connection', 'content-length', 'transfer-encoding', 'te', 'trailer', 'upgrade'];
    $headers = [];
    foreach (getallheaders() as $k => $v) {
        if (!in_array(strtolower($k), $skip)) {
            $headers[] = "$k: $v";
        }
    }
    return $headers;
}

// ── HELPERS DE URL ──
function getBaseUrl(string $url): string {
    $parts = parse_url($url);
    $base  = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) $base .= ':' . $parts['port'];
    $path  = isset($parts['path']) ? dirname($parts['path']) : '';
    return rtrim($base . $path, '/') . '/';
}

function resolveUrl(string $base, string $relative): string {
    if (preg_match('#^https?://#i', $relative)) return $relative;
    if (str_starts_with($relative, '/')) {
        $parts = parse_url($base);
        $root  = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) $root .= ':' . $parts['port'];
        return $root . $relative;
    }
    return rtrim($base, '/') . '/' . $relative;
}

function getProxyBase(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST']
        . dirname($_SERVER['PHP_SELF']) . '/proxy.php?url=';
}

function rewriteM3U8(string $body, string $url): string {
    $baseUrl   = getBaseUrl($url);
    $proxyBase = getProxyBase();
    $lines     = explode("\n", $body);
    $rewritten = [];
    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') {
            $rewritten[] = $line;
        } elseif (str_starts_with($line, '#')) {
            $line = preg_replace_callback('/URI="([^"]+)"/i', function($m) use ($baseUrl, $proxyBase) {
                $resolved = preg_match('#^https?://#i', $m[1]) ? $m[1] : resolveUrl($baseUrl, $m[1]);
                return 'URI="' . $proxyBase . urlencode($resolved) . '"';
            }, $line);
            $rewritten[] = $line;
        } elseif (preg_match('#^https?://#i', $line)) {
            $rewritten[] = $proxyBase . urlencode($line);
        } else {
            $rewritten[] = $proxyBase . urlencode(resolveUrl($baseUrl, $line));
        }
    }
    return implode("\n", $rewritten);
}

// ── FETCH COMPLETO (manifests M3U/M3U8) ──
function fetchFull(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => buildForwardHeaders(),
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; IPTV/1.0)',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $errno    = curl_errno($ch);
    $errMsg   = curl_error($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    $headers = substr($raw, 0, $hdrSize);
    $body    = substr($raw, $hdrSize);

    $ct = '';
    if (preg_match('/^content-type:\s*([^\r\n;]+)/im', $headers, $m)) {
        $ct = strtolower(trim($m[1]));
    }

    return [
        'body'     => $body,
        'ct'       => $ct,
        'code'     => $httpCode,
        'errno'    => $errno,
        'errmsg'   => $errMsg,
        'finalUrl' => $finalUrl,
    ];
}

// ── MANIFESTS Y URLs SIN EXTENSIÓN ──
if ($extIsM3U || $extIsM3U8 || $noExt) {

    $r = fetchFull($url);

    if ($r['errno'] || $r['code'] >= 400) {
        $errDesc = match($r['errno']) {
            7  => 'Conexión rechazada (servidor caído o puerto bloqueado)',
            18 => 'Transferencia incompleta',
            28 => 'Timeout de conexión',
            6  => 'Host DNS no resuelto',
            default => $r['errmsg']
        };
        error_log("proxy.php fetchFull error {$r['errno']} ({$errDesc}) HTTP {$r['code']} — {$url}");
        http_response_code($r['code'] ?: 502);
        header('Content-Type: text/plain');
        echo "proxy error {$r['errno']}: {$errDesc}";
        exit;
    }

    $body = $r['body'];
    $ct   = $r['ct'];

    $isHlsContent  = str_contains($ct, 'mpegurl') || str_contains($ct, 'x-mpegurl')
                  || str_contains($body, '#EXTM3U') || str_contains($body, '#EXT-X-');
    $isM3UList     = str_contains($body, '#EXTINF');
    $isM3U8Content = $isHlsContent && (str_contains($body, '#EXT-X-') || $extIsM3U8);

    if ($isM3U8Content) {
        $effectiveUrl = $r['finalUrl'] ?: $url;
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-cache, no-store');
        header('Access-Control-Allow-Origin: *');
        echo rewriteM3U8($body, $effectiveUrl);
    } elseif ($isM3UList) {
        header('Content-Type: application/x-mpegurl; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Access-Control-Allow-Origin: *');
        echo $body;
    } else {
        header('Content-Type: ' . ($ct ?: 'application/octet-stream'));
        header('Cache-Control: no-cache');
        header('Access-Control-Allow-Origin: *');
        echo $body;
    }
    exit;
}

// ── STREAM DIRECTO (.ts, VOD, binarios) ──
$ch = curl_init();
$fwdHeaders = buildForwardHeaders();
if (isset($_SERVER['HTTP_RANGE'])) {
    $fwdHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

curl_setopt_array($ch, [
    CURLOPT_URL                   => $url,
    CURLOPT_FOLLOWLOCATION        => true,
    CURLOPT_MAXREDIRS             => 5,
    CURLOPT_TIMEOUT               => 0,       // sin límite para VOD grande
    CURLOPT_CONNECTTIMEOUT        => 12,
    CURLOPT_SSL_VERIFYPEER        => false,
    CURLOPT_SSL_VERIFYHOST        => false,
    CURLOPT_HTTPHEADER            => $fwdHeaders,
    CURLOPT_USERAGENT             => 'Mozilla/5.0 (compatible; IPTV/1.0)',
    CURLOPT_ENCODING              => '',
    CURLOPT_HTTP_VERSION          => CURL_HTTP_VERSION_1_0,  // evita error 18
    CURLOPT_IGNORE_CONTENT_LENGTH => true,                   // tolera Content-Length incorrecto
    CURLOPT_BUFFERSIZE            => 131072,
    CURLOPT_WRITEFUNCTION         => function($curl, $data) {
        echo $data;
        if (ob_get_level()) ob_flush();
        flush();
        return strlen($data);
    },
    CURLOPT_HEADERFUNCTION        => function($curl, $header) {
        $lower = strtolower(trim($header));
        $pass  = ['content-type', 'content-range', 'accept-ranges',
                  'cache-control', 'last-modified', 'etag'];
        foreach ($pass as $h) {
            if (str_starts_with($lower, $h . ':')) {
                header(trim($header), false);
                break;
            }
        }
        // No reenviamos Content-Length (puede ser incorrecto en servidores IPTV)
        if (str_starts_with($lower, 'http/')) {
            $parts = explode(' ', trim($header), 3);
            if (isset($parts[1]) && is_numeric($parts[1])) {
                http_response_code((int)$parts[1]);
            }
        }
        return strlen($header);
    },
]);

header('Access-Control-Allow-Origin: *');
curl_exec($ch);

$errno = curl_errno($ch);
if ($errno) {
    $errDesc = match($errno) {
        7  => 'Conexión rechazada — servidor caído o puerto bloqueado',
        18 => 'Transferencia incompleta',
        28 => 'Timeout',
        6  => 'Host DNS no resuelto',
        default => curl_error($ch)
    };
    error_log("proxy.php stream error $errno ($errDesc) — $url");
}
curl_close($ch);
