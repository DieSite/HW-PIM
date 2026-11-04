{{--
    Concurrentie-analyse op het bewerkscherm. De inhoud wordt na elke
    uitsluiting opnieuw van de server gehaald (CompetitorAnalysisController),
    zodat niets wat je in het formulier nog niet hebt opgeslagen verloren gaat.

    De opmaak staat in een eigen stylesheet: Tailwind compileert alleen
    klassen die bij de laatste build in de bronbestanden stonden.
--}}
@php
    $competitorVariants = $product->parent_id === null ? $product->variants : collect([$product]);
@endphp

<div
    id="competitor-analysis"
    class="ca-panel"
    data-ca-url="{{ route('admin.catalog.products.competitor-analysis.show', $product->id) }}"
>
    @include('admin::catalog.products.edit.competitor-analysis.panel', [
        'product' => $product,
        'sizes'   => app(\App\Services\CompetitorPriceAnalysis::class)->forVariants($competitorVariants),
        'message' => null,
    ])
</div>

@pushOnce('styles')
    <style>
        .ca-panel { --ca-line: #e5e7eb; --ca-muted: #6b7280; --ca-head: #f9fafb; --ca-own: #fff4ec; --ca-off: #9ca3af; --ca-accent: #ff6700; font-size: 14px; color: #1f2937; }
        .dark .ca-panel { --ca-line: #374151; --ca-muted: #9ca3af; --ca-head: #111827; --ca-own: #3b2414; --ca-off: #6b7280; color: #e5e7eb; }
        .ca-muted { color: var(--ca-muted); font-size: 13px; margin: 0 0 12px; }
        .ca-message { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; }
        .ca-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; }
        .ca-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 640px), 1fr)); gap: 14px; }
        .ca-block { border: 1px solid var(--ca-line); border-radius: 8px; overflow-x: auto; }
        .ca-head { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; padding: 10px 12px; background: var(--ca-head); }
        .ca-head .ca-muted { margin: 0; }
        .ca-head strong + .ca-muted { margin-left: 8px; }
        .ca-calc { display: flex; flex-wrap: wrap; gap: 4px 18px; padding: 8px 12px; margin: 0; border-bottom: 1px solid var(--ca-line); font-size: 13px; }
        .ca-calc dt { color: var(--ca-muted); font-size: 12px; }
        .ca-calc dd { margin: 0; font-variant-numeric: tabular-nums; }
        .ca-calc-result dd { font-weight: 700; }
        .ca-warn { display: inline-block; margin-left: 4px; padding: 0 6px; border-radius: 999px; background: #fef3c7; color: #92400e; font-weight: 600; font-size: 12px; }
        .ca-table { width: 100%; border-collapse: collapse; }
        .ca-table td { padding: 7px 10px; border-top: 1px solid var(--ca-line); vertical-align: top; white-space: nowrap; }
        .ca-table tr:first-child td { border-top: 0; }
        .ca-table td.ca-shop { white-space: normal; }
        .ca-table td.ca-num { font-variant-numeric: tabular-nums; }
        .ca-rank, .ca-date { color: var(--ca-muted); font-size: 12px; }
        .ca-action { text-align: right; }
        .ca-own td { background: var(--ca-own); font-weight: 700; }
        .ca-off td, .ca-off a { color: var(--ca-off); }
        .ca-note { font-size: 12px; color: var(--ca-muted); font-weight: 400; }
        .ca-excluded .ca-rank, .ca-excluded .ca-shop-name, .ca-excluded .ca-num, .ca-excluded .ca-date { text-decoration: line-through; text-decoration-thickness: 2px; text-decoration-color: #dc2626; }
        .ca-excluded td:first-child { box-shadow: inset 3px 0 0 #dc2626; }
        .ca-note-excluded { display: inline-block; margin-top: 3px; padding: 1px 8px; border-radius: 999px; background: #fee2e2; color: #991b1b; font-weight: 600; }
        .dark .ca-note-excluded { background: #450a0a; color: #fecaca; }
        .ca-shop a { color: inherit; text-decoration: underline; }
        .ca-cheaper { color: #b91c1c; }
        .ca-pricier { color: #15803d; }
        .ca-off .ca-cheaper, .ca-off .ca-pricier { color: inherit; }
        .ca-link { background: none; border: 0; padding: 0; color: var(--ca-accent); font-weight: 600; cursor: pointer; font-size: 13px; }
        .ca-link:disabled, .ca-button:disabled { opacity: .5; cursor: wait; }
        .ca-exclude-row td { background: var(--ca-head); }
        .ca-exclude-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .ca-exclude-form input { flex: 1 1 220px; padding: 6px 8px; border: 1px solid var(--ca-line); border-radius: 6px; background: transparent; color: inherit; font-size: 13px; }
        .ca-button { background: var(--ca-accent); color: #fff; border: 0; border-radius: 6px; padding: 6px 10px; font-weight: 600; cursor: pointer; font-size: 13px; }
    </style>
@endPushOnce

@pushOnce('scripts')
    <script>
        (function () {
            const panel = () => document.getElementById('competitor-analysis');

            function csrfToken() {
                return document.querySelector('input[name=_token]')?.value
                    ?? document.querySelector('meta[name="csrf-token"]')?.content;
            }

            /**
             * De herberekening heeft prijzen in de database veranderd. Zet ze
             * ook in het open formulier en in de maten-lijst, anders zet
             * "Bewaar product" straks de oude prijs terug.
             */
            function applyPrices(prices, competition) {
                const ownSku = document.querySelector('input[name="sku"]')?.value;

                Object.entries(prices || {}).forEach(([sku, price]) => {
                    if (price === null) {
                        return;
                    }

                    if (sku === ownSku && typeof setPriceField === 'function') {
                        setPriceField('prijs', String(Math.round(price)));
                    }
                });

                window.dispatchEvent(new CustomEvent('competitor-prices-updated', {detail: {prices: prices || {}, competition: competition || null}}));
            }

            async function send(button, url, method, body) {
                const container = panel();
                button.disabled = true;

                try {
                    const response = await fetch(url, {
                        method: method,
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: body ? JSON.stringify(body) : null,
                    });

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(data.message || ('Fout ' + response.status));
                    }

                    container.innerHTML = data.html;
                    applyPrices(data.prices, data.competition);
                } catch (error) {
                    button.disabled = false;

                    const notice = document.createElement('div');
                    notice.className = 'ca-error';
                    notice.setAttribute('role', 'alert');
                    notice.textContent = 'Dat lukte niet: ' + error.message;
                    container.prepend(notice);
                }
            }

            document.addEventListener('click', function (event) {
                const container = panel();

                if (!container || !container.contains(event.target)) {
                    return;
                }

                const toggle = event.target.closest('[data-ca-exclude-toggle]');

                if (toggle) {
                    const row = toggle.closest('tr');
                    const formRow = row.classList.contains('ca-exclude-row') ? row : row.nextElementSibling;

                    formRow.hidden = !formRow.hidden;

                    if (!formRow.hidden) {
                        formRow.querySelector('[data-ca-reason]')?.focus();
                    }

                    return;
                }

                const exclude = event.target.closest('[data-ca-exclude]');

                if (exclude) {
                    const reason = exclude.closest('tr').querySelector('[data-ca-reason]')?.value ?? '';
                    send(exclude, exclude.dataset.caExclude, 'POST', {reason: reason.trim() || null});

                    return;
                }

                const include = event.target.closest('[data-ca-include]');

                if (include) {
                    send(include, include.dataset.caInclude, 'DELETE');
                }
            });

            /* Enter in het redenveld mag het productformulier niet opslaan. */
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && event.target.matches('#competitor-analysis [data-ca-reason]')) {
                    event.preventDefault();
                    event.target.closest('tr').querySelector('[data-ca-exclude]')?.click();
                }
            });
        })();
    </script>
@endPushOnce
