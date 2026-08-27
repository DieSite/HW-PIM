<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiDescriptionRunRequest extends FormRequest
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
        $fields = array_keys((array) config('ai.fields'));

        return [
            'fields'     => ['required', 'array', 'min:1'],
            'fields.*'   => ['string', Rule::in($fields)],
            'sync_woo'   => ['nullable', 'boolean'],

            'brand'      => ['nullable', 'string'],
            'collection' => ['nullable', 'string'],
            'scope'      => ['nullable', Rule::in(['all', 'duplicates', 'empty'])],
            'skus'       => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fields.required' => 'Kies minstens één tekstveld om te laten schrijven.',
        ];
    }

    /**
     * @return array{brand?:string, collection?:string, scope?:string, skus?:string}
     */
    public function filters(): array
    {
        return array_filter([
            'brand'      => $this->input('brand'),
            'collection' => $this->input('collection'),
            'scope'      => $this->input('scope'),
            'skus'       => $this->input('skus'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        /** @var list<string> $fields */
        $fields = $this->input('fields', []);

        return array_values($fields);
    }
}
