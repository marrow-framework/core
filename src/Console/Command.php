<?php

declare(strict_types=1);

namespace Marrow\Console;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base console command.
 *
 * Output convention — every line starts with 3 spaces:
 *   "   BADGE  message"
 *
 * Available badges:
 *   INFO  (blue)   — neutral information
 *   DONE  (green)  — success
 *   WARN  (yellow) — warning
 *   ERROR (red)    — error
 */
abstract class Command extends SymfonyCommand
{
    protected string $signature   = '';
    protected string $description = '';

    protected SymfonyStyle    $io;
    protected InputInterface  $input;
    protected OutputInterface $output;

    protected function configure(): void
    {
        if (empty($this->signature)) {
            return;
        }
        $this->parseSignature();
        $this->setDescription($this->description);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input  = $input;
        $this->output = $output;
        $this->io     = new SymfonyStyle($input, $output);

        try {
            return $this->handle() ?? self::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    abstract protected function handle(): int|null;

    // ── Output helpers ───────────────────────────────────────────────

    protected function info(string $message): void
    {
        $this->output->writeln("   <options=bold;fg=blue>INFO</>  {$message}");
    }

    protected function success(string $message): void
    {
        $this->output->writeln("   <options=bold;fg=green>DONE</>  {$message}");
    }

    protected function warn(string $message): void
    {
        $this->output->writeln("   <options=bold;fg=yellow>WARN</>  {$message}");
    }

    protected function error(string $message): void
    {
        $this->output->writeln("   <options=bold;fg=red>ERROR</>  {$message}");
    }

    protected function line(string $message): void
    {
        $this->output->writeln($message);
    }

    protected function newLine(int $count = 1): void
    {
        $this->output->writeln(array_fill(0, $count, ''));
    }

    /**
     * Dot-padded two-column detail line:
     *   "   Left label ........ Right value"
     *
     * @param string $style  Optional Symfony Console style tag for the right side
     *                       (only applied when $right contains no existing tags).
     */
    protected function twoColumnDetail(string $left, string $right, string $style = ''): void
    {
        $plainLeft   = preg_replace('/<[^>]+>/', '', $left) ?? $left;
        $dots        = str_repeat('.', max(2, 46 - mb_strlen(trim($plainLeft))));
        $styledRight = ($style && !str_contains($right, '<'))
            ? "<{$style}>{$right}</>"
            : $right;
        $this->output->writeln("   {$left} <fg=gray>{$dots}</> {$styledRight}");
    }

    /**
     * Migration result line with dot-padding and a timing/status badge:
     *
     *   "   create_posts_table .........  12ms  DONE"
     *   "   create_posts_table .........  12ms  ROLLBACK"
     *
     * @param bool $rollback  true → yellow ROLLBACK badge, false → green DONE badge
     */
    protected function migrationLine(string $name, int $ms, bool $rollback = false): void
    {
        $badge = $rollback
            ? '<options=bold;fg=yellow>ROLLBACK</>'
            : '<options=bold;fg=green>DONE</>';

        $timeColor = match (true) {
            $ms < 100  => 'fg=green',
            $ms < 500  => 'fg=yellow',
            default    => 'fg=red',
        };

        $nameTrim = mb_strimwidth($name, 0, 50, '…');
        $timePad  = str_pad("{$ms}ms", 7, ' ', STR_PAD_LEFT);
        $dots     = str_repeat('.', max(2, 52 - mb_strlen($nameTrim)));

        $this->output->writeln(
            "   {$nameTrim} <fg=gray>{$dots}</> <{$timeColor}>{$timePad}</>  {$badge}"
        );
    }

    /**
     * Run a labeled task — prints a spinner then DONE / FAIL on completion.
     */
    protected function task(string $title, callable $task): bool
    {
        $this->output->write("   <fg=gray>…</>  {$title}");
        try {
            $result = $task();
            $ok = ($result !== false);
        } catch (\Throwable) {
            $ok = false;
        }
        $this->output->write("\r");
        if ($ok) {
            $this->output->writeln("   <options=bold;fg=green>DONE</>  {$title}");
        } else {
            $this->output->writeln("   <options=bold;fg=red>FAIL</>  {$title}");
        }
        return $ok;
    }

    protected function progress(int $max, callable $callback): void
    {
        $bar = $this->io->createProgressBar($max);
        $bar->start();
        $callback($bar);
        $bar->finish();
        $this->output->writeln('');
    }

    // ── Interactive helpers ──────────────────────────────────────────

    protected function argument(string $name): mixed
    {
        return $this->input->getArgument($name);
    }

    protected function option(string $name): mixed
    {
        return $this->input->getOption($name);
    }

    protected function argumentOrAsk(string $name, string $question, ?string $default = null): string
    {
        $value = (string) ($this->input->getArgument($name) ?? '');
        if ($value !== '') {
            return $value;
        }
        return $this->ask($question, $default);
    }

    /**
     * Validates a class name before it's ever interpolated into a file path
     * or generated PHP source — rejects anything that isn't a plain PHP
     * identifier, optionally with `/`- or `\`-separated sub-namespace
     * segments (e.g. "PostController" or "Admin/PostController"). Throwing
     * here (caught centrally in execute()) means a stray `../` or a
     * syntax-breaking character fails the command instead of silently
     * writing wherever the input points or generating invalid PHP.
     *
     * @throws \InvalidArgumentException If $name isn't a valid PHP identifier
     *                                   (optionally with sub-namespace segments).
     */
    protected function validClassName(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*([\/\\\\][A-Za-z_][A-Za-z0-9_]*)*$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid class name [{$name}] — expected a PHP identifier, optionally with / or \\ sub-namespace segments."
            );
        }
        return $name;
    }

