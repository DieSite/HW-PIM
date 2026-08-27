<?php

use App\Services\AI\DescriptionValidator;

beforeEach(function () {
    $this->validator = new DescriptionValidator();
});

it('accepts a text that only mentions sizes the product actually has', function () {
    $problems = $this->validator->inventedSizes(
        'Verkrijgbaar in 170 cm x 240 cm en 200 cm x 300 cm.',
        ['170 cm x 240 cm', '200 cm x 300 cm'],
    );

    expect($problems)->toBe([]);
});

it('flags a size the product does not have', function () {
    $invented = $this->validator->inventedSizes(
        'Verkrijgbaar in 170 cm x 240 cm en 999 cm x 111 cm.',
        ['170 cm x 240 cm'],
    );

    expect($invented)->toHaveCount(1)
        ->and($invented[0])->toContain('999');
});

it('matches sizes regardless of how they are written', function () {
    expect($this->validator->inventedSizes('Ook in 170x240 leverbaar.', ['170 cm x 240 cm']))->toBe([])
        ->and($this->validator->inventedSizes('Ook in 170 × 240 cm leverbaar.', ['170 cm x 240 cm']))->toBe([]);
});

it('rejects a generated text that invents a size', function () {
    $problems = $this->validator->validate(
        ['beschrijving_k' => '<p>'.str_repeat('De standaardmaten zijn 400 cm x 600 cm. ', 10).'</p>'],
        ['170 cm x 240 cm'],
    );

    expect(collect($problems)->pluck('rule'))->toContain('invented_size');
});

it('rejects a text that is too short', function () {
    $problems = $this->validator->validate(['beschrijving_l' => '<p>Een kleed.</p>'], []);

    expect(collect($problems)->pluck('rule'))->toContain('too_short');
});

it('rejects a text that uses a banned phrase', function () {
    $text = '<p>'.str_repeat('Dit vloerkleed is een ware blikvanger in elke kamer. ', 12).'</p>';

    $problems = $this->validator->validate(['beschrijving_l' => $text], [], ['ware blikvanger']);

    expect(collect($problems)->pluck('rule'))->toContain('banned_phrase');
});

it('scores an identical text as fully similar and an unrelated one as not', function () {
    $text = 'Dit handgeweven vloerkleed van wol is gemaakt in Marokko en heeft een donkere ketting.';

    expect($this->validator->similarity($text, $text))->toBe(1.0)
        ->and($this->validator->similarity($text, 'Een compleet ander onderwerp met totaal andere woorden erin.'))
        ->toBeLessThan(0.1);
});

it('reports the closest sibling when comparing against several', function () {
    $text = 'Dit handgeweven vloerkleed van wol is gemaakt in Marokko en heeft een donkere ketting.';

    $similarity = $this->validator->maxSimilarity($text, [
        'Iets heel anders over een tafel in een showroom ergens.',
        $text,
    ]);

    expect($similarity)->toBe(1.0);
});

it('wraps bare text in a paragraph but leaves existing html alone', function () {
    expect($this->validator->normaliseHtml('Een nuchtere zin.'))->toBe('<p>Een nuchtere zin.</p>')
        ->and($this->validator->normaliseHtml('<p>Al opgemaakt.</p>'))->toBe('<p>Al opgemaakt.</p>');
});

it('splits blank-line separated text into separate paragraphs', function () {
    expect($this->validator->normaliseHtml("Eerste alinea.\n\nTweede alinea."))
        ->toBe('<p>Eerste alinea.</p><p>Tweede alinea.</p>');
});

it('measures length on the plain text, not on the markup', function () {
    expect($this->validator->plain('<p>Twee<br>woorden</p>'))->toBe('Twee woorden');
});
