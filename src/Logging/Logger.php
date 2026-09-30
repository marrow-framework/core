<?php

declare(strict_types=1);

namespace Marrow\Logging;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Wraps Monolog with a daily-rotation file handler + stderr in dev.
 * Exposes a PSR-3 LoggerInterface so Core code never imports Monolog directly.
 */
class Logger implements LoggerInterface
{
    private MonologLogger $monolog;

    public function __construct(
        string $channel,
        string $logPath,
        string $level = 'debug',
        bool $debug = false
    ) {
        $this->monolog = new MonologLogger($channel);
        $monologLevel = Level::fromName(ucfirst(strtolower($level)));

        $this->monolog->pushHandler(
            new RotatingFileHandler($logPath . '/app.log', 14, $monologLevel)
        );

        if ($debug) {
            $this->monolog->pushHandler(new StreamHandler('php://stderr', Level::Debug));
        }
    }

    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->monolog->emergency((string) $message, $context);
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->monolog->alert((string) $message, $context);
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->monolog->critical((string) $message, $context);
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->monolog->error((string) $message, $context);
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->monolog->warning((string) $message, $context);
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->monolog->notice((string) $message, $context);
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->monolog->info((string) $message, $context);
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->monolog->debug((string) $message, $context);
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->monolog->log($level, (string) $message, $context);
    }

    public function getMonolog(): MonologLogger
    {
        return $this->monolog;
    }
}
