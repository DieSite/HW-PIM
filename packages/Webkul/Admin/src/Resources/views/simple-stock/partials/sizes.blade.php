@php
    $euro = fn (float $amount): string => '€ '.number_format($amount, 0, ',', '.');
@endphp

@if ($sizes === [])
    <p class="sl-note" style="padding: 0 16px 12px;">Geen actieve maten.</p>
@else
    <div class="sl-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Maat</th>
                    <th class="num">Showroom</th>
                    <th class="num">Leverancier</th>
                    <th class="num">Uitverkoop</th>
                    <th>Prijs</th>
                    @if ($detailed)
                        <th>Met onderkleed</th>
                    @endif
                    <th>Levertijd</th>
                    @if ($detailed)
                        <th>SKU / EAN</th>
                    @endif
                </tr>
            </thead>

            <tbody>
                @foreach ($sizes as $size)
                    <tr @class(['sl-custom' => $size['custom']])>
                        <td>{{ $size['size'] }}</td>

                        @if ($size['custom'])
                            <td class="num" colspan="3">Op maat gemaakt</td>
                        @else
                            @foreach (['showroom', 'supplier', 'sale'] as $column)
                                <td class="num">
                                    @if ($size[$column] > 0)
                                        <span class="sl-stock {{ $column === 'sale' ? 'sale' : '' }}">{{ $size[$column] }}</span>
                                    @else
                                        <span class="sl-none">–</span>
                                    @endif
                                </td>
                            @endforeach
                        @endif

                        <td class="price">
                            @if ($size['price'] !== null)
                                {{ $euro($size['price']) }}{{ $size['custom'] ? ' /m²' : '' }}
                                @if ($detailed && $size['advice_price'] !== null)
                                    <span class="sl-old" title="Adviesverkoopprijs">{{ $euro($size['advice_price']) }}</span>
                                @endif
                            @endif
                        </td>

                        @if ($detailed)
                            <td class="price">
                                @if ($size['underlay_price'] !== null)
                                    {{ $euro($size['underlay_price']) }}{{ $size['custom'] ? ' /m²' : '' }}
                                @endif
                            </td>
                        @endif

                        <td>{{ $size['delivery_time'] ?? '' }}</td>

                        @if ($detailed)
                            <td class="sl-small">
                                {{ $size['sku'] }}
                                @if ($size['ean'])
                                    <br>{{ $size['ean'] }}
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
