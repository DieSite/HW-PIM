<x-admin::layouts>
    <x-slot:title>
        Levertijden
    </x-slot>

    <form action="{{ route('admin.tools.delivery-times.update') }}" method="POST">
        @csrf

        <div class="flex justify-between items-center">
            <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                Levertijden
            </p>

            <button type="submit" class="primary-button">
                Opslaan en toepassen
            </button>
        </div>

        <div class="flex flex-col gap-4 mt-3.5">
            <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
                <x-admin::flash-group />

                @if ($errors->any())
                    <div class="mb-3">
                        @foreach ($errors->all() as $error)
                            <p class="text-red-600 text-xs italic">{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <p class="text-gray-600 dark:text-gray-300 mb-2">
                    De levertijd wordt per variant (maat) berekend en naar de webshop gestuurd. Ligt een kleed
                    in de showroom van HW op voorraad, dan geldt de showroom-levertijd, voor elk merk. Anders
                    geldt de levertijd van het merk: <strong>op voorraad</strong> als de leverancier de maat
                    op voorraad heeft (Eurogros- of De Munk/handmatige voorraad), anders <strong>niet op voorraad</strong>.
                </p>

                <p class="text-gray-600 dark:text-gray-300 mb-4">
                    Een merk zonder levertijden krijgt geen levertijd. Na opslaan worden alle varianten op de
                    achtergrond bijgewerkt. Daarna houdt elke voorraadwijziging de levertijd vanzelf bij.
                </p>

                <x-admin::form.control-group class="!mb-0 w-[320px]">
                    <x-admin::form.control-group.label>Op voorraad in de HW-showroom (alle merken)</x-admin::form.control-group.label>
                    <input
                        type="text"
                        name="showroom"
                        value="{{ old('showroom', $showroom) }}"
                        placeholder="bijv. 2 tot 3 dagen"
                        class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600"
                    >
                </x-admin::form.control-group>
            </div>

            <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
                <p class="text-lg text-gray-800 dark:text-slate-50 font-bold mb-3">
                    Per merk
                </p>

                <div class="overflow-x-auto">
                    <x-admin::table class="w-full text-left border-collapse">
                        <x-admin::table.thead>
                            <x-admin::table.thead.tr>
                                <x-admin::table.th>Merk</x-admin::table.th>
                                <x-admin::table.th>Kleden</x-admin::table.th>
                                <x-admin::table.th>Op voorraad</x-admin::table.th>
                                <x-admin::table.th>Niet op voorraad</x-admin::table.th>
                            </x-admin::table.thead.tr>
                        </x-admin::table.thead>

                        <x-admin::table.tbody>
                            @foreach ($rows as $index => $row)
                                <x-admin::table.tbody.tr>
                                    <x-admin::table.td>
                                        {{ $row['brand'] }}
                                        <input type="hidden" name="rules[{{ $index }}][brand]" value="{{ $row['brand'] }}">
                                    </x-admin::table.td>
                                    <x-admin::table.td>{{ $row['rugs'] }}</x-admin::table.td>
                                    <x-admin::table.td>
                                        <input
                                            type="text"
                                            name="rules[{{ $index }}][in_stock]"
                                            value="{{ old("rules.$index.in_stock", $row['in_stock']) }}"
                                            placeholder="bijv. 1 tot 2 weken"
                                            class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600"
                                        >
                                    </x-admin::table.td>
                                    <x-admin::table.td>
                                        <input
                                            type="text"
                                            name="rules[{{ $index }}][out_of_stock]"
                                            value="{{ old("rules.$index.out_of_stock", $row['out_of_stock']) }}"
                                            placeholder="bijv. 3 tot 5 weken"
                                            class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600"
                                        >
                                    </x-admin::table.td>
                                </x-admin::table.tbody.tr>
                            @endforeach

                            @php($newIndex = count($rows))

                            <x-admin::table.tbody.tr>
                                <x-admin::table.td>
                                    <input
                                        type="text"
                                        name="rules[{{ $newIndex }}][brand]"
                                        value="{{ old("rules.$newIndex.brand") }}"
                                        placeholder="Nieuw merk (precies zoals in het veld merk)"
                                        class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600"
                                    >
                                </x-admin::table.td>
                                <x-admin::table.td></x-admin::table.td>
                                <x-admin::table.td>
                                    <input
                                        type="text"
                                        name="rules[{{ $newIndex }}][in_stock]"
                                        value="{{ old("rules.$newIndex.in_stock") }}"
                                        class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600"
                                    >
                                </x-admin::table.td>
                                <x-admin::table.td>
                                    <input
                                        type="text"
                                        name="rules[{{ $newIndex }}][out_of_stock]"
                                        value="{{ old("rules.$newIndex.out_of_stock") }}"
                                        class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600"
                                    >
                                </x-admin::table.td>
                            </x-admin::table.tbody.tr>
                        </x-admin::table.tbody>
                    </x-admin::table>
                </div>
            </div>
        </div>
    </form>
</x-admin::layouts>
