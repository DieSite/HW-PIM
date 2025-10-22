<x-admin::layouts>
    <v-errored-products :products="{{ $products->toJson() }}"></v-errored-products>
    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-errored-products-template"
        >
            <!-- Input Form -->
            <div>

                <!-- actions buttons -->
                <div class="flex justify-between items-center sticky z-[10002] bg-white border-b"
                     style="top: calc(57px + .75rem)">
                    <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                        Producten met errors
                    </p>
                </div>

                <!-- body content -->
                <div class="flex flex-col gap-4">

                    <!-- Content -->
                    <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">

                        <x-admin::flash-group/>

                        <div class="overflow-x-auto overflow-y-auto">
                            <x-admin::table class="w-full text-left border-collapse">
                                <x-admin::table.thead>
                                    <x-admin::table.thead.tr>
                                        <x-admin::table.th>Vloerkleed</x-admin::table.th>
                                        <x-admin::table.th>Maat</x-admin::table.th>
                                        <x-admin::table.th>Bekijken</x-admin::table.th>
                                    </x-admin::table.thead.tr>
                                </x-admin::table.thead>
                                <x-admin::table.tbody>
                                    <template v-if="products.length > 0">
                                        <x-admin::table.tbody.tr v-for="product in products">
                                            <x-admin::table.td v-text="product.productnaam"/>
                                            <x-admin::table.td v-text="product.maat.length > 0 ? product.maat : 'hoofdproduct'"/>
                                            <x-admin::table.td>
                                                <a :href="'/admin/catalog/products/edit/' + product.id">Bekijken</a>
                                            </x-admin::table.td>
                                        </x-admin::table.tbody.tr>
                                    </template>
                                    <template v-else>
                                        <x-admin::table.tbody.tr>
                                            <x-admin::table.td>Geen producten gevonden!</x-admin::table.td>
                                        </x-admin::table.tbody.tr>
                                    </template>
                                </x-admin::table.tbody>
                            </x-admin::table>
                        </div>
                    </div>
                </div>
            </div>


        </script>

        <script type="module">
            app.component('v-errored-products', {
                template: '#v-errored-products-template',

                props: ['products'],

                data() {
                    return {
                        search: '',
                    };
                },

                created() {
                    const url = new URL(window.location.href);
                    const querySearch = url.searchParams.get('search');
                    if (querySearch !== null) {
                        this.search = querySearch;
                    }
                    console.log(this.products);
                },

                methods: {
                    doSearch() {
                        const url = new URL(window.location.href);
                        if (this.search.length > 0) {
                            url.searchParams.set('search', this.search);
                        } else {
                            url.searchParams.delete('search');
                        }
                        window.location.href = url.toString();
                    }
                }
            });
        </script>
    @endPushOnce
</x-admin::layouts>
