@component('mail::message')
# Bol.com synchronisatie mislukt

Het product **{{ $product->values['common']['productnaam'] ?? $product->sku }}** kon niet naar Bol.com worden gesynchroniseerd.

## Productgegevens
- **SKU:** {{ $product->sku }}
- **Bol.com account:** {{ $bolComCredential->name }}
- **Stap:** {{ $event->step?->label() ?? $event->step }}
- **Tijdstip:** {{ $event->created_at->format('d-m-Y H:i:s') }}
@if ($event->bol_process_id)
- **Bol.com proces-ID:** {{ $event->bol_process_id }}
@endif

## Wat ging er mis?

{{ $event->customer_message ?? $event->message ?? 'Onbekende fout' }}

@isset($event->payload['response_body'])
@component('mail::panel')
**Technische details (response van Bol.com):**

```
{{ is_array($event->payload['response_body']) ? json_encode($event->payload['response_body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $event->payload['response_body'] }}
```
@endcomponent
@endisset

@component('mail::button', ['url' => route('admin.catalog.products.edit', $product->id)])
Open product in PIM
@endcomponent

{{ config('app.name') }}
@endcomponent
