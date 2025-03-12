<x-admin::layouts>
    <x-slot:title>
        @lang('woocommerce::app.woocommerce.credential.index.title')
        </x-slot>

        <v-credential>
            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('woocommerce::app.woocommerce.credential.index.title')
                </p>

                <div class="flex gap-x-2.5 items-center">
                    <!-- Create User Button -->
                    @if (bouncer()->hasPermission('shopify.credentials.create'))
                    <button
                        type="button"
                        class="primary-button">
                        @lang('woocommerce::app.woocommerce.credential.index.create')
                    </button>
                    @endif
                </div>
            </div>

            <!-- DataGrid Shimmer -->
            <x-admin::shimmer.datagrid />
        </v-credential>
        @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-credential-template">
            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('woocommerce::app.woocommerce.credential.index.title')
                </p>

                <div class="flex gap-x-2.5 items-center">
                    <!-- User Create Button -->
                    @if (bouncer()->hasPermission('shopify.credentials.create'))
                        <button
                            type="button"
                            class="primary-button"
                            @click="$refs.credentialCreateModal.open()"
                        >
                            @lang('woocommerce::app.woocommerce.credential.index.create')
                        </button>
                    @endif
                </div>
            </div>
            <!-- Datagrid -->
            <x-admin::datagrid :src="route('woocommerce.credentials.index')" ref="datagrid" class="mb-8"/>

            <!-- Modal Form -->
            <x-admin::form
                v-slot="{ meta, errors, handleSubmit }"
                as="div"
                ref="modalForm"
            >
                <form
                    @submit="handleSubmit($event, create)"
                    ref="credentialCreateForm"
                >
                    <!-- User Create Modal -->
                    <x-admin::modal ref="credentialCreateModal">
                        <!-- Modal Header -->
                        <x-slot:header>
                             <p class="text-lg text-gray-800 dark:text-white font-bold">
                                @lang('woocommerce::app.woocommerce.credential.index.create')
                            </p>

                        </x-slot>

                        <!-- Modal Content -->
                        <x-slot:content>
                            <!-- Name -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('woocommerce::app.woocommerce.credential.index.url')
                                </x-admin::form.control-group.label>
                                <x-admin::form.control-group.control
                                    type="text"
                                    id="shopUrl"
                                    name="shopUrl"
                                    rules="required"
                                    :label="trans('woocommerce::app.woocommerce.credential.index.url')"
                                    :placeholder="trans('woocommerce::app.woocommerce.credential.index.woocommerceurlplaceholder')"
                                />

                                <x-admin::form.control-group.error control-name="shopUrl" />
                            </x-admin::form.control-group>

                            <!-- consumerKey -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('woocommerce::app.woocommerce.credential.index.consumerKey')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    id="consumerKey"
                                    name="consumerKey"
                                    rules="required"
                                    :label="trans('woocommerce::app.woocommerce.credential.index.consumerKey')"
                                    :placeholder="trans('woocommerce::app.woocommerce.credential.index.consumerKey')"
                                />

                                <x-admin::form.control-group.error control-name="consumerKey" />
                            </x-admin::form.control-group>

                            <!-- consumerSecret -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('woocommerce::app.woocommerce.credential.index.consumerSecret')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="password"
                                    id="consumerSecret"
                                    name="consumerSecret"
                                    rules="required"
                                    :label="trans('woocommerce::app.woocommerce.credential.index.consumerSecret')"
                                    :placeholder="trans('woocommerce::app.woocommerce.credential.index.consumerSecret')"
                                />

                                <x-admin::form.control-group.error control-name="consumerSecret" />
                            </x-admin::form.control-group>  
             
                        </x-slot>

                        <!-- Modal Footer -->
                        <x-slot:footer>
                            <div class="flex gap-x-2.5 items-center">
                                <button
                                    type="submit"
                                    class="primary-button"
                                >
                                    @lang('woocommerce::app.woocommerce.credential.index.save')
                                </button>
                            </div>
                        </x-slot>
                    </x-admin::modal>
                </form>
            </x-admin::form>
        </script>

        <script type="module">
            app.component('v-credential', {
                template: '#v-credential-template',

                methods: {
                    create(params, {
                        setErrors
                    }) {
                        let formData = new FormData(this.$refs.credentialCreateForm);

                        this.$axios.post("{{ route('woocommerce.credentials.store') }}", formData)
                            .then((response) => {
                                window.location.href = response.data.redirect_url;
                            })
                            .catch(error => {
                                if (error.response.status == 422) {
                                    setErrors(error.response.data.errors);
                                }
                            });
                    },
                }
            })
        </script>
        @endPushOnce
</x-admin::layouts>