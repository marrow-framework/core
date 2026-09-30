<?php

declare(strict_types=1);

namespace Marrow\Database\Migrations;

/**
 * Base class for all migrations. Subclasses implement up() and down().
 */
abstract class Migration
{
    abstract public function up(): void;
    abstract public function down(): void;
}
