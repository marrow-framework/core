<?php

declare(strict_types=1);

namespace Marrow\Console\Commands;

use Marrow\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Development HTTP server.
 *
 * Uses Symfony\Component\Process (cross-platform, works on Windows)
 * instead of raw proc_open + stream_select, following the same approach
 * as Laravel's artisan serve.
 *
 * Output format:
 *   INFO  Server running on [http://localhost:8080].
 *
 *   2024-01-15 12:00:00   GET   /                200    12ms
 *   2024-01-15 12:00:01   POST  /login           302     4ms
 *   2024-01-15 12:00:02   GET   /missing         404     2ms
 */
class ServeCommand extends Command
{
    protected string $signature   = 'serve {--host=localhost} {--port=8080} {--watch-css}';
    protected string $description = 'Start the development server';

    /** @var resource|null */
    private $cssProcess = null;

    // ── Entry point ───────────────────────────────────────────────────

    protected function handle(): int
    {
        $host     = (string) ($this->option('host') ?? 'localhost');
        $port     = (int)    ($this->option('port') ?? 8080);
        $watchCss = (bool)   $this->option('watch-css');
        $root     = base_path('public');
        $router   = base_path('bin/server.php');

        $port = $this->findFreePort($host, $port);
        $url  = "http://{$host}:{$port}";

        $this->printBanner($url, $watchCss);

        if ($watchCss) {
            $this->startCssWatcher();
        }

        $attempt = 0;

        while (true) {
            $exitCode = $this->runServer($host, $port, $root, $router);

            // null = process failed to start; 0 = clean exit (Ctrl+C)
            if ($exitCode === null || $exitCode === 0) {
                break;
            }

            // Non-zero → crash: restart with exponential back-off
            $attempt++;
            $delay = min(10, 2 ** min($attempt - 1, 3)); // 1→2→4→8→10 s

            $this->output->writeln('');
            $this->output->writeln(sprintf(
                '   <fg=red;options=bold>ERROR</>  Server crashed (exit %d). Restarting in %ds… (attempt #%d)',
                $exitCode,
                $delay,
                $attempt
            ));

            sleep($delay);
        }

        $this->stopCssWatcher();

        $this->output->writeln('');
        $this->output->writeln('   <fg=gray>Server stopped.</>');
        return self::SUCCESS;
    }

    // ── Server process ────────────────────────────────────────────────

    /**
     * Spin up the PHP built-in server and stream its output until it exits.
     * Returns the exit code, or null if the process could not start.
     */
    private function runServer(string $host, int $port, string $root, string $router): ?int
    {
        // Build the command array (no shell quoting issues cross-platform)
        $cmd = ['php', '-S', "{$host}:{$port}", '-t', $root];
        if (file_exists($router)) {
            $cmd[] = $router;
        }

        $process = new Process($cmd);
        $process->setTimeout(null);   // run until killed
        $process->setIdleTimeout(null);

        $pending = [];   // ip:port → microtime() for request timing
        $buffer  = '';   // incomplete last line

        try {
            $process->start();
        } catch (\Throwable $e) {
            $this->output->writeln("   <fg=red>ERROR</>  Could not start PHP: {$e->getMessage()}");
            return 1;
        }

        while ($process->isRunning()) {
            // PHP built-in server writes to stderr; collect both just in case
            $chunk = $process->getIncrementalOutput()
                   . $process->getIncrementalErrorOutput();

            if ($chunk !== '') {
                $buffer .= $chunk;

                // Process complete lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = rtrim(substr($buffer, 0, $pos), "\r\n");
                    $buffer = substr($buffer, $pos + 1);
                    if ($line !== '') {
                        $this->parseLine($line, $pending);
                    }
                }
            }

            usleep(50_000); // 50 ms poll — keeps CPU idle, output latency < 50 ms
        }

        // Flush any partial line left in the buffer
        if (trim($buffer) !== '') {
            $this->parseLine(rtrim($buffer, "\r\n"), $pending);
        }

        return $process->getExitCode();
    }

    // ── Output parser ─────────────────────────────────────────────────

    /** @param array<string,float|true> $pending */
    private function parseLine(string $line, array &$pending): void
    {
        // Strip PHP's [timestamp] prefix (works for both PHP server and Monolog formats)
        $clean = preg_replace('/^\[.*?\]\s*/', '', $line) ?? $line;

        // ── RequestLogger Monolog line ────────────────────────────────
        // Format after timestamp strip: "Channel.LEVEL: METHOD /uri → STATUS (TIMEms)"
        // On Windows, Ctrl+C may kill the server before PHP writes its own [STATUS]: GET /
        // line, so this Monolog line is often the only source of request information.
        if (preg_match(
            '/^\w+\.\w+:\s+(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(\S+)\s+→\s+(\d+)\s+\((\d+)ms\)/',
            $clean,
            $m
        )) {
            $dispPath = strtok($m[2], '?') ?: $m[2];
            $this->printRequest($m[1], (int) $m[3], (int) $m[4], $dispPath);
            // Mark so the duplicate PHP-server log line for the same request is skipped
            $pending["__ml:{$m[1]}:{$m[2]}"] = true;
            return;
        }

        // ── Suppress all other Monolog-formatted lines ────────────────
        // Pattern: "ChannelName.LEVEL: …" — application-level logs that should
        // go to the log file, not to the serve output.
        if (preg_match('/^\w+\.\w+:\s/', $clean)) {
            return;
        }

        // ── Request completed: "ip:port [status]: METHOD /uri" ────────
        if (preg_match(
            '/^(\[?[\da-fA-F:.]+\]?):(\d+)\s+\[(\d+)\]:\s+(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(.+)$/',
            $clean,
            $m
        )) {
            [, $ip, $cport, $status, $method, $rawUri] = $m;
            $rawUri = trim($rawUri);

            // Skip if already displayed via Monolog RequestLogger line
            $markerKey = "__ml:{$method}:{$rawUri}";
            if (isset($pending[$markerKey])) {
                unset($pending[$markerKey], $pending["{$ip}:{$cport}"]);
                return;
            }

            $key   = "{$ip}:{$cport}";
            $start = is_float($pending[$key] ?? null) ? (float) $pending[$key] : microtime(true);
            unset($pending[$key]);
            $ms   = (int) round((microtime(true) - $start) * 1000);
            $path = strtok($rawUri, '?') ?: $rawUri;
            $this->printRequest($method, (int) $status, $ms, $path);
            return;
        }

        // ── Request accepted — record timing start ────────────────────
        if (preg_match('/^(\[?[\da-fA-F:.]+\]?):(\d+)\s+Accepted$/', $clean, $m)) {
            $pending["{$m[1]}:{$m[2]}"] = microtime(true);
            return;
        }

        // ── Ignore closing noise ──────────────────────────────────────
        if (str_contains($clean, 'Closing')) {
            return;
        }

        // ── PHP fatal / parse errors ──────────────────────────────────
        if (str_contains($line, 'PHP Fatal') || str_contains($line, 'PHP Parse error')) {
            $this->output->writeln("   <fg=red;options=bold>[PHP]</>  {$clean}");
            return;
        }

        // ── Port conflict ─────────────────────────────────────────────
        if (str_contains($line, 'already in use') || str_contains($line, 'Address already in use')) {
            $this->output->writeln('   <fg=red;options=bold>ERROR</>  Address already in use — try <options=bold>--port=XXXX</>.');
            return;
        }

        // ── Server start-up lines — already shown in banner ──────────
        if (str_contains($line, 'Development Server') || str_contains($line, 'Listening on')) {
            return;
        }

        // ── Everything else — dim passthrough ────────────────────────
        if (trim($clean) !== '') {
            $this->output->writeln("   <fg=gray>{$clean}</>");
        }
    }

    // ── Request line ──────────────────────────────────────────────────

    private function printRequest(string $method, int $status, int $ms, string $uri): void
    {
        $timestamp = date('Y-m-d H:i:s');

        // Method colour
        $methodTag = match ($method) {
            'GET'          => 'fg=cyan',
            'POST'         => 'fg=green',
            'PUT', 'PATCH' => 'fg=yellow',
            'DELETE'       => 'fg=red',
            default        => 'fg=white',
        };

        // Status colour
        $statusTag = match (true) {
            $status >= 500 => 'fg=red;options=bold',
            $status >= 400 => 'fg=yellow',
            $status >= 300 => 'fg=cyan',
            default        => 'fg=green',
        };

        // Response time colour
        $timeTag = match (true) {
            $ms < 50  => 'fg=green',
            $ms < 200 => 'fg=yellow',
            default   => 'fg=red',
        };

        // Fixed-width columns for clean alignment
        $methodPad = str_pad($method, 7);
        $uriPad    = mb_strimwidth($uri, 0, 50, '…');
        $statusPad = str_pad((string) $status, 3);
        $timePad   = str_pad("{$ms}ms", 8, ' ', STR_PAD_LEFT);

        // Dot-padding between URI and status (Laravel style)
        $dots = str_repeat('.', max(2, 52 - mb_strlen($uriPad)));

        $this->output->writeln(sprintf(
            '  <fg=gray>%s</>  <options=bold;%s>%s</>  %s <fg=gray>%s</>  <%s>%s</>  <%s>%s</>',
            $timestamp,
            $methodTag,
            $methodPad,
            $uriPad,
            $dots,
            $statusTag,
            $statusPad,
            $timeTag,
            $timePad
        ));
    }

    // ── Banner ────────────────────────────────────────────────────────

    private function printBanner(string $url, bool $watchCss): void
    {
        $this->output->writeln('');
        $this->output->writeln(
            "   <options=bold;fg=green>INFO</>  Server running on [<options=bold;fg=cyan>{$url}</>]."
        );
        $this->output->writeln('');
        $this->output->writeln('   Press <options=bold>Ctrl+C</> to stop the server');
        if ($watchCss) {
            $this->output->writeln('   CSS watcher <options=bold;fg=green>active</> (npm run dev)');
        }
        $this->output->writeln('');
    }

    // ── Port finder ───────────────────────────────────────────────────

    private function findFreePort(string $host, int $port): int
    {
        while ($port < 65535) {
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.1);
            if ($socket === false) {
                return $port;
            }
            fclose($socket);
            $this->output->writeln(
                "   <fg=yellow>Port {$port} is in use — trying " . ($port + 1) . '…</>'
            );
            $port++;
        }
        return $port;
    }

    // ── CSS watcher ───────────────────────────────────────────────────

    private function startCssWatcher(): void
    {
        $root = base_path();
        if (!file_exists("{$root}/package.json")) {
            $this->output->writeln('   <fg=yellow>--watch-css: no package.json found, skipping.</>');
            return;
        }
        $proc = proc_open('npm run dev 2>&1', [1 => ['pipe', 'w']], $pipes, $root);
        if (is_resource($proc)) {
            $this->cssProcess = $proc;
        }
    }

    private function stopCssWatcher(): void
    {
        if ($this->cssProcess !== null && is_resource($this->cssProcess)) {
            proc_terminate($this->cssProcess);
            proc_close($this->cssProcess);
            $this->cssProcess = null;
        }
    }
}
