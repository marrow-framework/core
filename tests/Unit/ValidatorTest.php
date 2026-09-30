<?php

declare(strict_types=1);

use Marrow\Validation\ValidatorInstance;

function makeValidator(array $data, array $rules): ValidatorInstance
{
    return new ValidatorInstance($data, $rules, [], null);
}

test('passes with valid name and email', function () {
    $v = makeValidator(
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'required|string', 'email' => 'required|email']
    );
    expect($v->passes())->toBeTrue();
    expect($v->fails())->toBeFalse();
});

test('fails required rule', function () {
    $v = makeValidator([], ['name' => 'required']);
    expect($v->fails())->toBeTrue();
    expect($v->errors())->toHaveKey('name');
});

test('fails email rule', function () {
    $v = makeValidator(['email' => 'not-an-email'], ['email' => 'required|email']);
    expect($v->fails())->toBeTrue();
    expect($v->errors())->toHaveKey('email');
});

test('fails min rule', function () {
    $v = makeValidator(['password' => 'ab'], ['password' => 'required|min:6']);
    expect($v->fails())->toBeTrue();
});

test('fails max rule', function () {
    $v = makeValidator(['name' => str_repeat('a', 300)], ['name' => 'max:255']);
    expect($v->fails())->toBeTrue();
});

test('in rule passes and fails', function () {
    expect(makeValidator(['status' => 'unknown'], ['status' => 'in:draft,published,archived'])->fails())->toBeTrue();
    expect(makeValidator(['status' => 'draft'],   ['status' => 'in:draft,published,archived'])->passes())->toBeTrue();
});

test('confirmed rule passes when matching', function () {
    $v = makeValidator(
        ['password' => 'secret', 'password_confirmation' => 'secret'],
        ['password' => 'required|confirmed']
    );
    expect($v->passes())->toBeTrue();
});

test('confirmed rule fails when mismatched', function () {
    $v = makeValidator(
        ['password' => 'secret', 'password_confirmation' => 'other'],
        ['password' => 'required|confirmed']
    );
    expect($v->fails())->toBeTrue();
});

test('nullable skips other rules', function () {
    $v = makeValidator(['bio' => null], ['bio' => 'nullable|string|max:500']);
    expect($v->passes())->toBeTrue();
});

test('validated returns only defined keys', function () {
    $v = makeValidator(
        ['title' => 'Hello', 'extra' => 'ignored'],
        ['title' => 'required|string']
    );
    $validated = $v->validated();
    expect($validated)->toHaveKey('title');
    expect($validated)->not->toHaveKey('extra');
});

test('integer rule', function () {
    expect(makeValidator(['age' => 'not-int'], ['age' => 'integer'])->fails())->toBeTrue();
    expect(makeValidator(['age' => 25],        ['age' => 'integer'])->passes())->toBeTrue();
});

test('url rule', function () {
    expect(makeValidator(['site' => 'not a url'],           ['site' => 'url'])->fails())->toBeTrue();
    expect(makeValidator(['site' => 'https://example.com'], ['site' => 'url'])->passes())->toBeTrue();
});

test('boolean rule accepts valid boolean values', function () {
    foreach ([true, false, 1, 0, '1', '0'] as $val) {
        expect(makeValidator(['active' => $val], ['active' => 'boolean'])->passes())
            ->toBeTrue("Expected boolean rule to pass for: " . var_export($val, true));
    }
    expect(makeValidator(['active' => 'yes'], ['active' => 'boolean'])->fails())->toBeTrue();
});

test('an unknown validation rule throws instead of silently passing', function () {
    expect(fn () => makeValidator(['name' => 'Alice'], ['name' => 'bogus_rule'])->fails())
        ->toThrow(InvalidArgumentException::class, 'Unknown validation rule [bogus_rule]');
});
