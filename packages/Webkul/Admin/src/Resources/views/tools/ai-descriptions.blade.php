<x-admin::layouts>
    <x-slot:title>AI-teksten</x-slot>

    <v-ai-descriptions
        :brands="{{ json_encode($brands) }}"
        :collections="{{ json_encode($collections) }}"
        :fields="{{ json_encode($fields) }}"
        :runs="{{ json_encode($runs) }}"
        :enabled="{{ $enabled ? 'true' : 'false' }}"
        driver="{{ $driver }}"
        preview-url="{{ route('admin.tools.ai-descriptions.preview') }}"
        run-url="{{ route('admin.tools.ai-descriptions.run') }}"
        review-url="{{ route('admin.tools.ai-descriptions.review') }}"
        csrf-token="{{ csrf_token() }}"
    ></v-ai-descriptions>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-ai-descriptions-template">
            <div class="flex flex-col gap-4">
                <div class="flex justify-between items-center">
                    <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">AI-teksten</p>
                    <a :href="reviewUrl" class="secondary-button">Concepten beoordelen</a>
                </div>

                <x-admin::flash-group />

                <div v-if="!enabled" class="bg-amber-100 border border-orange-200 text-amber-800 rounded-lg p-4 text-sm">
                    AI-teksten staan uit. Zet ze aan bij Configuratie → Algemeen → AI-teksten en vul daar een API-sleutel in.
                </div>

                <form :action="runUrl" method="POST" @submit="onSubmit">
                    <input type="hidden" name="_token" :value="csrfToken">

                    <!-- 1. Which rugs -->
                    <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6 mb-4">
                        <p class="text-lg font-bold mb-1 text-gray-800 dark:text-slate-50">1. Selecteer producten</p>
                        <p class="text-sm text-gray-500 dark:text-slate-300 mb-4">
                            Alleen hoofdproducten krijgen teksten: de webshop leest de beschrijving daar, varianten hebben geen eigen tekst.
                        </p>

                        <div class="grid grid-cols-3 gap-4">
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Merk</x-admin::form.control-group.label>
                                <select name="brand" v-model="filters.brand" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                                    <option value="">— alle merken —</option>
                                    <option v-for="brand in brands" :value="brand" v-text="brand"></option>
                                </select>
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Collectie</x-admin::form.control-group.label>
                                <select name="collection" v-model="filters.collection" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                                    <option value="">— alle collecties —</option>
                                    <option v-for="collection in collections" :value="collection" v-text="collection"></option>
                                </select>
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Bereik</x-admin::form.control-group.label>
                                <select name="scope" v-model="filters.scope" class="w-full min-h-[39px] py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800">
                                    <option value="duplicates">Alleen dubbele teksten</option>
                                    <option value="empty">Alleen lege teksten</option>
                                    <option value="all">Alle hoofdproducten</option>
                                </select>
                            </x-admin::form.control-group>
                        </div>

                        <x-admin::form.control-group class="mt-4">
                            <x-admin::form.control-group.label>Specifieke SKU's (optioneel)</x-admin::form.control-group.label>
                            <textarea name="skus" v-model="filters.skus" rows="2" placeholder="DMC0014, DMC0015 — gescheiden door komma's, spaties of regels" class="w-full py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800"></textarea>
                        </x-admin::form.control-group>
                    </div>

                    <!-- 2. Which texts -->
                    <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6 mb-4">
                        <p class="text-lg font-bold mb-4 text-gray-800 dark:text-slate-50">2. Welke teksten</p>

                        <div class="flex flex-col gap-2">
                            <label v-for="(config, code) in fields" :key="code" class="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-50">
                                <input type="checkbox" name="fields[]" :value="code" v-model="selectedFields">
                                <span v-text="config.label"></span>
                                <span class="text-gray-400 text-xs" v-text="'(' + code + ')'"></span>
                            </label>
                        </div>

                        <label class="flex items-center gap-2 mt-4 text-sm text-gray-700 dark:text-slate-50">
                            <input type="checkbox" name="sync_woo" value="1" v-model="syncWoo">
                            Na publiceren doorzetten naar WooCommerce
                        </label>
                    </div>

                    <!-- 3. Sample then run -->
                    <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
                        <p class="text-lg font-bold mb-1 text-gray-800 dark:text-slate-50">3. Proefdraaien en starten</p>
                        <p class="text-sm text-gray-500 dark:text-slate-300 mb-4">
                            Het voorbeeld schrijft drie echte teksten, zodat je de kwaliteit ziet voordat de hele reeks draait.
                            Niets wordt opgeslagen: alle teksten belanden eerst als concept bij Beoordelen.
                        </p>

                        <div class="flex items-center gap-2.5">
                            <button type="button" class="secondary-button" @click="doPreview" :disabled="loading || !enabled">
                                <span v-if="loading">Bezig met schrijven…</span>
                                <span v-else>Voorbeeld (3 producten)</span>
                            </button>
                            <button type="submit" class="primary-button" :disabled="!enabled || selectedFields.length === 0">
                                Reeks starten<span v-if="preview"> (<span v-text="preview.count"></span> producten)</span>
                            </button>
                        </div>

                        <p v-if="error" class="text-red-600 text-sm mt-3" v-text="error"></p>

                        <div v-if="preview" class="mt-6 flex flex-col gap-6">
                            <p class="text-sm text-gray-700 dark:text-slate-50">
                                <strong v-text="preview.count"></strong> producten vallen binnen deze selectie.
                            </p>

                            <div v-for="sample in preview.samples" :key="sample.sku" class="border rounded-lg p-4 dark:border-gray-800">
                                <p class="font-mono text-xs mb-3 text-gray-500" v-text="sample.sku"></p>

                                <p v-if="sample.error" class="text-red-600 text-sm" v-text="sample.error"></p>

                                <template v-else>
                                    <div v-for="(text, code) in sample.after" :key="code" class="mb-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1" v-text="fields[code] ? fields[code].label : code"></p>
                                        <div class="grid grid-cols-2 gap-3 text-sm">
                                            <div class="text-gray-500">
                                                <p class="text-xs mb-1">Nu</p>
                                                <div v-html="sample.before[code] || '<em>leeg</em>'"></div>
                                            </div>
                                            <div class="text-gray-800 dark:text-slate-50">
                                                <p class="text-xs mb-1">Voorstel</p>
                                                <div v-html="text"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <p v-if="sample.problems && sample.problems.length" class="text-xs text-orange-600">
                                        <span v-for="problem in sample.problems" :key="problem.message" class="block" v-text="problem.message"></span>
                                    </p>
                                    <p class="text-xs text-gray-400 mt-1">
                                        Gelijkenis met zusterproducten: <span v-text="Math.round(sample.similarity * 100) + '%'"></span>
                                    </p>
                                </template>
                            </div>
                        </div>
                    </div>
                </form>

                <div v-if="runs.length" class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
                    <p class="text-lg font-bold mb-4 text-gray-800 dark:text-slate-50">Recente reeksen</p>
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b">
                                <th class="py-2 pr-3">#</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 pr-3">Geschreven</th>
                                <th class="py-2 pr-3">Mislukt</th>
                                <th class="py-2 pr-3">Gestart</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="run in runs" :key="run.id" class="border-b">
                                <td class="py-2 pr-3" v-text="run.id"></td>
                                <td class="py-2 pr-3" v-text="run.status"></td>
                                <td class="py-2 pr-3" v-text="run.generated_count + ' / ' + run.matched_count"></td>
                                <td class="py-2 pr-3" v-text="run.failed_count"></td>
                                <td class="py-2 pr-3" v-text="run.created_at"></td>
                                <td class="py-2">
                                    <a :href="reviewUrl + '?run=' + run.id" class="text-violet-600 hover:underline">Beoordelen</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-ai-descriptions', {
                template: '#v-ai-descriptions-template',

                props: ['brands', 'collections', 'fields', 'runs', 'enabled', 'driver', 'previewUrl', 'runUrl', 'reviewUrl', 'csrfToken'],

                data() {
                    return {
                        loading: false,
                        error: null,
                        preview: null,
                        syncWoo: true,
                        selectedFields: Object.keys(this.fields),
                        filters: {
                            brand: '',
                            collection: '',
                            scope: 'duplicates',
                            skus: '',
                        },
                    };
                },

                methods: {
                    body() {
                        const body = new FormData();

                        Object.keys(this.filters).forEach((key) => body.append(key, this.filters[key]));
                        this.selectedFields.forEach((field) => body.append('fields[]', field));
                        body.append('sync_woo', this.syncWoo ? '1' : '0');

                        return body;
                    },

                    doPreview() {
                        if (this.selectedFields.length === 0) {
                            this.error = 'Kies minstens één tekstveld.';
                            return;
                        }

                        this.loading = true;
                        this.error = null;
                        this.preview = null;

                        fetch(this.previewUrl, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                            body: this.body(),
                        })
                            .then(async (response) => {
                                const json = await response.json();
                                if (!response.ok) {
                                    throw new Error(json.message || 'Voorbeeld mislukt.');
                                }
                                return json;
                            })
                            .then((json) => { this.preview = json; })
                            .catch((err) => { this.error = err.message; })
                            .finally(() => { this.loading = false; });
                    },

                    onSubmit(event) {
                        if (this.selectedFields.length === 0) {
                            event.preventDefault();
                            this.error = 'Kies minstens één tekstveld.';
                            return;
                        }

                        const count = this.preview ? this.preview.count : 'alle geselecteerde';

                        if (! window.confirm('Teksten laten schrijven voor ' + count + ' producten?\n\nDe teksten komen als concept klaar te staan; er verandert nog niets aan de webshop.')) {
                            event.preventDefault();
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
