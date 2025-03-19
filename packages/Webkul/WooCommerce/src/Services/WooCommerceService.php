<?php

namespace Webkul\WooCommerce\Services;

use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\WooCommerce\Http\Client\ApiClient;
use Webkul\WooCommerce\Repositories\AttributeMappingRepository;
use Webkul\WooCommerce\Repositories\CredentialRepository;

class WooCommerceService
{
    public const ATTRIBUTE_MAPPING = 'settings';

    public const DEFAULT_MAPPING = 'defaults';

    public const MEDIA_MAPPING = 'media';

    public const CUSTOM_FIELD_MAPPING = 'custom_field';

    public const TRANSLATIONS_KEY = 'translations';

    public function __construct(
        protected CredentialRepository $credentialRepository,
        protected AttributeMappingRepository $attributeMappingRepository,
        protected AttributeRepository $attributeRepository,
        protected AttributeFamilyRepository $attributeFamilyRepository
    ) {}

    public function getCustomAttributes(int $credentialId): array
    {
        $customAttributes = [];
        $this->credential = $this->credentialRepository->find($credentialId);

        if ($this->credential && $this->credential->extras) {
            $customMapping = isset($this->credential->extras[self::CUSTOM_FIELD_MAPPING]) ? $this->credential->extras[self::CUSTOM_FIELD_MAPPING] : [];
        }

        return $customMapping;
    }

    public function requestApiAction($action, $data, $parameters)
    {
        $credentialId = 1;
        if (! empty($parameters['credential'])) {
            $credentialId = $parameters['credential'];
        }

        $credential = $this->credentialRepository->find($credentialId);

        $credentials = [];
        if (! empty($credential)) {
            $credentials = [
                'shopUrl'        => $credential->shopUrl,
                'consumerKey'    => $credential->consumerKey,
                'consumerSecret' => $credential->consumerSecret,
            ];
        }

        if (empty($credentials['shopUrl'])) {
            throw new \Exception('Error! Save credentials before Exporting data.');
            exit();
        }

        $oauthClient = new ApiClient($credentials['shopUrl'], $credentials['consumerKey'], $credentials['consumerSecret']);

        $response = $oauthClient->request($action, $parameters, $data);

        return $response;
    }

    public function convertToCode($name)
    {
        setlocale(LC_ALL, 'en_US.utf8');
        $name = iconv('utf-8', 'ascii//TRANSLIT', $name);
        $name = preg_replace('/[^a-zA-Z0-9\']/', '_', $name);
        $name = ltrim($name, '_');
        $code = preg_replace(['#\s#', '#-#', '#[^a-zA-Z0-9_\s]#'], ['_', '_', ''], strtolower($name));

        return $code;
    }

    public function getOptionsByAttribute($code, $locale)
    {
        $attribute = $this->attributeRepository->findOneWhere(['code' => $code]);

        if (! $attribute) {
            return [];
        }

        // If attribute type is boolean, return Yes/No options
        if ($attribute->type === 'pim_catalog_boolean') {
            return ['Yes', 'No'];
        }

        return $attribute->options->map(function ($option) use ($locale) {
            $translation = $option->translations->firstWhere('locale', $locale);

            return $option->code;
        })->toArray();
    }

    public function getCredentialForQuickExport()
    {
        return $this->credentialRepository->findOneWhere(['defaultSet' => 1])?->toArray();

    }

    public function prepareAdditionalAttribute(string $id, string $code): array
    {
        $attribute = $this->getAttributeByCode($code);
        $name = $this->getDefaultName($attribute);

        if (! empty($attribute)) {
            return [
                'name'     => $code,
                'types'    => [$attribute['type']],
                'label'    => ! empty($name) ? $name : '['.$code.']',
                'required' => false,
            ];
        }

        return [
            'id'         => $id,
            'name'       => $code,
            'types'      => [],
            'label'      => ucfirst($code),
            'isEditable' => true,
            'required'   => false,
        ];
    }

    public function getAdditionalAttributes($credentialId)
    {
        $additionalAttributeMapping = $this->attributeMappingRepository->getAdditionalFieldMapping($credentialId);

        if (empty($additionalAttributeMapping)) {
            return [];
        }

        $attributes = array_values($additionalAttributeMapping);

        return $attributes;
    }

    public function getDefaultName(array $data, $defaultLocale = null): string
    {
        if (empty($data)) {
            return '';
        }

        $locale = $defaultLocale ?? core()->getRequestedLocaleCode();
        $translations = $this->getTranslationsData($data);
        $name = collect($translations)->firstWhere('locale', $locale)['name'] ?? '';

        return $name;
    }

    public function getTranslationsData(array $data): array
    {
        return isset($data[self::TRANSLATIONS_KEY]) && ! empty($data[self::TRANSLATIONS_KEY]) ? $data[self::TRANSLATIONS_KEY] : [];
    }

    public function getAttributeByCode(string $code): array
    {
        return $this->attributeRepository->findOneWhere(['code' => $code])?->toArray() ?? [];
    }
}
