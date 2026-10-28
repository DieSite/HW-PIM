<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Webkul\MagicAI\Services\OpenAI;

/**
 * Magic AI talks to the OpenAI REST API through Laravel's HTTP client, so the
 * credentials from the admin Configuration are swapped in on the shared core
 * singleton instead of being written to a config file.
 */
beforeEach(function () {
    $core = Mockery::mock(app('core'))->makePartial();
    $core->shouldReceive('getConfigData')->with('general.magic_ai.settings.api_key')->andReturn('sk-test');
    $core->shouldReceive('getConfigData')->with('general.magic_ai.settings.organization')->andReturn('org-test');

    app()->instance('core', $core);
});

it('asks the chat completions endpoint with the configured credentials', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'Een zacht hoogpolig vloerkleed.']]],
        ]),
    ]);

    $answer = (new OpenAI('gpt-4o-mini', 'Beschrijf dit kleed', 0.7))->ask();

    expect($answer)->toBe('Een zacht hoogpolig vloerkleed.');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-test')
        && $request->hasHeader('OpenAI-Organization', 'org-test')
        && $request['model'] === 'gpt-4o-mini'
        && $request['temperature'] === 0.7
        && $request['messages'] === [['role' => 'user', 'content' => 'Beschrijf dit kleed']]);
});

it('returns generated images as base64 data urls', function () {
    Http::fake([
        'api.openai.com/v1/images/generations' => Http::response([
            'data' => [['b64_json' => 'AAA'], ['b64_json' => 'BBB']],
        ]),
    ]);

    $images = (new OpenAI('dall-e-2', 'Een rond kleed', 0.7))->images(['n' => '2', 'size' => '1024x1024']);

    expect($images)->toBe([
        ['url' => 'data:image/png;base64,AAA'],
        ['url' => 'data:image/png;base64,BBB'],
    ]);

    Http::assertSent(fn (Request $request): bool => $request['n'] === 2
        && $request['quality'] === 'standard'
        && $request['response_format'] === 'b64_json');
});

it('throws when the API returns an error so the controller can report it', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
    ]);

    (new OpenAI('gpt-4o-mini', 'Beschrijf dit kleed', 0.7))->ask();
})->throws(RequestException::class);
