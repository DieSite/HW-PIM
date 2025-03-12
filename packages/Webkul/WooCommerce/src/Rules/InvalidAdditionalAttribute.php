<?php

namespace Webkul\WooCommerce\Rules;

use Illuminate\Contracts\Validation\Rule;
use Webkul\WooCommerce\Repositories\AttributeMappingRepository;

class InvalidAdditionalAttribute implements Rule
{
    public function __construct(
        protected AttributeMappingRepository $attributeMappingRepository
    ) {}

    public function passes($attribute, $value)
    {
        return ! $this->attributeMappingRepository->isInvalidAdditionalAttribute($value);
    }

    public function message()
    {
        return trans('Special characters & spaces are not allowed');
    }
}
