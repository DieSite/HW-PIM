{{--// resources/views/emails/bolcom/sync-successful.blade.php--}}
@component('mail::message')
# Product sync successful

Het product **{{ $product->values['common']['productnaam'] }}** is succesvol gesynchroniseerd met Bol.com.

## Product Details
- **Product Naam:** {{ $product->values['common']['productnaam'] }}
- **SKU:** {{ $product->sku }}
- **Bol.com Offer ID:** {{ $offer['offerId'] ?? 'Not available' }}
- **Bol.com Account:** {{ $bolComCredential->name ?? 'Not available' }}
- **EAN:** {{ $offer['ean'] ?? 'Not available' }}
- **Prijs:** €{{ $offer['pricing']['bundlePrices'][0]['unitPrice'] ?? 'Not available' }}
- **Voorraad:** {{ $offer['stock']['amount'] ?? 'Not available' }}

@if(count($offer['notPublishableReasons']) > 0)
## Let op: dit product is nog niet verkoopbaar
De volgende problemen moeten handmatig worden opgelost voordat het product kan worden gepubliceerd:

@foreach($offer['notPublishableReasons'] as $reason)
- {{ $reason['description'] }} (Code: {{ $reason['code'] }})
@endforeach

Ga naar het bol.com partner dashboard om het probleem op te lossen: [Bol.com Partner Dashboard](https://partner.bol.com)

@endif

{{ config('app.name') }}
@endcomponent
