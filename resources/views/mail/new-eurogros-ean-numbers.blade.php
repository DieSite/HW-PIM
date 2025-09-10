<x-mail::message>
# Nieuwe EAN nummers Eurogros

Eurogros heeft nieuwe EAN nummers. De volgende zijn toegevoegd:

<x-mail::panel :url="''">
@foreach($eanNumbers as $eanNumber)
- {{$eanNumber}}
@endforeach
</x-mail::panel>

Veel succes vandaag!<br>
Luuk | DieSite
</x-mail::message>
