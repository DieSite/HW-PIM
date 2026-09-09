@php
    /** Vaste spatie na het euroteken: anders valt het bedrag in een smalle kolom op twee regels. */
    $euro = fn (?float $value): string => $value === null ? '—' : "€\u{00A0}".number_format($value, 2, ',', '.');
    $pct = fn (?float $value): string => $value === null ? '—' : ($value > 0 ? '+' : '').number_format($value, 1, ',', '.').'%';
    $plain = fn (float $value): string => rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    $nf = fn (int $value): string => number_format($value, 0, ',', '.');

    /** Een lange modelnaam breekt de tabel; de SKU is de identificatie. */
    $naam = function (?string $model, ?string $maat): string {
        $parts = array_filter([$model, $maat]);

        return $parts === [] ? '—' : implode(' · ', $parts);
    };

    $link = fn (?string $url, string $label): string => $url === null ? $label : '['.$label.']('.$url.')';

    /** De SKU als link naar het bewerkscherm in de PIM — scheelt zoeken. */
    $skuLink = fn (string $sku): string => '['.$sku.']('.route('product.by-sku', $sku).')';

    /**
     * De "Klopt dat?"-cel: één of twee knoppen plus de vervolgstap. Als string
     * opgebouwd omdat een reeks @if/@endif binnen één tabelregel geen geldige
     * Blade is.
     */
    $oordeelCel = function (array $row, bool $metAkkoord): string {
        $knoppen = [];

        if (($row['afkeur_url'] ?? null) !== null) {
            $knoppen[] = '[Koppeling klopt niet]('.$row['afkeur_url'].')';
        }

        if ($metAkkoord && ($row['akkoord_url'] ?? null) !== null) {
            $knoppen[] = '[Klopt wel]('.$row['akkoord_url'].')';
        }

        $knoppen[] = '_'.e($row['actie']).'_';

        return implode('<br>', $knoppen);
    };

    /**
     * De concurrent-cel van tabel 1: de goedkoopste, en waar het signaal juist
     * uit de vergelijking met een tweede winkel komt ook die tweede — "37%
     * onder de 2e concurrent" is niet na te lopen zonder de pagina waar het
     * 37% onder ligt. Als string opgebouwd omdat een reeks @if/@endif binnen
     * één tabelregel geen geldige Blade is.
     */
    $concurrentCel = function (array $row) use ($euro, $link): string {
        if ($row['shop'] === null) {
            return '—';
        }

        $cel = $link($row['url'], e($row['shop'])).'<br>'.$euro($row['concurrentprijs']);

        if ($row['tweede_shop'] !== null) {
            $cel .= '<br><br>2e: '.$link($row['tweede_url'], e($row['tweede_shop'])).'<br>'.$euro($row['tweede_prijs']);
        }

        return $cel;
    };

    $changes = $report['changes'];
    $coverage = $report['coverage'];
    $actions = $report['actions'];

    /** Elk blok draagt tot 500 regels voor de CSV; de mail toont er max_rows. */
    $toon = fn (array $block): array => array_slice($block['items'], 0, $maxRows);

    $checks = collect($report['checks']);
    $flagged = $checks->where('status', '!=', 'ok');

    $statusLabel = fn (string $status): string => match ($status) {
        'alert' => '🚨',
        'warn'  => '⚠️',
        default => '✅',
    };
@endphp

@component('mail::message')
# Concurrentie-analyse vloerkleden

