<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Console\Command;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CliSignatureTest extends TestCase
{
    private function runCommand(Command $cmd, array $input = []): string
    {
        $output = new BufferedOutput();
        $cmd->run(new ArrayInput($input, $cmd->getDefinition()), $output);
        return $output->fetch();
    }

    public function test_argument_parsing(): void
    {
        $cmd = new class extends Command {
            protected string $signature = 'greet {name : Who to greet}';
            protected string $description = 'Greet someone';
            public function handle(): int
            {
                $this->line("Hello " . $this->argument('name'));
                return 0;
            }
        };

        $out = $this->runCommand($cmd, ['name' => 'Alice']);
        $this->assertStringContainsString('Hello Alice', $out);
    }

    public function test_option_parsing(): void
    {
        $cmd = new class extends Command {
            protected string $signature = 'echo {--upper : Uppercase output}';
            protected string $description = 'Echo with option';
            public function handle(): int
            {
                $this->line($this->option('upper') ? 'UPPER' : 'lower');
                return 0;
            }
        };

        $out = $this->runCommand($cmd, ['--upper' => true]);
        $this->assertStringContainsString('UPPER', $out);

        $out2 = $this->runCommand($cmd, []);
        $this->assertStringContainsString('lower', $out2);
    }

    public function test_option_with_value(): void
    {
        $cmd = new class extends Command {
            protected string $signature = 'count {--times=1 : How many times}';
            protected string $description = 'Count';
            public function handle(): int
            {
                $this->line((string) $this->option('times'));
                return 0;
            }
        };

        $out = $this->runCommand($cmd, ['--times' => '5']);
        $this->assertStringContainsString('5', $out);
    }

    public function test_command_name_set_correctly(): void
    {
        $cmd = new class extends Command {
            protected string $signature = 'my:command {arg}';
            protected string $description = 'Test';
            public function handle(): int
            {
                return 0;
            }
        };
        $this->assertSame('my:command', $cmd->getName());
    }
}
