<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryTimesRequest extends FormRequest
{
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
            'showroom'             => ['nullable', 'string', 'max:100'],
            'rules'                => ['array'],
            'rules.*.brand'        => ['nullable', 'string', 'max:191', 'distinct'],
            'rules.*.in_stock'     => ['nullable', 'string', 'max:100'],
            'rules.*.out_of_stock' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rules.*.brand.distinct' => 'Het merk ":input" staat er twee keer in.',
            'max'                    => 'Een levertijd mag maximaal :max tekens zijn.',
        ];
    }
}
