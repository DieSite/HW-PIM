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
 * OpenAI chat completions, as the alternative to the default Gemini driver.
 *
 * Deliberately not routed through Webkul's MagicAI package: that one is
 * hardcoded to gpt-3.5-turbo and silently forwards every other model name to an
 * Ollama host, which would fail in a way that looks like a model problem.
 *
 * @phpstan-type Config array{api_key:?string, model:string}
 */
class OpenAiDriver implements AiTextClient
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
            throw new RuntimeException('Geen OpenAI API-sleutel ingesteld (OPENAI_API_KEY of admin Configuratie).');
        }

        /** @var Response $response */
        $response = Http::timeout($this->request['timeout'])
            ->retry($this->request['retries'], 2000, throw: false)
            ->withToken($apiKey)
            ->asJson()
            ->post('https://api.openai.com/v1/chat/completions', $this->payload($aiRequest));

        if (! $response->successful()) {
            throw new RuntimeException(
                "OpenAI API-fout [{$response->status()}]: ".$this->errorMessage($response)
            );
        }

        $body = $response->json();
        $text = (string) Arr::get($body ?? [], 'choices.0.message.content', '');

        if (trim($text) === '') {
            throw new RuntimeException('OpenAI gaf een lege tekst terug.');
        }

        return new AiResponse(
            text: $text,
            model: $this->model(),
            inputTokens: (int) Arr::get($body ?? [], 'usage.prompt_tokens', 0),
            outputTokens: (int) Arr::get($body ?? [], 'usage.completion_tokens', 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiRequest $aiRequest): array
    {
        $content = [['type' => 'text', 'text' => $aiRequest->prompt]];

        foreach ($aiRequest->images as $image) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => "data:{$image['mime']};base64,".base64_encode($image['bytes'])],
            ];
        }

        $payload = [
            'model'    => $this->model(),
            'messages' => [
                ['role' => 'system', 'content' => $aiRequest->systemInstruction],
                ['role' => 'user', 'content' => $content],
            ],
            'max_completion_tokens' => $this->request['max_tokens'],
        ];

        if ($aiRequest->jsonSchema !== null) {
            $payload['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'product_texts',
                    'strict' => true,
                    'schema' => $aiRequest->jsonSchema,
                ],
            ];
        }

        return $payload;
    }

    private function errorMessage(Response $response): string
    {
        $message = Arr::get($response->json() ?? [], 'error.message');

        return is_string($message) && $message !== ''
            ? $message
            : mb_substr($response->body(), 0, 500);
    }
}
