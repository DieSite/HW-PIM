<?php

use App\Services\Concerns\SplitsMultiValueAttributes;

/**
 * The PIM stores multiselect attributes (`kleuren`, `materiaal`) either as an
 * array or as one string joined with "|" or ", ". Both the Bol payload and the
 * competitor catalog CSV read them, so the parsing lives in one trait.
 */
function multiValueSplitter(): object
{
    return new class
    {
        use SplitsMultiValueAttributes;

        /** @return string[] */
        public function split(mixed $raw): array
        {
            return $this->splitMultiValue($raw);
        }
    };
}

it('splits the separators the PIM actually uses', function () {
    $s = multiValueSplitter();

    expect($s->split('Beige'))->toBe(['Beige'])
        ->and($s->split('Oranje, Geel'))->toBe(['Oranje', 'Geel'])
        ->and($s->split('Zwart|Beige'))->toBe(['Zwart', 'Beige'])
        ->and($s->split(['Beige', 'Wit']))->toBe(['Beige', 'Wit']);
});

it('trims array members and drops the ones that are not text', function () {
    // Both cases changed when this moved out of BolPayloadBuilder: members were
    // untrimmed, and a nested array used to become the literal string "Array".
    $s = multiValueSplitter();

    expect($s->split([' Beige ', 'Wit']))->toBe(['Beige', 'Wit'])
        ->and($s->split(['Beige', ['x']]))->toBe(['Beige'])
        ->and($s->split(['Beige', null, '', 'Wit']))->toBe(['Beige', 'Wit']);
});

it('returns nothing for values that carry no labels', function () {
    $s = multiValueSplitter();

    expect($s->split(''))->toBe([])
        ->and($s->split(null))->toBe([])
        ->and($s->split([]))->toBe([])
        ->and($s->split(new stdClass()))->toBe([]);
});
