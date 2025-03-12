<?php

namespace Webkul\WooCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StandardAttributeRequest extends FormRequest
{
    /**
     * Authorize the request (always return true if no authorization needed).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Define validation rules.
     */
    public function rules(): array
    {
        return [
            'slug' => 'required|string|max:255',
            'sku'  => 'required|string|max:255',
        ];
    }

    /**
     * Customize error messages.
     */
    public function messages(): array
    {
        return [
            'slug.required' => 'The slug field is required.',
            'sku.required'  => 'The SKU field is required.',
        ];
    }
}
