<?php

declare(strict_types=1);

use App\Enums\OutputType;

test('enabledIn returns true when bit is set', function () {
    expect(OutputType::PDF->enabledIn(3))->toBeTrue();
    expect(OutputType::Markdown->enabledIn(3))->toBeTrue();
});

test('enabledIn returns false when bit is not set', function () {
    expect(OutputType::Audio->enabledIn(3))->toBeFalse();
});

test('isSetIn is alias for enabledIn', function () {
    expect(OutputType::PDF->isSetIn(3))->toBe(OutputType::PDF->enabledIn(3));
    expect(OutputType::Audio->isSetIn(3))->toBe(OutputType::Audio->enabledIn(3));
});

test('isValidBitmask validates range', function () {
    expect(OutputType::isValidBitmask(0))->toBeFalse();
    expect(OutputType::isValidBitmask(1))->toBeTrue();
    expect(OutputType::isValidBitmask(7))->toBeTrue();
    expect(OutputType::isValidBitmask(8))->toBeFalse();
});

test('fromBitmask returns all enabled types', function () {
    expect(OutputType::fromBitmask(7))->toEqual([OutputType::PDF, OutputType::Markdown, OutputType::Audio]);
    expect(OutputType::fromBitmask(5))->toEqual([OutputType::PDF, OutputType::Audio]);
    expect(OutputType::fromBitmask(1))->toEqual([OutputType::PDF]);
});
