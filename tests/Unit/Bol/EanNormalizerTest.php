<?php

use App\Services\Bol\EanNormalizer;

it('returns null for null or non-scalar input', function () {
    expect(EanNormalizer::normalize(null))->toBeNull()
        ->and(EanNormalizer::normalize([]))->toBeNull()
        ->and(EanNormalizer::normalize(new stdClass()))->toBeNull();
});

it('strips non-digits and validates length', function () {
    expect(EanNormalizer::normalize('abc'))->toBeNull()
        ->and(EanNormalizer::normalize('12345'))->toBeNull();
});

it('keeps a valid 13-digit EAN unchanged', function () {
    expect(EanNormalizer::normalize('5414452716061'))->toBe('5414452716061');
});

it('trims a leading zero from a 14-digit code', function () {
    expect(EanNormalizer::normalize('05715694000315'))->toBe('5715694000315');
});

it('handles formatted EANs with dashes or spaces', function () {
    expect(EanNormalizer::normalize('5 414 452 716 061'))->toBe('5414452716061')
        ->and(EanNormalizer::normalize('5414-4527-16061'))->toBe('5414452716061');
});

it('rejects EANs longer than 14 digits even after trimming', function () {
    expect(EanNormalizer::normalize('123456789012345'))->toBeNull();
});
