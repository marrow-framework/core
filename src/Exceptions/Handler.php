<?php

declare(strict_types=1);

namespace Marrow\Exceptions;

use Marrow\Http\JsonResponse;
use Marrow\Http\Request;
use Marrow\Http\Response;
use Marrow\Template\Engine;
use Marrow\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Global exception handler.
 *
 *  - ValidationException  → 422 JSON or redirect-back with flashed errors
 *  - Debug mode           → interactive debug page (inline CSS, no CDN)
 *  - Production           → styled Twig error templates
 *  - JSON requests        → JSON error envelope
 */
class Handler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Engine $view,
        private readonly bool $debug,
        private readonly string $errorViewsPath
    ) {
    }

    public function render(Request $request, Throwable $e): Response|JsonResponse
    {
        if ($e instanceof ValidationException) {
            return $this->renderValidationError($request, $e);
        }

        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

        $this->logException($request, $e, $status);

        if ($request->wantsJson()) {
            return $this->renderJson($e, $status);
        }

        if ($this->debug) {
            return $this->renderDebugPage($request, $e, $status);
        }

        return $this->renderErrorPage($e, $status);
    }

    // ── Validation ───────────────────────────────────────────────────

    private function renderValidationError(Request $request, ValidationException $e): Response|JsonResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse(
                ['message' => 'Les données soumises sont invalides.', 'errors' => $e->errors()],
                422
            );
        }

        $referer = $request->headers->get('referer', '/');

        try {
            $session = \Marrow\Application::getInstance()
                ->getContainer()
                ->make(\Marrow\Session\SessionManager::class);
            $session->flash('_errors', $e->errors());
            $session->flash('_old_input', $request->all());
        } catch (Throwable) {
        }

        return new Response('', 302, ['Location' => $referer]);
    }

    // ── JSON ─────────────────────────────────────────────────────────

    private function renderJson(Throwable $e, int $status): JsonResponse
    {
        $data = ['error' => $e->getMessage(), 'status' => $status];
        if ($this->debug) {
            $data['trace'] = array_map(
                fn($f) => ($f['file'] ?? '') . ':' . ($f['line'] ?? ''),
                array_slice($e->getTrace(), 0, 10)
            );
        }
        return new JsonResponse($data, $status);
    }

    // ── Production error page ────────────────────────────────────────

    private function renderErrorPage(Throwable $e, int $status): Response
    {
        $template = "@core_errors/{$status}.html.twig";
        $appView  = $this->errorViewsPath . "/{$status}.html.twig";
        if (is_file($appView)) {
            $template = "errors/{$status}.html.twig";
        }

        try {
            $html = $this->view->render($template, [
                'code'    => $status,
                'message' => $e->getMessage(),
            ]);
            return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (Throwable) {
            return new Response(
                $this->fallbackHtml($status, $e->getMessage()),
                $status,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }
    }

    // ── Debug page ───────────────────────────────────────────────────

    private function renderDebugPage(Request $request, Throwable $e, int $status): Response
    {
        $html = $this->buildDebugHtml($request, $e, $status);
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function buildDebugHtml(Request $request, Throwable $e, int $status): string
    {
        $class   = get_class($e);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $file    = $e->getFile();
        $line    = $e->getLine();

        // Build frame data: each frame + its source snippet encoded as JSON for the JS
        $frames     = $e->getTrace();
        $frameList  = [];
        $sourceData = [];

        // Frame 0 = exception origin
        array_unshift($frames, ['file' => $file, 'line' => $line, 'function' => '{exception}', 'class' => $class, 'type' => '']);

        foreach (array_slice($frames, 0, 25) as $i => $frame) {
            $frameFile = $frame['file'] ?? null;
            $frameLine = (int) ($frame['line'] ?? 0);
            $fn        = htmlspecialchars(
                ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'],
                ENT_QUOTES | ENT_SUBSTITUTE
            );
            $shortFile = $frameFile ? $this->shortenPath($frameFile) : '[internal]';

            $frameList[]  = ['fn' => $fn, 'file' => $shortFile, 'line' => $frameLine];
            $sourceData[] = $frameFile
                ? $this->buildSourceData($frameFile, $frameLine)
                : null;
        }

        $framesJson = json_encode($frameList, JSON_HEX_TAG | JSON_HEX_AMP);
        $sourceJson = json_encode($sourceData, JSON_HEX_TAG | JSON_HEX_AMP);

        // Request info table rows
        $reqMethod  = htmlspecialchars($request->getMethod(), ENT_QUOTES);
        $reqUri     = htmlspecialchars($request->getRequestUri(), ENT_QUOTES);
        $headerRows = '';
        foreach ($request->headers->all() as $name => $values) {
            $k           = htmlspecialchars($name, ENT_QUOTES);
            $v           = htmlspecialchars(implode(', ', $values), ENT_QUOTES);
            $headerRows .= "<tr><td class=\"td-key\">{$k}</td><td class=\"td-val\">{$v}</td></tr>";
        }
        $bodyRows = '';
        foreach ($request->request->all() as $k => $v) {
            $k        = htmlspecialchars((string) $k, ENT_QUOTES);
            $v        = htmlspecialchars((string) $v, ENT_QUOTES);
            $bodyRows .= "<tr><td class=\"td-key\">{$k}</td><td class=\"td-val\">{$v}</td></tr>";
        }

        // Chained exceptions
        $chainHtml = '';
        $prev = $e->getPrevious();
        while ($prev !== null) {
            $prevClass   = htmlspecialchars(get_class($prev), ENT_QUOTES);
            $prevMessage = htmlspecialchars($prev->getMessage(), ENT_QUOTES);
            $chainHtml  .= "<div class=\"chain-item\"><span class=\"badge-class\">{$prevClass}</span> {$prevMessage}</div>";
            $prev = $prev->getPrevious();
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$status} — {$class}</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0b0d11;--surface:#13161c;--surface2:#181b22;--border:#1e2330;--border2:#252a38;
  --text:#e2e8f0;--muted:#64748b;--muted2:#374151;
  --accent:#6366f1;--red:#f87171;--red-bg:rgba(248,113,113,.08);
  --green:#22c55e;--yellow:#facc15;--blue:#60a5fa;
  --mono:'SF Mono','Cascadia Code','Fira Code','JetBrains Mono',monospace;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,'Segoe UI',sans-serif;font-size:14px;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}

/* ── Header ── */
.hd{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:16px;background:var(--surface)}
.hd-left{flex:1;min-width:0}
.badge-status{display:inline-block;padding:2px 10px;border-radius:4px;font-size:11px;font-family:var(--mono);font-weight:700;background:var(--red-bg);color:var(--red);border:1px solid rgba(248,113,113,.2);margin-bottom:8px}
.badge-class{display:inline-block;padding:2px 10px;border-radius:4px;font-size:11px;font-family:var(--mono);background:rgba(99,102,241,.1);color:#a5b4fc;border:1px solid rgba(99,102,241,.2)}
.hd-message{font-size:18px;font-weight:700;line-height:1.4;margin:6px 0;word-break:break-word;color:var(--text)}
.hd-file{font-family:var(--mono);font-size:11px;color:var(--muted);word-break:break-all}
.chain{margin-top:10px;display:flex;flex-direction:column;gap:4px}
.chain-item{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:8px}
.chain-item .badge-class{font-size:10px}

/* ── Body layout ── */
.body{display:grid;grid-template-columns:300px 1fr;height:calc(100vh - var(--hd-h,120px));overflow:hidden}

/* ── Frame list ── */
.frames{overflow-y:auto;border-right:1px solid var(--border);background:var(--surface)}
.frame{padding:10px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s}
.frame:hover{background:var(--surface2)}
.frame.active{background:var(--surface2);border-left:2px solid var(--accent);padding-left:12px}
.frame-fn{font-family:var(--mono);font-size:11px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.frame-loc{font-size:10px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.frame-num{font-size:10px;color:var(--muted2);margin-bottom:2px}

/* ── Right panel ── */
.right{display:flex;flex-direction:column;overflow:hidden}
.tabs{display:flex;gap:0;border-bottom:1px solid var(--border);background:var(--surface);flex-shrink:0}
.tab{padding:9px 16px;font-size:12px;font-weight:500;cursor:pointer;border-bottom:2px solid transparent;color:var(--muted);transition:color .15s}
.tab:hover{color:var(--text)}
.tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.pane{display:none;flex:1;overflow:auto}
.pane.active{display:flex;flex-direction:column}

/* ── Source pane ── */
.src-header{padding:10px 16px;font-family:var(--mono);font-size:11px;color:var(--muted);background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.src-body{flex:1;overflow:auto;background:var(--bg)}
pre.src{margin:0;padding:12px 0;font-family:var(--mono);font-size:12px;line-height:1.75;counter-reset:ln}
.ln{display:block;padding:0 16px;white-space:pre}
.ln:hover{background:var(--surface)}
.ln.fault{background:var(--red-bg)!important}
.ln-n{display:inline-block;width:36px;color:var(--muted2);user-select:none;text-align:right;margin-right:16px;font-size:11px}
.ln.fault .ln-n{color:var(--red)}
.src-empty{padding:24px;color:var(--muted);font-family:var(--mono);font-size:12px}

/* ── Request pane ── */
.req-body{padding:16px;display:flex;flex-direction:column;gap:12px}
.req-section{background:var(--surface);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.req-title{padding:8px 14px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);background:var(--surface2)}
.req-table{width:100%;border-collapse:collapse;font-family:var(--mono);font-size:12px}
.td-key{padding:6px 14px;color:#a5b4fc;white-space:nowrap;vertical-align:top;width:1px}
.td-val{padding:6px 14px;color:var(--text);word-break:break-all}
.req-table tr+tr td{border-top:1px solid var(--border)}
.req-line{padding:10px 14px;font-family:var(--mono);font-size:13px}
.m-get{color:var(--blue)}
.m-post{color:var(--green)}
.m-put,.m-patch{color:var(--yellow)}
.m-delete{color:var(--red)}
</style>
</head>
<body>

<div class="hd" id="hd">
  <div class="hd-left">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
      <span class="badge-status">{$status}</span>
      <span class="badge-class">{$class}</span>
    </div>
    <p class="hd-message">{$message}</p>
    <p class="hd-file" id="hd-file">{$file}:{$line}</p>
    {$chainHtml}
  </div>
</div>

<div class="body" id="body">

  <!-- Frame list -->
  <div class="frames" id="frame-list"></div>

  <!-- Right panel -->
  <div class="right">
    <div class="tabs">
      <div class="tab active" onclick="showTab('src')">Source</div>
      <div class="tab" onclick="showTab('req')">Request</div>
    </div>

    <div class="pane active" id="pane-src">
      <div class="src-header" id="src-header">&nbsp;</div>
      <div class="src-body"><pre class="src" id="src-code"><span class="src-empty">Select a frame to view source.</span></pre></div>
    </div>

    <div class="pane" id="pane-req">
      <div class="req-body">
        <div class="req-section">
          <div class="req-title">Request</div>
          <div class="req-line"><span class="m-{$reqMethod}">{$reqMethod}</span> {$reqUri}</div>
        </div>
        <div class="req-section">
          <div class="req-title">Headers</div>
          <table class="req-table">{$headerRows}</table>
        </div>
        <div class="req-section">
          <div class="req-title">Body Parameters</div>
          <table class="req-table">{$bodyRows}</table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
var FRAMES = {$framesJson};
var SOURCES = {$sourceJson};
var currentFrame = -1;

function pad(n, w) { return String(n).padStart(w, ' '); }

function renderSource(idx) {
  var s = SOURCES[idx];
  var hdr = document.getElementById('src-header');
  var pre = document.getElementById('src-code');
  if (!s || !s.lines || !s.lines.length) {
    hdr.textContent = FRAMES[idx] ? (FRAMES[idx].file + ':' + FRAMES[idx].line) : '[internal]';
    pre.innerHTML = '<span class="src-empty">Source not available for this frame.</span>';
    return;
  }
  hdr.textContent = s.path + ':' + s.line;
  var html = '';
  for (var i = 0; i < s.lines.length; i++) {
    var ln = s.lines[i];
    var fault = ln[0] === s.line;
    html += '<span class="ln' + (fault ? ' fault' : '') + '">';
    html += '<span class="ln-n">' + pad(ln[0], 4) + '</span>';
    html += ln[1];
    html += '</span>';
  }
  pre.innerHTML = html;
  // Scroll fault line into view
  var faultEl = pre.querySelector('.fault');
  if (faultEl) faultEl.scrollIntoView({ block: 'center' });
}

function selectFrame(idx) {
  if (currentFrame === idx) return;
  if (currentFrame >= 0) {
    var prev = document.getElementById('fr-' + currentFrame);
    if (prev) prev.classList.remove('active');
  }
  currentFrame = idx;
  var el = document.getElementById('fr-' + idx);
  if (el) { el.classList.add('active'); el.scrollIntoView({ block: 'nearest' }); }
  renderSource(idx);
}

function showTab(name) {
  document.querySelectorAll('.pane').forEach(function(p) { p.classList.remove('active'); });
  document.querySelectorAll('.tab').forEach(function(t) { t.classList.remove('active'); });
  document.getElementById('pane-' + name).classList.add('active');
  event.currentTarget.classList.add('active');
}

// Build frame list
(function() {
  var list = document.getElementById('frame-list');
  FRAMES.forEach(function(f, i) {
    var el = document.createElement('div');
    el.className = 'frame';
    el.id = 'fr-' + i;
    el.onclick = function() { selectFrame(i); };
    el.innerHTML =
      '<div class="frame-num">#' + i + '</div>' +
      '<div class="frame-fn">' + (f.fn || '&mdash;') + '</div>' +
      '<div class="frame-loc">' + f.file + (f.line ? ':' + f.line : '') + '</div>';
    list.appendChild(el);
  });
  // Resize body height based on header
  var hd = document.getElementById('hd');
  document.getElementById('body').style.height = 'calc(100vh - ' + hd.offsetHeight + 'px)';
  // Select first frame automatically
  selectFrame(0);
})();

window.addEventListener('resize', function() {
  var hd = document.getElementById('hd');
  document.getElementById('body').style.height = 'calc(100vh - ' + hd.offsetHeight + 'px)';
});
</script>
</body>
</html>
HTML;
    }

    /**
     * Build JSON-serialisable source data for one stack frame.
     *
     * @return array{path:string,line:int,lines:array<array{int,string}>}|null
     */
    private function buildSourceData(string $file, int $faultLine): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $all   = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $start = max(0, $faultLine - 11);
        $end   = min(count($all) - 1, $faultLine + 9);
        $lines = [];

        for ($i = $start; $i <= $end; $i++) {
            $lines[] = [
                $i + 1,
                htmlspecialchars($all[$i], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ];
        }

        return ['path' => $this->shortenPath($file), 'line' => $faultLine, 'lines' => $lines];
    }

    private function shortenPath(string $path): string
    {
        try {
            $base = \Marrow\Application::getInstance()->getBasePath();
            if (str_starts_with($path, $base)) {
                return ltrim(substr($path, strlen($base)), '/\\');
            }
        } catch (Throwable) {
        }
        return $path;
    }

    private function logException(Request $request, Throwable $e, int $status): void
    {
        if ($status >= 500) {
            $this->logger->error($e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'url'       => $request->getRequestUri(),
                'method'    => $request->getMethod(),
            ]);
        }
    }

    private function fallbackHtml(int $status, string $message): string
    {
        $msg = htmlspecialchars($message, ENT_QUOTES);
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>{$status}</title>"
            . "<style>body{background:#0b0d11;color:#e2e8f0;font-family:system-ui;display:flex;"
            . "align-items:center;justify-content:center;min-height:100vh;margin:0}"
            . ".box{text-align:center}.code{font-size:5rem;font-weight:700;color:#6366f1;line-height:1}"
            . ".msg{color:#64748b;margin-top:.5rem}</style></head>"
            . "<body><div class='box'><div class='code'>{$status}</div><p class='msg'>{$msg}</p></div></body></html>";
    }
}
