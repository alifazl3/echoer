<?php
declare(strict_types=1);

/**
 * Request Log UI with copy-curl button, improved CSS, and JSON pretty view.
 * No Persian comments per user's preference.
 */

$LOG_ROOT = realpath(__DIR__ . '/../request_curl_logs') ?: (__DIR__ . '/../request_curl_logs');
$APP_TITLE = 'Request Log UI';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function human_bytes(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    $n = $bytes;
    while ($n >= 1024 && $i < count($units)-1) { $n = $n/1024; $i++; }
    return (strpos((string)$n,'.')!==false ? number_format((float)$n, 2, '.', ',') : (string)$n) . ' ' . $units[$i];
}

function clip_text(string $text, int $limit = 8000): string {
    if ($limit <= 0) return '';
    if (strlen($text) <= $limit) return $text;
    return substr($text, 0, $limit) . "\n… trimmed";
}

function safe_dirname(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $name)) { return ''; }
    return $name;
}

function read_bytes(string $path, int $limit): string {
    if (!is_file($path)) return '';
    $size = filesize($path) ?: 0;
    $limit = min($limit, max(0, (int)$size));
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    $data = ($limit > 0) ? fread($fh, $limit) : '';
    fclose($fh);
    return $data === false ? '' : $data;
}

function read_all_capped(string $path, int $cap): string {
    if (!is_file($path)) return '';
    $size = filesize($path) ?: 0;
    if ($size > $cap) return read_bytes($path, $cap);
    $raw = @file_get_contents($path);
    return $raw === false ? '' : $raw;
}

function detect_is_text(string $headersPath, string $bodyPath): bool {
    $ct = '';
    if (is_file($headersPath)) {
        $headers = @file($headersPath, FILE_IGNORE_NEW_LINES);
        if ($headers) {
            foreach ($headers as $h) {
                $p = strpos($h, ':');
                if ($p !== false && strcasecmp(substr($h, 0, $p), 'Content-Type') === 0) {
                    $ct = trim(substr($h, $p+1));
                    break;
                }
            }
        }
    }
    if ($ct) {
        $ctLower = strtolower($ct);
        if (str_starts_with($ctLower, 'text/')) return true;
        if (str_contains($ctLower, 'json')) return true;
        if (str_contains($ctLower, 'xml')) return true;
        if (str_contains($ctLower, 'javascript')) return true;
        if (str_contains($ctLower, 'charset=')) return true;
    }
    $sample = read_bytes($bodyPath, 1024);
    if ($sample === '') return true;
    $nonPrintable = preg_match('/[^\P{C}\t\r\n]/u', $sample) === 1;
    return !$nonPrintable;
}

function looks_like_json(string $contentTypeHeader, string $sample): bool {
    if (stripos($contentTypeHeader, 'json') !== false) return true;
    $trim = ltrim($sample);
    return ($trim !== '') && (($trim[0] === '{') || ($trim[0] === '['));
}

