<?php

namespace Webkul\WooCommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\WooCommerce\Repositories\AttributeMappingRepository;
use Webkul\WooCommerce\Rules\DuplicateAdditionalAttribute;
use Webkul\WooCommerce\Rules\InvalidAdditionalAttribute;

class AdditionalAttributeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules()
    {
        return [
            'code' => [
                'required',
                'string',
                new DuplicateAdditionalAttribute(app(AttributeMappingRepository::class), $this->credentialId),
                new InvalidAdditionalAttribute(app(AttributeMappingRepository::class)),
            ],
        ];
    }
}
