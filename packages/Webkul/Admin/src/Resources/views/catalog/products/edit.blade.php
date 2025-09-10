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
                    @endif

                    <a href="{{ route('product.frontend', ['product' => $product->id]) }}" class="secondary-button" target="_blank">
                        Naar frontend
                    </a>

                    <!-- Save Button -->
                    <button class="primary-button">
                        @lang('admin::app.catalog.products.edit.save-btn')
                    </button>
                </div>
            </div>
        </div>

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
                if(confirm('(Vergeet niet op te slaan na het bevestigen van de teksten)\n\nMeta titel: ' + data.meta_title + "\n\n" + 'Meta beschrijving: \n' + data.meta_description)) {
                    document.querySelector('input[name="values[common][meta_titel]"]').value = data.meta_title;
                    tinymce.get("meta_beschrijving").setContent(data.meta_description);
                }
            })
            .catch(error => {
                alert('Er is een fout opgetreden bij het ophalen van de meta');
                console.error('Error:', error);
            });
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
                if(confirm('De zonder onderkleed prijs is: €' + data.original_price + '\nBerekende prijs is: €' + data.price + "\n(Vergeet niet op te slaan na het bevestigen van de prijs)")) {
                    document.querySelector('input[name="values[common][prijs][EUR]"]').value = data.price;
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
</script>
