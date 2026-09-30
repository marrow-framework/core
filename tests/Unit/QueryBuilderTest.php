<?php

declare(strict_types=1);

use Marrow\Database\Connection;

beforeEach(function () {
    $this->connection = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
    $this->connection->statement(
        'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER, active INTEGER DEFAULT 1)'
    );
    $this->connection->statement("INSERT INTO users (name, email, age, active) VALUES ('Alice', 'alice@test.com', 30, 1)");
    $this->connection->statement("INSERT INTO users (name, email, age, active) VALUES ('Bob',   'bob@test.com',   25, 0)");
    $this->connection->statement("INSERT INTO users (name, email, age, active) VALUES ('Carol', 'carol@test.com', 35, 1)");
});

test('get all rows', function () {
    expect($this->connection->table('users')->get())->toHaveCount(3);
});

test('where clause filters rows', function () {
    expect($this->connection->table('users')->where('active', '=', 1)->get())->toHaveCount(2);
});

test('first returns matching row', function () {
    $row = $this->connection->table('users')->where('name', '=', 'Bob')->first();
    expect($row)->not->toBeNull();
    expect($row->name)->toBe('Bob');
});

test('find by primary key', function () {
    $row = $this->connection->table('users')->find(1);
    expect($row)->not->toBeNull();
    expect($row->name)->toBe('Alice');
});

test('count total and filtered', function () {
    expect($this->connection->table('users')->count())->toBe(3);
    expect($this->connection->table('users')->where('active', '=', 1)->count())->toBe(2);
});

test('pluck extracts column values', function () {
    $names = $this->connection->table('users')->orderBy('name')->pluck('name');
    expect($names)->toBe(['Alice', 'Bob', 'Carol']);
});

test('orderBy DESC puts Carol first', function () {
    $rows = $this->connection->table('users')->orderBy('age', 'DESC')->get();
    expect($rows->first()->name)->toBe('Carol');
});

test('limit and offset', function () {
    $rows = $this->connection->table('users')->orderBy('id')->limit(2)->offset(1)->get();
    expect($rows)->toHaveCount(2);
    expect($rows->first()->name)->toBe('Bob');
});

test('whereIn', function () {
    $rows = $this->connection->table('users')->whereIn('name', ['Alice', 'Carol'])->get();
    expect($rows)->toHaveCount(2);
});

test('whereNotIn', function () {
    $rows = $this->connection->table('users')->whereNotIn('name', ['Alice'])->get();
    expect($rows)->toHaveCount(2);
});

test('insertGetId returns new row id', function () {
    $id = $this->connection->table('users')->insertGetId([
        'name' => 'Dave', 'email' => 'dave@test.com', 'age' => 28, 'active' => 1,
    ]);
    expect($id)->toBeInt()->toBe(4);
});

test('update sets column value', function () {
    $this->connection->table('users')->where('name', '=', 'Bob')->updateWhere(['active' => 1]);
    $bob = $this->connection->table('users')->where('name', '=', 'Bob')->first();
    expect((int) $bob->active)->toBe(1);
});

test('delete removes row', function () {
    $this->connection->table('users')->where('name', '=', 'Bob')->deleteWhere();
    expect($this->connection->table('users')->count())->toBe(2);
});

test('sum avg min max aggregates', function () {
    $q = $this->connection->table('users');
    expect($q->sum('age'))->toBe(90.0);
    expect($q->avg('age'))->toBe(30.0);
    expect($q->min('age'))->toBe(25.0);
    expect($q->max('age'))->toBe(35.0);
});

test('min/max on a non-numeric column are not corrupted by the float cast', function () {
    $q = $this->connection->table('users');
    expect($q->min('name'))->toBe('Alice');
    expect($q->max('name'))->toBe('Carol');
});

test('toSql returns parameterised SQL', function () {
    [$sql, $bindings] = $this->connection->table('users')
        ->where('active', '=', 1)
        ->orderBy('name')
        ->toSql();
    expect($sql)->toContain('WHERE');
    expect($bindings)->toContain(1);
});

test('when applies callback only when condition is true', function () {
    $rows  = $this->connection->table('users')->when(true,  fn ($q) => $q->where('active', '=', 0))->get();
    $rows2 = $this->connection->table('users')->when(false, fn ($q) => $q->where('active', '=', 0))->get();
    expect($rows)->toHaveCount(1);
    expect($rows2)->toHaveCount(3);
});
