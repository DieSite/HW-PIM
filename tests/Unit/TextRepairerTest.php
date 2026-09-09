<?php

use App\Services\AI\TextRepairer;

/**
 * Every mangled spelling below was taken from ai_description_drafts.fields, not
 * invented: the model wrote them in a real run.
 */
beforeEach(function () {
    $this->repairer = new TextRepairer();
});

it('repairs the mangled spellings the model produced', function (string $mangled, string $expected) {
    expect($this->repairer->repair($mangled))->toBe($expected);
})->with([
    ['Het kleed heeft een gem#leerd oppervlak.', 'Het kleed heeft een gemêleerd oppervlak.'],
    ['Het gem#leerde dessin combineert.', 'Het gemêleerde dessin combineert.'],
    ['Een gem6leerd vlak.', 'Een gemêleerd vlak.'],
    ["Zijn gem'eleerde tinten.", 'Zijn gemêleerde tinten.'],
    ['Het gem#eleerde vlak.', 'Het gemêleerde vlak.'],
    ['Je ziet het fijne reli#f van de draden.', 'Je ziet het fijne reliëf van de draden.'],
    ["Het reli'ef is subtiel.", 'Het reliëf is subtiel.'],
    ['Een cr#me ondertoon.', 'Een crème ondertoon.'],
]);

it('decodes accented HTML entities the model falls back to', function () {
    expect($this->repairer->repair('<p>Een gem&ecirc;leerde vloer met reli&euml;f.</p>'))
        ->toBe('<p>Een gemêleerde vloer met reliëf.</p>');
});

it('leaves structural entities alone', function () {
    /** These are legitimate in the HTML these texts become. */
    expect($this->repairer->repair('<p>Wol &amp; katoen&nbsp;&mdash; 170 &times; 240 &lt;p&gt;</p>'))
        ->toBe('<p>Wol &amp; katoen&nbsp;&mdash; 170 &times; 240 &lt;p&gt;</p>');
});

it('keeps the capital when a repaired word opens a sentence', function () {
    expect($this->repairer->repair('Gem#leerd wol geeft diepte.'))->toBe('Gemêleerd wol geeft diepte.');
});

it('reports words it could not repair', function () {
    expect($this->repairer->garbledWords('Een fraai dess#in met kle6ren.'))
        ->toEqualCanonicalizing(['dess#in', 'kle6ren']);
});

it('reports nothing once a known word is repaired', function () {
    expect($this->repairer->garbledWords($this->repairer->repair('Een gem#leerd vlak met reli#f.')))->toBe([]);
});

it('does not mistake Dutch apostrophes for mangled accents', function (string $text) {
    expect($this->repairer->garbledWords($text))->toBe([]);
})->with([
    ["Op de luchtfoto's is het patroon te zien."],
    ["Twee auto's passen op het kleed."],
    ["'s Morgens valt er licht op."],
    ["Z'n structuur is grof."],
]);

it('does not flag ordinary text', function () {
    $text = 'Dit wollen vloerkleed van 170 x 240 cm heeft een dichte pool en een warme, gemêleerde kleur.';

    expect($this->repairer->garbledWords($text))->toBe([])
        ->and($this->repairer->repair($text))->toBe($text);
});
