<?php

namespace Webkul\WooCommerce\DTO;

readonly class ProductBatch
{
    public string $code;

    public string $type;

    public function __construct(
        public string $sku,
        public ?int $parentId,
        public array $variants,
        private array $raw,
    ) {
        $this->code = $sku;
        $this->type = empty($variants) ? 'simple' : 'variable';
    }

    public static function fromProductArray(array $data): self
    {
        return new self(
            sku: $data['sku'],
            parentId: $data['parent_id'] ?? null,
            variants: $data['variants'] ?? [],
            raw: $data,
        );
    }

    public function toArray(): array
    {
        return array_merge($this->raw, [
            'code' => $this->code,
            'type' => $this->type,
        ]);
    }
}