@php
    $euro = fn (?float $value): string => $value === null ? '—' : '€ '.number_format($value, 2, ',', '.');
    $pct = fn (?float $value): string => $value === null ? '—' : ($value > 0 ? '+' : '').number_format($value, 1, ',', '.').'%';
    $plain = fn (float $value): string => rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');

    $changes = $report['changes'];
    $coverage = $report['coverage'];
    $outliers = $report['outliers'];

    $shopsWithChanges = collect($report['shops'])->where('changes', '>', 0);

    $checks = collect($report['checks']);
    $flagged = $checks->where('status', '!=', 'ok');

    $statusLabel = fn (string $status): string => match ($status) {
        'alert' => '🚨 Alarm',
        'warn'  => '⚠️ Let op',
        default => '✅ OK',
    };

    $groupTitles = [
        'prices'   => 'Aanwijzingen dat een prijs niet klopt',
        'pipeline' => 'Signalen over de analyse zelf',
    ];

    /** Outlier groups that describe one of our own price changes. */
    $changeGroups = [
        'drops' => [
            'title' => 'Grote prijsdalingen (≥ '.$plain($thresholds['drop_pct']).'%)',
            'why'   => 'Zo\'n daling in één run is óf een echte actie van een concurrent óf een verkeerde koppeling. Controleer de bron-URL voordat de prijs blijft staan.',
        ],
        'rises' => [
            'title' => 'Grote prijsstijgingen (≥ '.$plain($thresholds['rise_pct']).'%)',
            'why'   => 'De concurrent die ons omlaag trok is duurder geworden of niet meer gevonden; onze prijs veert terug richting de adviesprijs.',
        ],
        'not_cheapest' => [
            'title' => 'Niet meer de goedkoopste (begrensd op de kortingsbodem)',
            'why'   => 'Hier zit de concurrent onder onze maximale korting, dus we volgen hem bewust niet. Wel het moment om de adviesprijs of de bodem te heroverwegen.',
        ],
        'lost_coverage' => [
            'title' => 'Terug naar de adviesprijs (geen concurrent meer gevonden)',
            'why'   => 'Deze kleden stonden lager door een concurrent die nu niet meer gevonden wordt. Vaak terecht (uitverkocht of verwijderd), soms een scrape die niet doorkwam.',
        ],
    ];
@endphp

@component('mail::message')
# Concurrentie-analyse vloerkleden

