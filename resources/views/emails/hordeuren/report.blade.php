@component('mail::message')
# Concurrentie-analyse plissé hordeuren

De concurrentie-analyse is afgerond op **{{ $finishedAt->copy()->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}** (duur: {{ (int) $startedAt->diffInMinutes($finishedAt) }} min). Het volledige Excel-rapport met de prijsvergelijking per deurmaat vind je in de bijlage.

@isset($summary)
- **Concurrenten gecontroleerd:** {{ $summary['shops'] }}
- **Cellen met een echte prijs:** {{ $summary['priced'] }} van {{ $summary['cells'] }} (de rest is een eerlijk label zoals "n.v.t." of "Op aanvraag")
@if (($summary['missing'] ?? 0) > 0)
- **Lege cellen:** {{ $summary['missing'] }} — deze concurrenten/maten leverden ook na meerdere pogingen niets op
@endif
@endisset

@if ($hadFailures)
@component('mail::panel')
Let op: niet alle scrapes zijn gelukt, ook niet na automatische herkansingen — sommige cellen kunnen leeg zijn of "n.v.t." tonen waar normaal een prijs staat. Start de analyse eventueel opnieuw voor een nieuwe poging.
@endcomponent
@endif

Groeten,<br>
{{ config('app.name') }}
@endcomponent
