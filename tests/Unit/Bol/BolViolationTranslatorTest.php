<?php

use App\Services\Bol\BolViolationTranslator;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;

function fakeRequestException(int $status, array $body): RequestException
{
    $factory = new Factory();
    $factory->fake([
        'fake.test/*' => $factory::response($body, $status, ['Content-Type' => 'application/json']),
    ]);

    try {
        $factory->get('https://fake.test/x')->throw();
    } catch (RequestException $e) {
        return $e;
    }

    throw new RuntimeException('Expected exception was not thrown');
}

it('translates an ean violation', function () {
    $exception = fakeRequestException(400, [
        'violations' => [['name' => 'ean', 'reason' => "Request contains invalid value(s): '05715694000315'."]],
    ]);

    $translator = new BolViolationTranslator();

    expect($translator->translate($exception))->toContain('EAN-code');
});

it('translates a 401 auth error', function () {
    $exception = fakeRequestException(401, ['title' => 'Unauthorized']);
    $translator = new BolViolationTranslator();

    expect($translator->translate($exception))->toContain('geautoriseerd');
});

it('translates a 429 rate limit', function () {
    $exception = fakeRequestException(429, ['title' => 'Too Many Requests']);
    $translator = new BolViolationTranslator();

    expect($translator->translate($exception))->toContain('rate limit');
});

it('translates a 500 server error', function () {
    $exception = fakeRequestException(503, ['title' => 'Service Unavailable']);
    $translator = new BolViolationTranslator();

    expect($translator->translate($exception))->toContain('niet bereikbaar');
});

it('translates unknown violations with their raw fields', function () {
    $exception = fakeRequestException(400, [
        'violations' => [['name' => 'weirdField', 'reason' => 'broken']],
    ]);
    $translator = new BolViolationTranslator();

    expect($translator->translate($exception))->toContain('weirdField');
});

it('finds violations nested in previous exceptions', function () {
    $inner = fakeRequestException(400, [
        'violations' => [['name' => 'pricing', 'reason' => 'invalid']],
    ]);

    $outer = new Exception('Bol.com API error', previous: $inner);
    $wrapped = new Exception('Failed to sync with Bol.com', previous: $outer);

    $translator = new BolViolationTranslator();

    expect($translator->translate($wrapped))->toContain('prijs');
});
