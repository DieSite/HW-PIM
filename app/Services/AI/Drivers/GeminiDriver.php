<?php

namespace App\Services\AI\Drivers;

use App\Services\AI\AiRequest;
use App\Services\AI\AiResponse;
use App\Services\AI\AiTextClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Gemini via the generateContent REST endpoint.
 *
 * Plain HTTP rather than an SDK, matching PhotoroomService and BolApiClient —
 * it keeps the dependency list untouched and the request shape visible.
 *
 * @phpstan-type Config array{api_key:?string, base_url:string, model:string}
 */
class GeminiDriver implements AiTextClient
{
    /**
     * @param  Config  $config
     * @param  array{timeout:int, max_tokens:int, temperature:float, retries:int}  $request
     */
    public function __construct(
        private readonly array $config,
        private readonly array $request,
    ) {}

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function complete(AiRequest $aiRequest): AiResponse
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');

        if ($apiKey === '') {
            throw new RuntimeException('Geen Gemini API-sleutel ingesteld (GEMINI_API_KEY of admin Configuratie).');
        }

        $url = rtrim((string) $this->config['base_url'], '/')."/models/{$this->model()}:generateContent";

        /** @var Response $response */
        $response = Http::timeout($this->request['timeout'])
            ->retry($this->request['retries'], 2000, throw: false)
            ->withHeader('x-goog-api-key', $apiKey)
            ->asJson()
            ->post($url, $this->payload($aiRequest));

        if (! $response->successful()) {
            throw new RuntimeException(
                "Gemini API-fout [{$response->status()}]: ".$this->errorMessage($response)
            );
        }

        return $this->toResponse($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiRequest $aiRequest): array
    {
        $parts = [['text' => $aiRequest->prompt]];

        foreach ($aiRequest->images as $image) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $image['mime'],
                    'data'      => base64_encode($image['bytes']),
                ],
            ];
        }

        $generationConfig = [
            'temperature'     => $this->request['temperature'],
            'maxOutputTokens' => $this->request['max_tokens'],
        ];

        if ($aiRequest->jsonSchema !== null) {
            $generationConfig['responseMimeType'] = 'application/json';
            $generationConfig['responseSchema'] = $aiRequest->jsonSchema;
        }

        return [
            'systemInstruction' => ['parts' => [['text' => $aiRequest->systemInstruction]]],
            'contents'          => [['role' => 'user', 'parts' => $parts]],
            'generationConfig'  => $generationConfig,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function toResponse(?array $body): AiResponse
    {
        $candidate = Arr::get($body ?? [], 'candidates.0');

        if ($candidate === null) {
            $blockReason = Arr::get($body ?? [], 'promptFeedback.blockReason');

            throw new RuntimeException(
                $blockReason
                    ? "Gemini weigerde het verzoek ({$blockReason})."
                    : 'Gemini gaf geen antwoord terug.'
            );
        }

        $text = collect(Arr::get($candidate, 'content.parts', []))
            ->pluck('text')
            ->filter(fn ($part): bool => is_string($part) && $part !== '')
            ->implode('');

        if (trim($text) === '') {
            $finishReason = (string) Arr::get($candidate, 'finishReason', 'onbekend');

            throw new RuntimeException("Gemini gaf een lege tekst terug (finishReason: {$finishReason}).");
        }

        return new AiResponse(
            text: $text,
            model: $this->model(),
            inputTokens: (int) Arr::get($body ?? [], 'usageMetadata.promptTokenCount', 0),
            outputTokens: (int) Arr::get($body ?? [], 'usageMetadata.candidatesTokenCount', 0),
        );
    }

    private function errorMessage(Response $response): string
    {
        $message = Arr::get($response->json() ?? [], 'error.message');

        return is_string($message) && $message !== ''
            ? $message
            : mb_substr($response->body(), 0, 500);
    }
}
