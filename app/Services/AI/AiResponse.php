<?php

namespace App\Services\AI;

use JsonException;
use RuntimeException;

/**
 * The raw text a provider returned, plus what it cost.
 */
class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    /**
     * Decode the response as a JSON object.
     *
     * Providers occasionally wrap structured output in a markdown fence even
     * when asked not to, so the fence is stripped before decoding.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $text = trim($this->text);
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);

        try {
            $decoded = json_decode(trim($text), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Model gaf geen geldige JSON terug: {$exception->getMessage()}");
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Model gaf geen JSON-object terug.');
        }

        return $decoded;
    }
}
