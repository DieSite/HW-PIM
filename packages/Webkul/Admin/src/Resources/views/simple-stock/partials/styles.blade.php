@push('styles')
        <style>
            .sl { --sl-accent: #ff6700; --sl-text: #1f2937; --sl-muted: #6b7280; --sl-line: #e5e7eb; --sl-bg: #f3f4f6; --sl-ok: #15803d; --sl-ok-bg: #dcfce7; --sl-sale-bg: #fef3c7; --sl-sale: #92400e; min-height: 100vh; background: var(--sl-bg); color: var(--sl-text); font-family: Inter, system-ui, sans-serif; }
            .sl a { color: inherit; }
            .sl-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 20px; background: #fff; border-bottom: 1px solid var(--sl-line); position: sticky; top: 0; z-index: 10; }
            .sl-bar h1 { font-size: 18px; font-weight: 700; margin: 0; }
            .sl-bar nav { display: flex; gap: 16px; align-items: center; font-size: 14px; color: var(--sl-muted); }
            .sl-bar nav a, .sl-bar nav button { color: var(--sl-muted); background: none; border: 0; cursor: pointer; font: inherit; text-decoration: underline; padding: 0; }
            .sl-main { max-width: 1280px; margin: 0 auto; padding: 20px 16px 48px; }
            .sl-search { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
            .sl-search input[type=search] { flex: 1 1 320px; font-size: 20px; padding: 14px 16px; border: 2px solid var(--sl-line); border-radius: 10px; background: #fff; outline: none; }
            .sl-search input[type=search]:focus { border-color: var(--sl-accent); }
            .sl-search button { font-size: 18px; font-weight: 600; padding: 0 24px; border: 0; border-radius: 10px; background: var(--sl-accent); color: #fff; cursor: pointer; }
            .sl-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
            .sl-filters label { cursor: pointer; }
            .sl-filters input { position: absolute; opacity: 0; pointer-events: none; }
            .sl-filters span { display: inline-block; padding: 8px 14px; border-radius: 999px; background: #fff; border: 1px solid var(--sl-line); font-size: 15px; }
            .sl-filters input:checked + span { background: var(--sl-text); border-color: var(--sl-text); color: #fff; }
            .sl-filters input:focus-visible + span { outline: 2px solid var(--sl-accent); outline-offset: 2px; }
            .sl-note { color: var(--sl-muted); font-size: 15px; margin: 8px 0 16px; }
            .sl-card { background: #fff; border-radius: 12px; border: 1px solid var(--sl-line); margin-bottom: 16px; overflow: hidden; }
            .sl-head { display: flex; gap: 16px; padding: 16px; align-items: flex-start; }
            .sl-thumb { width: 88px; height: 88px; border-radius: 8px; object-fit: cover; background: var(--sl-bg); flex: none; }
            .sl-title { font-size: 20px; font-weight: 700; margin: 0 0 4px; }
            .sl-meta { color: var(--sl-muted); font-size: 14px; }
            .sl-links { display: flex; gap: 14px; margin-top: 8px; font-size: 14px; }
            .sl-links a { color: var(--sl-accent); font-weight: 600; text-decoration: none; }
            .sl-badge { display: inline-block; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: #fee2e2; color: #991b1b; margin-left: 6px; vertical-align: middle; }
            .sl-table-wrap { overflow-x: auto; border-top: 1px solid var(--sl-line); }
            .sl table { width: 100%; border-collapse: collapse; font-size: 16px; }
            .sl th { text-align: left; font-size: 13px; font-weight: 600; color: var(--sl-muted); text-transform: uppercase; letter-spacing: .03em; padding: 10px 16px; background: #fafafa; white-space: nowrap; }
            .sl td { padding: 10px 16px; border-top: 1px solid var(--sl-line); white-space: nowrap; }
            .sl td.num, .sl th.num { text-align: center; }
            .sl td.price { font-variant-numeric: tabular-nums; }
            .sl-stock { display: inline-block; min-width: 32px; padding: 2px 10px; border-radius: 999px; font-weight: 700; background: var(--sl-ok-bg); color: var(--sl-ok); }
            .sl-stock.sale { background: var(--sl-sale-bg); color: var(--sl-sale); }
            .sl-none { color: #c4c7cc; }
            .sl-custom td { color: var(--sl-muted); font-style: italic; }
            .sl-empty { text-align: center; padding: 48px 16px; color: var(--sl-muted); font-size: 17px; }
            .sl-title a { text-decoration: none; }
            .sl-title a:hover { text-decoration: underline; }
            .sl-back { display: inline-block; margin-bottom: 14px; font-size: 15px; color: var(--sl-muted) !important; }
            .sl-detail { display: grid; grid-template-columns: minmax(0, 420px) minmax(0, 1fr); gap: 20px; padding: 16px; }
            .sl-gallery-main { width: 100%; aspect-ratio: 1; object-fit: contain; background: var(--sl-bg); border-radius: 10px; }
            .sl-gallery-thumbs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
            .sl-gallery-thumbs button { padding: 0; border: 2px solid transparent; border-radius: 6px; background: none; cursor: pointer; }
            .sl-gallery-thumbs button[aria-current=true] { border-color: var(--sl-accent); }
            .sl-gallery-thumbs img { width: 64px; height: 64px; object-fit: cover; border-radius: 4px; display: block; }
            .sl-specs { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; font-size: 15px; }
            .sl-specs dt { color: var(--sl-muted); }
            .sl-specs dd { margin: 0; }
            .sl-section { padding: 16px; border-top: 1px solid var(--sl-line); }
            .sl-section h3 { font-size: 16px; font-weight: 700; margin: 0 0 10px; }
            .sl-section p { margin: 0 0 10px; line-height: 1.55; max-width: 75ch; }
            .sl-old { color: var(--sl-muted); text-decoration: line-through; font-size: 14px; margin-left: 4px; }
            .sl-small { color: var(--sl-muted); font-size: 13px; }
            .sl-related { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; }
            .sl-related a { text-decoration: none; font-size: 14px; overflow-wrap: anywhere; }
            .sl-related img, .sl-related .sl-thumb { width: 100%; height: auto; aspect-ratio: 1; margin-bottom: 4px; }
            .sl-related a[aria-current=true] { font-weight: 700; }
            .sl-analysis { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 440px), 1fr)); gap: 14px; }
            .sl-analysis-block { border: 1px solid var(--sl-line); border-radius: 10px; overflow-x: auto; }
            .sl-analysis-block header { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; padding: 10px 12px; background: #fafafa; }
            .sl-analysis-block header strong { font-size: 15px; }
            .sl-analysis table { font-size: 14px; }
            .sl-analysis td { padding: 7px 12px; }
            .sl-analysis td.rank { color: var(--sl-muted); width: 1%; }
            .sl-analysis tr.own td { background: #fff4ec; font-weight: 700; }
            .sl-analysis tr.stale td { color: #9ca3af; }
            .sl-analysis tr.excluded td:not(:last-child) { text-decoration: line-through; text-decoration-thickness: 2px; text-decoration-color: #dc2626; }
            .sl-analysis tr.excluded td:first-child { box-shadow: inset 3px 0 0 #dc2626; }
            .sl-analysis tr.stale .sl-cheaper, .sl-analysis tr.stale .sl-pricier { color: inherit; }
            .sl-excluded-label { display: inline-block; padding: 1px 8px; border-radius: 999px; background: #fee2e2; color: #991b1b; font-weight: 600; font-style: normal; }
            .sl-analysis a { color: inherit; }
            .sl-cheaper { color: #b91c1c; }
            .sl-pricier { color: var(--sl-ok); }
            @media (max-width: 800px) { .sl-detail { grid-template-columns: 1fr; } }
            @media (max-width: 640px) {
                .sl-bar { padding: 10px 16px; }
                .sl-thumb { width: 64px; height: 64px; }
                .sl-title { font-size: 18px; }
                .sl th, .sl td { padding: 8px 10px; }
            }
        </style>
@endpush