function try_pretty_json(string $raw): ?string {
    $rawTrim = trim($raw);
    if ($rawTrim === '' || (!in_array($rawTrim[0], ['{','['], true))) return null;
    $decoded = json_decode($rawTrim, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $decoded = json_decode($rawTrim, false);
        if (json_last_error() !== JSON_ERROR_NONE) return null;
    }
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function list_request_dirs(string $root): array {
    if (!is_dir($root)) return [];
    $items = [];
    $it = new DirectoryIterator($root);
    foreach ($it as $f) {
        if ($f->isDot() || !$f->isDir()) continue;
        $items[] = ['name' => $f->getFilename(), 'path' => $f->getPathname(), 'mtime' => $f->getMTime()];
    }
    usort($items, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
    return $items;
}

function load_meta(string $dir): array {
    $metaPath = $dir . '/meta.json';
    if (!is_file($metaPath)) return [];
    $raw = @file_get_contents($metaPath);
    $j = json_decode($raw ?? '[]', true);
    return is_array($j) ? $j : [];
}

function load_replay(string $dir): string {
    $p = $dir . '/replay.sh';
    return is_file($p) ? (@file_get_contents($p) ?: '') : '';
}

function load_resp_headers(string $dir): string {
    $p = $dir . '/response_headers.txt';
    return is_file($p) ? (@file_get_contents($p) ?: '') : '';
}

function body_size(string $path): int {
    return is_file($path) ? (filesize($path) ?: 0) : 0;
}

// routing
$action = $_GET['a'] ?? 'list';
if ($action === 'repeat') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?? '', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $dirInput = $payload['dir'] ?? '';
    $dir = $dirInput !== '' ? safe_dirname((string)$dirInput) : '';
    header('Content-Type: application/json');
    if ($dir === '') {
        echo json_encode(['ok' => false, 'message' => 'Invalid directory']);
        exit;
    }
    $rootReal = realpath($LOG_ROOT);
    $fullDir = $rootReal ? realpath($LOG_ROOT . '/' . $dir) : false;
    if (!$rootReal || !$fullDir || !str_starts_with($fullDir, $rootReal) || !is_dir($fullDir)) {
        echo json_encode(['ok' => false, 'message' => 'Directory not found']);
        exit;
    }
    $script = $fullDir . '/replay.sh';
    if (!is_file($script) || !is_readable($script)) {
        echo json_encode(['ok' => false, 'message' => 'Replay script missing']);
        exit;
    }
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $start = microtime(true);
    $process = @proc_open(['bash', $script], $descriptorSpec, $pipes, $fullDir);
    if (!is_resource($process)) {
        echo json_encode(['ok' => false, 'message' => 'Unable to start replay']);
        exit;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], true);
    stream_set_blocking($pipes[2], true);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $durationMs = (int)round((microtime(true) - $start) * 1000);
    $stdout = clip_text($stdout, 10000);
    $stderr = clip_text($stderr, 4000);
    echo json_encode([
        'ok' => true,
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'durationMs' => $durationMs,
    ]);
    exit;
}
if ($action === 'download' && isset($_GET['dir'], $_GET['file'])) {
    $dir = safe_dirname($_GET['dir']);
    $file = basename($_GET['file']);
    if ($dir === '' || $file === '') { http_response_code(400); exit('Bad request'); }
    $full = realpath($LOG_ROOT . '/' . $dir . '/' . $file);
    $rootReal = realpath($LOG_ROOT);
    if (!$full || !$rootReal || !str_starts_with($full, $rootReal)) { http_response_code(404); exit('Not found'); }
    if (!is_file($full)) { http_response_code(404); exit('Not found'); }
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($full));
    header('Content-Type: application/octet-stream');
    readfile($full);
    exit;
}

$dirParam = isset($_GET['dir']) ? safe_dirname($_GET['dir']) : null;
$viewDir = $dirParam ? $LOG_ROOT . '/' . $dirParam : null;
$isDetail = $dirParam && is_dir($viewDir);

// pagination/search
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(200, max(10, (int)($_GET['pp'] ?? 50)));

$all = list_request_dirs($LOG_ROOT);
if ($q !== '') {
    $all = array_values(array_filter($all, function($it) use($q) {
        $name = strtolower($it['name']);
        if (str_contains($name, strtolower($q))) return true;
        $meta = load_meta($it['path']);
        $needle = strtolower($q);
        $hay = strtolower(($meta['method'] ?? '') . ' ' . ($meta['url'] ?? '') . ' ' . ($meta['client_ip'] ?? ''));
        return str_contains($hay, $needle);
    }));
}
$total = count($all);
$pages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;
$list = array_slice($all, $offset, $perPage);

