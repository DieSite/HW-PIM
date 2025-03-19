<?php

namespace Webkul\WooCommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Webkul\WooCommerce\DataGrids\Credential\CredentialDataGrid;
use Webkul\WooCommerce\Helpers\AttributeMappingHelper;
use Webkul\WooCommerce\Http\Requests\CredentialForm;
use Webkul\WooCommerce\Repositories\CredentialRepository;
use Webkul\WooCommerce\Services\WooCommerceService;
use Webkul\WooCommerce\Traits\RestApiRequestTrait;

class CredentialController
{
    use RestApiRequestTrait;

    protected $credential;

    public const READONLY_CREDENTIALS = 'credentailsReadonlyUrls';

    public const QUICK_EXPORT_CODE = 'woocommerce_product_quick_export';

    public const SECTION = 'woocommerce_connector';

    public const MEDIA_MAPPING = 'media';

    public const MAPPING_SECTION = 'settings';

    public const DEFAULTS_SECTION = 'defaults';

    public const QUICKSETTING_SECTION = 'quicksettings';

    public const OTHER_MAPPING_SECTION = 'woocommerce_other_mappings';

    public const OTHER_MAPPING = 'woocommerce_connector_othermapping';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected CredentialRepository $credentialRepository,
        protected WooCommerceService $wooCommerceService,
        protected AttributeMappingHelper $attributeMappingHelper
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (request()->ajax()) {
            return app(CredentialDataGrid::class)->toJson();
        }

        return view('woocommerce::credentials.index');
    }

    /**
     * Create a new woocommerce credential.
     */
    public function store(CredentialForm $request): JsonResponse
    {
        $credential = $request->all();
        $url = $credential['shopUrl'];

        if (strpos($url, 'http') !== 0) {
            return new JsonResponse([
                'errors' => [
                    'shopUrl' => [trans('woocommerce::app.woocommerce.credential.invalidurl')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $credential['active'] = 1;

        $this->credential = $credential;

        $isCredentialsValid = $this->checkCredentials($credential);
        if (! $isCredentialsValid) {
            return new JsonResponse([
                'errors' => [
                    'shopUrl'     => trans('woocommerce::app.woocommerce.credential.invalid'),
                    'consumerKey' => trans('woocommerce::app.woocommerce.credential.invalid'),
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $credentialCreate = $this->credentialRepository->create($this->credential);
        session()->flash('success', trans('woocommerce::app.woocommerce.credential.created'));

        return new JsonResponse([
            'redirect_url' => route('woocommerce.credentials.edit', $credentialCreate->id),
        ]);
    }

    /**
     * Edit a Woocommerce credential by ID.
     *
     * @return View
     */
    public function edit(int $id)
    {
        $this->credential = $this->credentialRepository->find($id);

        if (! $this->credential) {
            abort(404);
        }

        $method = 'GET';

        $credential = $this->credential;
        $attributeMappings = $this->credential?->extras['settings'] ?? [];
        $defaultMappings = $this->credential?->extras['defaults'] ?? [];
        $mediaMapping = $this->credential?->extras['media'] ?? [];
        $standardAttributes = $this->attributeMappingHelper->getStandardFields();
        $customAttributes = $this->credential?->extras['custom_field'] ?? [];
        $enabled = $this->credential?->extras['enableSelect'] ?? 0;
        $quickSettings = $this->credential?->extras['quicksettings'] ?? [];
        $additionalAttributes = $this->credential?->extras['additional_attributes'] ?? [];

        return view('woocommerce::credentials.edit.index', compact('credential', 'standardAttributes', 'defaultMappings', 'mediaMapping', 'customAttributes', 'attributeMappings', 'enabled', 'quickSettings', 'additionalAttributes', 'id'));
    }

    /**
     * Update a WooCommerce credential by ID.
     *
     * @return JsonResponse
     */
    public function update(int $id)
    {
        $this->credential = $data = request()->except(['code']);

        if (! $this->credential) {
            abort(404);
        }

        $response = $this->checkCredentials($this->credential);

        if ($response['code'] !== 200) {
            if ($response['code'] === 404) {
                return redirect()
                    ->route('woocommerce.credentials.edit', $id)
                    ->withErrors([
                        'shopUrl' => trans('woocommerce::app.woocommerce.credential.invalid'),
                    ])
                    ->withInput();
            }

            $message = $response['message'] ?? '';
            $errorField = str_contains($message, 'key') ? 'consumerKey' : 'consumerSecret';

            return redirect()
                ->route('woocommerce.credentials.edit', $id)
                ->withErrors([$errorField => $message])
                ->withInput();
        }

        // If defaultSet is 1, reset other credentials' defaultSet to 0
        if (! empty($data['defaultSet']) && $data['defaultSet'] == 1) {
            $this->credentialRepository->resetDefaultSet($id);
        }

        $this->credentialRepository->update($this->credential, $id);
        session()->flash('success', trans('woocommerce::app.woocommerce.credential.edit.tab.'.$data['tab'].'.update-success'));

        return redirect()->route('woocommerce.credentials.edit', $id);
    }

    /**
     * Delete a woocommerce credential by ID.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->credentialRepository->delete($id);

        return new JsonResponse([
            'message' => trans('woocommerce::app.woocommerce.credential.delete-success'),
        ]);
    }
}
