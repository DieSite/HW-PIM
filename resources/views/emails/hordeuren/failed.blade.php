@component('mail::message')
# Concurrentie-analyse hordeuren mislukt

De concurrentie-analyse voor de plissé hordeuren kon niet worden afgerond. Er is geen rapport verstuurd.

@component('mail::panel')
**Technische details:**

```
{{ $error }}
```
@endcomponent

Start de analyse opnieuw via het PIM (Tools → Hordeuren concurrentie-analyse). Blijft dit misgaan, geef de details hierboven dan door aan de beheerder.

Groeten,<br>
{{ config('app.name') }}
@endcomponent
