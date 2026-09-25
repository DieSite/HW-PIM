<x-admin::layouts.anonymous>
    <x-slot:title>
        {{ $client->name }} koppelen
    </x-slot>

    <div class="flex justify-center items-center h-[100vh]">
        <div class="flex flex-col gap-5 items-center">
            <img
                class="w-max"
                src="{{ unopim_asset('images/logo.svg') }}"
                alt="{{ config('app.name') }}"
            />

            <div class="flex flex-col w-[380px] max-w-full bg-white dark:bg-cherry-800 rounded-md box-shadow">
                <p class="p-4 text-xl text-gray-800 dark:text-slate-50 font-bold">
                    {{ $client->name }} koppelen
                </p>

                <div class="flex flex-col gap-3 p-4 border-y dark:border-gray-800 text-sm text-gray-600 dark:text-gray-300">
                    <p>
                        <strong>{{ $client->name }}</strong> vraagt toegang tot de PIM namens
                        <strong>{{ $user->name }}</strong> ({{ $user->email }}).
                    </p>

                    <p>Met deze koppeling kan de applicatie:</p>

                    <ul class="list-disc ltr:pl-5 rtl:pr-5">
                        <li>producten en productfamilies lezen;</li>
                        <li>producten aanmaken en bijwerken (volgens jouw rechten);</li>
                        <li>de synchronisatie naar WooCommerce en Bol.com starten.</li>
                    </ul>

                    <p>Producten verwijderen kan niet via deze koppeling.</p>

                    @if ($redirect = collect($client->redirect_uris)->first())
                        <p class="text-xs text-gray-500">
                            Na goedkeuring ga je terug naar {{ parse_url($redirect, PHP_URL_HOST) }}.
                        </p>
                    @endif
                </div>

                <div class="flex justify-between items-center gap-2 p-4">
                    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="state" value="">
                        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">

                        <button type="submit" class="secondary-button">Weigeren</button>
                    </form>

                    <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                        @csrf
                        <input type="hidden" name="state" value="">
                        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">

                        <button type="submit" class="primary-button">Toestaan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-admin::layouts.anonymous>
