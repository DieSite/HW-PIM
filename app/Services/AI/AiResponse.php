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
     * when asked not to, so the fence is stripped before decoding. They also
     * sometimes emit raw newlines or tabs inside string values, which strict
     * JSON forbids; those are escaped and the decode is retried once.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $text = trim($this->text);
        $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text));

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if ($exception->getCode() !== JSON_ERROR_CTRL_CHAR) {
                throw new RuntimeException("Model gaf geen geldige JSON terug: {$exception->getMessage()}");
            }

            try {
                $decoded = json_decode($this->escapeControlCharactersInStrings($text), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $retryException) {
                throw new RuntimeException("Model gaf geen geldige JSON terug: {$retryException->getMessage()}");
            }
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Model gaf geen JSON-object terug.');
        }

        return $decoded;
    }

    /**
     * Escapes raw control characters (U+0000–U+001F) that appear inside JSON
     * string literals, leaving whitespace between tokens untouched.
     */
    private function escapeControlCharactersInStrings(string $json): string
    {
        $escaped = '';
        $inString = false;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if (! $inString) {
                $inString = $char === '"';
                $escaped .= $char;

                continue;
            }

            if ($char === '\\' && $i + 1 < $length) {
                $escaped .= $char.$json[++$i];

                continue;
            }

            if ($char === '"') {
                $inString = false;
                $escaped .= $char;

                continue;
            }

            $escaped .= match (true) {
                $char === "\n"   => '\n',
                $char === "\r"   => '\r',
                $char === "\t"   => '\t',
                ord($char) < 0x20 => sprintf('\u%04x', ord($char)),
                default          => $char,
            };
        }

        return $escaped;
    }
}
