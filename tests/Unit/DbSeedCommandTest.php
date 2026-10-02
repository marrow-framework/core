<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Console\Commands\DbSeedCommand;
use Marrow\Container;
use Marrow\Database\Seeder;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// fakerphp/faker moved to require-dev this release (it's only ever touched by
// Database\Factory::fake(), never at boot) — unlike `tinker`'s psy/psysh,
// Console\Kernel's registration-time try/catch can't help here, since a
// seeder is only resolved/run when `db:seed` actually executes. These tests
// cover the dedicated try/catch DbSeedCommand now needs instead.

function runDbSeedCommand(string $class): string
{
    $cmd = new DbSeedCommand(new Container());
    $output = new BufferedOutput();
    $cmd->run(new ArrayInput(['--class' => $class], $cmd->getDefinition()), $output);
    return $output->fetch();
}

class FixtureSuccessSeeder extends Seeder
{
    public function run(): void
    {
        // no-op
    }
}

class FixtureFakerDependentSeeder extends Seeder
{
    public function run(): void
    {
        // Mirrors exactly what PHP throws when fakerphp/faker isn't installed
        // and Database\Factory::fake() (or a seeder using it directly) tries
        // to instantiate Faker\Generator.
        throw new \Error('Class "Faker\Generator" not found');
    }
}

class FixtureBrokenSeeder extends Seeder
{
    public function run(): void
    {
        throw new \RuntimeException('something unrelated broke');
    }
}

test('runs a seeder successfully and reports DONE', function () {
    $output = runDbSeedCommand(FixtureSuccessSeeder::class);

    expect($output)->toContain('DONE');
    expect($output)->toContain('Seeded [' . FixtureSuccessSeeder::class . ']');
});

test('reports a missing seeder class without throwing', function () {
    $output = runDbSeedCommand('Marrow\Tests\Unit\NoSuchSeederAtAll');

    expect($output)->toContain('ERROR');
    expect($output)->toContain('not found');
});

test('a seeder failing because fakerphp/faker is not installed gets a friendly, actionable error instead of a raw stack trace', function () {
    $output = runDbSeedCommand(FixtureFakerDependentSeeder::class);

    expect($output)->toContain('ERROR');
    expect($output)->toContain('fakerphp/faker');
    expect($output)->toContain('composer require --dev fakerphp/faker');
});

test('any other seeder failure still surfaces its own message, not the Faker-specific one', function () {
    $output = runDbSeedCommand(FixtureBrokenSeeder::class);

    expect($output)->toContain('ERROR');
    expect($output)->toContain('something unrelated broke');
    expect($output)->not->toContain('fakerphp/faker');
});
