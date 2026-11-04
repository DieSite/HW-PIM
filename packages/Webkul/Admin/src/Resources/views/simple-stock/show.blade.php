<x-admin::layouts.anonymous>
    <x-slot:title>
        {{ $rug['name'] }} – Voorraad zoeken
    </x-slot>

    @include('admin::simple-stock.partials.styles')

    @push('scripts')
        @include('admin::custom.keep-scroll')
    @endpush

    <div class="sl">
        @include('admin::simple-stock.partials.header')

        <main class="sl-main">
            <a class="sl-back" href="{{ route('admin.simple-stock.index', $search) }}">← Terug naar zoeken</a>

            <section class="sl-card">
                <div class="sl-head">
                    <div>
                        <h2 class="sl-title">
                            {{ $rug['name'] }}
                            @unless ($rug['active'])
                                <span class="sl-badge">Niet actief</span>
                            @endunless
                        </h2>

                        <div class="sl-meta">
                            {{ collect([$rug['brand'], $rug['collection'], $rug['colour'], $rug['sku']])->filter()->implode(' · ') }}
                        </div>

                        <div class="sl-links">
                            <a href="{{ route('product.frontend', $rug['id']) }}" target="_blank" rel="noopener">Bekijk in webshop</a>

                            @if ($canEdit)
                                <a href="{{ route('admin.catalog.products.edit', $rug['id']) }}">Bewerken in PIM</a>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="sl-detail">
                    <div>
                        @if ($rug['images'] === [])
                            <div class="sl-gallery-main"></div>
                        @else
                            <img
                                class="sl-gallery-main"
                                id="sl-main-image"
                                src="{{ $rug['images'][0]['large'] }}"
                                alt="{{ $rug['name'] }}"
                            >

                            @if (count($rug['images']) > 1)
                                <div class="sl-gallery-thumbs">
                                    @foreach ($rug['images'] as $index => $image)
                                        <button
                                            type="button"
                                            data-large="{{ $image['large'] }}"
                                            aria-current="{{ $index === 0 ? 'true' : 'false' }}"
                                            aria-label="Foto {{ $index + 1 }}"
                                            onclick="document.getElementById('sl-main-image').src = this.dataset.large; this.parentNode.querySelectorAll('button').forEach(b => b.setAttribute('aria-current', b === this));"
                                        >
                                            <img src="{{ $image['thumbnail'] }}" alt="" loading="lazy">
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>

                    <div>
                        @if ($rug['specifications'] === [])
                            <p class="sl-note">Geen eigenschappen ingevuld.</p>
                        @else
                            <dl class="sl-specs">
                                @foreach ($rug['specifications'] as $label => $value)
                                    <dt>{{ $label }}</dt>
                                    <dd>{{ $value }}</dd>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                </div>

                <div class="sl-section">
                    <h3>Maten, voorraad en prijzen</h3>
                    <p class="sl-small">
                        Showroom = ligt bij ons. Leverancier = voorradig bij de leverancier.
                        Doorgestreept = adviesverkoopprijs.
                    </p>
                </div>

                @include('admin::simple-stock.partials.sizes', ['sizes' => $rug['sizes'], 'detailed' => true])

                @if ($rug['price_analysis'] !== [])
                    @php
                        $euro = fn (float $amount): string => '€ '.number_format($amount, 0, ',', '.');
                        $withCompetitors = array_values(array_filter($rug['price_analysis'], fn (array $size): bool => $size['competitors'] > 0));
                        $withoutCompetitors = array_column(array_filter($rug['price_analysis'], fn (array $size): bool => $size['competitors'] === 0), 'size');
                    @endphp

                    <div class="sl-section">
                        <h3>Prijsanalyse concurrenten</h3>

                        <p class="sl-small">
                            Per maat alle prijzen die we bij concurrenten vonden, van goedkoop naar duur, met onze eigen prijs ertussen.
                            Verschil = hun prijs min onze prijs. Grijs = telt niet mee voor onze prijs: ouder dan {{ config('competitor_pricing.max_price_age_days', 14) }} dagen of handmatig uitgesloten.
                        </p>

                        @if ($withCompetitors === [])
                            <p class="sl-note">Voor geen enkele maat van dit kleed een concurrentprijs gevonden.</p>
                        @else
                            <div class="sl-analysis">
                                @foreach ($withCompetitors as $size)
                                    <div class="sl-analysis-block">
                                        <header>
                                            <strong>{{ $size['size'] }}</strong>
                                            <span class="sl-small">
                                                {{ $size['competitors'] }} {{ $size['competitors'] === 1 ? 'concurrent' : 'concurrenten' }}
                                                @if ($size['advice_price'] !== null)
                                                    · advies {{ $euro($size['advice_price']) }}
                                                @endif
                                            </span>
                                        </header>

                                        <table>
                                            <tbody>
                                                @foreach ($size['rows'] as $index => $row)
                                                    <tr @class(['own' => $row['own'], 'stale' => ! $row['counts'], 'excluded' => $row['excluded']])>
                                                        <td class="rank">{{ $index + 1 }}.</td>
                                                        <td>
                                                            @if ($row['url'])
                                                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer">{{ $row['shop'] }}</a>
                                                            @else
                                                                {{ $row['shop'] }}
                                                            @endif
                                                        </td>
                                                        <td class="price">{{ $euro($row['price']) }}</td>
                                                        <td class="price">
                                                            @if (! $row['own'] && $row['difference'] !== null && $size['price'])
                                                                @php
                                                                    $sign = $row['difference'] > 0 ? '+' : ($row['difference'] < 0 ? '−' : '');
                                                                    $percentage = number_format(abs($row['difference']) / $size['price'] * 100, 0, ',', '.');
                                                                @endphp
                                                                @if ($sign === '')
                                                                    gelijk
                                                                @else
                                                                    <span @class(['sl-cheaper' => $row['difference'] < 0, 'sl-pricier' => $row['difference'] > 0])>{{ $sign }}{{ $euro(abs($row['difference'])) }} ({{ $sign }}{{ $percentage }}%)</span>
                                                                @endif
                                                            @endif
                                                        </td>
                                                        <td class="sl-small">
                                                            @if ($row['own'])
                                                                onze prijs
                                                            @elseif ($row['excluded'])
                                                                <span class="sl-excluded-label" title="{{ $row['exclusion']['reason'] }}">uitgesloten</span>
                                                            @elseif ($row['scraped_at'])
                                                                {{ $row['scraped_at']->format('d-m-Y') }}
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($withCompetitors !== [] && $withoutCompetitors !== [])
                            <p class="sl-small" style="margin-top: 12px;">Geen concurrentprijs gevonden voor: {{ implode(', ', $withoutCompetitors) }}.</p>
                        @endif
                    </div>
                @endif

                @if ($rug['description'] !== [] || $rug['short'] !== [])
                    <div class="sl-section">
                        <h3>Omschrijving</h3>

                        @foreach ([...$rug['description'], ...$rug['short']] as $paragraph)
                            <p>{{ $paragraph }}</p>
                        @endforeach
                    </div>
                @endif

                @if ($rug['related'] !== [])
                    <div class="sl-section">
                        <h3>Andere kleuren</h3>

                        <div class="sl-related">
                            @foreach ($rug['related'] as $other)
                                <a href="{{ route('admin.simple-stock.show', ['rug' => $other['id']] + $search) }}">
                                    @if ($other['thumbnail'])
                                        <img class="sl-thumb" src="{{ $other['thumbnail'] }}" alt="" loading="lazy">
                                    @else
                                        <div class="sl-thumb"></div>
                                    @endif
                                    {{ $other['name'] }}
                                    @if ($other['colour'])
                                        <span class="sl-small">· {{ $other['colour'] }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>
        </main>
    </div>
</x-admin::layouts.anonymous>
