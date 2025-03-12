<?php

namespace Webkul\WooCommerce\Rules;

use Illuminate\Contracts\Validation\Rule;
use Webkul\WooCommerce\Repositories\AttributeMappingRepository;

class DuplicateAdditionalAttribute implements Rule
{
    protected ?int $credentialId;

    public function __construct(
        protected AttributeMappingRepository $attributeMappingRepository,
        ?int $credentialId = null
    ) {
        $this->credentialId = $credentialId;
    }

    public function passes($attribute, $value)
    {
        return ! $this->attributeMappingRepository->isDuplicateAdditionalAttribute($value, $this->credentialId);
    }

    public function message()
    {
        return trans('This attribute is already added.');
    }
}
