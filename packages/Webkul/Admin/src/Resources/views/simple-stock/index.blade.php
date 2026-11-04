<x-admin::layouts.anonymous>
    <x-slot:title>
        Voorraad zoeken
    </x-slot>

    @include('admin::simple-stock.partials.styles')

    @push('scripts')
        @include('admin::custom.keep-scroll')
    @endpush

    <div class="sl">
        @include('admin::simple-stock.partials.header')

        <main class="sl-main">
            <form method="GET" action="{{ route('admin.simple-stock.index') }}">
                <div class="sl-search">
                    <input
                        type="search"
                        name="q"
                        value="{{ $query }}"
                        placeholder="Naam, merk, collectie, kleur, SKU of EAN"
                        aria-label="Zoeken"
                        autocomplete="off"
                        autofocus
                    >

                    <button type="submit">Zoeken</button>
                </div>

                <div class="sl-filters" role="radiogroup" aria-label="Voorraad">
                    @foreach ([
                        'alles'       => 'Alle maten',
                        'op-voorraad' => 'Alleen op voorraad',
                        'showroom'    => 'Alleen in de showroom',
                    ] as $value => $label)
                        <label>
                            <input
                                type="radio"
                                name="voorraad"
                                value="{{ $value }}"
                                @checked($filter === $value)
                                onchange="this.form.submit()"
                            >
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </form>

            @if (! $searched)
                <p class="sl-empty">
                    Zoek op naam, merk, collectie, kleur, SKU of EAN.<br>
                    Of kies "Alleen in de showroom" voor alles wat hier ligt.
                </p>
            @elseif ($rugs === [])
                <p class="sl-empty">Niets gevonden voor "{{ $query }}".</p>
            @else
                <p class="sl-note">
                    @if ($truncated)
                        De eerste {{ count($rugs) }} kleden. Zoek specifieker om de rest te vinden.
                    @else
                        {{ count($rugs) }} {{ count($rugs) === 1 ? 'kleed' : 'kleden' }} gevonden.
                    @endif
                    Klik op een kleed voor alle details.
                </p>

                @foreach ($rugs as $rug)
                    @php
                        $detailUrl = route('admin.simple-stock.show', ['rug' => $rug['id']] + $search);
                    @endphp

                    <section class="sl-card">
                        <div class="sl-head">
                            <a href="{{ $detailUrl }}" tabindex="-1">
                                @if ($rug['thumbnail'])
                                    <img class="sl-thumb" src="{{ $rug['thumbnail'] }}" alt="" loading="lazy">
                                @else
                                    <div class="sl-thumb"></div>
                                @endif
                            </a>

                            <div>
                                <h2 class="sl-title">
                                    <a href="{{ $detailUrl }}">{{ $rug['name'] }}</a>
                                    @unless ($rug['active'])
                                        <span class="sl-badge">Niet actief</span>
                                    @endunless
                                </h2>

                                <div class="sl-meta">
                                    {{ collect([$rug['brand'], $rug['collection'], $rug['colour'], $rug['sku']])->filter()->implode(' · ') }}
                                </div>

                                <div class="sl-links">
                                    <a href="{{ $detailUrl }}">Alle details</a>
                                    <a href="{{ route('product.frontend', $rug['id']) }}" target="_blank" rel="noopener">Bekijk in webshop</a>
                                </div>
                            </div>
                        </div>

                        @include('admin::simple-stock.partials.sizes', ['sizes' => $rug['sizes'], 'detailed' => false])
                    </section>
                @endforeach
            @endif
        </main>
    </div>
</x-admin::layouts.anonymous>
