<?php

namespace Webkul\WooCommerce\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\WooCommerce\Contracts\Mapping;
use Webkul\WooCommerce\Helpers\AttributeMappingHelper;

class AttributeMappingRepository extends Repository
{
    public const STANDARD_ATTRIBUTE_SECTION = 'settings';

    public const ADDITIONAL_ATTRIBUTE_SECTION = 'additional_attributes';

    public const MAPPING_SECTION = 'section';

    public const MAPPING_KEY = 'custom_field';

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

    public function saveAdditionalField($credentialId, array $additionalAttributes, string $section = self::ADDITIONAL_ATTRIBUTE_SECTION)
    {
        $extras = $this->select('extras')->where('id', $credentialId)->get()->toArray()[0]['extras'];
        $mappedValue = $this->getAdditionalFieldMapping($credentialId);
        $mappedValue[] = $additionalAttributes;
        $extras[$section] = $mappedValue;

        $this->where('id', $credentialId)->update(['extras' => $extras]);
    }

    public function mapAdditionalField(string $code, string $section = self::STANDARD_ATTRIBUTE_SECTION)
    {
        $fieldValues = $this->getStandardMapping($section);
        $fieldValues[$code] = $code;
        $this->saveStandardAttributes($fieldValues, $section);
    }

    public function saveStandardAttributes(array $data, string $section = self::STANDARD_ATTRIBUTE_SECTION)
    {
        $data = ['mapping' => $data];
        $this->where(self::MAPPING_SECTION, $section)->update($data);
    }

    public function removeAdditionalField(string $code, $credentialId): void
    {
        $extras = $this->select('extras')->where('id', $credentialId)->get()->toArray()[0]['extras'];
        $mappedValues = $this->getAdditionalFieldMapping($credentialId);
        if (empty($mappedValues)) {
            return;
        }

        $mappedValues = array_values(array_filter($mappedValues, fn ($item) => $item['name'] !== $code));
        $extras['additional_attributes'] = $mappedValues;

        // Remove from settings if it exists
        if (isset($extras['settings'][$code])) {
            unset($extras['settings'][$code]);
        }

        // Remove from defaults if it exists
        if (isset($extras['defaults'][$code])) {
            unset($extras['defaults'][$code]);
        }

        $this->where('id', $credentialId)->update(['extras' => $extras]);
    }

    public function removeStandardField(string $code, $section = self::STANDARD_ATTRIBUTE_SECTION): void
    {
        $mappedValues = $this->getStandardMapping($section);

        if (empty($mappedValues)) {
            return;
        }

        unset($mappedValues[$code]);
        $this->saveStandardAttributes($mappedValues, $section);
    }

    public function isDuplicateAdditionalAttribute(string $code, $credentialId, string $standard = self::STANDARD_ATTRIBUTE_SECTION, string $additional = self::ADDITIONAL_ATTRIBUTE_SECTION): bool
    {
        $additionalfields = $this->getAdditionalFieldMapping($credentialId);
        $standardFields = $this->getStandardFields($standard);
        $mappedFields = array_merge($additionalfields, $standardFields);

        if (empty($mappedFields)) {
            return false;
        }

        foreach ($mappedFields as $mappedValue) {
            if (isset($mappedValue['name']) && $mappedValue['name'] === $code) {
                return true;
            }
        }

        return false;
    }

    public function isInvalidAdditionalAttribute(string $code): bool
    {
        if (preg_match('/[^\w]/', $code) === 1) {
            return true;
        }

        return false;
    }

    public function getAdditionalFieldMapping($credentialId, string $section = self::ADDITIONAL_ATTRIBUTE_SECTION): array
    {
        $mapping = $this->select('extras')->where('id', $credentialId)->get()->toArray();

        $mapping = isset($mapping[0]['extras']) && ! empty($mapping[0]['extras'][$section]) ? $mapping[0]['extras'][$section] : [];

        return $mapping;
    }
}
