<?php

use App\Services\AI\AiRequest;
use App\Services\AI\Drivers\GeminiDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function geminiDriver(): GeminiDriver
{
    return new GeminiDriver(
        ['api_key' => 'test-key', 'base_url' => 'https://example.test/v1beta', 'model' => 'gemini-3.7-flash'],
        ['timeout' => 10, 'max_tokens' => 1024, 'temperature' => 1.0, 'retries' => 1],
    );
}

/**
 * @param  array<string, mixed>  $body
 */
function geminiReply(array $body): void
{
    Http::fake(['*' => Http::response($body)]);
}

it('sends the house style as a system instruction and the brief as user content', function () {
    geminiReply([
        'candidates'    => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
        'usageMetadata' => ['promptTokenCount' => 120, 'candidatesTokenCount' => 40],
    ]);

    geminiDriver()->complete(new AiRequest('Huisstijl', 'Productgegevens'));

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->url() === 'https://example.test/v1beta/models/gemini-3.7-flash:generateContent'
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && $payload['systemInstruction']['parts'][0]['text'] === 'Huisstijl'
            && $payload['contents'][0]['parts'][0]['text'] === 'Productgegevens';
    });
});

it('attaches the product photo as inline data', function () {
    geminiReply(['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]]);

    geminiDriver()->complete(new AiRequest('Huisstijl', 'Brief', [['bytes' => 'RAWJPEG', 'mime' => 'image/jpeg']]));

    Http::assertSent(function (Request $request): bool {
        $image = $request->data()['contents'][0]['parts'][1]['inline_data'];

        return $image['mime_type'] === 'image/jpeg' && base64_decode($image['data']) === 'RAWJPEG';
    });
});

it('asks for structured json when a schema is given', function () {
    geminiReply(['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]]);

    $schema = ['type' => 'object', 'properties' => ['beschrijving_l' => ['type' => 'string']]];

    geminiDriver()->complete(new AiRequest('Huisstijl', 'Brief', [], $schema));

    Http::assertSent(function (Request $request) use ($schema): bool {
        $config = $request->data()['generationConfig'];

        return $config['responseMimeType'] === 'application/json' && $config['responseSchema'] === $schema;
    });
});

it('reports token usage back', function () {
    geminiReply([
        'candidates'    => [['content' => ['parts' => [['text' => 'antwoord']]]]],
        'usageMetadata' => ['promptTokenCount' => 3400, 'candidatesTokenCount' => 900],
    ]);

    $response = geminiDriver()->complete(new AiRequest('Huisstijl', 'Brief'));

    expect($response->inputTokens)->toBe(3400)
        ->and($response->outputTokens)->toBe(900)
        ->and($response->model)->toBe('gemini-3.7-flash');
});

it('joins a reply that arrives in several parts', function () {
    geminiReply(['candidates' => [['content' => ['parts' => [['text' => '{"a":'], ['text' => '1}']]]]]]);

    expect(geminiDriver()->complete(new AiRequest('Huisstijl', 'Brief'))->json())->toBe(['a' => 1]);
});

it('strips a markdown fence the model added around its json', function () {
    geminiReply(['candidates' => [['content' => ['parts' => [['text' => "```json\n{\"a\":1}\n```"]]]]]]);

    expect(geminiDriver()->complete(new AiRequest('Huisstijl', 'Brief'))->json())->toBe(['a' => 1]);
});

it('surfaces the provider error message rather than a bare status code', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid.']], 400)]);

    expect(fn () => geminiDriver()->complete(new AiRequest('Huisstijl', 'Brief')))
        ->toThrow(RuntimeException::class, 'API key not valid.');
});

it('explains a blocked prompt instead of failing on a missing candidate', function () {
    geminiReply(['promptFeedback' => ['blockReason' => 'SAFETY']]);

    expect(fn () => geminiDriver()->complete(new AiRequest('Huisstijl', 'Brief')))
        ->toThrow(RuntimeException::class, 'SAFETY');
});

it('names the finish reason when the reply comes back empty', function () {
    geminiReply(['candidates' => [['content' => ['parts' => []], 'finishReason' => 'MAX_TOKENS']]]);

    expect(fn () => geminiDriver()->complete(new AiRequest('Huisstijl', 'Brief')))
        ->toThrow(RuntimeException::class, 'MAX_TOKENS');
});

it('refuses to call out without an api key', function () {
    Http::fake();

    $driver = new GeminiDriver(
        ['api_key' => null, 'base_url' => 'https://example.test/v1beta', 'model' => 'gemini-3.7-flash'],
        ['timeout' => 10, 'max_tokens' => 1024, 'temperature' => 1.0, 'retries' => 1],
    );

    expect(fn () => $driver->complete(new AiRequest('Huisstijl', 'Brief')))
        ->toThrow(RuntimeException::class, 'Geen Gemini API-sleutel');

    Http::assertNothingSent();
});
