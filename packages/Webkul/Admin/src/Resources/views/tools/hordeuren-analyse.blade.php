<x-admin::layouts>
    <x-slot:title>
        Hordeuren concurrentie-analyse
    </x-slot>

    <div class="flex justify-between items-center">
        <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
            Hordeuren concurrentie-analyse
        </p>
    </div>

    <div class="flex flex-col gap-4 mt-3.5">
        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
            <x-admin::flash-group />

            <p class="text-gray-600 dark:text-gray-300 mb-2">
                Vergelijkt onze plissé-hordeurprijzen live met de concurrenten voor 34 deurconfiguraties:
                6 standaard deurmaten (enkel en dubbel; klein, middel, groot) plus het eigen assortiment
                (96E t/m 190N, als enkele én dubbele deur, met zwart én grijs gaas) en bouwt daar een
                Excel-rapport van.
            </p>

            <p class="text-gray-600 dark:text-gray-300 mb-2">
                De analyse draait op de achtergrond en duurt <strong>± 30–60 minuten</strong>.
                Zodra hij klaar is wordt het rapport gemaild naar het onderstaande e-mailadres.
            </p>

            @if ($runningSince)
                <p class="text-amber-600 dark:text-amber-400 mb-2">
                    Er staat een analyse in de wachtrij of hij draait op dit moment
                    (gestart {{ $runningSince->format('d-m-Y H:i') }}). Het rapport wordt gemaild zodra hij klaar is.
                </p>
            @endif

            @if ($lastReportAt)
                <p class="text-gray-600 dark:text-gray-300 mb-6">
                    Laatste rapport: <strong>{{ $lastReportAt->format('d-m-Y H:i') }}</strong>
                </p>
            @endif

            <form action="{{ route('admin.tools.hordeuren-analyse.run') }}" method="POST" class="flex items-end gap-2.5">
                @csrf

                <x-admin::form.control-group class="!mb-0 w-[350px]">
                    <x-admin::form.control-group.label>
                        E-mailadres voor het rapport
                    </x-admin::form.control-group.label>

                    <input
                        type="email"
                        name="email"
                        value="{{ old('email', $defaultEmail) }}"
                        placeholder="naam@voorbeeld.nl"
                        class="w-full py-2 px-3 border rounded-md text-sm text-gray-600 dark:text-gray-300 dark:bg-cherry-800 dark:border-gray-600"
                        required
                    >

                    @error('email')
                        <p class="text-red-600 text-xs italic mt-1">{{ $message }}</p>
                    @enderror
                </x-admin::form.control-group>

                <button type="submit" class="primary-button">
                    Start analyse
                </button>
            </form>
        </div>
    </div>
</x-admin::layouts>
