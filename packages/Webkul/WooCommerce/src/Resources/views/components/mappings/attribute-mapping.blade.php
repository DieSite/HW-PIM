@props([
'id' => null,
'standardAttributes' => [],
'defaultMappings' => [],
'mediaMapping' => [],
'customAttributes' => [],
'attributeMappings' => [],
'enableSelect' => 0,
])
<v-create-attributes-mappings
    :id="$id"
    :standard-attributes="$standardAttributes"
    :default-mappings="$defaultMappings"
    :media-mapping="{{json_encode($mediaMapping)}}"
    :custom-attributes="{{json_encode($customAttributes)}}"
    :attribute-mappings="$attributeMappings" />

@pushOnce('styles')
<style>
    .cursor-not-allowed input {
        cursor: not-allowed;
    }
</style>
@endPushOnce
@pushOnce('scripts')
<script
    type="text/x-template"
    id="v-create-attributes-mapping-template">
    <x-admin::form  
            :action="route('woocommerce.attribute-mapping.update', $id)" enctype="multipart/form-data"
        >
        @method('PUT')
            <x-admin::form.control-group.control type="hidden" name="tab" value="attribute-mapping"/>
            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('woocommerce::app.woocommerce.credential.edit.tab.attributeMapping.title')
                </p>

                <div class="flex gap-x-2.5 items-center">
                    <!-- Cancel Button -->
                    <a
                        href="{{ route('woocommerce.credentials.index') }}"
                        class="transparent-button"
                    >
                        @lang('admin::app.catalog.attribute-groups.create.back-btn')
                    </a>

                    <!-- Save Button -->
                    <button
                        type="submit"
                        class="primary-button"
                    >
                        @lang('woocommerce::app.woocommerce.credential.edit.tab.attributeMapping.save')
                    </button>
                </div>
            </div>
           
            <div class="flex gap-2.5 mt-3.5 max-xl:flex-wrap">   
                @php
                $errorMessage = '';
                if (isset($standardAttributes['error'])) {
                    $errorMessage = $standardAttributes['error'];
                    $standardAttributes = [];
                @endphp
                    <p class="mt-1 text-red-600 text-xs italic">{{ $errorMessage }}</p>
                @php
                }
                @endphp

                @php
                    $localeOptions = core()->getAllActiveLocales();
                    $channelOptions = core()->getAllChannels();
                    $currencyOptions = core()->getAllActiveCurrencies();
                    $oldValues = [];
                    foreach ($standardAttributes as $attr) {
                        $oldValues[$attr['name']] = old('default_' . $attr['name']);
                    }
                    
                    @endphp
                       <div class="w-full p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all">
                            <p class="break-words font-bold">@lang('woocommerce::app.mappings.attribute-mapping.field-woocommerce')</p>
                            <p class="break-words font-bold">@lang('woocommerce::app.mappings.attribute-mapping.unopim-attribute')</p>
                            <p class="break-words font-bold">@lang('woocommerce::app.mappings.attribute-mapping.fixed-value')</p>
                        </div>
                        <template v-for="(field, fieldIndex) in standardAttributes" :key="fieldIndex">
                            <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                                <p class="break-words">@{{ field.label }} {{ ' [' }}@{{ field.name }}{{ ']' }}
                                    <span 
                                            class="required text-red-600" 
                                            v-if="field.required"
                                        >
                                        </span>
                                    <p class='my-2.5'><span class="icon-information"></span>
                                        <span
                                        class="text-xs ml-2">@{{ field.tooltip ? field.tooltip : '@lang("woocommerce::app.mappings.attribute-mapping.other-mapping.tooltip").' }}
                                        </span>
                                    </p>
                                </p>

                                <x-admin::form.control-group class="!mb-0">
                                    <v-async-select-handler
                                        :type="field.name === 'tags' ? 'multiselect' : 'select'"
                                        :name="field.name"
                                        :value="attributeMappings[field.name] ?? ''"
                                        :label="field.label"
                                        :rules="field.required ? 'required' : ''"
                                        :placeholder="field.label"
                                        track-by="code"
                                        label-by="label"
                                        :entityName="JSON.stringify(field.types)"
                                        async=true
                                        list-route="{{ route('admin.woocommerce.get-attribute')}}"
                                        @input="handleSelectChange($event, field.name)"
                                        :disabled="isDisabled(field.name) ? 'disabled' : false"
                                    />
                                    <v-error-message
                                        :name="[field.name]"
                                        v-slot="{ message }"
                                    >
                                        <p
                                            class="mt-1 text-red-600 text-xs italic"
                                            v-text="message"
                                        >
                                        </p>
                                    </v-error-message>
                                </x-admin::form.control-group>
                                <div class="flex w-full">
                                <x-admin::form.control-group class="!mb-0 w-full" ::class="{'cursor-not-allowed' : isDisabled(getDefaultFieldName(field.name)) ? 'cursor-not-allowed' : '' } ">
                                    <x-admin::form.control-group.control
                                        type="text"
                                        ::name="getDefaultFieldName(field.name)"
                                        ::id="getDefaultFieldName(field.name)"
                                        ::value="oldValues[`${field?.name}`] ?? (defaultMappings[field?.name] ?? '')"
                                        ::placeholder="field.label"
                                        @input="isFieldDisabled($event, `${field.name}`)"
                                        ::disabled="isDisabled(field.name) ? 'disabled' : false"
                                    />
                                    <x-admin::form.control-group.error ::control-name="`default_${field.name}`" />
                                </x-admin::form.control-group>
                                </div>
                            </div>
                        </template>

                    </div>
            </div>
            <div class="w-full p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all">
                    <p class="break-words font-bold">@lang('woocommerce::app.mappings.attribute-mapping.other-mapping.title')</p>
                </div>
                <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all">
                <p> @lang('woocommerce::app.mappings.attribute-mapping.other-mapping.enabled') </p>
                <x-admin::form.control-group class="flex items-center">
                    <div>
                        <input
                            type="hidden"
                            name="enableSelect"
                            value="0" />

                        <x-admin::form.control-group.control
                            type="switch"
                            name="enableSelect"
                            value="1"
                            ref="enableSelect"
                            @change="toggleSwitch"
                            v-model="nonSelectAsSelect"
                            ::checked="enableSelect" />
                    </div>
                    <div class="mb-2">
                        <span class="text-l ml-2 isEnabled">
                            {{ $enableSelect ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                </x-admin::form.control-group>
                </div>
                <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                    <p class="break-words">@lang('woocommerce::app.mappings.attribute-mapping.other-mapping.custom-mapping')</p>
                    @php 

                    $oldValues = implode(',',json_decode($customAttributes));
                    @endphp
                    <x-admin::form.control-group class="!mb-0"  v-if=" (nonSelectAsSelect == true)">
                        <x-admin::form.control-group.control
                        type="multiselect"
                        name="custom_field"
                        label="trans('woocommerce::app.configuration.fields.custom_field')"
                        track-by="code"
                        label-by="label"
                        ::queryParams="[1]"
                        async="true"
                        :value="$oldValues ?? ''"
                        :list-route="route('woocommerce.additional_attributes.options.get',['credentialId' => $id])"
                        @select-option="selectOption"
                        entityName="attributes"
                        @remove-option="removeOption"
                        />

                        <x-admin::form.control-group.error control-name="custom_field" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="!mb-0"  v-else>
                        <x-admin::form.control-group.control
                        type="multiselect"
                        name="custom_field"
                        label="trans('woocommerce::app.configuration.fields.custom_field')"
                        track-by="code"
                        label-by="label"
                        ::queryParams="[0]"
                        async="true"
                        :value="$oldValues ?? ''"
                        :list-route="route('woocommerce.additional_attributes.options.get',['credentialId' => $id])"
                        @select-option="selectOption"
                        entityName="attributes"
                        @remove-option="removeOption"
                        />

                        <x-admin::form.control-group.error control-name="custom_field" />
                    </x-admin::form.control-group>
                </div>
                <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                    <p class="break-words">@lang('woocommerce::app.mappings.attribute-mapping.other-mapping.media-mapping')</p>
                    @php 
                    $oldValues = implode(',',json_decode($mediaMapping));
                    @endphp
                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.control
                        type="multiselect"
                        name="media"
                        label="trans('woocommerce::app.configuration.fields.media')"
                        track-by="code"
                        label-by="label"
                        async="true"
                        :value="$oldValues ?? ''"
                        :list-route="route('woocommerce.media.options.get',['credentialId' => $id])"
                        @select-option="selectOption"
                        entityName="attributes"
                        @remove-option="removeOption"
                        />
                        <x-admin::form.control-group.error control-name="media" />
                    </x-admin::form.control-group>
                </div>
            </div>
        </x-admin::form>
    </script>
<script type="module">
    app.component('v-create-attributes-mappings', {
        template: '#v-create-attributes-mapping-template',
        props: {
            id: {
                type: String,
                required: true
            },
            standardAttributes: {
                type: Array,
                required: true
            },
            customAttributes: {
                type: Array,
                required: true
            },
            mediaMapping: {
                type: Array,
                required: true
            },
        },
        data() {
            return {
                onchange: {},
                customAttributes: @json($customAttributes),
                standardAttributes: @json($standardAttributes),
                attributeMappings: @json($attributeMappings),
                oldValues: @json($oldValues),
                defaultMappings: @json($defaultMappings),
                id: @json($id),
                nonSelectAsSelect: @json($enableSelect),
                disabledFields: {},
            };
        },
        watch: {
            enableTagsAttribute(newValue) {
                this.nonSelectAsSelect = newValue == undefined ? false : true;
            },
        },
        mounted() {
            this.enableSelect = this.nonSelectAsSelect == 0 ? false : true;
            this.processDisabledFields();
        },
        methods: {
            isDisabled(fieldName) {
                return this.disabledFields[fieldName];
            },

            getDefaultFieldName(fieldName) {
                return fieldName.startsWith('default_') ? fieldName : `default_${fieldName}`;
            },

            toggleSwitch() {
                this.enableSelect = this.$refs.enableSelect.checked;
                document.querySelector('.isEnabled').innerText = this.enableSelect ? 'Enabled' : 'Disabled';
            },

            parseJson(value) {
                try {
                    return value ? JSON.parse(value) : [];
                } catch (error) {
                    return [];
                }
            },

            handleDependentChange(fieldName, dependentFieldName) {
                let value = this.$refs[fieldName].selectedOption;
                this.$refs[dependentFieldName].params[fieldName] = value;
                this.$refs[dependentFieldName].optionsList = '';
            },

            handleSelectChange(event, fieldName) {
                var defaultFieldName = 'default_' + fieldName;
                if (defaultFieldName === 'default_slug' || defaultFieldName === 'default_sku') {
                    this.disabledFields[defaultFieldName] = true;
                    return;
                }

                if (!event) {
                    this.disabledFields[defaultFieldName] = false;
                } else {
                    this.disabledFields[defaultFieldName] = true;
                }
            },

            handleChange(event, fieldName) {
                if (!event) {
                    this.disabledFields[fieldName] = false;
                } else {
                    this.disabledFields[fieldName] = true;
                }
            },

            isFieldDisabled(event, defaultFieldName) {
                if (defaultFieldName === 'default_slug' || defaultFieldName === 'default_sku') {
                    this.disabledFields[defaultFieldName] = true;
                    return;
                }
                let fieldName = defaultFieldName.replace('default_', '');
                const value = event.target.value;

                this.disabledFields[fieldName] = true;
                if (value == 'null' || value == '' || !value) {
                    this.disabledFields[fieldName] = false;
                }
            },

            processDisabledFields() {
                Object.keys(this.defaultMappings).forEach((key) => {
                    this.disabledFields[key] = true;
                });

                return this.disabledFields;
            }
        }
    });
</script>
@endPushOnce