Run van **{{ $report['since']->copy()->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}** tot **{{ $report['until']->copy()->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}**.

@if ($report['alerts'] > 0)
@component('mail::panel')
**Let op: {{ $report['alerts'] }} {{ $report['alerts'] === 1 ? 'controle slaat' : 'controles slaan' }} alarm.** Er staan mogelijk verkeerde prijzen in de winkel. Zie "{{ $groupTitles['prices'] }}" en "{{ $groupTitles['pipeline'] }}" verderop in deze mail.
@endcomponent
@endif

## Wat er veranderd is

@if ($changes['total'] === 0)
Er is deze run **geen enkele prijs gewijzigd**: alle prijzen stonden al gelijk aan wat de concurrentielogica berekent.
@else
- **{{ $changes['total'] }} prijswijzigingen** op {{ $changes['products'] }} {{ $changes['products'] === 1 ? 'variant' : 'varianten' }}
- **{{ $changes['down'] }} omlaag**, **{{ $changes['up'] }} omhoog** — gemiddeld {{ $pct($changes['avg_pct']) }}
- Netto effect op de prijslijst: **{{ $euro($changes['total_delta']) }}**

@component('mail::table')
| Type wijziging | Aantal |
|:---------------|-------:|
| Gevolgd op een concurrent | {{ $changes['competitor'] }} |
| Terug naar de adviesprijs (geen concurrent) | {{ $changes['advies'] }} |
| Afgeleide bundelprijs (met onderkleed) | {{ $changes['derived'] }} |
| Begrensd op de kortingsbodem | {{ $changes['clamped'] }} |
| Met handmatige extra korting | {{ $changes['manual'] }} |
@endcomponent
@endif

## Concurrentdekking

- **{{ number_format($coverage['prices'], 0, ',', '.') }} concurrentprijzen** over {{ number_format($coverage['skus'], 0, ',', '.') }} varianten bij {{ $coverage['shops'] }} winkels
- **{{ number_format($coverage['fresh'], 0, ',', '.') }}** daarvan zijn tijdens deze run opnieuw opgehaald

@if ($shopsWithChanges->isNotEmpty())
@component('mail::table')
| Concurrent | Prijzen | Ververst | Wijzigingen | Gem. effect | Mediaan vs. advies |
|:-----------|--------:|---------:|------------:|------------:|-------------------:|
@foreach ($shopsWithChanges->take(10) as $shop)
| {{ $shop['shop'] }} | {{ number_format($shop['prices'], 0, ',', '.') }} | {{ number_format($shop['fresh'], 0, ',', '.') }} | {{ $shop['changes'] }} | {{ $pct($shop['avg_pct']) }} | {{ $shop['median_ratio'] === null ? '—' : number_format($shop['median_ratio'], 0, ',', '.').'%' }} |
@endforeach
@endcomponent

@if ($shopsWithChanges->count() > 10)
*En nog {{ $shopsWithChanges->count() - 10 }} andere winkels; de volledige lijst staat in de bijlage.*

@endif
@endif

## Klopt het?

@if ($flagged->isEmpty())
Alle {{ $checks->count() }} controles staan op groen: de scrape is compleet, elke winkel heeft geleverd en er is geen enkel signaal dat een prijs niet klopt.
@else
{{ $flagged->count() }} van de {{ $checks->count() }} controles vragen aandacht. Geen van de prijssignalen is een bewijs — het zijn de patronen die in de praktijk bij een verkeerde prijs horen.
@endif

@component('mail::table')
| Controle | Status | Bevinding |
|:---------|:-------|:----------|
@foreach ($checks as $check)
| {{ $check['label'] }} | {{ $statusLabel($check['status']) }} | {{ $check['value'] }} |
@endforeach
@endcomponent

@foreach ($groupTitles as $group => $title)
@php($groupChecks = $flagged->where('group', $group))
@continue($groupChecks->isEmpty())
### {{ $title }}

@foreach ($groupChecks as $check)
**{{ $statusLabel($check['status']) }} — {{ $check['label'] }} ({{ $check['value'] }})**

{{ $check['detail'] }}

@foreach (array_slice($check['items'], 0, $maxRows) as $item)
- {{ $item }}
@endforeach
@if (count($check['items']) > $maxRows)
- *En nog {{ count($check['items']) - $maxRows }} andere; zie de bijlage.*
@endif

@endforeach
@endforeach

## Uitschieters

@if ($report['outlier_total'] === 0)
Geen uitschieters: alle wijzigingen bleven binnen de drempels en er staan geen verdachte of verouderde concurrentprijzen open.
@else
@foreach ($changeGroups as $key => $group)
@continue(($outliers[$key] ?? []) === [])
### {{ $group['title'] }} — {{ count($outliers[$key]) }}

{{ $group['why'] }}

@component('mail::table')
| SKU | Oud | Nieuw | Verschil | Concurrent |
|:----|----:|------:|---------:|:-----------|
@foreach (array_slice($outliers[$key], 0, $maxRows) as $row)
| {{ $row['sku'] }} | {{ $euro($row['old_price']) }} | {{ $euro($row['new_price']) }} | {{ $pct($row['pct']) }} | {{ $row['shop'] ?? '—' }} |
@endforeach
@endcomponent

@if (count($outliers[$key]) > $maxRows)
*En nog {{ count($outliers[$key]) - $maxRows }} andere; zie de bijlage.*

@endif

@endforeach

@if ($outliers['suspicious'] !== [])
### Verdacht lage concurrentprijzen (< {{ $plain($thresholds['competitor_ratio']) }}% van de adviesprijs) — {{ count($outliers['suspicious']) }}

Een gezonde koppeling zit rond 75–110% van de adviesprijs. Ver daaronder staat er meestal een ánder kleed op de pagina van de concurrent.

@component('mail::table')
| SKU | Concurrent | Concurrentprijs | Adviesprijs | % van advies |
|:----|:-----------|----------------:|------------:|-------------:|
@foreach (array_slice($outliers['suspicious'], 0, $maxRows) as $row)
| {{ $row['sku'] }} | {{ $row['shop'] }} | {{ $euro($row['competitor_price']) }} | {{ $euro($row['advies']) }} | {{ number_format($row['ratio'], 0, ',', '.') }}% |
@endforeach
@endcomponent

@if (count($outliers['suspicious']) > $maxRows)
*En nog {{ count($outliers['suspicious']) - $maxRows }} andere; zie de bijlage.*

@endif
@endif

@if ($outliers['stale'] !== [])
### Verouderde concurrentprijzen (> {{ $thresholds['stale_days'] }} dagen niet bevestigd) — {{ count($outliers['stale']) }}

Deze prijzen bepalen nog steeds onze prijs, terwijl de scraper ze al een tijd niet meer heeft kunnen bevestigen.

@component('mail::table')
| SKU | Concurrent | Prijs | Laatst bevestigd | Leeftijd |
|:----|:-----------|------:|:-----------------|---------:|
@foreach (array_slice($outliers['stale'], 0, $maxRows) as $row)
| {{ $row['sku'] }} | {{ $row['shop'] }} | {{ $euro($row['competitor_price']) }} | {{ $row['scraped_at']?->format('d-m-Y') ?? 'onbekend' }} | {{ $row['age_days'] === null ? '—' : $row['age_days'].' dgn' }} |
@endforeach
@endcomponent

@if (count($outliers['stale']) > $maxRows)
*En nog {{ count($outliers['stale']) - $maxRows }} andere; zie de bijlage.*

@endif
@endif
@endif

@if ($report['rows'] !== [])
Alle {{ $changes['total'] }} wijzigingen staan met reden en bron-URL in `prijswijzigingen.csv`.
@endif
@if ($report['flagged'] > 0)
Alle {{ $report['flagged'] }} bevindingen van de controles staan in `aandachtspunten.csv`.
@endif

Groeten,<br>
{{ config('app.name') }}
@endcomponent
