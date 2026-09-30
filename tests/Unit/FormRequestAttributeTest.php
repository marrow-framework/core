<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit;

use Marrow\Tests\Unit\Fixtures\AttributeFormRequest;

// ── Tests ─────────────────────────────────────────────────────────────────────
//
// #[Required]/#[Email]/... are an alternative to hand-writing the rules()
// array — AttributeFormRequest::rules() delegates entirely to
// rulesFromAttributes(), which only reflects the class definition (these
// properties never hold real submitted data at that point).

test('rulesFromAttributes() combines multiple attributes into one pipe rule', function () {
    $rules = (new AttributeFormRequest())->rules();

    expect($rules['title'])->toBe('required|string|max:255');
});

test('#[Email] resolves to the email rule', function () {
    $rules = (new AttributeFormRequest())->rules();

    expect($rules['email'])->toBe('required|email');
});

test('#[In] resolves to an in:... rule with the given values', function () {
    $rules = (new AttributeFormRequest())->rules();

    expect($rules['status'])->toBe('required|in:draft,published');
});

test('#[Rule] is a raw escape hatch for anything without a dedicated attribute', function () {
    $rules = (new AttributeFormRequest())->rules();

    expect($rules['password'])->toBe('nullable|min:8');
});

test('#[Confirmed] resolves on its own with no other attribute', function () {
    $rules = (new AttributeFormRequest())->rules();

    expect($rules['newPassword'])->toBe('confirmed');
});

test('a property with no validation attribute is not in the rules array', function () {
    $rules = (new AttributeFormRequest())->rules();

    expect($rules)->not->toHaveKey('internalTrackingId');
});
