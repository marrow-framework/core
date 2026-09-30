<?php

declare(strict_types=1);

namespace Marrow\Database;

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;

/**
 * Thin wrapper around Faker\Generator that adds Marrow-specific helpers
 * (password hashing) and normalises a few signatures that differ from Faker's
 * defaults (words → string, dateTimeBetween → formatted string).
 *
 * Any method or property not declared here is proxied to the underlying
 * Faker generator, giving access to the full Faker API.
 */
class FakeGenerator
{
    private FakerGenerator $faker;

    public function __construct(string $locale = 'en_US')
    {
        $this->faker = FakerFactory::create($locale);
    }

    // ── Marrow-specific helpers ─────────────────────────────────────

    public function password(string $plain = 'password'): string
    {
        return \Marrow\Auth\Hash::make($plain);
    }

    // ── Normalised signatures (different from Faker defaults) ─────────

    /** Always returns a plain string; Faker's words() returns an array by default. */
    public function words(int $n = 3): string
    {
        return $this->faker->words($n, true);
    }

    /** Always returns a Y-m-d H:i:s string; Faker returns a DateTime object. */
    public function dateTimeBetween(string $start = '-1 year', string $end = 'now'): string
    {
        return $this->faker->dateTimeBetween($start, $end)->format('Y-m-d H:i:s');
    }

    // ── Faker proxy ───────────────────────────────────────────────────

    public function __call(string $name, array $args): mixed
    {
        return $this->faker->$name(...$args);
    }

    public function __get(string $name): mixed
    {
        return $this->faker->$name;
    }
}
