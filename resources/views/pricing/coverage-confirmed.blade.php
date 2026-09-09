<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Genoteerd — {{ $sku }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
               background: #f4f4f5; color: #27272a; margin: 0; padding: 48px 20px; }
        .kaart { max-width: 520px; margin: 0 auto; background: #fff; border: 1px solid #e4e4e7;
                 border-radius: 8px; padding: 32px; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { line-height: 1.6; margin: 0 0 12px; }
        .sku { font-weight: 600; }
        .stil { color: #71717a; font-size: 14px; }
    </style>
</head>
<body>
    <div class="kaart">
        <h1>Genoteerd</h1>
        <p><span class="sku">{{ $sku }}</span>@if ($model) — {{ $model }}@endif @if ($maat) · {{ $maat }}@endif</p>
        <p>Dit kleed staat nu als “geen concurrent” genoteerd en verdwijnt uit het blok
            <em>Kleden zonder enige concurrentprijs</em> in het dagrapport.</p>
        <p class="stil">Kom je later toch een concurrent tegen, mail de URL dan naar
            {{ config('competitor_pricing.report.support_address') }} — dan zetten we hem terug.</p>
    </div>
</body>
</html>
