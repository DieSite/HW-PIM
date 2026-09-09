<?php

use App\Models\AiDescriptionDraft;

function makeMangledDraft(array $fields, string $status = AiDescriptionDraft::STATUS_PENDING): AiDescriptionDraft
{
    return AiDescriptionDraft::create([
        'product_id' => 999999,
        'status'     => $status,
        'fields'     => $fields,
    ]);
}

it('repairs the mangled accents in existing drafts', function () {
    $draft = makeMangledDraft([
        'beschrijving_l'    => '<p>Het gem#leerde vlak toont een fijn reli#f.</p>',
        'meta_beschrijving' => '<p>Een gem&ecirc;leerd dessin.</p>',
    ]);

    $this->artisan('ai:repair-draft-texts')->assertSuccessful();

    $draft->refresh();

    expect($draft->fields['beschrijving_l'])->toBe('<p>Het gemêleerde vlak toont een fijn reliëf.</p>')
        ->and($draft->fields['meta_beschrijving'])->toBe('<p>Een gemêleerd dessin.</p>');
});

it('writes nothing on a dry run', function () {
    $draft = makeMangledDraft(['beschrijving_l' => '<p>Een gem#leerd vlak.</p>']);

    $this->artisan('ai:repair-draft-texts', ['--dry-run' => true])->assertSuccessful();

    expect($draft->refresh()->fields['beschrijving_l'])->toBe('<p>Een gem#leerd vlak.</p>');
});

it('reports a word that is not in the repair list instead of guessing at it', function () {
    makeMangledDraft(['beschrijving_l' => '<p>Een fraai dess#in.</p>']);

    $this->artisan('ai:repair-draft-texts')
        ->expectsOutputToContain('dess#in')
        ->assertSuccessful();
});

it('warns that a published draft left the mangled text on the product', function () {
    makeMangledDraft(['beschrijving_l' => '<p>Een gem#leerd vlak.</p>'], AiDescriptionDraft::STATUS_APPLIED);

    $this->artisan('ai:repair-draft-texts')
        ->expectsOutputToContain('already published')
        ->assertSuccessful();
});

it('leaves a clean draft untouched', function () {
    $draft = makeMangledDraft(['beschrijving_l' => '<p>Een gemêleerd vlak met reliëf.</p>']);
    $updatedAt = $draft->updated_at;

    $this->artisan('ai:repair-draft-texts')->assertSuccessful();

    expect($draft->refresh()->fields['beschrijving_l'])->toBe('<p>Een gemêleerd vlak met reliëf.</p>')
        ->and($draft->updated_at->eq($updatedAt))->toBeTrue();
});
