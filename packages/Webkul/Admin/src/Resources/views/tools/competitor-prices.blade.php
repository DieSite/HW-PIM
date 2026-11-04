<x-admin::layouts>
    <x-slot:title>
        Concurrentieprijzen
    </x-slot>

    @php
        $euro = fn (?float $amount): string => $amount === null ? '–' : '€ '.number_format($amount, 0, ',', '.');
        $pct = fn (?float $value): string => $value === null ? '–' : number_format($value, 1, ',', '.').'%';
        $signed = fn (float $value, string $text): string => ($value > 0 ? '+' : ($value < 0 ? '−' : '')).$text;
        $discountLabel = number_format($filters['min_discount'], $filters['min_discount'] == floor($filters['min_discount']) ? 0 : 1, ',', '.');
    @endphp

    @push('styles')
        {{-- Eigen CSS: Tailwind compileert alleen klassen die bij de laatste build in de bron stonden. --}}
        <style>
            .cp { --cp-line: #e5e7eb; --cp-muted: #6b7280; --cp-soft: #f9fafb; --cp-card: #fff; --cp-text: #1f2937; --cp-accent: #ff6700; --cp-own: #fff4ec; --cp-off: #9ca3af; color: var(--cp-text); font-size: 14px; }
            .dark .cp { --cp-line: #374151; --cp-muted: #9ca3af; --cp-soft: #111827; --cp-card: #1f1f2e; --cp-text: #e5e7eb; --cp-own: #3b2414; --cp-off: #6b7280; }
            .cp-card { background: var(--cp-card); border-radius: 6px; box-shadow: 0 1px 2px rgba(0, 0, 0, .06); }
            .cp-intro { color: var(--cp-muted); max-width: 90ch; margin: 4px 0 0; }
            .cp-filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; padding: 14px 16px; margin-top: 14px; }
            .cp-filters label { display: grid; gap: 4px; font-size: 12px; font-weight: 600; color: var(--cp-muted); }
            .cp-filters input, .cp-filters select, .cp-bar input, .cp-bar select { padding: 7px 9px; border: 1px solid var(--cp-line); border-radius: 6px; background: var(--cp-card); color: var(--cp-text); font-size: 14px; }
            .cp-percent { display: flex; align-items: center; gap: 4px; }
            .cp-percent input { width: 72px; font-weight: 700; font-size: 16px; }
            .cp-summary { margin: 14px 0 8px; color: var(--cp-muted); }
            .cp-summary strong { color: var(--cp-text); }
            .cp-summary-link { margin-left: 8px; color: var(--cp-accent); font-weight: 600; }
            .cp-toggle { display: flex !important; grid-auto-flow: column; align-items: center; gap: 6px !important; padding-bottom: 8px; color: var(--cp-text) !important; font-size: 14px !important; cursor: pointer; }
            .cp-toggle input { width: 16px; height: 16px; }
            .cp-filters input:disabled { opacity: .45; }
            .cp-bar { position: sticky; top: 57px; z-index: 30; display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; padding: 10px 16px; margin-bottom: 14px; border: 1px solid var(--cp-line); }
            .cp-bar-group { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
            .cp-bar-label { font-size: 12px; font-weight: 600; color: var(--cp-muted); }
            .cp-count { font-weight: 700; min-width: 120px; }
            .cp-chip-button { padding: 5px 10px; border: 1px solid var(--cp-line); border-radius: 999px; background: var(--cp-card); color: var(--cp-text); font-size: 13px; cursor: pointer; }
            .cp-chip-button:hover { border-color: var(--cp-accent); color: var(--cp-accent); }
            .cp-below input { width: 56px; }
            .cp-actions { margin-left: auto; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
            .cp-actions input[name=reason] { width: 260px; }
            .cp-button { padding: 7px 12px; border: 0; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px; }
            .cp-button-exclude { background: #dc2626; color: #fff; }
            .cp-button-include { background: var(--cp-soft); color: var(--cp-text); border: 1px solid var(--cp-line); }
            .cp-button:disabled { opacity: .4; cursor: not-allowed; }
            .cp-rug { margin-bottom: 16px; }
            /* Blijft onder de selectiebalk staan zolang er rijen van dit kleed in beeld zijn. Geen overflow op .cp-rug: dat breekt sticky. */
            .cp-rug-head { position: sticky; top: calc(57px + var(--cp-bar-h, 0px)); z-index: 20; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; padding: 12px 16px; border-bottom: 1px solid var(--cp-line); background: var(--cp-card); border-radius: 6px 6px 0 0; box-shadow: 0 2px 4px -2px rgba(0, 0, 0, .12); }
            .cp-size:last-child, .cp-size:last-child .cp-table-wrap { border-radius: 0 0 6px 6px; }
            .cp-rug-head h2 { margin: 0; font-size: 16px; font-weight: 700; }
            .cp-rug-head a { color: var(--cp-accent); font-weight: 600; font-size: 13px; }
            .cp-size { border-bottom: 1px solid var(--cp-line); }
            .cp-size:last-child { border-bottom: 0; }
            /* Plakt onder de kleedkop (hoogte per kleed gemeten) zolang de rijen van deze maat in beeld zijn. */
            .cp-size-head { position: sticky; top: calc(57px + var(--cp-bar-h, 0px) + var(--cp-rug-h, 0px)); z-index: 10; display: flex; flex-wrap: wrap; gap: 6px 18px; align-items: center; padding: 10px 16px; background: var(--cp-soft); box-shadow: 0 1px 0 var(--cp-line); }
            .cp-size-head .cp-size-name { font-weight: 700; }
            .cp-fact { font-size: 13px; color: var(--cp-muted); }
            .cp-fact strong { color: var(--cp-text); }
            .cp-discount { padding: 2px 8px; border-radius: 999px; background: #fee2e2; color: #991b1b; font-weight: 700; font-size: 13px; }
            .cp-why { font-size: 13px; color: var(--cp-muted); font-style: italic; }
            .cp-table-wrap { overflow-x: auto; }
            .cp table { width: 100%; border-collapse: collapse; }
            .cp th { text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--cp-muted); padding: 6px 10px; white-space: nowrap; }
            .cp td { padding: 7px 10px; border-top: 1px solid var(--cp-line); white-space: nowrap; vertical-align: middle; }
            .cp td.cp-num, .cp th.cp-num { text-align: right; font-variant-numeric: tabular-nums; }
            .cp td.cp-check, .cp th.cp-check { width: 1%; padding-left: 16px; }
            .cp td.cp-check input { width: 16px; height: 16px; cursor: pointer; }
            .cp tr.cp-selected td { background: #ffedd5; }
            .dark .cp tr.cp-selected td { background: #3b2414; }
            .cp tr.cp-own td { background: var(--cp-own); font-weight: 700; }
            .cp tr.cp-off td { color: var(--cp-off); }
            .cp tr.cp-excluded .cp-strike { text-decoration: line-through; text-decoration-thickness: 2px; text-decoration-color: #dc2626; }
            .cp tr.cp-excluded td:first-child { box-shadow: inset 3px 0 0 #dc2626; }
            .cp td a { color: inherit; text-decoration: underline; }
            .cp-cheaper { color: #b91c1c; }
            .cp-pricier { color: #15803d; }
            .cp tr.cp-off .cp-cheaper, .cp tr.cp-off .cp-pricier { color: inherit; }
            .cp-badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
            .cp-badge-sets { background: #ffedd5; color: #c2410c; }
            .cp-badge-counts { background: #dcfce7; color: #15803d; }
            .cp-badge-stale { background: #f3f4f6; color: #6b7280; }
            .cp-badge-excluded { background: #fee2e2; color: #991b1b; white-space: normal; }
            .cp-row-action { background: none; border: 0; padding: 0; color: var(--cp-accent); font-weight: 600; cursor: pointer; font-size: 13px; }
            .cp-empty { padding: 48px 16px; text-align: center; color: var(--cp-muted); }
            .cp-pages { display: flex; gap: 12px; align-items: center; justify-content: center; margin: 16px 0 32px; }
            .cp-pages a, .cp-pages span.cp-disabled { padding: 7px 12px; border: 1px solid var(--cp-line); border-radius: 6px; background: var(--cp-card); }
            .cp-pages a { color: var(--cp-accent); font-weight: 600; }
            .cp-pages span.cp-disabled { color: var(--cp-off); }
            .cp-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
            .cp-errors { margin-top: 12px; padding: 8px 12px; border-radius: 6px; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        </style>
    @endpush

    <div class="cp">
        <div>
            <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">Concurrentieprijzen</p>
            <p class="cp-intro">
                @if ($filters['excluded_only'])
                    Alle maten met een uitgesloten concurrentprijs, ongeacht de korting. Zet een prijs terug met "Weer meetellen";
                    met "Uitgesloten" in de selectiebalk pak je ze allemaal tegelijk.
                @else
                    Maten die we minstens {{ $discountLabel }}% onder de adviesprijs verkopen, met alle concurrentprijzen die daarachter zitten.
                @endif
                Klopt een prijs niet (ander kleed, tijdelijke actie, foute koppeling), sluit hem dan uit: de maat wordt direct opnieuw berekend
                en naar de webshop en Bol gestuurd. Een uitsluiting geldt alleen voor dat bedrag; verandert de prijs van de winkel, dan telt hij weer mee.
            </p>
        </div>

        <x-admin::flash-group />

        @if ($errors->any())
            <div class="cp-errors" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="GET" action="{{ route('admin.tools.competitor-prices.index') }}" class="cp-card cp-filters">
            <label @if ($filters['excluded_only']) title="Geldt niet als je alleen uitgesloten prijzen bekijkt" @endif>
                Korting t.o.v. advies, minimaal
                <span class="cp-percent">
                    <input type="number" name="korting" min="0" max="100" step="1" value="{{ $discountLabel }}" aria-label="Minimale korting in procent" @disabled($filters['excluded_only'])>
                    %
                </span>
            </label>

            <label class="cp-toggle">
                <input type="checkbox" name="uitgesloten" value="1" @checked($filters['excluded_only']) onchange="this.form.querySelector('[name=korting]').disabled = this.checked">
                Alleen uitgesloten prijzen
            </label>

            <label>
                Merk
                <select name="merk">
                    <option value="">Alle merken</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand }}" @selected($filters['brand'] === $brand)>{{ $brand }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                Concurrent
                <select name="winkel">
                    <option value="">Alle winkels</option>
                    @foreach ($shops as $shop)
                        <option value="{{ $shop }}" @selected($filters['shop'] === $shop)>{{ $shop }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                Zoeken
                <input type="search" name="zoek" value="{{ $filters['search'] }}" placeholder="Naam of SKU">
            </label>

            <label>
                Per pagina
                <select name="per_pagina">
                    @foreach ($pageSizes as $size)
                        <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} kleden</option>
                    @endforeach
                </select>
            </label>

            <button type="submit" class="primary-button">Toon</button>

            @if (request()->hasAny(['korting', 'merk', 'winkel', 'zoek', 'per_pagina', 'uitgesloten']))
                <a href="{{ route('admin.tools.competitor-prices.index') }}" class="transparent-button">Wis filters</a>
            @endif
        </form>

        <p class="cp-summary">
            @if ($filters['excluded_only'])
                <strong>{{ $excludedCount }} uitgesloten {{ $excludedCount === 1 ? 'prijs' : 'prijzen' }}</strong> in
                <strong>{{ $sizeCount }} {{ $sizeCount === 1 ? 'maat' : 'maten' }}</strong> van
                <strong>{{ $rugs->total() }} {{ $rugs->total() === 1 ? 'kleed' : 'kleden' }}</strong>.
                <a class="cp-summary-link" href="{{ request()->fullUrlWithQuery(['uitgesloten' => null, 'page' => null]) }}">Terug naar de kortingen</a>
            @else
                <strong>{{ $sizeCount }} {{ $sizeCount === 1 ? 'maat' : 'maten' }}</strong> in
                <strong>{{ $rugs->total() }} {{ $rugs->total() === 1 ? 'kleed' : 'kleden' }}</strong>
                met minstens {{ $discountLabel }}% korting, grootste korting eerst.
                @if ($excludedCount > 0)
                    <a class="cp-summary-link" href="{{ request()->fullUrlWithQuery(['uitgesloten' => 1, 'page' => null]) }}">Alle {{ $excludedCount }} uitgesloten {{ $excludedCount === 1 ? 'prijs' : 'prijzen' }} bekijken</a>
                @endif
            @endif
        </p>

        @if ($rugs->isEmpty())
            <div class="cp-card cp-empty">
                @if ($filters['excluded_only'])
                    Geen uitgesloten prijzen{{ $filters['brand'] || $filters['shop'] || $filters['search'] ? ' binnen deze filters' : '' }}.
                @else
                    Geen maten met minstens {{ $discountLabel }}% korting{{ $filters['brand'] || $filters['shop'] || $filters['search'] ? ' binnen deze filters' : '' }}.
                    Verlaag het percentage om meer te zien.
                @endif
            </div>
        @else
            <form method="POST" action="{{ route('admin.tools.competitor-prices.update') }}" id="cp-bulk">
                @csrf

                @if ($canEdit)
                    <div class="cp-card cp-bar" role="region" aria-label="Bulkacties">
                        <span class="cp-count" data-cp-count aria-live="polite">Niets geselecteerd</span>

                        <div class="cp-bar-group">
                            <span class="cp-bar-label">Selecteer:</span>
                            <button type="button" class="cp-chip-button" data-cp-select="all">Alle prijzen</button>
                            <button type="button" class="cp-chip-button" data-cp-select="sets" title="De goedkoopste meetellende prijs per maat: die bepaalt onze prijs">Die onze prijs bepalen</button>
                            <button type="button" class="cp-chip-button" data-cp-select="excluded">Uitgesloten</button>

                            <select data-cp-select-shop aria-label="Selecteer alle prijzen van een winkel">
                                <option value="">Van winkel…</option>
                                @foreach ($shops as $shop)
                                    <option value="{{ $shop }}">{{ $shop }}</option>
                                @endforeach
                            </select>

                            <span class="cp-below cp-bar-group">
                                <input type="number" min="0" max="100" step="1" value="{{ $discountLabel }}" data-cp-below-value aria-label="Procent onder advies">
                                <button type="button" class="cp-chip-button" data-cp-select="below">% of meer onder advies</button>
                            </span>

                            <button type="button" class="cp-chip-button" data-cp-select="none">Niets</button>
                        </div>

                        <div class="cp-actions">
                            <input type="text" name="reason" maxlength="500" placeholder="Reden (optioneel)" aria-label="Reden voor uitsluiten">
                            <button type="submit" name="action" value="exclude" class="cp-button cp-button-exclude" data-cp-exclude disabled>Uitsluiten</button>
                            <button type="submit" name="action" value="include" class="cp-button cp-button-include" data-cp-include disabled>Weer meetellen</button>
                        </div>
                    </div>
                @endif

                @foreach ($rugs as $rug)
                    <section class="cp-card cp-rug">
                        <header class="cp-rug-head">
                            <div>
                                <h2>{{ $rug['name'] }}</h2>
                                <span class="cp-fact">{{ collect([$rug['brand'], $rug['sku']])->filter()->implode(' · ') }}</span>
                            </div>

                            <a href="{{ route('admin.catalog.products.edit', $rug['id']) }}#sectie-concurrentie">Product openen</a>
                        </header>

                        @foreach ($rug['sizes'] as $size)
                            @php
                                $why = match (true) {
                                    $size['manual_discount'] > 0 => 'met '.rtrim(rtrim(number_format($size['manual_discount'], 1, ',', ''), '0'), ',').'% extra korting',
                                    $size['cheapest'] === null => 'geen meetellende concurrent',
                                    $size['floor'] !== null && $size['price'] <= ceil($size['floor']) => 'op de bodem; '.$size['cheapest']['shop'].' zit eronder ('.$euro($size['cheapest']['price']).')',
                                    default => 'volgt '.$size['cheapest']['shop'],
                                };
                            @endphp

                            <div class="cp-size">
                                <div class="cp-size-head">
                                    <span class="cp-size-name">{{ $size['size'] }}</span>
                                    <span class="cp-fact">{{ $size['sku'] }}</span>
                                    <span class="cp-fact">Advies <strong>{{ $euro($size['advice_price']) }}</strong></span>
                                    <span class="cp-fact">Onze prijs <strong>{{ $euro($size['price']) }}</strong></span>
                                    @if ($size['discount'] !== null && $size['discount'] > 0)
                                        <span class="cp-discount">−{{ $pct($size['discount']) }}</span>
                                    @endif
                                    <span class="cp-why">{{ $why }}</span>
                                    @if ($size['computed_price'] !== null && $size['price'] !== null && abs($size['computed_price'] - $size['price']) >= 0.005)
                                        <span class="cp-fact" title="Wat de volgende analyse zou zetten">→ berekend {{ $euro($size['computed_price']) }}</span>
                                    @endif
                                </div>

                                <div class="cp-table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="cp-check"><span class="cp-hidden">Selecteren</span></th>
                                                <th>#</th>
                                                <th>Winkel</th>
                                                <th class="cp-num">Prijs</th>
                                                <th class="cp-num">t.o.v. onze prijs</th>
                                                <th class="cp-num">t.o.v. advies</th>
                                                <th>Opgehaald</th>
                                                <th>Status</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($size['rows'] as $index => $row)
                                                <tr @class(['cp-own' => $row['own'], 'cp-off' => ! $row['counts'], 'cp-excluded' => $row['excluded']])>
                                                    <td class="cp-check">
                                                        @if (! $row['own'] && $canEdit)
                                                            <input
                                                                type="checkbox"
                                                                name="ids[]"
                                                                value="{{ $row['id'] }}"
                                                                aria-label="{{ $row['shop'] }} {{ $euro($row['price']) }} voor {{ $size['sku'] }}"
                                                                data-cp-price
                                                                data-shop="{{ $row['shop'] }}"
                                                                data-below="{{ $row['below_advice'] !== null ? round($row['below_advice'], 2) : '' }}"
                                                                @if ($row['sets_price']) data-sets @endif
                                                                @if ($row['excluded']) data-excluded @endif
                                                            >
                                                        @endif
                                                    </td>
                                                    <td class="cp-fact cp-strike">{{ $index + 1 }}.</td>
                                                    <td>
                                                        @if ($row['url'])
                                                            <a class="cp-strike" href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer">{{ $row['shop'] }}</a>
                                                        @else
                                                            <span class="cp-strike">{{ $row['shop'] }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="cp-num cp-strike">{{ $euro($row['price']) }}</td>
                                                    <td class="cp-num cp-strike">
                                                        @if (! $row['own'] && $row['difference'] !== null && $size['price'])
                                                            @if (abs($row['difference']) < 0.005)
                                                                gelijk
                                                            @else
                                                                <span @class(['cp-cheaper' => $row['difference'] < 0, 'cp-pricier' => $row['difference'] > 0])>
                                                                    {{ $signed($row['difference'], $euro(abs($row['difference']))) }}
                                                                    ({{ $signed($row['difference'], number_format(abs($row['difference']) / $size['price'] * 100, 1, ',', '.').'%') }})
                                                                </span>
                                                            @endif
                                                        @endif
                                                    </td>
                                                    <td class="cp-num cp-strike">
                                                        @if ($row['below_advice'] !== null)
                                                            @if (abs($row['below_advice']) < 0.05)
                                                                gelijk
                                                            @else
                                                                <span @class(['cp-cheaper' => $row['below_advice'] > 0 && ! $row['own'], 'cp-pricier' => $row['below_advice'] < 0 && ! $row['own']])>{{ $row['below_advice'] > 0 ? '−' : '+' }}{{ $pct(abs($row['below_advice'])) }}</span>
                                                            @endif
                                                        @endif
                                                    </td>
                                                    <td class="cp-fact cp-strike">{{ $row['own'] ? '' : $row['scraped_at']?->format('d-m-Y') }}</td>
                                                    <td>
                                                        @if ($row['own'])
                                                            <span class="cp-fact">onze prijs</span>
                                                        @elseif ($row['excluded'])
                                                            <span class="cp-badge cp-badge-excluded">
                                                                Uitgesloten{{ $row['exclusion']['by'] ? ' door '.$row['exclusion']['by'] : '' }}{{ $row['exclusion']['reason'] ? ': '.$row['exclusion']['reason'] : '' }}
                                                            </span>
                                                        @elseif ($row['stale'])
                                                            <span class="cp-badge cp-badge-stale">Ouder dan {{ $maxAge }} dagen</span>
                                                        @elseif ($row['sets_price'])
                                                            <span class="cp-badge cp-badge-sets">Bepaalt onze prijs</span>
                                                        @else
                                                            <span class="cp-badge cp-badge-counts">Telt mee</span>
                                                        @endif
                                                    </td>
                                                    <td class="cp-num">
                                                        @if (! $row['own'] && $canEdit)
                                                            <button type="submit" class="cp-row-action" name="row_action" value="{{ ($row['excluded'] ? 'include:' : 'exclude:').$row['id'] }}">
                                                                {{ $row['excluded'] ? 'Weer meetellen' : 'Uitsluiten' }}
                                                            </button>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </form>

            @if ($rugs->hasPages())
                <nav class="cp-pages" aria-label="Pagina's">
                    @if ($rugs->onFirstPage())
                        <span class="cp-disabled">← Vorige</span>
                    @else
                        <a href="{{ $rugs->previousPageUrl() }}">← Vorige</a>
                    @endif

                    <span>Pagina {{ $rugs->currentPage() }} van {{ $rugs->lastPage() }}</span>

                    @if ($rugs->hasMorePages())
                        <a href="{{ $rugs->nextPageUrl() }}">Volgende →</a>
                    @else
                        <span class="cp-disabled">Volgende →</span>
                    @endif
                </nav>
            @endif
        @endif
    </div>

    @push('scripts')
        @include('admin::custom.keep-scroll')

        <script>
            /*
             * De kleedkoppen plakken net onder de selectiebalk; die balk kan
             * over twee regels lopen, dus meet zijn hoogte (ook na resizen).
             * Op :root, zodat het Vue's herrendering van #app overleeft.
             */
            (function () {
                function syncHeights() {
                    const bar = document.querySelector('#cp-bulk .cp-bar');
                    document.documentElement.style.setProperty('--cp-bar-h', bar ? bar.offsetHeight + 'px' : '0px');

                    /* Een kleedkop kan over twee regels lopen; elke maat plakt onder de kop van zijn eigen kleed. */
                    document.querySelectorAll('.cp-rug').forEach(function (rug) {
                        const head = rug.querySelector('.cp-rug-head');
                        rug.style.setProperty('--cp-rug-h', head ? head.offsetHeight + 'px' : '0px');
                    });
                }

                document.addEventListener('DOMContentLoaded', function () {
                    syncHeights();

                    if ('ResizeObserver' in window) {
                        const observer = new ResizeObserver(syncHeights);
                        document.querySelectorAll('#cp-bulk .cp-bar, .cp-rug-head').forEach((element) => observer.observe(element));
                    }
                });

                window.addEventListener('resize', syncHeights);
            })();

            /*
             * Alles via document-brede listeners: Vue rendert #app na het laden
             * opnieuw, dus elementen die je nu opzoekt bestaan straks niet meer.
             */
            (function () {
                const form = () => document.getElementById('cp-bulk');
                const boxes = () => [...(form()?.querySelectorAll('[data-cp-price]') ?? [])];

                function refresh() {
                    const root = form();

                    if (! root) {
                        return;
                    }

                    const checked = boxes().filter((box) => box.checked);
                    const excluded = checked.filter((box) => box.hasAttribute('data-excluded')).length;
                    const active = checked.length - excluded;
                    const count = root.querySelector('[data-cp-count]');
                    const excludeButton = root.querySelector('[data-cp-exclude]');
                    const includeButton = root.querySelector('[data-cp-include]');

                    boxes().forEach((box) => box.closest('tr').classList.toggle('cp-selected', box.checked));

                    if (count) {
                        count.textContent = checked.length === 0
                            ? 'Niets geselecteerd'
                            : checked.length + (checked.length === 1 ? ' prijs' : ' prijzen') + ' geselecteerd';
                    }

                    if (excludeButton) {
                        excludeButton.disabled = active === 0;
                        excludeButton.textContent = active > 0 ? active + ' uitsluiten' : 'Uitsluiten';
                    }

                    if (includeButton) {
                        includeButton.disabled = excluded === 0;
                        includeButton.textContent = excluded > 0 ? excluded + ' weer meetellen' : 'Weer meetellen';
                    }
                }

                function select(predicate) {
                    boxes().forEach((box) => {
                        if (predicate(box)) {
                            box.checked = true;
                        }
                    });

                    refresh();
                }

                let last = null;

                document.addEventListener('click', function (event) {
                    const root = form();

                    if (! root || ! root.contains(event.target)) {
                        return;
                    }

                    const helper = event.target.closest('[data-cp-select]');

                    if (helper) {
                        const notExcluded = (box) => ! box.hasAttribute('data-excluded');

                        switch (helper.dataset.cpSelect) {
                            case 'all':
                                select(notExcluded);
                                break;
                            case 'sets':
                                select((box) => box.hasAttribute('data-sets'));
                                break;
                            case 'excluded':
                                select((box) => box.hasAttribute('data-excluded'));
                                break;
                            case 'below': {
                                const minimum = parseFloat(root.querySelector('[data-cp-below-value]').value);

                                if (Number.isFinite(minimum)) {
                                    select((box) => box.dataset.below !== '' && parseFloat(box.dataset.below) >= minimum && notExcluded(box));
                                }
                                break;
                            }
                            case 'none':
                                boxes().forEach((box) => box.checked = false);
                                refresh();
                                break;
                        }

                        return;
                    }

                    const box = event.target.closest('[data-cp-price]');

                    if (! box) {
                        return;
                    }

                    /* Shift-klik zet alles tussen de vorige en deze aanvinkbox gelijk. */
                    const all = boxes();

                    if (event.shiftKey && last && last !== box && all.includes(last)) {
                        const [from, to] = [all.indexOf(last), all.indexOf(box)].sort((a, b) => a - b);
                        all.slice(from, to + 1).forEach((other) => other.checked = box.checked);
                    }

                    last = box;
                    refresh();
                });

                document.addEventListener('change', function (event) {
                    const shopSelect = event.target.closest('#cp-bulk [data-cp-select-shop]');

                    if (! shopSelect) {
                        return;
                    }

                    const shop = shopSelect.value;

                    if (shop !== '') {
                        select((box) => box.dataset.shop === shop && ! box.hasAttribute('data-excluded'));
                    }

                    shopSelect.value = '';
                });

                /* Enter in het redenveld sluit de geselecteerde prijzen uit, niets anders. */
                document.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter' || ! event.target.matches('#cp-bulk input[name=reason]')) {
                        return;
                    }

                    event.preventDefault();

                    const excludeButton = form().querySelector('[data-cp-exclude]');

                    if (! excludeButton.disabled) {
                        excludeButton.click();
                    }
                });

                /* Een rij-knop werkt alleen op die ene prijs: de vinkjes gaan dan niet mee. */
                document.addEventListener('submit', function (event) {
                    if (event.target.id === 'cp-bulk' && event.submitter && event.submitter.name === 'row_action') {
                        boxes().forEach((box) => box.disabled = true);
                    }
                });
            })();
        </script>
    @endpush
</x-admin::layouts>
