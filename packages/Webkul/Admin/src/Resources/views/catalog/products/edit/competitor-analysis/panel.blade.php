@php
    $euro = fn (?float $amount, int $decimals = 0): string => $amount === null ? '–' : '€ '.number_format($amount, $decimals, ',', '.');
    $maxAge = \App\Models\CompetitorPrice::maxAgeDays();
    $withCompetitors = array_values(array_filter($sizes, fn (array $size): bool => $size['competitors'] > 0));
    $withoutCompetitors = array_column(array_filter($sizes, fn (array $size): bool => $size['competitors'] === 0), 'size');
@endphp

@if ($message)
    <div class="ca-message" role="status">{{ $message }} De prijs is opnieuw berekend en wordt naar de webshop en Bol gestuurd.</div>
@endif

@if ($sizes === [])
    <p class="ca-muted">Dit product heeft geen standaardmaten om met concurrenten te vergelijken (maatwerk en met-onderkleed hebben geen eigen concurrentprijs).</p>
@elseif ($withCompetitors === [])
    <p class="ca-muted">Voor geen enkele maat een concurrentprijs gevonden.</p>
@else
    <p class="ca-muted">
        Onze prijs = de goedkoopste concurrent die meetelt, maar nooit boven de adviesprijs en nooit meer dan de maximale korting eronder.
        Grijze prijzen tellen niet mee: ouder dan {{ $maxAge }} dagen, of uitgesloten. Een uitsluiting geldt alleen voor dat bedrag: verandert de prijs van de winkel, dan telt hij weer mee.
    </p>

    <div class="ca-grid">
        @foreach ($withCompetitors as $size)
            <div class="ca-block">
                <header class="ca-head">
                    <div>
                        <strong>{{ $size['size'] }}</strong>
                        <span class="ca-muted">{{ $size['sku'] }}</span>
                    </div>

                    <div class="ca-muted">
                        {{ $size['counting'] }} van {{ $size['competitors'] }} {{ $size['competitors'] === 1 ? 'concurrent telt' : 'concurrenten tellen' }} mee
                    </div>
                </header>

                <dl class="ca-calc">
                    <div><dt>Advies</dt><dd>{{ $euro($size['advice_price']) }}</dd></div>
                    <div><dt>Bodem (−{{ rtrim(rtrim(number_format($size['max_discount'], 1, ',', ''), '0'), ',') }}%)</dt><dd>{{ $euro($size['floor']) }}</dd></div>
                    <div><dt>Goedkoopste die meetelt</dt><dd>{{ $size['cheapest'] ? $euro($size['cheapest']['price']).' · '.$size['cheapest']['shop'] : 'geen' }}</dd></div>
                    @if ($size['manual_discount'] > 0)
                        <div><dt>Extra korting</dt><dd>−{{ rtrim(rtrim(number_format($size['manual_discount'], 1, ',', ''), '0'), ',') }}%</dd></div>
                    @endif
                    <div class="ca-calc-result">
                        <dt>Onze prijs</dt>
                        <dd>
                            {{ $euro($size['price']) }}
                            @if ($size['computed_price'] !== null && $size['price'] !== null && abs($size['computed_price'] - $size['price']) >= 0.005)
                                <span class="ca-warn" title="De analyse zou nu {{ $euro($size['computed_price']) }} zetten. Dat gebeurt bij de volgende run, of direct na een uitsluiting.">
                                    berekend {{ $euro($size['computed_price']) }}
                                </span>
                            @endif
                        </dd>
                    </div>
                </dl>

                <table class="ca-table">
                    <tbody>
                        @foreach ($size['rows'] as $index => $row)
                            <tr @class(['ca-own' => $row['own'], 'ca-off' => ! $row['counts'], 'ca-excluded' => $row['excluded']])>
                                <td class="ca-rank">{{ $index + 1 }}.</td>
                                <td class="ca-shop">
                                    @if ($row['url'])
                                        <a class="ca-shop-name" href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer">{{ $row['shop'] }}</a>
                                    @else
                                        <span class="ca-shop-name">{{ $row['shop'] }}</span>
                                    @endif

                                    @if ($row['excluded'])
                                        <div class="ca-note ca-note-excluded">
                                            Uitgesloten{{ $row['exclusion']['by'] ? ' door '.$row['exclusion']['by'] : '' }}{{ $row['exclusion']['at'] ? ' op '.$row['exclusion']['at']->format('d-m-Y') : '' }}{{ $row['exclusion']['reason'] ? ': '.$row['exclusion']['reason'] : '' }}
                                        </div>
                                    @elseif ($row['stale'])
                                        <div class="ca-note">Ouder dan {{ $maxAge }} dagen</div>
                                    @endif
                                </td>
                                <td class="ca-num">{{ $euro($row['price']) }}</td>
                                <td class="ca-num">
                                    @if (! $row['own'] && $row['difference'] !== null && $size['price'])
                                        @php
                                            $sign = $row['difference'] > 0 ? '+' : ($row['difference'] < 0 ? '−' : '');
                                        @endphp
                                        @if ($sign === '')
                                            gelijk
                                        @else
                                            <span @class(['ca-cheaper' => $row['difference'] < 0, 'ca-pricier' => $row['difference'] > 0])>{{ $sign }}{{ $euro(abs($row['difference'])) }} ({{ $sign }}{{ number_format(abs($row['difference']) / $size['price'] * 100, 0, ',', '.') }}%)</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="ca-date">
                                    @if ($row['own'])
                                        onze prijs
                                    @elseif ($row['scraped_at'])
                                        {{ $row['scraped_at']->format('d-m-Y') }}
                                    @endif
                                </td>
                                <td class="ca-action">
                                    @if (! $row['own'])
                                        @if ($row['excluded'])
                                            <button
                                                type="button"
                                                class="ca-link"
                                                data-ca-include="{{ route('admin.catalog.products.competitor-prices.include', ['productId' => $product->id, 'competitorPrice' => $row['id']]) }}"
                                            >Weer meetellen</button>
                                        @else
                                            <button
                                                type="button"
                                                class="ca-link"
                                                data-ca-exclude-toggle
                                            >Uitsluiten</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>

                            @if (! $row['own'] && ! $row['excluded'])
                                <tr class="ca-exclude-row" hidden>
                                    <td></td>
                                    <td colspan="5">
                                        <div class="ca-exclude-form">
                                            <input
                                                type="text"
                                                maxlength="500"
                                                placeholder="Reden (optioneel), bijv. ander kleed of tijdelijke actie"
                                                aria-label="Reden voor uitsluiten"
                                                data-ca-reason
                                            >
                                            <button
                                                type="button"
                                                class="ca-button"
                                                data-ca-exclude="{{ route('admin.catalog.products.competitor-prices.exclude', ['productId' => $product->id, 'competitorPrice' => $row['id']]) }}"
                                            >{{ $euro($row['price']) }} van {{ $row['shop'] }} uitsluiten</button>
                                            <button type="button" class="ca-link" data-ca-exclude-toggle>Annuleren</button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    @if ($withoutCompetitors !== [])
        <p class="ca-muted" style="margin-top: 12px;">Geen concurrentprijs gevonden voor: {{ implode(', ', $withoutCompetitors) }}.</p>
    @endif
@endif
