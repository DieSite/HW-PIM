<x-admin::layouts>
    <v-product-stock-editor :products="{{ $products->toJson() }}"></v-product-stock-editor>
    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-product-stock-editor-template"
        >
            <!-- Input Form -->
            <x-admin::form
                :action="route('admin.tools.product-hw-stock-editor.post')"
                enctype="multipart/form-data"
            >

                <!-- actions buttons -->
                <div class="flex justify-between items-center sticky z-[10002] bg-white border-b" style="top: calc(57px + .75rem)">
                    <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                        Showroom-voorraad updaten (toont alleen huidige voorraad)
                    </p>

                    <div>
                        <input @class('px-3') @keyup.enter="doSearch()" v-model="search" placeholder="Zoeken op titel">
                    </div>

                    <div v-if="products.current_page === products.last_page" class="flex gap-x-2.5 items-center">
                        <!-- Save Button -->
                        <button
                            type="submit"
                            class="primary-button"
                        >
                            Updaten (laatste pagina)
                        </button>
                    </div>
                    <div v-else class="flex gap-x-2.5 items-center">
                        <!-- Save Button -->
                        <button
                            type="submit"
                            class="primary-button"
                        >
                            Updaten en naar volgende pagina (pagina <span v-text="products.current_page"></span> van <span v-text="products.last_page"></span>)
                        </button>
                        <input type="hidden" name="next_page" :value="products.current_page + 1">
                        <input type="hidden" name="search" :value="search">
                    </div>
                </div>

                <!-- body content -->
                <div class="flex flex-col gap-4">

                    <!-- Content -->
                    <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">

                        <x-admin::flash-group />

                        <div class="overflow-x-auto overflow-y-auto">
                            <x-admin::table class="w-full text-left border-collapse">
                                <x-admin::table.thead>
                                    <x-admin::table.thead.tr>
                                        <x-admin::table.th>Vloerkleed</x-admin::table.th>
                                        <x-admin::table.th>Maat</x-admin::table.th>
                                        <x-admin::table.th>Voorraad Showroom</x-admin::table.th>
                                    </x-admin::table.thead.tr>
                                </x-admin::table.thead>
                                <x-admin::table.tbody>
                                    <template v-if="products.total > 0">
                                        <x-admin::table.tbody.tr v-for="product in products.data">
                                            <x-admin::table.td v-text="product.productnaam" />
                                            <x-admin::table.td v-text="product.maat" />
                                            <x-admin::table.td>
                                                <x-admin::form.control-group>
                                                    <v-field
                                                        type="text"
                                                        :name="'product[' + product.id + '][voorraad_hw_5_korting]'"
                                                        :value="product.voorraad_hw_5_korting"
                                                        v-slot="{ field }"
                                                    >
                                                        <input
                                                            type="text"
                                                            :id="'product[' + product.id + '][voorraad_hw_5_korting]'"
                                                            class="flex w-full min-h-[39px] py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 transition-all hover:border-gray-400 dark:hover:border-gray-400 dark:focus:border-gray-400 focus:border-gray-400 dark:bg-cherry-800 dark:border-gray-800"
                                                            name="'product[' + product.id + '][voorraad_hw_5_korting]'"
                                                            v-model="product.voorraad_hw_5_korting"
                                                            v-bind="field"
                                                        >
                                                    </v-field>
                                                </x-admin::form.control-group>
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
            </x-admin::form>


        </script>

        <script type="module">
            app.component('v-product-stock-editor', {
                template: '#v-product-stock-editor-template',

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
