<x-admin::layouts.with-history>
    <x-slot:entityName>
        product
    </x-slot>
    <x-slot:title>
        @lang('admin::app.catalog.products.edit.title')
    </x-slot>

    {!! view_render_event('unopim.admin.catalog.product.edit.before', ['product' => $product]) !!}

    <x-admin::form
        method="PUT"
        enctype="multipart/form-data"
    >
        {!! view_render_event('unopim.admin.catalog.product.edit.actions.before', ['product' => $product]) !!}

        <input type="hidden" name="sku" value="{{ $product->sku }}">

        <!-- Page Header -->
        <div class="grid gap-2.5">
            <div class="flex gap-4 justify-between items-center max-sm:flex-wrap">
                <div class="grid gap-1.5">
                    <p class="text-xl text-gray-800 dark:text-slate-50 font-bold leading-6">
                        @lang('admin::app.catalog.products.edit.title') | SKU: {{ $product->sku }}
                    </p>
                </div>

                <div class="flex gap-x-2.5 items-center">
                    <!-- Back Button -->
                    <a
                        href="{{ route('admin.catalog.products.index') }}"
                        class="transparent-button"
                    >
                        @lang('admin::app.account.edit.back-btn')
                    </a>

                    @if(!is_null($product->parent))
                        <a
                            href="{{ route('admin.catalog.products.edit', ['id' => $product->parent_id]) }}"
                            class="secondary-button"
                        >
                            Naar hoofdproduct
                        </a>
                    @endif

                    @if(isset($product->values['common']['onderkleed']) && $product->values['common']['onderkleed'] === 'Met onderkleed')
                        <button type="button" onclick="calcMetOnderkleed()" class="secondary-button">
                            Prijs berekenen
                        </button>
                    @endif
                    @if(is_null($product->parent_id))
                        <button type="button" onclick="getMetaFields()" class="secondary-button">
                            Meta velden genereren
                        </button>

                        @if (app(\App\Services\AI\AiSettings::class)->enabled())
                            <button type="button" onclick="generateAiTexts(this)" class="secondary-button flex items-center gap-1">
                                <span class="icon-magic-wand text-sm"></span>
                                Teksten genereren (AI)
                            </button>
                        @endif
                    @endif

                    <a href="{{ route('product.frontend', ['product' => $product->id]) }}" class="secondary-button"
                       target="_blank">
                        Naar frontend
                    </a>

                    <!-- Save Button -->
                    <button class="primary-button">
                        @lang('admin::app.catalog.products.edit.save-btn')
                    </button>
                </div>
            </div>
        </div>

        @isset($product->additional['product_sku_already_exists'])
            <div id="alert-border-2" class="flex items-center mt-5 p-4 mb-4 text-red-800 border-t-4 border-red-300 bg-red-50 dark:text-red-400 dark:bg-gray-800 dark:border-red-800" role="alert">
                <svg class="shrink-0 w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z"/>
                </svg>
                <div class="ms-3 text-sm font-medium">
                    Het lijkt er op dat dit product al bestaat op de WordPress website. Ga naar de frontend om het product
                    te bekijken. Het kan ook om een variatie gaan. Verwijder het product met SKU {{ $product->sku }} in
                    WordPress en sla opnieuw op.
                </div>
            </div>
        @endisset

        @isset($product->additional['product_sync_error'])
            <div id="alert-border-2" class="flex items-center mt-5 p-4 mb-4 text-red-800 border-t-4 border-red-300 bg-red-50 dark:text-red-400 dark:bg-gray-800 dark:border-red-800" role="alert">
                <svg class="shrink-0 w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z"/>
                </svg>
                <div class="ms-3 text-sm font-medium">
                    Het lijkt er op dat er iets mis is bij het synchroniseren van het product met de WordPress website:<br/>
                    <div class="italic p-2 my-2 border-t border-b border-red-300">{{ $product->additional['product_sync_error'] }}</div>
                    Probeer de fout op te lossen of bel Luuk!
                </div>
            </div>
        @endisset


        @php
            $channels = core()->getAllChannels();

            $currentChannel = core()->getRequestedChannel() ?? core()->getDefaultChannel();

            $currentLocale = core()->getRequestedLocale();

            $currentLocale = $currentChannel->locales->contains($currentLocale) ? $currentLocale : $currentChannel->locales->first();
        @endphp

            <!-- Channel and Locale Switcher -->
        <div class="flex  gap-4 justify-between items-center mt-7 max-md:flex-wrap">
            <div class="flex gap-x-1 items-center">
                <!-- Channel Switcher -->
                <x-admin::dropdown>
                    <!-- Dropdown Toggler -->
                    <x-slot:toggle>
                        <button
                            type="button"
                            class="
                            flex gap-x-1 items-center px-3 py-1.5 border-2 border-transparent rounded-md font-semibold whitespace-nowrap cursor-pointer marker:shadow appearance-none transition-all hover:!bg-violet-50 dark:hover:!bg-cherry-900 text-gray-600 dark:!text-slate-50"
                        >
                            <span class="icon-channel   text-2xl"></span>

                            {{ ! empty($currentChannel->name) ? $currentChannel->name : '[' . $currentChannel->code . ']' }}

                            <input type="hidden" name="channel" value="{{ $currentChannel->code }}"/>

                            <span class="icon-chevron-down   text-2xl"></span>
                        </button>
                    </x-slot>

                    <!-- Dropdown Content -->
                    <x-slot:content class="!p-0">
                        @foreach ($channels as $channel)
                            <a
                                href="?{{ Arr::query(['channel' => $channel->code, 'locale' => $currentLocale?->code]) }}"
                                class="flex gap-2.5 px-5 py-2 text-base cursor-pointer hover:bg-violet-50 dark:hover:bg-cherry-800 dark:text-white"
                            >
                                {{ ! empty($channel->name) ? $channel->name : '[' . $channel->code . ']' }}
                            </a>
                        @endforeach
                    </x-slot>
                </x-admin::dropdown>

                <!-- Locale Switcher -->
                <x-admin::dropdown>
                    <!-- Dropdown Toggler -->
                    <x-slot:toggle>
                        <button
                            type="button"
                            class="flex gap-x-1 items-center px-3 py-1.5 border-2 border-transparent rounded-md font-semibold whitespace-nowrap cursor-pointer marker:shadow appearance-none transition-all hover:!bg-violet-50 dark:hover:!bg-cherry-900 text-gray-600 dark:!text-slate-50 "
                        >
                            <span class="icon-language text-2xl"></span>

                            {{ $currentLocale?->name }}

                            <input type="hidden" name="locale" value="{{ $currentLocale?->code }}"/>

                            <span class="icon-chevron-down text-2xl"></span>
                        </button>
                    </x-slot>

                    <!-- Dropdown Content -->
                    <x-slot:content class="!p-0">
                        @foreach ($currentChannel->locales->sortBy('name') as $locale)
                            <a
                                href="?{{ Arr::query(['channel' => $currentChannel->code, 'locale' => $locale->code]) }}"
                                class="flex gap-2.5 px-5 py-2 text-base cursor-pointer hover:bg-violet-50 dark:hover:bg-cherry-800 dark:text-white {{ $locale->code == $currentLocale?->code ? 'bg-gray-100 dark:bg-cherry-800' : ''}}"
                            >
                                {{ $locale->name }}
                            </a>
                        @endforeach
                    </x-slot>
                </x-admin::dropdown>
            </div>
        </div>

        {!! view_render_event('unopim.admin.catalog.product.edit.actions.after', ['product' => $product]) !!}

        <!-- body content -->
        {!! view_render_event('unopim.admin.catalog.product.edit.form.before', ['product' => $product]) !!}

        <div class="flex gap-2.5 mt-3.5 max-xl:flex-wrap">
            <div class="left-column flex flex-col gap-2 flex-1 max-xl:flex-auto">
                @foreach ($product->attribute_family->familyGroups()->orderBy('position')->get() as $group)
                    {!! view_render_event('unopim.admin.catalog.product.edit.form.column_before', ['product' => $product]) !!}

                    <div class="flex flex-col gap-2">
                        @php
                            $customAttributes = $product->getEditableAttributes($group);

                            $groupLabel = $group->name;
                            $groupLabel = empty($groupLabel) ? "[{$group->code}]" : $groupLabel;
                        @endphp

                        @if (count($customAttributes))
                            {!! view_render_event('unopim.admin.catalog.product.edit.form.' . $group->code . '.before', ['product' => $product]) !!}

                            <div class="relative p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                                <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
                                    {{ $groupLabel }}
                                </p>

                                <x-admin::products.dynamic-attribute-fields
                                    :fields="$customAttributes"
                                    :fieldValues="$product->values"
                                    :currentLocaleCode="$currentLocale->code"
                                    :currentChannelCode="$currentChannel->code"
                                    :channelCurrencies="$currentChannel->currencies"
                                    :variantFields="$product?->parent ? $product->parent->super_attributes->pluck('code')->toArray() : []"
                                    :productId="$product->id"
                                    fieldsWrapper="values"
                                >
                                </x-admin::products.dynamic-attribute-fields>

                            </div>

                            {!! view_render_event('unopim.admin.catalog.product.edit.form.' . $group->code . '.after', ['product' => $product]) !!}
                        @endif

                        <!-- Product Type View Blade File -->
                    </div>

                    {!! view_render_event('unopim.admin.catalog.product.edit.form.column_after', ['product' => $product]) !!}
                @endforeach
            </div>
            <div class="right-column flex flex-col gap-2 w-[360px] max-w-full max-sm:w-full">
                @if(is_null($product->parent))
                    <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                        <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
                            Status
                        </p>

                        <div class="mb-2.5">
                            <select name="status"
                                    class="w-full p-2 border border-gray-300 rounded-md text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                <option value="1" @selected(old('status', $product->status) == 1)>✓ Ingeschakeld
                                </option>
                                <option value="0" @selected(old('status', $product->status) == 0)>✗ Uitgeschakeld
                                </option>
                            </select>
                        </div>
                    </div>
                @endif

                <!-- Add Bol.com Integration Box -->
                <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                    <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
                        Bol.com
                    </p>

                    <div class="mb-2.5">
                        @php
                            $hasEan = !empty($product->values['common']['ean'] ?? '');
                            $eanValue = $product->values['common']['ean'] ?? '';
                            $bolSyncDisabled = !$hasEan;
                        @endphp

                            <!-- Bol.com Integration Checkbox -->
                        <label
                            class="flex gap-1 items-center mb-2.5 {{ $bolSyncDisabled ? 'opacity-50' : 'cursor-pointer' }} select-none">
                            <input
                                type="checkbox"
                                name="bol_com_sync"
                                id="bol_com_sync"
                                value="1"
                                class="form-checkbox"
                                {{ $product->bol_com_sync ? 'checked' : '' }}
                                {{ $bolSyncDisabled ? 'disabled' : '' }}
                                onchange="toggleBolComCredentials(this); toggleDeleteWarning(this);"
                            >
                            <span class="text-xs text-gray-600 dark:text-gray-300 font-medium">
                                Sync met Bol.com
                            </span>
                        </label>

                        @if(isset($product->bolComCredentials->first()->pivot->reference) && $product->bolComCredentials->first()->pivot->reference)
                            <div id="bol_com_delete_warning"
                                 class="hidden text-xs text-orange-500 dark:text-orange-400 mt-1 mb-2 p-2 bg-orange-50 dark:bg-opacity-10 border border-orange-200 dark:border-orange-800 rounded">
                                <strong>Let op:</strong> Bij het uitschakelen van de Bol.com synchronisatie en opslaan
                                wordt dit product verwijderd van Bol.com.
                            </div>
                        @endif

                        @if($bolSyncDisabled)
                            <p class="text-xs text-red-500 dark:text-red-400 mt-1 mb-2">
                                Een EAN code is vereist voor Bol.com synchronisatie.
                            </p>
                        @endif

                        <!-- Credentials Checkboxes -->
                        @if(!$bolSyncDisabled)
                            <div class="mt-3">
                                <label class="block text-xs text-gray-600 dark:text-gray-300 font-medium mb-1">
                                    Accounts
                                </label>
                                <div
                                    class="p-2 border border-gray-300 rounded-md dark:border-gray-700 dark:bg-gray-900 max-h-40 overflow-y-auto">
                                    @foreach (app('App\Services\BolComProductService')->getCredentialsOptions() as $credentialId => $credentialName)
                                        <div class="flex items-center mb-1 last:mb-0">
                                            <input
                                                type="checkbox"
                                                id="bol_com_credential_{{ $credentialId }}"
                                                name="bol_com_credentials[]"
                                                value="{{ $credentialId }}"
                                                class="form-checkbox mr-2"
                                                {{ !$product->bol_com_sync ? 'disabled' : '' }}
                                                {{ $product->bolComCredentials->contains('id', $credentialId) ? 'checked' : '' }}
                                            >
                                            <label for="bol_com_credential_{{ $credentialId }}"
                                                   class="text-sm text-gray-600 dark:text-gray-300 cursor-pointer">
                                                {{ $credentialName }}
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if(!$bolSyncDisabled)
                            <div class="mt-3">
                                <label for="bol_price_override"
                                       class="block text-xs text-gray-600 dark:text-gray-300 font-medium mb-1">
                                    Prijs overschrijven
                                </label>
                                <input
                                    type="number"
                                    name="bol_price_override"
                                    id="bol_price_override"
                                    value="{{ $product->bol_price_override ?? '' }}"
                                    class="w-full p-2 border border-gray-300 rounded-md text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                    step="0.01"
                                    min="0"
                                    {{ $bolSyncDisabled ? 'disabled' : '' }}
                                >
                            </div>

                            <div class="mt-3">
                                <label for="bol_default_price"
                                       class="block text-xs text-gray-600 dark:text-gray-300 font-medium mb-1">
                                    Standaard Bol.com prijs (zonder overschrijven)
                                </label>
                                <input
                                    type="number"
                                    id="bol_default_price"
                                    value="{{ app(\App\Services\BolComProductService::class)->getProductPrice($product, true) }}"
                                    class="w-full p-2 border border-gray-300 rounded-md text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                    style="cursor:not-allowed;"
                                    disabled
                                >
                            </div>
                        @endif


                        <!-- Delivery Time Dropdown -->
                        @if(!$bolSyncDisabled)
                            <div class="mt-3">
                                <label for="bol_com_delivery_code"
                                       class="block text-xs text-gray-600 dark:text-gray-300 font-medium mb-1">
                                    Levertijd
                                </label>
                                @php
                                    $deliveryCode = isset($product->bolComCredentials->first()->pivot->delivery_code)
                                        ? $product->bolComCredentials->first()->pivot->delivery_code
                                        : '';
                                @endphp

                                <select
                                    id="bol_com_delivery_code"
                                    name="bol_com_delivery_code"
                                    class="w-full p-2 border border-gray-300 rounded-md text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                    {{ !$product->bol_com_sync ? 'disabled' : '' }}
                                    required
                                >
                                    <option value="">Selecteer levertijd</option>
                                    <option value="24uurs-12" {{ $deliveryCode == '24uurs-12' ? 'selected' : '' }}>24
                                        uur - 12
                                    </option>
                                    <option value="24uurs-13" {{ $deliveryCode == '24uurs-13' ? 'selected' : '' }}>24
                                        uur - 13
                                    </option>
                                    <option value="24uurs-14" {{ $deliveryCode == '24uurs-14' ? 'selected' : '' }}>24
                                        uur - 14
                                    </option>
                                    <option value="24uurs-15" {{ $deliveryCode == '24uurs-15' ? 'selected' : '' }}>24
                                        uur - 15
                                    </option>
                                    <option value="24uurs-16" {{ $deliveryCode == '24uurs-16' ? 'selected' : '' }}>24
                                        uur - 16
                                    </option>
                                    <option value="24uurs-17" {{ $deliveryCode == '24uurs-17' ? 'selected' : '' }}>24
                                        uur - 17
                                    </option>
                                    <option value="24uurs-18" {{ $deliveryCode == '24uurs-18' ? 'selected' : '' }}>24
                                        uur - 18
                                    </option>
                                    <option value="24uurs-19" {{ $deliveryCode == '24uurs-19' ? 'selected' : '' }}>24
                                        uur - 19
                                    </option>
                                    <option value="24uurs-20" {{ $deliveryCode == '24uurs-20' ? 'selected' : '' }}>24
                                        uur - 20
                                    </option>
                                    <option value="24uurs-21" {{ $deliveryCode == '24uurs-21' ? 'selected' : '' }}>24
                                        uur - 21
                                    </option>
                                    <option value="24uurs-22" {{ $deliveryCode == '24uurs-22' ? 'selected' : '' }}>24
                                        uur - 22
                                    </option>
                                    <option value="24uurs-23" {{ $deliveryCode == '24uurs-23' ? 'selected' : '' }}>24
                                        uur - 23
                                    </option>
                                    <option value="1-2d" {{ $deliveryCode == '1-2d' ? 'selected' : '' }}>1-2 dagen
                                    </option>
                                    <option value="2-3d" {{ $deliveryCode == '2-3d' ? 'selected' : '' }}>2-3 dagen
                                    </option>
                                    <option value="3-5d" {{ $deliveryCode == '3-5d' ? 'selected' : '' }}>3-5 dagen
                                    </option>
                                    <option value="4-8d" {{ $deliveryCode == '4-8d' ? 'selected' : '' }}>4-8 dagen
                                    </option>
                                    <option value="1-8d" {{ $deliveryCode == '1-8d' ? 'selected' : '' }}>1-8 dagen
                                    </option>
                                    <option
                                        value="MijnLeverBelofte" {{ $deliveryCode == 'MijnLeverBelofte' ? 'selected' : '' }}>
                                        Mijn Lever Belofte
                                    </option>
                                    <option value="VVB" {{ $deliveryCode == 'VVB' ? 'selected' : '' }}>VVB</option>
                                </select>
                                <div id="delivery_code_error" class="text-xs text-red-500 mt-1 hidden">
                                    Selecteer een levertijd.
                                </div>
                            </div>
                        @endif

                        @if($product->bolComCredentials->isNotEmpty())
                            <div class="mt-3">
                                <p class="text-xs text-gray-600 dark:text-gray-300 font-medium">Bol.com Referenties:</p>
                                <div class="mt-1">
                                    @foreach($product->bolComCredentials as $credential)
                                        @if($credential->pivot->reference)
                                            <p class="text-xs text-gray-600 dark:text-gray-300 mb-1">
                                                {{ $credential->pivot->reference }} <span class="text-gray-500">({{ $credential->name }})</span>
                                            </p>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Price History (competitor analysis) -->
                @include('admin::catalog.products.edit.price-history')

                <!-- Categories View Blade File -->
                @if($product->type !== 'simple')
                    @include('admin::catalog.products.edit.categories', ['currentLocaleCode' => $currentLocale?->code, 'productCategories' => $product->values['categories'] ?? []])


                    @includeIf('admin::catalog.products.edit.types.' . $product->type)

                    <!-- Related, Cross Sells, Up Sells View Blade File -->
                    @include('admin::catalog.products.edit.links', [
                        'upSellAssociations'    => $product->values['associations']['up_sells'] ?? [],
                        'crossSellAssociations' => $product->values['associations']['cross_sells'] ?? [],
                        'relatedAssociations'   => $product->values['associations']['related_products'] ?? [],
                    ])
                @endif

                <!-- Include Product Type Additional Blade Files If Any -->
                @foreach ($product->getTypeInstance()->getAdditionalViews() as $view)
                    @includeIf($view)
                @endforeach
            </div>
        </div>

        {!! view_render_event('unopim.admin.catalog.product.edit.form.after', ['product' => $product]) !!}
    </x-admin::form>

    {!! view_render_event('unopim.admin.catalog.product.edit.after', ['product' => $product]) !!}

    @if (is_null($product->parent_id) && (app(\App\Services\AI\AiSettings::class)->enabled()))
        {{--
            Het voorstel-venster voor de AI-teksten. De opmaak staat hier en niet
            in JavaScript, omdat Tailwind alleen klassennamen compileert die het
            in de bronbestanden terugvindt.
        --}}
        <div id="ai-texts-modal" class="hidden">
            <div
                class="fixed inset-0 bg-gray-500 bg-opacity-50 z-[10001]"
                onclick="hideAiTextsModal()"
            ></div>

            <div class="fixed inset-0 z-[10002] overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <div class="w-full max-w-[900px] max-h-[96%] overflow-y-auto rounded-lg bg-white dark:bg-gray-900 box-shadow p-6">
                        <p class="text-lg font-bold text-gray-800 dark:text-slate-50">Voorstel van de AI</p>
                        <p
                            class="text-sm text-gray-500 dark:text-slate-300 mb-4"
                            data-ai-texts-subtitle
                        >
                            Neem je dit over, vergeet dan niet daarna op Opslaan te drukken.
                        </p>

                        <div
                            class="hidden rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700 mb-4"
                            data-ai-texts-error
                        ></div>

                        <div
                            class="hidden rounded-md bg-orange-50 border border-orange-200 p-3 text-sm text-orange-600 mb-4"
                            data-ai-texts-problems
                        ></div>

                        <div data-ai-texts-body></div>

                        <div class="flex items-center gap-2.5 pt-2">
                            <button type="button" class="primary-button" data-ai-texts-accept>
                                Overnemen in het formulier
                            </button>
                            <button type="button" class="secondary-button" onclick="hideAiTextsModal()" data-ai-texts-cancel>
                                Annuleren
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-admin::layouts.with-history>

