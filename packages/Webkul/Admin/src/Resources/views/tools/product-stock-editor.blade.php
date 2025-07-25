<x-admin::layouts>
    <v-product-stock-editor :products="{{ $products->toJson() }}"></v-product-stock-editor>
    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-product-stock-editor-template"
        >
            <!-- Input Form -->
            <x-admin::form
                :action="route('admin.tools.product-stock-editor.post',  ['brand' => $current_brand])"
                enctype="multipart/form-data"
            >

                <!-- actions buttons -->
                <div class="flex justify-between items-center sticky z-[10002] bg-white border-b" style="top: calc(57px + .75rem)">
                    <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                        Productvoorraad updaten
                    </p>

                    <div>
                        <select onchange="confirm('Heb je alles opgeslagen?') && (window.location.href = this.value)">
                            @foreach($brands as $brand)
                                <option value="{{ route('admin.tools.product-stock-editor.index', ['brand' => $brand]) }}" @selected($brand === $current_brand)>{{ $brand }}</option>
                            @endforeach
                        </select>
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
                                        <x-admin::table.th>Voorraad Eurogros</x-admin::table.th>
                                        <x-admin::table.th>Voorraad De Munk</x-admin::table.th>
                                        <x-admin::table.th>Voorraad Showroom</x-admin::table.th>
                                        <x-admin::table.th>Uitverkoop</x-admin::table.th>
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
                                                        :name="'product[' + product.id + '][voorraad_eurogros]'"
                                                        :value="product.voorraad_eurogros"
                                                        v-slot="{ field }"
                                                    >
                                                        <input
                                                            type="text"
                                                            :id="'product[' + product.id + '][voorraad_eurogros]'"
                                                            class="flex w-full min-h-[39px] py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 transition-all hover:border-gray-400 dark:hover:border-gray-400 dark:focus:border-gray-400 focus:border-gray-400 dark:bg-cherry-800 dark:border-gray-800"
                                                            name="'product[' + product.id + '][voorraad_eurogros]'"
                                                            v-model="product.voorraad_eurogros"
                                                            v-bind="field"
                                                        >
                                                    </v-field>
                                                </x-admin::form.control-group>
                                            </x-admin::table.td>
                                            <x-admin::table.td>
                                                <x-admin::form.control-group>
                                                    <v-field
                                                        type="text"
                                                        :name="'product[' + product.id + '][voorraad_5_korting_handmatig]'"
                                                        :value="product.voorraad_5_korting_handmatig"
                                                        v-slot="{ field }"
                                                    >
                                                        <input
                                                            type="text"
                                                            :id="'product[' + product.id + '][voorraad_5_korting_handmatig]'"
                                                            class="flex w-full min-h-[39px] py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 transition-all hover:border-gray-400 dark:hover:border-gray-400 dark:focus:border-gray-400 focus:border-gray-400 dark:bg-cherry-800 dark:border-gray-800"
                                                            name="'product[' + product.id + '][voorraad_5_korting_handmatig]'"
                                                            v-model="product.voorraad_5_korting_handmatig"
                                                            v-bind="field"
                                                        >
                                                    </v-field>
                                                </x-admin::form.control-group>
                                            </x-admin::table.td>
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
                                            <x-admin::table.td>
                                                <x-admin::form.control-group>
                                                    <v-field
                                                        type="text"
                                                        :name="'product[' + product.id + '][uitverkoop_15_korting]'"
                                                        :value="product.uitverkoop_15_korting"
                                                        v-slot="{ field }"
                                                    >
                                                        <input
                                                            type="text"
                                                            :id="'product[' + product.id + '][uitverkoop_15_korting]'"
                                                            class="flex w-full min-h-[39px] py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 transition-all hover:border-gray-400 dark:hover:border-gray-400 dark:focus:border-gray-400 focus:border-gray-400 dark:bg-cherry-800 dark:border-gray-800"
                                                            name="'product[' + product.id + '][uitverkoop_15_korting]'"
                                                            v-model="product.uitverkoop_15_korting"
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
            });
        </script>
    @endPushOnce
</x-admin::layouts>
