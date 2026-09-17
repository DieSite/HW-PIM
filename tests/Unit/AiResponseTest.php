<?php

use App\Services\AI\AiResponse;

it('decodes JSON whose string values contain raw newlines and tabs', function (): void {
    $text = "{\n  \"beschrijving_l\": \"<p>Regel een</p>\n<p>Regel\ttwee</p>\",\n  \"meta_beschrijving\": \"Kort \\\"citaat\\\"\r\nklaar\"\n}";

    $decoded = (new AiResponse($text, 'fake-model'))->json();

    expect($decoded)->toBe([
        'beschrijving_l'    => "<p>Regel een</p>\n<p>Regel\ttwee</p>",
        'meta_beschrijving' => "Kort \"citaat\"\r\nklaar",
    ]);
});

it('decodes fenced JSON with control characters inside strings', function (): void {
    $text = "```json\n{\"beschrijving_k\": \"a\nb\"}\n```";

    expect((new AiResponse($text, 'fake-model'))->json())->toBe(['beschrijving_k' => "a\nb"]);
});

it('still rejects JSON that is otherwise broken', function (): void {
    (new AiResponse("{\"beschrijving_k\": \"a\nb\"", 'fake-model'))->json();
})->throws(RuntimeException::class, 'Model gaf geen geldige JSON terug');