    /** Normalizes a --module option the same way make:module names module directories (PascalCase). */
    protected function moduleOption(): ?string
    {
        $raw = $this->option('module');
        return is_string($raw) && $raw !== '' ? ucfirst($raw) : null;
    }

    /**
     * A generated class is only protected by module isolation once it's
     * declared in its module's `providers: [...]` — this is opt-in by
     * design, not auto-discovered by namespace convention (see
     * Container::validateModuleAccess()). Inserts it there automatically so
     * a freshly generated class is protected without a manual step.
     */
    protected function registerAsProvider(string $module, string $fqcn): void
    {
        $modulePath = base_path("modules/{$module}/{$module}Module.php");

        if (!is_file($modulePath)) {
            $this->line("Note: could not find {$modulePath} — add \\{$fqcn}::class to its providers: manually.");
            return;
        }

        $source = file_get_contents($modulePath);
        $updated = $this->insertIntoProviders($source, $fqcn);

        if ($updated === null) {
            $this->line("Note: could not locate providers: in {$module}Module.php — add \\{$fqcn}::class manually.");
            return;
        }

        if ($updated !== $source) {
            file_put_contents($modulePath, $updated);
        }
    }

    /**
     * Inserts "\Fqcn::class" into the providers: [...] array of a #[Module(...)]
     * attribute. Returns null if no providers: array could be found (caller
     * falls back to a manual instruction rather than risk corrupting the
     * file). Idempotent — does nothing if the FQCN is already listed.
     */
    private function insertIntoProviders(string $source, string $fqcn): ?string
    {
        if (!preg_match('/providers\s*:\s*\[/', $source, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $openBracket = $match[0][1] + strlen($match[0][0]) - 1;
        $depth = 0;
        $closeBracket = null;

        for ($i = $openBracket; $i < strlen($source); $i++) {
            if ($source[$i] === '[') {
                $depth++;
            } elseif ($source[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    $closeBracket = $i;
                    break;
                }
            }
        }

        if ($closeBracket === null) {
            return null;
        }

        $inner = substr($source, $openBracket + 1, $closeBracket - $openBracket - 1);
        $entry = "\\{$fqcn}::class";

        if (str_contains($inner, $entry)) {
            return $source;
        }

        $trimmedInner = trim($inner);
        $newInner = $trimmedInner === ''
            ? "\n        {$entry},\n    "
            : rtrim($inner) . (str_ends_with(rtrim($inner), ',') ? '' : ',') . "\n        {$entry},\n    ";

        return substr($source, 0, $openBracket + 1) . $newInner . substr($source, $closeBracket);
    }

    protected function ask(string $question, ?string $default = null): string
    {
        return (string) $this->io->ask($question, $default);
    }

    protected function confirm(string $question, bool $default = false): bool
    {
        return $this->io->confirm($question, $default);
    }

    protected function secret(string $question): string
    {
        return (string) $this->io->askHidden($question);
    }

    protected function choice(string $question, array $choices, mixed $default = null): string
    {
        return (string) $this->io->choice($question, $choices, $default);
    }

    protected function table(array $headers, array $rows): void
    {
        $this->io->table($headers, $rows);
    }

    /**
     * Call another registered console command by name.
     *
     * @param array<string, mixed> $arguments  Extra arguments/options to pass
     */
    protected function call(string $command, array $arguments = []): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            $this->error("Cannot call [{$command}]: no application context.");
            return self::FAILURE;
        }
        $input = new ArrayInput(array_merge(['command' => $command], $arguments));
        $input->setInteractive(false);
        return $application->find($command)->run($input, $this->output);
    }