<script>
    function toggleBolComCredentials(checkbox) {
        const credentialCheckboxes = document.querySelectorAll('input[name="bol_com_credentials[]"]');
        credentialCheckboxes.forEach(credBox => {
            credBox.disabled = !checkbox.checked;

            if (!checkbox.checked) {
                credBox.checked = false;
            }
        });

        const deliveryCodeSelect = document.getElementById('bol_com_delivery_code');
        if (deliveryCodeSelect) {
            deliveryCodeSelect.disabled = !checkbox.checked;
        }
    }

    function toggleDeleteWarning(checkbox) {
        const warningElement = document.getElementById('bol_com_delete_warning');
        if (warningElement) {
            if (checkbox.checked) {
                warningElement.classList.add('hidden');
            } else {
                warningElement.classList.remove('hidden');
            }
        }
    }

    function getMetaFields() {
        const sku = document.querySelector('input[name="sku"]').value;
        const title = document.querySelector('input[name="values[common][productnaam]"]').value;
        const merk = document.querySelector('input[name="values[common][merk]"]').value;

// Maak een XHR request
        fetch('/product/meta_fields', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value
            },
            body: JSON.stringify({sku: sku, title: title, merk: merk})
        })
            .then(response => response.json())
            .then(data => {
                if (confirm('(Vergeet niet op te slaan na het bevestigen van de teksten)\n\nMeta titel: ' + data.meta_title + "\n\n" + 'Meta beschrijving: \n' + data.meta_description)) {
                    const input = document.querySelector('input[name="values[common][meta_titel]"]');
                    input.value = data.meta_title;

                    // Trigger zowel 'input' als 'change' events
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                    input.dispatchEvent(new Event('change', {bubbles: true}));

                    tinymce.get("meta_beschrijving").setContent(data.meta_description);
                }
            })
            .catch(error => {
                alert('Er is een fout opgetreden bij het ophalen van de meta');
                console.error('Error:', error);
            });
    }

    /**
     * Vraag de AI om nieuwe teksten voor dit product en laat ze eerst zien.
     *
     * Er wordt niets opgeslagen: bij "Overnemen" worden alleen de velden in dit
     * formulier gevuld, daarna moet je zelf nog op Opslaan drukken.
     */
    function generateAiTexts(button, fields) {
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="icon-magic-wand text-sm"></span> Bezig met schrijven…';

        // Zonder veldenlijst schrijft één call alle teksten tegelijk; dat is
        // goedkoper dan drie losse calls, want de opdracht en de foto worden
        // dan gedeeld. Met een lijst betaal je alleen voor wat je vraagt.
        //
        // De formulierwaarden gaan altijd mee en gaan voor op wat is opgeslagen:
        // bij een net aangemaakt product staat er nog niets in de database, en
        // bij een wijziging wil je dat de AI de nieuwe kleur ziet, niet de oude.
        const payload = {
            product_id: {{ $product->id }},
            values: readProductFormValues(),
        };

        if (fields && fields.length) {
            payload.fields = fields;
        }

        fetch('{{ route('admin.catalog.products.ai-description.generate') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value,
            },
            body: JSON.stringify(payload),
        })
            .then(async (response) => {
                const json = await response.json();
                if (!response.ok) {
                    throw new Error(json.message || 'De AI kon geen teksten schrijven.');
                }
                return json;
            })
            .then((json) => showAiTextsModal(json))
            .catch((error) => {
                showAiTextsModal({ texts: {}, error: error.message });
                console.error('AI-teksten:', error);
            })
            .finally(() => {
                button.disabled = false;
                button.innerHTML = original;
            });
    }

    /**
     * Vult en toont het voorstel-venster.
     *
     * Alle opmaak staat in de blade-markup hieronder, niet in strings hier:
     * Tailwind scant geen klassennamen die alleen in JavaScript voorkomen, dus
     * die zouden niet in de gecompileerde admin-CSS terechtkomen.
     */
    function showAiTextsModal(result) {
        const labels = @json(collect(config('ai.fields'))->map(fn ($field) => $field['label']));
        const modal = document.getElementById('ai-texts-modal');
        const body = modal.querySelector('[data-ai-texts-body]');
        const failure = modal.querySelector('[data-ai-texts-error]');
        const warning = modal.querySelector('[data-ai-texts-problems]');
        const subtitle = modal.querySelector('[data-ai-texts-subtitle]');
        const accept = modal.querySelector('[data-ai-texts-accept]');
        const cancel = modal.querySelector('[data-ai-texts-cancel]');

        const texts = result.texts || {};
        const hasTexts = Object.keys(texts).length > 0;

        body.replaceChildren();
        warning.replaceChildren();

        failure.textContent = result.error || '';
        failure.classList.toggle('hidden', !result.error);
        warning.classList.toggle('hidden', !(result.problems && result.problems.length));
        subtitle.classList.toggle('hidden', !hasTexts);
        accept.classList.toggle('hidden', !hasTexts);
        cancel.textContent = hasTexts ? 'Annuleren' : 'Sluiten';

        (result.problems || []).forEach((problem) => {
            const line = document.createElement('p');
            line.textContent = problem.message;
            warning.appendChild(line);
        });

        Object.keys(texts).forEach((code) => {
            const label = document.createElement('p');
            label.className = 'text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1';
            label.textContent = labels[code] || code;

            const text = document.createElement('div');
            text.className = 'text-sm text-gray-800 dark:text-slate-50 border rounded-md p-3 dark:border-gray-800 mb-4';
            text.innerHTML = texts[code];

            body.appendChild(label);
            body.appendChild(text);
        });

        accept.onclick = () => {
            applyAiTexts(texts);
            hideAiTextsModal();
        };

        modal.classList.remove('hidden');
    }

    function hideAiTextsModal() {
        document.getElementById('ai-texts-modal').classList.add('hidden');
    }

    /**
     * De huidige, mogelijk nog niet opgeslagen waarden uit het formulier.
     *
     * Namen zien eruit als values[common][merk] of values[common][prijs][EUR];
     * die laatste vorm wordt genest teruggegeven, want zo staat hij ook in de
     * database.
     */
    function readProductFormValues() {
        const values = {};

        document
            .querySelectorAll('[name^="values[common]["]')
            .forEach((input) => {
                if (input.disabled || input.type === 'file') {
                    return;
                }

                if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) {
                    return;
                }

                const keys = [...input.name.matchAll(/\[([^\]]+)\]/g)].map((m) => m[1]).slice(1);

                if (!keys.length) {
                    return;
                }

                const editor = window.tinymce ? tinymce.get(input.id) : null;
                const value = editor ? editor.getContent() : input.value;

                if (value === '' || value === null) {
                    return;
                }

                if (keys.length === 1) {
                    values[keys[0]] = value;
                } else {
                    values[keys[0]] = Object.assign({}, values[keys[0]], { [keys[1]]: value });
                }
            });

        return values;
    }

    /**
     * De velden zitten in een VeeValidate <v-field>, die een losse .value niet
     * ziet. Vandaar zowel setContent op de TinyMCE-instantie als input/change
     * op de onderliggende textarea — dezelfde aanpak als getMetaFields().
     */
    function applyAiTexts(texts) {
        Object.keys(texts).forEach((code) => {
            const editor = window.tinymce ? tinymce.get(code) : null;

            if (editor) {
                editor.setContent(texts[code]);
                editor.fire('keyup');
            }

            const field = document.querySelector('[name="values[common][' + code + ']"]');

            if (field) {
                field.value = texts[code];
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    /**
     * Een prijsveld vullen. De inputs zitten in een VeeValidate <v-field>, die
     * een losse .value niet ziet — vandaar beide events.
     */
    function setPriceField(code, value) {
        const input = document.querySelector('input[name="values[common][' + code + '][EUR]"]');

        if (!input) {
            return;
        }

        input.value = value;

        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function calcMetOnderkleed() {
        const sku = document.querySelector('input[name="sku"]').value;

// Maak een XHR request
        fetch('/product/met_onderkleed_price', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value
            },
            body: JSON.stringify({sku: sku})
        })
            .then(response => response.json())
            .then(data => {
                let message = 'De zonder onderkleed prijs is: €' + data.original_price
                    + '\nBerekende prijs is: €' + data.price;

                if (data.advies_price) {
                    message += '\n\nDe zonder onderkleed adviesverkoopprijs is: €' + data.original_advies_price
                        + '\nBerekende adviesverkoopprijs is: €' + data.advies_price;
                }

                message += '\n\n(Vergeet niet op te slaan na het bevestigen van de prijs)';

                if (confirm(message)) {
                    setPriceField('prijs', data.price);

                    // De adviesverkoopprijs is het plafond waar de dynamische
                    // prijsbepaling de bundelprijs tegenaan legt; die moet dus
                    // meelopen. Ontbreekt hij op de kale variant, dan valt er
                    // niets af te leiden en blijft het veld ongemoeid.
                    if (data.advies_price) {
                        setPriceField('adviesverkoopprijs', data.advies_price);
                    }
                }
            })
            .catch(error => {
                alert('Er is een fout opgetreden bij het berekenen van de prijs');
                console.error('Error:', error);
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const checkbox = document.getElementById('bol_com_sync');
        if (checkbox) {
            toggleDeleteWarning(checkbox);

            if (checkbox.checked) {
                const deliveryCodeSelect = document.getElementById('bol_com_delivery_code');
                if (deliveryCodeSelect) {
                    deliveryCodeSelect.setAttribute('required', 'required');
                }
            }
        }
    });

    function photoroomTransform(button, url, fieldLabel) {
        if (!confirm('Logo verwijderen uit "' + fieldLabel + '" via AI (Photoroom)?\n\nDit vervangt de huidige afbeelding in dit veld. De verwerking verloopt op de achtergrond.')) {
            return;
        }

        button.disabled = true;
        button.textContent = 'Bezig...';

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value,
            },
        })
            .then(response => response.json())
            .then(data => {
                alert(data.message ?? 'Transformatie gestart.');
                button.disabled = false;
                button.innerHTML = '<span class="icon-magic-wand text-sm"></span> Logo verwijderen via AI';
            })
            .catch(error => {
                alert('Er is een fout opgetreden.');
                console.error('Photoroom error:', error);
                button.disabled = false;
                button.innerHTML = '<span class="icon-magic-wand text-sm"></span> Logo verwijderen via AI';
            });
    }
</script>
