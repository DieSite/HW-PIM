<?php

namespace Webkul\WooCommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Webkul\WooCommerce\Helpers\AttributeMappingHelper;
use Webkul\WooCommerce\Http\Requests\AdditionalAttributeRequest;
use Webkul\WooCommerce\Http\Requests\StandardAttributeRequest;
use Webkul\WooCommerce\Repositories\AttributeMappingRepository;
use Webkul\WooCommerce\Repositories\CredentialRepository;
use Webkul\WooCommerce\Services\WooCommerceService;

class AttributeMappingController
{
    public const MEDIA_MAPPING = 'media';

    public const MAPPING_SECTION = 'settings';

    public const DEFAULTS_SECTION = 'defaults';

    public const QUICKSETTING_SECTION = 'quicksettings';

    public const CUSTOM_FIELD_MAPPING = 'custom_field';

    public const ENABLED = 'enableSelect';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected AttributeMappingRepository $attributeMappingRepository,
        protected CredentialRepository $credentialRepository,
        protected WooCommerceService $wooCommerceService,
    ) {}

    /**
     * Standard Mapping page
     */
    public function index(): View
    {
        return view(
            'woocommerce::mappings.attribute-mapping.index',
            [
                'mappingFields'        => AttributeMappingHelper::getStandardFields(),
                'fieldValues'          => $this->attributeMappingRepository->getStandardMapping(),
                'additionalAttributes' => $this->attributeMappingRepository->getCustomAttributesMapping(),
            ]
        );
    }

    public function update(StandardAttributeRequest $request, int $id)
    {
        $data = request()->except(['code']);

        if (! $data) {
            abort(404);
        }

        $data['extras'] = $this->transformData($data);
        $credentialExtras = $this->credentialRepository->find($id)?->extras ?? [];
        $data['extras']['additional_attributes'] = $credentialExtras['additional_attributes'] ?? [];
        $this->credentialRepository->update($data, $id);

        session()->flash('success', trans('woocommerce::app.woocommerce.credential.edit.tab.'.$data['tab'].'.update-success'));
        $id = $id.'?'.$data['tab'];

        return redirect()->route('woocommerce.credentials.edit', $id);
    }

    /**
     * Add additional attributes to the standard mapping.
     *
     * @param  AdditionalAttributeRequest  $request  Contains 'code' and 'id'.
     * @return JsonResponse Indicates success of the addition.
     */
    public function addAdditionalAttribute(AdditionalAttributeRequest $request): JsonResponse
    {
        $data = $request->only(['code', 'id', 'type']);
        $credentialId = request()->get('credentialId');

        $fields = $this->wooCommerceService->prepareAdditionalAttribute($data['id'], $data['code']);

        if (isset($fields['error'])) {
            return new JsonResponse([
                'message' => $fields['error'],
            ], 404);
        }

        $this->attributeMappingRepository->saveAdditionalField($credentialId, $fields);

        // if ($data['type']) {
        //     $this->attributeMappingRepository->mapAdditionalField($data['code']);
        // }

        return new JsonResponse([
            'message' => trans('woocommerce::app.mappings.attribute-mapping.additional-field.add-success'),
        ]);
    }

    public function removeAdditionalAttribute(): JsonResponse
    {
        $this->attributeMappingRepository->removeAdditionalField(request()->get('code'), request()->get('credentialId'));

        return new JsonResponse([
            'message' => trans('woocommerce::app.mappings.attribute-mapping.additional-field.remove-success'),
        ]);
    }

    public function transformData($credentialData)
    {
        $output = [
            'scheme'   => (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http',
            'hostname' => $_SERVER['HTTP_HOST'],
        ];

        foreach ($credentialData as $key => $value) {
            if (in_array($key, ['_token', '_method', 'tab'], true) || $value === '') {
                continue; // Skip unnecessary fields
            }

            switch (true) {
                case str_starts_with($key, 'default_'):
                    $newKey = substr($key, 8); // Remove "default_" prefix
                    $output[self::DEFAULTS_SECTION][$newKey] = $value;
                    break;

                case in_array($key, ['media', 'custom_field'], true):
                    $output[constant('self::'.strtoupper($key).'_MAPPING')] = explode(',', $value);
                    break;

                case $key === self::ENABLED:
                    $output[self::ENABLED] = $value;
                    break;
                case str_starts_with($key, 'quick_') || $key == 'auto_sync':
                    $output[self::QUICKSETTING_SECTION][$key] = $value;
                    break;

                default:
                    $output[self::MAPPING_SECTION][$key] = $value;
                    break;
            }
        }

        return $output;
    }
}
