<x-admin::layouts>
    <x-slot:title>
        Dropbox
    </x-slot>

    <div class="flex justify-between items-center">
        <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
            Dropbox
        </p>
    </div>

    <div class="flex flex-col gap-4 mt-3.5">
        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6">
            <x-admin::flash-group />

            <p class="text-gray-600 dark:text-gray-300 mb-6">
                Via deze koppeling kan de MCP-server (de AI-assistent) productfoto's uit Dropbox zoeken
                en in de PIM zetten. De PIM heeft alleen leestoegang.
            </p>

            @if (! $hasAppCredentials)
                <p class="text-amber-600 dark:text-amber-400 mb-2">
                    De Dropbox-app is nog niet ingesteld. Zet <code>DROPBOX_APP_KEY</code> en
                    <code>DROPBOX_APP_SECRET</code> in de <code>.env</code> en voeg in de Dropbox App Console
                    deze redirect-URI toe:
                </p>

                <p class="mb-2"><code class="text-sm text-gray-800 dark:text-slate-50">{{ $redirectUri }}</code></p>
            @elseif ($connection)
                <p class="text-gray-800 dark:text-slate-50 mb-2">
                    Gekoppeld met <strong>{{ $connection['account_name'] }}</strong>
                    @if ($connection['account_email'])
                        ({{ $connection['account_email'] }})
                    @endif
                </p>

                <p class="text-gray-600 dark:text-gray-300 mb-6">
                    Gekoppeld
                    @if ($connection['connected_at'])
                        op {{ \Illuminate\Support\Carbon::parse($connection['connected_at'])->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}
                    @endif
                    @if ($connection['connected_by'])
                        door {{ $connection['connected_by'] }}
                    @endif
                    @if ($connection['root_namespace_id'])
                        · teamaccount, de teammappen zijn bereikbaar
                    @endif
                </p>

                <div class="flex gap-2.5">
                    <form action="{{ route('admin.tools.dropbox.connect') }}" method="POST">
                        @csrf

                        <button type="submit" class="secondary-button">
                            Ander account koppelen
                        </button>
                    </form>

                    <form action="{{ route('admin.tools.dropbox.disconnect') }}" method="POST">
                        @csrf

                        <button type="submit" class="secondary-button">
                            Ontkoppelen
                        </button>
                    </form>
                </div>
            @else
                <p class="text-gray-600 dark:text-gray-300 mb-6">
                    Er is nog geen Dropbox-account gekoppeld. Log bij Dropbox in met het account waarin de
                    leveranciersfoto's staan en geef toegang.
                </p>

                <form action="{{ route('admin.tools.dropbox.connect') }}" method="POST">
                    @csrf

                    <button type="submit" class="primary-button">
                        Koppel Dropbox
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-admin::layouts>
