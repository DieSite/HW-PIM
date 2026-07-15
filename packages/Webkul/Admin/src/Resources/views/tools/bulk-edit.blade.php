<x-admin::layouts>
    <x-slot:title>Bulk bewerken</x-slot>

    <v-bulk-edit
        :brands="{{ json_encode($brands) }}"
        :attributes="{{ json_encode($attributes) }}"
        preview-url="{{ route('admin.tools.bulk-edit.preview') }}"
        apply-url="{{ route('admin.tools.bulk-edit.apply') }}"
        csrf-token="{{ csrf_token() }}"
    ></v-bulk-edit>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-bulk-edit-template">
            <div class="flex flex-col gap-4">
                <div class="flex justify-between items-center">
                    <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">Bulk bewerken</p>
                </div>

                <x-admin::flash-group />

                <form :action="applyUrl" method="POST" @submit="onSubmit">
                    <input type="hidden" name="_token" :value="csrfToken">

                    <!-- 1. FILTER: which products -->
                    <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6 mb-4">
                        <p class="text-lg font-bold mb-4 text-gray-800 dark:text-slate-50">1. Selecteer producten</p>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Merk</x-admin::form.control-group.label>
                                <select name="brand" v-model="filters.brand" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                                    <option value="">— alle merken —</option>
                                    <option v-for="brand in brands" :value="brand" v-text="brand"></option>
                                </select>
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>SKU begint met</x-admin::form.control-group.label>
                                <input type="text" name="sku_prefix" v-model="filters.sku_prefix" placeholder="bijv. ERG, DMC" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Bereik</x-admin::form.control-group.label>
                                <select name="scope" v-model="filters.scope" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                                    <option value="all">Alle producten</option>
                                    <option value="parents">Alleen hoofdproducten</option>
                                    <option value="variants">Alleen varianten</option>
                                </select>
                            </x-admin::form.control-group>
                        </div>

                        <p class="text-sm font-semibold mt-4 mb-2 text-gray-700 dark:text-slate-100">Extra voorwaarde (optioneel)</p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <select name="condition_attribute" v-model="filters.condition_attribute" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                                <option value="">— geen —</option>
                                <option v-for="attr in attributes" :value="attr" v-text="attr"></option>
                            </select>
                            <select name="condition_operator" v-model="filters.condition_operator" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                                <option value="contains">bevat</option>
                                <option value="equals">is gelijk aan</option>
                                <option value="empty">is leeg</option>
                            </select>
                            <input type="text" name="condition_value" v-model="filters.condition_value" v-if="filters.condition_operator !== 'empty'" placeholder="waarde" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                        </div>
                    </div>

                    <!-- 2. OPERATION: what to change -->
                    <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6 mb-4">
                        <p class="text-lg font-bold mb-4 text-gray-800 dark:text-slate-50">2. Wijziging</p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Attribuut</x-admin::form.control-group.label>
                                <select name="target" v-model="operation.target" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                                    <option value="">— kies attribuut —</option>
                                    <option v-for="attr in attributes" :value="attr" v-text="attr"></option>
                                </select>
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Bewerking</x-admin::form.control-group.label>
                                <select name="type" v-model="operation.type" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                                    <option value="replace">Zoeken &amp; vervangen</option>
                                    <option value="set">Waarde instellen</option>
                                    <option value="clear">Leegmaken</option>
                                </select>
                            </x-admin::form.control-group>
                        </div>

                        <template v-if="operation.type === 'replace'">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>Zoeken naar</x-admin::form.control-group.label>
                                    <textarea name="find" v-model="operation.find" rows="3" class="w-full py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800"></textarea>
                                </x-admin::form.control-group>
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>Vervangen door</x-admin::form.control-group.label>
                                    <textarea name="replace" v-model="operation.replace" rows="3" class="w-full py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800"></textarea>
                                </x-admin::form.control-group>
                            </div>
                        </template>

                        <template v-if="operation.type === 'set'">
                            <x-admin::form.control-group class="mt-4">
                                <x-admin::form.control-group.label>Nieuwe waarde</x-admin::form.control-group.label>
                                <textarea name="value" v-model="operation.value" rows="3" class="w-full py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800"></textarea>
                            </x-admin::form.control-group>
                        </template>

                        <label class="flex items-center gap-2 mt-4 text-sm text-gray-700 dark:text-slate-100">
                            <input type="checkbox" name="sync_woo" value="1" v-model="operation.sync_woo">
                            Wijzigingen daarna synchroniseren naar WooCommerce
                        </label>
                    </div>

                    <!-- 3. PREVIEW + APPLY -->
                    <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
                        <div class="flex items-center gap-2.5">
                            <button type="button" class="secondary-button" @click="doPreview" :disabled="loading">
                                <span v-if="loading">Bezig…</span>
                                <span v-else>Voorbeeld</span>
                            </button>
                            <button type="submit" class="primary-button" :disabled="preview === null || preview.count === 0">
                                Toepassen<span v-if="preview"> (<span v-text="preview.count"></span> producten)</span>
                            </button>
                        </div>

                        <p v-if="error" class="text-red-600 text-sm mt-3" v-text="error"></p>

                        <div v-if="preview" class="mt-4">
                            <p class="text-sm text-gray-700 dark:text-slate-100 mb-3">
                                <strong v-text="preview.count"></strong> producten worden gewijzigd.
                                <span v-if="preview.count > 0">Hieronder <strong v-text="preview.samples.length"></strong> willekeurige voorbeelden:</span>
                            </p>

                            <div v-if="preview.samples.length" class="overflow-x-auto">
                                <table class="w-full text-left text-sm border-collapse">
                                    <thead>
                                        <tr class="border-b">
                                            <th class="py-2 pr-3 align-top w-32">SKU</th>
                                            <th class="py-2 pr-3 align-top">Voor</th>
                                            <th class="py-2 align-top">Na</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="sample in preview.samples" class="border-b align-top">
                                            <td class="py-2 pr-3 font-mono text-xs" v-text="sample.sku"></td>
                                            <td class="py-2 pr-3 text-gray-500" v-html="excerpt(sample.before, 'before')"></td>
                                            <td class="py-2 text-gray-800 dark:text-slate-100" v-html="excerpt(sample.after, 'after')"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </script>

        <script type="module">
            app.component('v-bulk-edit', {
                template: '#v-bulk-edit-template',

                props: ['brands', 'attributes', 'previewUrl', 'applyUrl', 'csrfToken'],

                data() {
                    return {
                        loading: false,
                        error: null,
                        preview: null,
                        filters: {
                            brand: '',
                            sku_prefix: '',
                            scope: 'all',
                            condition_attribute: '',
                            condition_operator: 'contains',
                            condition_value: '',
                        },
                        operation: {
                            target: '',
                            type: 'replace',
                            find: '',
                            replace: '',
                            value: '',
                            sync_woo: false,
                        },
                    };
                },

                methods: {
                    payload() {
                        return { ...this.filters, ...this.operation };
                    },

                    doPreview() {
                        this.loading = true;
                        this.error = null;
                        this.preview = null;

                        const body = new FormData();
                        const data = this.payload();
                        Object.keys(data).forEach((key) => {
                            let value = data[key];
                            if (value === true) value = '1';
                            else if (value === false) value = '0';
                            body.append(key, value);
                        });

                        fetch(this.previewUrl, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                            body,
                        })
                            .then(async (response) => {
                                const json = await response.json();
                                if (!response.ok) {
                                    throw new Error(json.message || 'Voorbeeld mislukt. Controleer de invoer.');
                                }
                                return json;
                            })
                            .then((json) => { this.preview = json; })
                            .catch((err) => { this.error = err.message; })
                            .finally(() => { this.loading = false; });
                    },

                    onSubmit(event) {
                        if (this.preview === null || this.preview.count === 0) {
                            event.preventDefault();
                            this.error = 'Bekijk eerst een voorbeeld voordat je toepast.';
                            return;
                        }
                        if (!window.confirm(`Weet je zeker dat je ${this.preview.count} producten wilt bijwerken?`)) {
                            event.preventDefault();
                        }
                    },

                    escapeHtml(text) {
                        const div = document.createElement('div');
                        div.textContent = text ?? '';
                        return div.innerHTML;
                    },

                    excerpt(text, side) {
                        text = text ?? '';
                        const needle = side === 'before' ? this.operation.find : this.operation.replace;

                        if (this.operation.type === 'replace' && needle) {
                            const idx = text.indexOf(needle);
                            if (idx !== -1) {
                                const start = Math.max(0, idx - 60);
                                const end = Math.min(text.length, idx + needle.length + 60);
                                const before = (start > 0 ? '…' : '') + this.escapeHtml(text.slice(start, idx));
                                const hit = '<mark class="bg-yellow-200 dark:bg-yellow-700">' + this.escapeHtml(needle) + '</mark>';
                                const after = this.escapeHtml(text.slice(idx + needle.length, end)) + (end < text.length ? '…' : '');
                                return before + hit + after;
                            }
                        }

                        const truncated = text.length > 300 ? text.slice(0, 300) + '…' : text;
                        return this.escapeHtml(truncated) || '<em class="text-gray-400">(leeg)</em>';
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
