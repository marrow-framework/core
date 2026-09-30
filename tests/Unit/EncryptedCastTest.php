<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Database\Connection;
use Marrow\Database\Model;
use Marrow\Tests\Unit\Fixtures\EncryptedFieldModel;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// Model's `encrypted` cast used to fall back to storing/reading plaintext
// silently whenever APP_KEY was empty. It now delegates to Crypto, which
// fails loudly instead — see CryptoTest.php for the encryption behaviour
// itself.

beforeEach(function () {
    $conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $conn->statement('CREATE TABLE secrets (id INTEGER PRIMARY KEY AUTOINCREMENT, ssn TEXT, created_at TEXT, updated_at TEXT)');
    Model::setConnection($conn);
    $this->conn = $conn;

    $this->previousAppKey = $_ENV['APP_KEY'] ?? null;
    $_ENV['APP_KEY'] = 'a-test-app-key-for-encrypted-cast';
});

afterEach(function () {
    if ($this->previousAppKey === null) {
        unset($_ENV['APP_KEY']);
    } else {
        $_ENV['APP_KEY'] = $this->previousAppKey;
    }
});

test('an encrypted attribute round-trips through save and a fresh read', function () {
    $model = EncryptedFieldModel::create(['ssn' => '123-45-6789']);

    $stored = $this->conn->selectOne('SELECT ssn FROM secrets WHERE id = ?', [$model->id]);
    expect($stored['ssn'])->not->toBe('123-45-6789'); // stored ciphertext, not plaintext

    $fresh = EncryptedFieldModel::find($model->id);
    expect($fresh->ssn)->toBe('123-45-6789');
});

test('saving an encrypted attribute without APP_KEY configured fails loudly', function () {
    unset($_ENV['APP_KEY']);

    expect(fn () => EncryptedFieldModel::create(['ssn' => '123-45-6789']))
        ->toThrow(\RuntimeException::class, 'APP_KEY');
});
