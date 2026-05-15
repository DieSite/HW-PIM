<?php

namespace App\Services\Bol;

class ValidationFailure
{
    public function __construct(
        public readonly string $code,
        public readonly string $field,
        public readonly string $customerMessage,
    ) {}

    public function toArray(): array
    {
        return [
            'code'             => $this->code,
            'field'            => $this->field,
            'customer_message' => $this->customerMessage,
        ];
    }
}
