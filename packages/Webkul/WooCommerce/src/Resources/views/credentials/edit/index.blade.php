<x-woocommerce::layouts.with-history.credential>
    <x-slot:title>
        @lang('dam::app.admin.dam.asset.edit.title')
    </x-slot:title>

    <x-slot:entityName>
        woocommerce_credentials
        </x-slot>

        @php
        $items = [
        [
        'url' => '?',
        'code' => 'preview',
        'name' => 'woocommerce::app.woocommerce.credential.edit.tab.credential-settings.label',
        'icon' => ''
        ],
        ];

        if (bouncer()->hasPermission('dam.asset.property')) {
        $items[] = [
        'url' => '?attribute-mapping',
        'code' => 'attribute-mapping',
        'name' => 'woocommerce::app.woocommerce.credential.edit.tab.attributeMapping.label',
        'icon' => ''
        ];
        }

        if (bouncer()->hasPermission('history.view')) {
        $items[] = [
        'url' => '?history',
        'code' => 'history',
        'name' => 'woocommerce::app.woocommerce.credential.edit.tab.history.label',
        'icon' => ''
        ];
        }

        @endphp

        <x-slot:add-tabs :items="$items"></x-slot:add-tabs>
        <v-edit-asset></v-edit-asset>
        <x-slot:attributes>
            {!! view_render_event('unopim.admin.woocommerce.assets.edit.attribute-mapping.before') !!}
            @include('woocommerce::credentials.edit.attribute-mapping.index')
            {!! view_render_event('unopim.admin.woocommerce.assets.edit.attribute-mapping.after') !!}
        </x-slot:attributes>

        <x-slot:mapping>
            {!! view_render_event('unopim.admin.woocommerce.assets.edit.attribute-mapping.before') !!}
            @include('woocommerce::credentials.edit.attribute-mapping.index')
            {!! view_render_event('unopim.admin.woocommerce.assets.edit.attribute-mapping.after') !!}
        </x-slot:mapping>

        <x-slot:title>
            @lang('woocommerce::app.woocommerce.credential.index.title')
            </x-slot>

            <x-admin::form
                :action="route('woocommerce.credentials.update', ['id' => $credential->id])">
                @method('PUT')
                <x-admin::form.control-group.control type="hidden" name="tab" value="credential-settings" />
                <div class="flex justify-between items-center">
                    <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                        @lang('woocommerce::app.woocommerce.credential.edit.title')
                    </p>

                    <div class="flex gap-x-2.5 items-center">
                        <a
                            href="{{ route('woocommerce.credentials.index') }}"
                            class="transparent-button">
                            @lang('woocommerce::app.woocommerce.credential.edit.back-btn')
                        </a>

                        <button
                            type="submit"
                            class="primary-button"
                            aria-lebel="Submit">
                            @lang('woocommerce::app.woocommerce.credential.edit.save')
                        </button>
                    </div>
                </div>

                <!-- body content -->
                <div class="flex gap-2.5 mt-3.5 max-xl:flex-wrap">
                    <!-- Left Section -->
                    <div class="flex flex-col gap-2 flex-1 max-xl:flex-auto">

                        <!-- General Information -->
                        <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                            <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
                                @lang('admin::app.settings.channels.edit.general')
                            </p>

                            <!-- shopUrl -->
                            <x-admin::form.control-group class="w-[525px]">
                                <x-admin::form.control-group.label class="required">
                                    @lang('woocommerce::app.woocommerce.credential.index.url')
                                </x-admin::form.control-group.label>
                                <x-admin::form.control-group.control
                                    type="hidden"
                                    name="shopUrl"
                                    :value="old('shopUrl') ?? $credential->shopUrl" />
                                <x-admin::form.control-group.control
                                    type="text"
                                    id="shopUrl"
                                    name="shopUrl"
                                    rules="required"
                                    :disabled=false
                                    :value="old('shopUrl') ?? $credential->shopUrl"
                                    :label="trans('woocommerce::app.woocommerce.credential.index.url')"
                                    :placeholder="trans('woocommerce::app.woocommerce.credential.index.woocommerceurlplaceholder')" />

                                <x-admin::form.control-group.error control-name="shopUrl" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group class="w-[525px]">
                                <x-admin::form.control-group.label class="required">
                                    @lang('woocommerce::app.woocommerce.credential.index.consumerKey')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    id="consumerKey"
                                    name="consumerKey"
                                    rules="required"
                                    :value="old('consumerKey') ?? $credential->consumerKey"
                                    :label="trans('woocommerce::app.woocommerce.credential.index.consumerKey')"
                                    :placeholder="trans('woocommerce::app.woocommerce.credential.index.consumerKey')" />

                                <x-admin::form.control-group.error control-name="consumerKey" />
                            </x-admin::form.control-group>
                            <x-admin::form.control-group class="w-[525px]">
                                <x-admin::form.control-group.label class="required">
                                    @lang('woocommerce::app.woocommerce.credential.index.consumerSecret')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="password"
                                    id="consumerSecret"
                                    name="consumerSecret"
                                    rules="required"
                                    :value="old('consumerSecret') ?? $credential->consumerSecret"
                                    :label="trans('woocommerce::app.woocommerce.credential.index.consumerSecret')"
                                    :placeholder="trans('woocommerce::app.woocommerce.credential.index.consumerSecret')" />

                                <x-admin::form.control-group.error control-name="consumerSecret" />
                            </x-admin::form.control-group>
                        </div>
                    </div>
                    <!-- right Section -->
                    <div class="flex flex-col gap-2 w-[360px] max-w-full max-sm:w-full">

                        <!-- General Information -->
                        <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                            <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
                                @lang('woocommerce::app.woocommerce.credential.edit.settings')
                            </p>

                            <!-- Enable/Disable -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>
                                    @lang('woocommerce::app.woocommerce.credential.edit.active')
                                </x-admin::form.control-group.label>
                                <input
                                    type="hidden"
                                    name="active"
                                    value="0" />

                                <x-admin::form.control-group.control
                                    type="switch"
                                    name="active"
                                    value="1"
                                    :checked="(boolean) $credential->active" />
                            </x-admin::form.control-group>
                        </div>
                    </div>
                </div>

            </x-admin::form>

            </x-admin::layouts.with-history.asset>