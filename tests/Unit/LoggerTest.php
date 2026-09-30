<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Logging\Logger;

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->logDir = sys_get_temp_dir() . '/marrow-logger-test-' . uniqid();
    mkdir($this->logDir, 0755, true);
});

afterEach(function () {
    foreach (glob($this->logDir . '/*') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($this->logDir);
});

/** RotatingFileHandler names the file app-{Y-m-d}.log, not a fixed app.log. */
function readLogFile(string $dir): string
{
    $files = glob($dir . '/app-*.log') ?: [];
    return $files === [] ? '' : (string) file_get_contents($files[0]);
}

test('info() writes a record containing the message to the log file', function () {
    $logger = new Logger('test', $this->logDir);
    $logger->info('hello from the test suite');

    $contents = readLogFile($this->logDir);
    expect($contents)->toContain('hello from the test suite');
    expect($contents)->toContain('test.INFO');
});

test('error() is tagged at the ERROR level', function () {
    $logger = new Logger('test', $this->logDir);
    $logger->error('something broke');

    expect(readLogFile($this->logDir))->toContain('test.ERROR');
});

test('context data is included in the log line', function () {
    $logger = new Logger('test', $this->logDir);
    $logger->warning('rate limited', ['ip' => '203.0.113.5']);

    expect(readLogFile($this->logDir))->toContain('203.0.113.5');
});

test('debug() below the configured level is filtered out', function () {
    $logger = new Logger('test', $this->logDir, level: 'warning');
    $logger->debug('too verbose to keep');

    expect(readLogFile($this->logDir))->not->toContain('too verbose to keep');
});
