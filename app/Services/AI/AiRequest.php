<?php

namespace App\Services\AI;

/**
 * A single provider-agnostic completion request.
 *
 * Images are carried as raw bytes plus a mime type so each driver can encode
 * them the way its own API wants (Gemini inline_data, OpenAI image_url, ...).
 *
 * @phpstan-type ImagePart array{bytes:string, mime:string}
 */
class AiRequest
{
    /**
     * @param  array<string, mixed>|null  $jsonSchema  Response schema when the driver can enforce structured output.
     * @param  list<ImagePart>  $images
     */
    public function __construct(
        public readonly string $systemInstruction,
        public readonly string $prompt,
        public readonly array $images = [],
        public readonly ?array $jsonSchema = null,
    ) {}

    /**
     * @param  ImagePart  $image
     */
    public function withImage(array $image): self
    {
        return new self(
            $this->systemInstruction,
            $this->prompt,
            [...$this->images, $image],
            $this->jsonSchema,
        );
    }

    public function withPrompt(string $prompt): self
    {
        return new self($this->systemInstruction, $prompt, $this->images, $this->jsonSchema);
    }

    /**
     * A narrower follow-up request: a new prompt, a schema covering only the
     * fields still being asked for, and the images dropped when none of those
     * fields needs to look at the product.
     *
     * @param  array<string, mixed>|null  $jsonSchema
     */
    public function retryWith(string $prompt, ?array $jsonSchema, bool $keepImages): self
    {
        return new self(
            $this->systemInstruction,
            $prompt,
            $keepImages ? $this->images : [],
            $jsonSchema,
        );
    }
}
