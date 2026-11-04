<?php

namespace App\Http\Requests;

use App\Services\CompetitorDiscountOverview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompetitorPricesFilterRequest extends FormRequest
{
    public const PAGE_SIZES = [25, 50, 100];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'korting'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'merk'        => ['nullable', 'string', 'max:191'],
            'winkel'      => ['nullable', 'string', 'max:191'],
            'zoek'        => ['nullable', 'string', 'max:191'],
            'per_pagina'  => ['nullable', 'integer', Rule::in(self::PAGE_SIZES)],
            'uitgesloten' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'korting.numeric' => 'Vul bij korting een getal in.',
            'korting.min'     => 'De korting kan niet negatief zijn.',
            'korting.max'     => 'De korting kan niet meer dan 100% zijn.',
            'per_pagina.in'   => 'Kies 25, 50 of 100 kleden per pagina.',
        ];
    }

    /**
     * @return array{min_discount: float, excluded_only: bool, brand: ?string, search: ?string, shop: ?string}
     */
    public function filters(): array
    {
        $discount = $this->validated('korting');

        return [
            'min_discount'  => $discount === null || $discount === '' ? CompetitorDiscountOverview::DEFAULT_MIN_DISCOUNT : (float) $discount,
            'excluded_only' => (bool) $this->validated('uitgesloten'),
            'brand'         => $this->validated('merk'),
            'search'        => $this->validated('zoek'),
            'shop'          => $this->validated('winkel'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_pagina') ?? self::PAGE_SIZES[0]);
    }
}