// detail prep
if ($isDetail) {
    $meta = load_meta($viewDir);
    $replay = load_replay($viewDir);

    $reqBodyPath = $viewDir . '/body.bin';
    $reqBodySize = body_size($reqBodyPath);
    $reqBodyRawPreview = $reqBodySize ? read_all_capped($reqBodyPath, 2_000_000) : '';
    $reqContentType = '';
    if (!empty($meta['headers']) && is_array($meta['headers'])) {
        foreach ($meta['headers'] as $k => $v) {
            if (strcasecmp($k, 'Content-Type') === 0) { $reqContentType = is_array($v) ? implode(',',$v) : $v; break; }
        }
    }
    $reqPretty = ( ($reqBodyRawPreview !== '') && looks_like_json($reqContentType, $reqBodyRawPreview) )
        ? (try_pretty_json($reqBodyRawPreview) ?? null)
        : null;

    $respHeadersText = load_resp_headers($viewDir);
    $respBodyPath = $viewDir . '/response_body.bin';
    $respBodySize = body_size($respBodyPath);
    $respBodyRawPreview = $respBodySize ? read_all_capped($respBodyPath, 2_000_000) : '';
    $respStatusPath = $viewDir . '/response_status.txt';
    $respStatus = is_file($respStatusPath) ? trim((string)@file_get_contents($respStatusPath)) : '';
    $respCT = '';
    if ($respHeadersText) {
        foreach (explode("\n", $respHeadersText) as $hLine) {
            $p = strpos($hLine, ':');
            if ($p !== false && strcasecmp(substr($hLine, 0, $p), 'Content-Type') === 0) {
                $respCT = trim(substr($hLine, $p+1));
                break;
            }
        }
    }
    $respIsText = $respBodySize > 0 ? detect_is_text($viewDir . '/response_headers.txt', $respBodyPath) : true;
    $respPretty = ($respBodyRawPreview !== '' && looks_like_json($respCT, $respBodyRawPreview))
        ? (try_pretty_json($respBodyRawPreview) ?? null)
        : null;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= e($APP_TITLE) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f6f6f6;--fg:#111;--muted:#636363;--card:#ffffff;--accent:#111;
  --border:#d9d9d9;--chip:#ededed;--chip2:#e2e2e2;--shadow:0 10px 25px rgba(0,0,0,.08);
  --badge-fg:#2c2c2c;--badge-bg:#f0f0f0;--badge-strong:#d6d6d6;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font-family:"Inter",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Ubuntu}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
.container{max-width:1200px;margin:0 auto;padding:20px}
.header{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px}
.h1{font-size:22px;font-weight:700;letter-spacing:.15px}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;box-shadow:var(--shadow)}
.controls{display:flex;gap:8px;align-items:center}
input[type="text"], select, button{
  background:var(--chip2);border:1px solid var(--border);color:var(--fg);
  padding:10px 12px;border-radius:10px;font-size:14px
}
button{cursor:pointer;transition:background-color .2s ease, color .2s ease}
.btn{background:var(--chip);border:1px solid var(--border);padding:8px 12px;border-radius:10px}
.btn.primary{background:var(--fg);color:#fff;border-color:var(--fg)}
.btn.ghost{background:var(--chip2);color:var(--fg)}
.btn:disabled{opacity:.6;cursor:wait}
.row{display:grid;grid-template-columns:240px 1fr 90px 120px 150px;gap:10px;align-items:center;padding:12px;border-bottom:1px solid var(--border)}
.row.head{font-weight:600;color:var(--muted);border-bottom:2px solid var(--border)}
.grid{margin-top:8px}
.badge{display:inline-block;font-size:12px;font-weight:600;padding:3px 9px;border-radius:999px;background:var(--badge-bg);color:var(--badge-fg);border:1px solid var(--border)}
.badge.success{background:var(--badge-strong);color:#111}
.badge.error{background:#cfcfcf;color:#111}
.meta-grid{display:grid;grid-template-columns:180px 1fr;gap:8px;margin-bottom:6px}
pre, textarea.code{
  background:#fafafa;border:1px solid var(--border);padding:12px;border-radius:10px;overflow:auto;max-height:500px;color:#1f2937;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:13px;line-height:1.5;margin:0
}
.code-wrap{position:relative}
.copybar{display:flex;gap:8px;justify-content:flex-end;margin-bottom:8px}
.tabs{display:flex;gap:6px;margin-bottom:8px}
.tabbtn{background:var(--chip);border:1px solid var(--border);padding:6px 10px;border-radius:8px;font-size:12px;cursor:pointer}
.tabbtn.active{background:var(--fg);color:#fff;border-color:var(--fg)}
.tabbtn[disabled]{opacity:.4;cursor:not-allowed}
.footer{color:var(--muted);font-size:12px;margin-top:20px}
.pagination{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:10px}
.pagination a{background:var(--chip);padding:8px 12px;border-radius:10px;border:1px solid var(--border);color:var(--fg)}
.kv{color:var(--muted)}
.flex2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media (max-width: 920px){
  .row{grid-template-columns:1fr 1fr 80px 100px 120px}
  .flex2{grid-template-columns:1fr}
}
.toast{position:fixed;right:16px;bottom:16px;background:#111;color:#fefefe;border:1px solid #444;padding:10px 14px;border-radius:10px;opacity:0;transform:translateY(8px);transition:.2s;pointer-events:none;min-width:160px;text-align:center}
.toast.show{opacity:1;transform:translateY(0)}
.repeat-result{margin-top:12px;border:1px solid var(--border);border-radius:12px;padding:12px;background:#f9f9f9}
.repeat-title{font-size:13px;font-weight:600;margin-bottom:8px;color:var(--muted)}
.json-view{background:#111822;color:#f7fafc}
.json-view.json-enhanced{background:#111822;color:#f7fafc}
.json-view .json-key{color:#7dd3fc}
.json-view .json-string{color:#f9a8d4}
.json-view .json-number{color:#d8b4fe}
.json-view .json-boolean{color:#fcd34d}
.json-view .json-null{color:#cbd5f5;font-style:italic}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="h1"><?= e($APP_TITLE) ?></div>
    <div class="controls">
      <form method="get" style="display:flex;gap:8px;align-items:center">
        <input type="text" name="q" placeholder="Search URL / method / IP / folder" value="<?= e($q) ?>">
        <select name="pp">
          <?php foreach ([25,50,100,200] as $opt): ?>
          <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= $opt ?>/page</option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
        <?php if ($isDetail): ?><a class="btn ghost" href="?">All</a><?php endif; ?>
      </form>
    </div>
  </div>

<?php if ($isDetail): ?>
  <div class="card">
    <h2 style="margin:0 0 12px 0;">Request <?= e($dirParam) ?></h2>
    <div class="meta-grid">
      <div class="kv">Time</div><div><?= e($meta['timestamp'] ?? '-') ?></div>
      <div class="kv">Client IP</div><div><?= e($meta['client_ip'] ?? '-') ?></div>
      <div class="kv">Method</div><div><span class="badge"><?= e($meta['method'] ?? '-') ?></span></div>
      <div class="kv">URL</div><div style="overflow-wrap:anywhere"><?= e($meta['url'] ?? '-') ?></div>
      <div class="kv">Multipart</div><div><?= !empty($meta['is_multipart']) ? '<span class="badge success">yes</span>' : '<span class="badge">no</span>' ?></div>
      <div class="kv">Request body</div>
      <div><?= $reqBodySize ? human_bytes($reqBodySize) . ' — ' : '– ' ?><a href="?a=download&dir=<?= e($dirParam) ?>&file=body.bin">download</a></div>
      <div class="kv">Response status</div>
      <div>
        <?php if ($respStatus!== ''): ?>
          <span class="badge <?= ((int)$respStatus>=200 && (int)$respStatus<400)?'success':'error' ?>"><?= e($respStatus) ?></span>
        <?php else: ?>
          <span class="badge">n/a</span>
        <?php endif; ?>
      </div>
      <div class="kv">Response body</div>
      <div>
        <?= $respBodySize ? human_bytes($respBodySize) . ' — ' : '– ' ?>
        <a href="?a=download&dir=<?= e($dirParam) ?>&file=response_body.bin">download</a>
      </div>
    </div>

    <h3 style="display:flex;align-items:center;justify-content:space-between;margin-top:14px">Replay (curl)
      <span class="copybar">
        <button class="btn ghost" id="repeatBtn" type="button" data-dir="<?= e($dirParam) ?>">Repeat</button>
        <button class="btn primary" id="copyCurlBtn" type="button">Copy curl</button>
      </span>
    </h3>
    <div class="code-wrap">
      <pre id="curlCode"><?= e($replay ?: '(replay.sh missing)') ?></pre>
      <textarea id="curlHidden" style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true"><?= $replay ? e($replay) : '' ?></textarea>
    </div>

    <div id="repeatResult" class="repeat-result" hidden>
      <div class="repeat-title">Last repeat output</div>
      <pre id="repeatOutput"></pre>
    </div>

    <h3 style="margin-top:18px">Headers</h3>
    <div class="flex2">
      <div>
        <div class="kv" style="margin-bottom:6px;">Request</div>
        <pre><?php
          $h = $meta['headers'] ?? [];
          if ($h) {
              foreach ($h as $k => $v) { echo e($k . ': ' . (is_array($v)?implode(',',$v):$v)) . "\n"; }
          } else {
              echo e('(no headers)');
          }
        ?></pre>
      </div>
      <div>
        <div class="kv" style="margin-bottom:6px;">Response</div>
        <pre><?= e($respHeadersText ?: '(no headers)') ?></pre>
      </div>
    </div>

    <h3 style="margin-top:18px">Body preview</h3>
    <div class="flex2">
      <div>
        <div class="tabs">
          <button class="tabbtn <?= $reqPretty ? 'active':'' ?>" data-tab="req-pretty" <?= $reqPretty ? '' : 'disabled' ?>>Pretty</button>
          <button class="tabbtn <?= $reqPretty ? '' : 'active' ?>" data-tab="req-raw">Raw</button>
        </div>
        <div id="req-pretty" style="<?= $reqPretty ? '' : 'display:none' ?>">
          <pre<?= $reqPretty ? ' class="json-view" data-json="' . e(base64_encode($reqPretty)) . '"' : '' ?>><?= $reqPretty ? e($reqPretty) : e('(not JSON or too large)') ?></pre>
        </div>
        <div id="req-raw" style="<?= $reqPretty ? 'display:none' : '' ?>">
          <pre><?php
            if ($reqBodySize) {
                echo e($reqBodyRawPreview);
            } else {
                echo e('(empty)');
            }
          ?></pre>
        </div>
      </div>
      <div>
        <div class="tabs">
          <button class="tabbtn <?= $respPretty ? 'active':'' ?>" data-tab="resp-pretty" <?= $respPretty ? '' : 'disabled' ?>>Pretty</button>
          <button class="tabbtn <?= $respPretty ? '' : 'active' ?>" data-tab="resp-raw">Raw</button>
        </div>
        <div id="resp-pretty" style="<?= $respPretty ? '' : 'display:none' ?>">
          <pre<?= $respPretty ? ' class="json-view" data-json="' . e(base64_encode($respPretty)) . '"' : '' ?>><?= $respPretty ? e($respPretty) : e('(not JSON or too large)') ?></pre>
        </div>
        <div id="resp-raw" style="<?= $respPretty ? 'display:none' : '' ?>">
          <pre><?php
            if ($respBodySize) {
                if ($respIsText) echo e($respBodyRawPreview);
                else echo e('(binary content)');
            } else {
                echo e('(empty)');
            }
          ?></pre>
        </div>
      </div>
    </div>

    <h3 style="margin-top:18px">Artifacts</h3>
    <ul>
      <?php foreach (['replay.sh','body.bin','response_status.txt','response_headers.txt','response_body.bin','meta.json'] as $f): ?>
        <?php if (is_file($viewDir . '/' . $f)): ?>
          <li><a href="?a=download&dir=<?= e($dirParam) ?>&file=<?= e($f) ?>"><?= e($f) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
  </div>
<?php else: ?>
  <div class="card">
    <div class="row head">
      <div>Folder</div><div>URL</div><div>Method</div><div>IP</div><div>When</div>
    </div>
    <div class="grid">
      <?php if (!$list): ?>
        <div style="padding:12px;color:#9aa3af;">No entries</div>
      <?php else: ?>
        <?php foreach ($list as $it):
            $metaI = load_meta($it['path']);
            $url = $metaI['url'] ?? '-';
            $method = $metaI['method'] ?? '-';
            $ip = $metaI['client_ip'] ?? '-';
            $time = $metaI['timestamp'] ?? date('Y-m-d_His', $it['mtime']);
        ?>
          <div class="row">
            <div><a class="btn ghost" href="?dir=<?= e($it['name']) ?>"><?= e($it['name']) ?></a></div>
            <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($url) ?></div>
            <div><span class="badge"><?= e($method) ?></span></div>
            <div><?= e($ip) ?></div>
            <div><?= e($time) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="pagination">
      <div style="color:#9aa3af;">Total: <?= $total ?></div>
      <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(['q'=>$q,'pp'=>$perPage,'page'=>$page-1]) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <span class="badge"><?= $page ?>/<?= $pages ?></span>
      <?php if ($page < $pages): ?>
        <a href="?<?= http_build_query(['q'=>$q,'pp'=>$perPage,'page'=>$page+1]) ?>">Next &raquo;</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

  <div class="footer">Root: <?= e($LOG_ROOT) ?></div>
</div>

<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script>
(function(){
  const toast = document.getElementById('toast');
  let toastTimer = null;
  function showToast(message){
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(()=>toast.classList.remove('show'), 1600);
  }

  const copyBtn = document.getElementById('copyCurlBtn');
  const hidden = document.getElementById('curlHidden');
  if (copyBtn && hidden) {
    copyBtn.addEventListener('click', async () => {
      try {
        const text = hidden.value || '';
        if (!text.trim()) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
        } else {
          hidden.style.display = 'block';
          hidden.select();
          document.execCommand('copy');
          hidden.style.display = 'none';
        }
        showToast('Copied curl command');
      } catch (e) {
        showToast('Copy failed');
      }
    });
  }

  const repeatBtn = document.getElementById('repeatBtn');
  const repeatOutput = document.getElementById('repeatOutput');
  const repeatResult = document.getElementById('repeatResult');
  if (repeatBtn && repeatOutput && repeatResult) {
    repeatBtn.addEventListener('click', async () => {
      const dir = repeatBtn.getAttribute('data-dir') || '';
      if (!dir) {
        showToast('Missing directory');
        return;
      }
      const originalText = repeatBtn.textContent;
      repeatBtn.disabled = true;
      repeatBtn.textContent = 'Repeating…';
      try {
        const response = await fetch('?a=repeat', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({dir})
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data) {
          throw new Error('bad response');
        }
        if (!data.ok) {
          showToast(data.message || 'Repeat failed');
          if (typeof data.stderr === 'string' && data.stderr.trim()) {
            repeatOutput.textContent = data.stderr.trim();
            repeatResult.hidden = false;
          }
        } else {
          const stdout = typeof data.stdout === 'string' ? data.stdout : '';
          const stderr = typeof data.stderr === 'string' ? data.stderr : '';
          const lines = [`Exit code: ${data.exitCode}`];
          if (typeof data.durationMs === 'number') {
            lines.push(`Duration: ${data.durationMs} ms`);
          }
          if (stdout.trim()) {
            lines.push('', stdout.trim());
          }
          if (stderr.trim()) {
            lines.push('', 'stderr:', stderr.trim());
          }
          repeatOutput.textContent = lines.join('\n');
          repeatResult.hidden = false;
          showToast('Request repeated');
        }
      } catch (err) {
        showToast('Repeat failed');
      } finally {
        repeatBtn.disabled = false;
        repeatBtn.textContent = originalText;
      }
    });
  }

  function setupTabs(groupPrefix){
    const prettyBtn = document.querySelector('.tabbtn[data-tab="'+groupPrefix+'-pretty"]');
    const rawBtn = document.querySelector('.tabbtn[data-tab="'+groupPrefix+'-raw"]');
    const pretty = document.getElementById(groupPrefix+'-pretty');
    const raw = document.getElementById(groupPrefix+'-raw');
    if (!prettyBtn || !rawBtn || !pretty || !raw) return;
    prettyBtn.addEventListener('click', () => {
      if (prettyBtn.hasAttribute('disabled')) return;
      prettyBtn.classList.add('active'); rawBtn.classList.remove('active');
      pretty.style.display=''; raw.style.display='none';
    });
    rawBtn.addEventListener('click', () => {
      rawBtn.classList.add('active'); prettyBtn.classList.remove('active');
      raw.style.display=''; pretty.style.display='none';
    });
  }
  setupTabs('req');
  setupTabs('resp');

  function escapeHtml(str){
    return str.replace(/[&<>"']/g, (c) => {
      switch (c) {
        case '&': return '&amp;';
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '"': return '&quot;';
        default: return '&#39;';
      }
    });
  }

  function decodeBase64Utf8(b64){
    try {
      const binary = atob(b64);
      if (typeof TextDecoder === 'undefined') {
        return decodeURIComponent(escape(binary));
      }
      const bytes = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
      }
      return new TextDecoder().decode(bytes);
    } catch (err) {
      return '';
    }
  }

  function renderValue(value, depth){
    const indent = '  '.repeat(depth);
    if (Array.isArray(value)) {
      if (!value.length) return '[]';
      const inner = value.map((item) => indent + '  ' + renderValue(item, depth + 1)).join('\n');
      return '[\n' + inner + '\n' + indent + ']';
    }
    if (value && typeof value === 'object') {
      const entries = Object.entries(value);
      if (!entries.length) return '{}';
      const inner = entries.map(([key, val]) => indent + '  ' + '<span class="json-key">"' + escapeHtml(key) + '"</span>: ' + renderValue(val, depth + 1)).join('\n');
      return '{\n' + inner + '\n' + indent + '}';
    }
    if (typeof value === 'string') {
      return '<span class="json-string">"' + escapeHtml(value) + '"</span>';
    }
    if (typeof value === 'number') {
      return '<span class="json-number">' + value + '</span>';
    }
    if (typeof value === 'boolean') {
      return '<span class="json-boolean">' + value + '</span>';
    }
    return '<span class="json-null">null</span>';
  }

  document.querySelectorAll('.json-view[data-json]').forEach((pre) => {
    const encoded = pre.getAttribute('data-json');
    if (!encoded) return;
    const raw = decodeBase64Utf8(encoded);
    if (!raw) return;
    try {
      const parsed = JSON.parse(raw);
      pre.innerHTML = renderValue(parsed, 0);
      pre.classList.add('json-enhanced');
    } catch (err) {
      // ignore invalid json
    }
  });
})();
</script>
</body>
</html>

