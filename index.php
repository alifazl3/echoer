<?php
declare(strict_types=1);

/**
 * index.php
 * Logs every incoming HTTP request as a reproducible curl command.
 * No Persian comments per user's preference.
 */

// ----------------- Configuration -----------------
$LOG_ROOT          = __DIR__ . '/request_curl_logs';
$REDACT_SENSITIVE  = true; // true to redact Authorization/Cookie headers
$SENSITIVE_HEADERS = ['authorization', 'cookie', 'proxy-authorization'];
$IGNORE_HEADERS    = ['content-length']; // curl will compute these
$SHELL_FILE_NAME   = 'replay.sh';        // generated shell script per request
$BODY_FILE_NAME    = 'body.bin';         // raw body saved here (if any)
$RESPOND_WITH_JSON = true;               // respond with a small JSON after logging

// Ensure log directory exists
if (!is_dir($LOG_ROOT)) {
    @mkdir($LOG_ROOT, 0775, true);
}

// ----------------- Helpers -----------------
if (!function_exists('getallheaders')) {
    function getallheaders(): array {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = str_replace('_', '-', ucwords(strtolower($key), '_'));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }
}

function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') return true;
    return false;
}

function shell_arg(string $s): string {
    return "'" . str_replace("'", "'\\''", $s) . "'";
}

function header_should_be_redacted(string $name, array $sensitive): bool {
    return in_array(strtolower($name), $sensitive, true);
}

function header_should_be_ignored(string $name, array $ignore): bool {
    return in_array(strtolower($name), $ignore, true);
}

// ----------------- Gather request data -----------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$scheme  = isHttps() ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$uri     = $_SERVER['REQUEST_URI'] ?? '/';
$fullUrl = $scheme . '://' . $host . $uri;

$headers = getallheaders();
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '-';

// Read raw body
$rawBody = file_get_contents('php://input');

// Detect multipart
$isMultipart = false;
$contentType = '';
foreach ($headers as $hn => $hv) {
    if (strtolower($hn) === 'content-type') {
        $contentType = strtolower($hv);
        if (strpos($contentType, 'multipart/form-data') !== false) {
            $isMultipart = true;
        }
        break;
    }
}

// ----------------- Build log directory -----------------
$ts = (new DateTimeImmutable('now'))->format('Y-m-d_His');
try {
    $reqId = bin2hex(random_bytes(4));
} catch (Throwable $e) {
    $reqId = substr(hash('sha256', (string)mt_rand()), 0, 8);
}
$reqDir = "{$LOG_ROOT}/{$ts}_{$reqId}";
@mkdir($reqDir, 0775, true);

// Save index line
$indexFile = "{$LOG_ROOT}/index.log";
$line = sprintf("[%s] %s %s (ip=%s) dir=%s\n", $ts, $method, $fullUrl, $clientIp, basename($reqDir));
@file_put_contents($indexFile, $line, FILE_APPEND);

// ----------------- Compose curl command -----------------
$curl = [];
$curl[] = "#!/usr/bin/env bash";
$curl[] = "set -euo pipefail";
$curl[] = "";
$curl[] = "# Replays the captured HTTP request";
$curl[] = "# Timestamp: {$ts}";
$curl[] = "# Client IP: {$clientIp}";
$curl[] = "";
$curl[] = "cd \"\$(dirname \"\$0\")\"";
$curl[] = "";
$curl[] = "curl \\";
$curl[] = "  -i \\";
$curl[] = "  -sS \\";
$curl[] = "  -X " . escapeshellarg($method) . " \\";

// Headers
foreach ($headers as $name => $value) {
    if (header_should_be_ignored($name, $IGNORE_HEADERS)) {
        continue;
    }
    $hVal = $value;
    if ($REDACT_SENSITIVE && header_should_be_redacted($name, $SENSITIVE_HEADERS)) {
        $hVal = 'REDACTED';
    }
    $curl[] = "  -H " . shell_arg($name . ': ' . $hVal) . " \\";
}

// Data
if ($isMultipart) {
    // Text fields
    foreach ($_POST as $k => $v) {
        if (is_array($v)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($v));
            foreach ($iterator as $value) {
                $curl[] = "  -F " . shell_arg("{$k}={$value}") . " \\";
            }
        } else {
            $curl[] = "  -F " . shell_arg("{$k}={$v}") . " \\";
        }
    }
    // Files
    foreach ($_FILES as $field => $info) {
        if (is_array($info['name'] ?? null)) {
            $count = count($info['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($info['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $tmp = $info['tmp_name'][$i];
                    $filename = $info['name'][$i];
                    $type = $info['type'][$i] ?? 'application/octet-stream';
                    $dest = $reqDir . '/upload_' . $i . '_' . basename($filename);
                    @copy($tmp, $dest);
                    $curl[] = "  -F " . shell_arg("{$field}=@{$dest};filename=" . $filename . ";type={$type}") . " \\";
                }
            }
        } else {
            if (($info['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmp = $info['tmp_name'];
                $filename = $info['name'];
                $type = $info['type'] ?? 'application/octet-stream';
                $dest = $reqDir . '/upload_' . basename($filename);
                @copy($tmp, $dest);
                $curl[] = "  -F " . shell_arg("{$field}=@{$dest};filename=" . $filename . ";type={$type}") . " \\";
            }
        }
    }
} else {
    if ($rawBody !== '' && $rawBody !== false) {
        $bodyPath = $reqDir . '/' . $BODY_FILE_NAME;
        @file_put_contents($bodyPath, $rawBody);
        $curl[] = "  --data-binary @" . shell_arg($BODY_FILE_NAME) . " \\";
    }
}

// URL last
$curl[] = "  " . shell_arg($fullUrl);

// ----------------- Persist files -----------------
$scriptPath = $reqDir . '/' . $SHELL_FILE_NAME;
@file_put_contents($scriptPath, implode(PHP_EOL, $curl));
@chmod($scriptPath, 0755);

$meta = [
    'timestamp'    => $ts,
    'client_ip'    => $clientIp,
    'method'       => $method,
    'url'          => $fullUrl,
    'headers'      => $headers,
    'is_multipart' => $isMultipart,
];
@file_put_contents($reqDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ----------------- Example app response -----------------
if ($RESPOND_WITH_JSON) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'         => true,
        'message'    => 'Request logged as curl.',
        'log_dir'    => basename($reqDir),
        'replay_cmd' => "./request_curl_logs/" . basename($reqDir) . "/{$SHELL_FILE_NAME}",
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// If you want to continue with your own app logic instead of the JSON above,
// you can include your router/controller below this line.