Run van **{{ $report['since']->copy()->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}** tot **{{ $report['until']->copy()->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}** — {{ $nf($changes['total']) }} {{ $changes['total'] === 1 ? 'prijs' : 'prijzen' }} gewijzigd over {{ $nf($coverage['prices']) }} concurrentprijzen bij {{ $coverage['shops'] }} winkels.

@if ($report['alerts'] > 0)
@component('mail::panel')
**{{ $report['alerts'] }} {{ $report['alerts'] === 1 ? 'controle slaat' : 'controles slaan' }} alarm** — zie onderaan bij "Staat de analyse zelf goed?".
@endcomponent
@endif

## 1. {{ $actions['suspects']['title'] }}

{{ $actions['suspects']['action'] }}

@if ($actions['suspects']['items'] === [])
Geen enkel kleed gaf een aanwijzing dat de prijs niet klopt.
@else
@component('mail::table')
| Kleed | Wat is er mis | Onze prijs | Goedkoopste concurrent | Klopt dat? |
|:------|:--------------|-----------:|:-----------------------|:-----------|
@foreach ($actions['suspects']['items'] as $row)
| **{!! $skuLink($row['sku']) !!}**<br>{{ $naam($row['model'], $row['maat']) }} | {{ $row['reden'] }} | **{{ $euro($row['prijs']) }}**<br>advies {{ $euro($row['advies']) }} | {!! $concurrentCel($row) !!} | {!! $oordeelCel($row, true) !!} |
@endforeach
@endcomponent
@if ($actions['suspects']['total'] > count($actions['suspects']['items']))
Nog {{ $nf($actions['suspects']['total'] - count($actions['suspects']['items'])) }} kleden met eenzelfde signaal staan in **acties.csv**.
@endif
@endif

## 2. {{ $actions['new_products']['title'] }} — {{ $nf($actions['new_products']['total']) }}

{{ $actions['new_products']['action'] }}

@php $rijen = $toon($actions['new_products']); @endphp
@if ($rijen === [])
Geen nieuwe kleden toegevoegd in deze periode.
@else
@component('mail::table')
| Kleed | Prijs | Advies | Concurrenten | Actie |
|:------|------:|-------:|-------------:|:------|
@foreach ($rijen as $row)
| {!! $skuLink($row['sku']) !!}<br>{{ $naam($row['model'], $row['maat']) }} | {{ $euro($row['prijs']) }} | {{ $euro($row['advies']) }} | {{ $row['competitors'] }} | {{ $row['actie'] }} |
@endforeach
@endcomponent
@if ($actions['new_products']['total'] > count($rijen))
Nog {{ $nf($actions['new_products']['total'] - count($rijen)) }} in **acties.csv**.
@endif
@endif

## 3. {{ $actions['new_prices']['title'] }} — {{ $nf($actions['new_prices']['total']) }}

{{ $actions['new_prices']['action'] }}

@php $rijen = $toon($actions['new_prices']); @endphp
@if ($rijen === [])
Geen kleden die voor het eerst een concurrentprijs kregen.
@else
@component('mail::table')
| Kleed | Concurrent | Concurrentprijs | Onze prijs | Klopt dat? |
|:------|:-----------|----------------:|-----------:|:-----------|
@foreach ($rijen as $row)
| {!! $skuLink($row['sku']) !!}<br>{{ $naam($row['model'], $row['maat']) }} | {{ $row['shop'] ? $link($row['url'], $row['shop']) : '—' }} | {{ $euro($row['concurrentprijs']) }} | {{ $euro($row['prijs']) }} | {!! $oordeelCel($row, false) !!} |
@endforeach
@endcomponent
@if ($actions['new_prices']['total'] > count($rijen))
Nog {{ $nf($actions['new_prices']['total'] - count($rijen)) }} in **acties.csv**.
@endif
@endif

## 4. {{ $actions['lost_prices']['title'] }} — {{ $nf($actions['lost_prices']['total']) }}

{{ $actions['lost_prices']['action'] }}

@php $rijen = $toon($actions['lost_prices']); @endphp
@if ($rijen === [])
Geen enkele koppeling verdwenen deze run.
@else
@component('mail::table')
| Kleed | Kwijt bij | Laatste prijs | Concurrenten over | Actie |
|:------|:----------|--------------:|------------------:|:------|
@foreach ($rijen as $row)
| {!! $skuLink($row['sku']) !!}<br>{{ $naam($row['model'], $row['maat']) }} | {{ $link($row['url'], $row['shops']) }} | {{ $euro($row['laatste_prijs']) }} | {{ $row['resterend'] }} | {{ $row['actie'] }} |
@endforeach
@endcomponent
@if ($actions['lost_prices']['total'] > count($rijen))
Nog {{ $nf($actions['lost_prices']['total'] - count($rijen)) }} in **acties.csv**.
@endif
@endif

## 5. {{ $actions['no_coverage']['title'] }} — {{ $nf($actions['no_coverage']['total']) }}

{{ $actions['no_coverage']['action'] }}

@php $rijen = $toon($actions['no_coverage']); @endphp
@if ($rijen === [])
Elk kleed heeft minstens één concurrentprijs.
@else
@component('mail::table')
| Kleed | Prijs | Advies | Klopt dat? |
|:------|------:|-------:|:-----------|
@foreach ($rijen as $row)
| {!! $skuLink($row['sku']) !!}<br>{{ $naam($row['model'], $row['maat']) }} | {{ $euro($row['prijs']) }} | {{ $euro($row['advies']) }} | [Klopt, geen concurrent gevonden]({{ $row['bevestig_url'] }})<br>[Mail url van concurrent]({{ $row['mail_url'] }}) |
@endforeach
@endcomponent
De duurste {{ count($rijen) }} staan hierboven; de volledige lijst zit in **acties.csv**.
Een kleed dat je als “geen concurrent” bevestigt verdwijnt uit dit blok, zodat er alleen overblijft wat nog niemand heeft nagekeken.
@endif

---

## Staat de analyse zelf goed?

@if ($flagged->isEmpty())
✅ Alle {{ $checks->count() }} controles staan op groen.
@else
{{ $flagged->count() }} van de {{ $checks->count() }} controles vragen aandacht; de rest staat op groen. Details per bevinding staan in **aandachtspunten.csv**.

@component('mail::table')
| | Controle | Uitkomst |
|:-|:---------|:---------|
@foreach ($flagged as $check)
| {{ $statusLabel($check['status']) }} | {{ $check['label'] }} | {{ $check['value'] }} |
@endforeach
@endcomponent
@endif

@if ($report['rows'] !== [])
Alle {{ $nf($changes['total']) }} prijswijzigingen van deze run zitten als CSV bij deze mail.
@endif

@endcomponent
