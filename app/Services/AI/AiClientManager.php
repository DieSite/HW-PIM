<?php

namespace App\Services\AI;

use App\Services\AI\Drivers\GeminiDriver;
use App\Services\AI\Drivers\OpenAiDriver;
use InvalidArgumentException;

/**
 * Builds the configured AI client. Gemini is the default driver.
 */
class AiClientManager
{
    public function __construct(private readonly AiSettings $settings) {}

    public function client(?string $driver = null): AiTextClient
    {
        $driver ??= $this->settings->driver();

        /** @var array{timeout:int, max_tokens:int, temperature:float, retries:int} $request */
        $request = config('ai.request');

        $config = $driver === $this->settings->driver()
            ? $this->settings->driverConfig()
            : (array) config("ai.drivers.{$driver}", []);

        return match ($driver) {
            'gemini' => new GeminiDriver($config, $request),
            'openai' => new OpenAiDriver($config, $request),
            default  => throw new InvalidArgumentException("Onbekende AI-driver: {$driver}"),
        };
    }
}
