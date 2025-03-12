<?php

namespace Webkul\WooCommerce\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\WooCommerce\Contracts\Mapping;
use Webkul\WooCommerce\Helpers\AttributeMappingHelper;

class AttributeMappingRepository extends Repository
{
    const STANDARD_ATTRIBUTE_SECTION = 'settings';

    const ADDITIONAL_ATTRIBUTE_SECTION = 'additional_attributes';

    const MAPPING_SECTION = 'section';

    const MAPPING_KEY = 'custom_field';

    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return Mapping::class;
    }

    public function getStandardFields(string $section): array
    {
        $fields = [];
        switch ($section) {
            case self::STANDARD_ATTRIBUTE_SECTION:
                $fields = AttributeMappingHelper::getStandardFields();
                break;
        }

        return $fields;
    }

    public function getMappings(string $section): array
    {
        $mapping = $this->where(self::MAPPING_SECTION, $section)->get()->toArray();
        $mapping = isset($mapping[0]) && ! empty($mapping[0][self::MAPPING_KEY]) ? $mapping[0][self::MAPPING_KEY] : [];

        return $mapping;
    }

    public function getStandardMapping($credentialId, string $section = self::STANDARD_ATTRIBUTE_SECTION): array
    {
        $mapping = $this->select('extras')->where('id', $credentialId)->get()->toArray();

        $mapping = isset($mapping[0]['extras']) && ! empty($mapping[0]['extras'][self::STANDARD_ATTRIBUTE_SECTION]) ? $mapping[0]['extras'][self::STANDARD_ATTRIBUTE_SECTION] : [];

        return $mapping;
    }

    public function getCustomAttributesMapping($credentialId, string $section = self::ADDITIONAL_ATTRIBUTE_SECTION): array
    {
        $mapping = $this->select('extras')->where('id', $credentialId)->get()->toArray();

        $mapping = isset($mapping[0]['extras']) && ! empty($mapping[0]['extras'][self::MAPPING_KEY]) ? $mapping[0]['extras'][self::MAPPING_KEY] : [];

        return $mapping;
    }
}