    // ── Signature parsing ────────────────────────────────────────────

    private function parseSignature(): void
    {
        $parts = preg_split('/\s+/', trim($this->signature), 2);
        $this->setName($parts[0]);

        if (!isset($parts[1])) {
            return;
        }

        preg_match_all('/\{([^}]+)\}/', $parts[1], $matches);

        foreach ($matches[1] as $token) {
            $token = trim($token);
            if (str_starts_with($token, '--')) {
                $this->parseOption(substr($token, 2));
            } else {
                $this->parseArgument($token);
            }
        }
    }

    private function parseArgument(string $token): void
    {
        $description = '';
        if (str_contains($token, ':')) {
            [$token, $description] = explode(':', $token, 2);
            // A signature like "{name? : description}" leaves a trailing
            // space on $token ("name? ") after the split above — without
            // trimming it, str_ends_with($token, '?') below is false, the
            // argument is wrongly registered as REQUIRED, and its name
            // literally includes the '?' character (since trim($token) at
            // the end only strips whitespace, not the marker).
            $token = rtrim($token);
        }

        $mode    = InputArgument::REQUIRED;
        $default = null;

        if (str_ends_with($token, '?')) {
            $token = rtrim($token, '?');
            $mode  = InputArgument::OPTIONAL;
        } elseif (str_contains($token, '=')) {
            [$token, $default] = explode('=', $token, 2);
            $mode = InputArgument::OPTIONAL;
        }

        $this->addArgument(trim($token), $mode, trim($description), $default);
    }

    private function parseOption(string $token): void
    {
        $description = '';
        if (str_contains($token, ':')) {
            [$token, $description] = explode(':', $token, 2);
            // Same trailing-space issue as parseArgument(): "{--module= :
            // description}" leaves $token as "module= " after the split,
            // so str_ends_with($token, '=') below is false and the option
            // falls through to being treated as having a literal default
            // value of " " (a single space) instead of no value at all.
            $token = rtrim($token);
        }

        $mode    = InputOption::VALUE_NONE;
        $default = null;

        if (str_ends_with($token, '=')) {
            $token = rtrim($token, '=');
            $mode  = InputOption::VALUE_OPTIONAL;
        } elseif (str_contains($token, '=')) {
            [$token, $default] = explode('=', $token, 2);
            $mode = InputOption::VALUE_OPTIONAL;
        }

        $this->addOption(trim($token), null, $mode, trim($description), $default);
    }
}
