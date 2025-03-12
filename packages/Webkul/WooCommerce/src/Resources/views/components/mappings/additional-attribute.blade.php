@props([
'fieldValues' => [],
'additionalAttributes' => [],
'credentialId' => null,
'addAttributeRoute' => route('woocommerce.mappings.additional_attributes.add',['credentialId' => $credentialId]),
'route' => route('woocommerce.additional_attributes.options.get',['credentialId' => $credentialId])
])


<v-additional-attribute-mapping
    @add-attribute="handleAddAttribute"
    add-attribute-route="{{ $addAttributeRoute }}">
    {{ $slot }}
</v-additional-attribute-mapping>

@pushOnce('scripts')
<script type="text/x-template" id="v-additional-attribute-mapping-template">
    <div class="mt-5 bg-white dark:bg-cherry-900 rounded-lg shadow-md border border-gray-300 dark:border-gray-800">
        <table class="w-full table-auto">
            <thead class="bg-violet-50 dark:bg-cherry-800">
                <tr class="text-left">
                    <th class="px-4 py-2 text-md font-medium text-gray-700 dark:text-slate-50 " style="width: 45%;">
                        <p class="text-base text-gray-800 dark:text-gray-300 font-semibold">
                            @lang('woocommerce::app.mappings.attribute-mapping.other-mapping.title')
                        </p>
                   </th>
                    <th class="px-4 py-2 text-md font-medium text-gray-700 dark:text-slate-50"></th>
                    <th class="px-4 py-2 text-md font-medium text-gray-700 dark:text-slate-50"></th>
                </tr>
            </thead>
            <tbody class="text-gray-600 dark:text-gray-300 w-full">
                    <tr class=" transition-all w-full border-b dark:border-cherry-800">
                        <!-- Field Name (Label) -->
                        <td class="px-4 py-2 text-sm " style="width: 45%;">

                            <p class="text-xs text-gray-500 py-2"><span class="icon-information text-violet-600"></span> @lang('woocommerce::app.mappings.attribute-mapping.other-mapping.desc')</p>
                        </td>

                        <!-- Mapped Field (Select Dropdown) -->
                        <td class="px-4 py-2" style="width: 50%;">
                            <x-admin::form.control-group>
                                <v-taggingselect-handler
                                    id="newAdditionalAttributes"
                                    name="newAdditionalAttributes"
                                    v-model="newAdditionalAttributes"
                                    label="@lang('woocommerce::app.mappings.attribute-mapping.other-mapping.label')"
                                    value=""
                                    placeholder="@lang('woocommerce::app.mappings.attribute-mapping.other-mapping.placeholder')"
                                    ref="taggingField"
                                    @add-option="addAdditionalAttribute"
                                    @select-option="addAdditionalAttribute"
                                    list-route="{{ $route }}"
                                    :entity-name="'attributes'"
                                    track-by="code"
                                    label-by="label"
                                >
                                </v-taggingselect-handler>
                                <x-admin::form.control-group.error control-name="newAdditionalAttributes" />
                        </x-admin::form.control-group>
                        </td>
                    </tr>
            </tbody>
        </table>
    </div>

    </script>

<script type="module">
    app.component('v-additional-attribute-mapping', {
        template: '#v-additional-attribute-mapping-template',
        props: ['attributes', 'addAttributeRoute', 'fieldValues'],

        data() {
            return {
                uuid: null,
                newAdditionalAttributes: '',
                attributeType: '',
                mappedAdditionalAttributes: @json($additionalAttributes),
            };
        },

        methods: {

            addAdditionalAttribute(field) {

                if (!field?.target?.value) {
                    this.$emitter.emit('add-flash', {
                        type: 'error',
                        message: 'Attribute Not Found'
                    });
                    return;
                }

                field = field?.target?.value;

                if (this.addAttributeRoute) {
                    this.saveAdditionalField(field);
                } else {
                    this.emitNewField(field);
                }

                this.$refs.taggingField.selectedValue = [];
            },

            saveAdditionalField(field) {
                this.uuid = this.generateRandomCode(10);
                const id = 'undefined' !== typeof field.id ? field.id : this.uuid;
                const type = 'undefined' !== typeof field.type ? field.type : null;
                return this.$axios.post(this.addAttributeRoute, {
                        id: id,
                        code: field.code,
                        type: type
                    })
                    .then(response => {
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message
                        });

                        this.emitNewField(field);
                    })
                    .catch(error => {
                        let errorResponse = error.response;
                        console.log(error);
                        if (errorResponse.status > 400 && errorResponse.status < 500 && errorResponse?.data?.message) {
                            this.$emitter.emit('add-flash', {
                                type: 'warning',
                                message: errorResponse.data.message
                            });
                        }
                    });
            },

            emitNewField(field) {
                let label = 'undefined' !== typeof field.label ? field.label : field.code;
                const id = 'undefined' !== typeof field.id ? field.id : this.uuid;
                const newAttribute = {
                    id: id,
                    label: label,
                    code: field.code,
                    name: field.code,
                    types: 'undefined' === typeof field.type ? [] : [field.type],
                    removable: true,
                    isEditable: 'undefined' === typeof field.type ? true : false,
                };

                this.$emit('add-attribute', newAttribute);

                this.mappedAdditionalAttributes.push(newAttribute);

                this.newAdditionalAttributes = '';

                this.attributeType = '';
            },
            generateRandomCode(length) {
                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                let result = '';
                for (let i = 0; i < length; i++) {
                    result += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                return result;
            }
        },



    });
</script>
@endPushOnce