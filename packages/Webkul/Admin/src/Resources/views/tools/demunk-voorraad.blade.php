<x-admin::layouts>
    <x-slot:title>
        De Munk voorraad-koppelingen
    </x-slot>

    <div class="flex justify-between items-center">
        <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
            De Munk voorraad-koppelingen
        </p>

        <form action="{{ route('admin.tools.demunk-voorraad.import') }}" method="POST">
            @csrf
            <button type="submit" class="primary-button">
                Nu importeren
            </button>
        </form>
    </div>

    <div class="flex flex-col gap-4 mt-3.5">
        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
            <x-admin::flash-group />

            <p class="text-gray-600 dark:text-gray-300 mb-2">
                De voorraad van De Munk wordt automatisch uit het dealerportaal gelezen en aan onze
                producten gekoppeld op basis van collectie, kwaliteit en kleurnummer. Zekere koppelingen
                worden automatisch gemaakt en de voorraad synct direct; hieronder kun je ze controleren,
                corrigeren of ontkoppelen.
            </p>

            @if ($runningSince)
                <p class="text-amber-600 dark:text-amber-400 mb-2">
                    Er draait op dit moment een import (gestart {{ $runningSince->format('d-m-Y H:i') }}).
                    Ververs deze pagina over enkele minuten.
                </p>
            @endif

            @if ($importedAt)
                <p class="text-gray-600 dark:text-gray-300">
                    Laatste import: <strong>{{ $importedAt->format('d-m-Y H:i') }}</strong>
                    ({{ $articleCount }} artikelen op voorraad bij De Munk)
                </p>
            @endif
        </div>

        {{-- Handmatig koppelen / corrigeren --}}
        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
            <p class="text-lg text-gray-800 dark:text-slate-50 font-bold mb-3">
                Handmatig koppelen of corrigeren
            </p>

            <form action="{{ route('admin.tools.demunk-voorraad.link') }}" method="POST" class="flex flex-wrap items-end gap-2.5">
                @csrf

                <x-admin::form.control-group class="!mb-0 w-[320px]">
                    <x-admin::form.control-group.label>Product (zoek op naam)</x-admin::form.control-group.label>
                    <input
                        type="text"
                        id="demunk-product-search"
                        list="demunk-product-list"
                        placeholder="bijv. Diamante 08"
                        autocomplete="off"
                        class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600"
                    >
                    <datalist id="demunk-product-list"></datalist>
                    <input type="hidden" name="product_id" id="demunk-product-id">
                    @error('product_id')
                        <p class="text-red-600 text-xs italic mt-1">{{ $message }}</p>
                    @enderror
                </x-admin::form.control-group>

                <x-admin::form.control-group class="!mb-0 w-[130px]">
                    <x-admin::form.control-group.label>Collectie</x-admin::form.control-group.label>
                    <input type="text" name="demunk_collectie" id="demunk-collectie" placeholder="MODERN"
                        class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600">
                </x-admin::form.control-group>

                <x-admin::form.control-group class="!mb-0 w-[150px]">
                    <x-admin::form.control-group.label>Kwaliteit</x-admin::form.control-group.label>
                    <input type="text" name="demunk_kwaliteit" id="demunk-kwaliteit" placeholder="DIAMANTE"
                        class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600">
                </x-admin::form.control-group>

                <x-admin::form.control-group class="!mb-0 w-[110px]">
                    <x-admin::form.control-group.label>Kleur</x-admin::form.control-group.label>
                    <input type="text" name="demunk_kleur" id="demunk-kleur" placeholder="DI-08"
                        class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600">
                </x-admin::form.control-group>

                <button type="submit" class="primary-button">Koppelen</button>
            </form>

            <p class="text-gray-500 dark:text-gray-400 text-xs mt-2">
                Handmatige koppelingen worden vergrendeld en nooit door de automatische matcher overschreven.
            </p>
        </div>

        {{-- Ongekoppelde De Munk-artikelen --}}
        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
            <p class="text-lg text-gray-800 dark:text-slate-50 font-bold mb-3">
                Op voorraad bij De Munk zonder koppeling ({{ count($unmatched) }})
            </p>

            @if (count($unmatched) === 0)
                <p class="text-gray-500 dark:text-gray-400">
                    Geen ongekoppelde artikelen in de laatste import (of er is nog geen import gedraaid).
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-600 dark:text-gray-300">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-600">
                            <tr>
                                <th class="py-2 pr-4">Collectie</th>
                                <th class="py-2 pr-4">Kwaliteit</th>
                                <th class="py-2 pr-4">Kleur</th>
                                <th class="py-2 pr-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($unmatched as $item)
                                <tr class="border-b dark:border-gray-700">
                                    <td class="py-2 pr-4">{{ $item['collectie'] }}</td>
                                    <td class="py-2 pr-4">{{ $item['kwaliteit'] }}</td>
                                    <td class="py-2 pr-4">{{ $item['kleur'] }}</td>
                                    <td class="py-2 pr-4">
                                        <button type="button" class="secondary-button demunk-fill"
                                            data-collectie="{{ $item['collectie'] }}"
                                            data-kwaliteit="{{ $item['kwaliteit'] }}"
                                            data-kleur="{{ $item['kleur'] }}">
                                            Koppel dit artikel
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Bestaande koppelingen --}}
        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
            <p class="text-lg text-gray-800 dark:text-slate-50 font-bold mb-3">
                Koppelingen ({{ count($links) }})
            </p>

            @if (count($links) === 0)
                <p class="text-gray-500 dark:text-gray-400">
                    Nog geen koppelingen. Voer een import uit om ze automatisch te laten aanmaken.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-600 dark:text-gray-300">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-600">
                            <tr>
                                <th class="py-2 pr-4">Product</th>
                                <th class="py-2 pr-4">SKU</th>
                                <th class="py-2 pr-4">De Munk</th>
                                <th class="py-2 pr-4">Bron</th>
                                <th class="py-2 pr-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($links as $link)
                                <tr class="border-b dark:border-gray-700">
                                    <td class="py-2 pr-4">{{ $link->productnaam ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $link->sku }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($link->demunk_kleur)
                                            {{ $link->demunk_collectie }} / {{ $link->demunk_kwaliteit }} / {{ $link->demunk_kleur }}
                                        @else
                                            <span class="text-gray-400">geen De Munk-bron</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        <span class="px-2 py-0.5 rounded text-xs {{ $link->source === 'manual' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600' }}">
                                            {{ $link->source === 'manual' ? 'handmatig' : 'automatisch' }}@if ($link->locked) 🔒 @endif
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4">
                                        <form action="{{ route('admin.tools.demunk-voorraad.unlink') }}" method="POST"
                                            onsubmit="return confirm('Koppeling verwijderen?');">
                                            @csrf
                                            <input type="hidden" name="link_id" value="{{ $link->id }}">
                                            <button type="submit" class="text-red-600 hover:underline">Ontkoppelen</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @push('scripts')
        <script>
            (function () {
                const search = document.getElementById('demunk-product-search');
                const list = document.getElementById('demunk-product-list');
                const hiddenId = document.getElementById('demunk-product-id');
                const searchUrl = "{{ route('admin.tools.demunk-voorraad.search-products') }}";
                let byLabel = {};

                let timer = null;
                search.addEventListener('input', function () {
                    hiddenId.value = '';
                    if (byLabel[search.value]) {
                        hiddenId.value = byLabel[search.value];
                        return;
                    }
                    clearTimeout(timer);
                    const term = search.value.trim();
                    if (term.length < 2) return;
                    timer = setTimeout(function () {
                        fetch(searchUrl + '?q=' + encodeURIComponent(term), {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (rows) {
                                list.innerHTML = '';
                                byLabel = {};
                                rows.forEach(function (row) {
                                    byLabel[row.label] = row.id;
                                    const opt = document.createElement('option');
                                    opt.value = row.label;
                                    list.appendChild(opt);
                                });
                                if (byLabel[search.value]) {
                                    hiddenId.value = byLabel[search.value];
                                }
                            });
                    }, 250);
                });

                document.querySelectorAll('.demunk-fill').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        document.getElementById('demunk-collectie').value = btn.dataset.collectie;
                        document.getElementById('demunk-kwaliteit').value = btn.dataset.kwaliteit;
                        document.getElementById('demunk-kleur').value = btn.dataset.kleur;
                        search.focus();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    });
                });
            })();
        </script>
    @endpush
</x-admin::layouts>
