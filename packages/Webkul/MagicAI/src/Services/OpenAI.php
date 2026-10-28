<?php

namespace Webkul\MagicAI\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class OpenAI
{
    /**
     * OpenAI REST API base URL.
     */
    protected const BASE_URL = 'https://api.openai.com/v1';

    /**
     * New service instance.
     */
    public function __construct(
        protected string $model,
        protected string $prompt,
        protected float $temperature,
        protected bool $stream = false
    ) {}

    /**
     * Set LLM prompt text.
     */
    public function ask(): string
    {
        $result = $this->client()->post('chat/completions', [
            'model'       => $this->model,
            'temperature' => $this->temperature,
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => $this->prompt,
                ],
            ],
        ])->throw();

        return (string) $result->json('choices.0.message.content');
    }

    /**
     * Generate image.
     */
    public function images(array $options): array
    {
        $result = $this->client()->post('images/generations', [
            'model'           => $this->model,
            'prompt'          => $this->prompt,
            'n'               => intval($options['n'] ?? 1),
            'size'            => $options['size'],
            'quality'         => $options['quality'] ?? 'standard',
            'response_format' => 'b64_json',
        ])->throw();

        $images = [];

        foreach ($result->json('data', []) as $image) {
            $images[]['url'] = 'data:image/png;base64,'.$image['b64_json'];
        }

        return $images;
    }

    /**
     * HTTP client authenticated with the Magic AI credentials from the admin configuration.
     */
    protected function client(): PendingRequest
    {
        $organization = core()->getConfigData('general.magic_ai.settings.organization');

        return Http::baseUrl(self::BASE_URL)
            ->timeout(30)
            ->withToken((string) core()->getConfigData('general.magic_ai.settings.api_key'))
            ->when($organization, fn (PendingRequest $request): PendingRequest => $request->withHeaders([
                'OpenAI-Organization' => $organization,
            ]))
            ->asJson();
    }
}
