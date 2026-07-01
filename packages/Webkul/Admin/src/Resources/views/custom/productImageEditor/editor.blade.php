@php
    use Illuminate\Support\Facades\Storage;

    $cfg = config('product_image_editor');

    $iconPath = core()->getConfigData('image_editor.settings.hw_icon');

    $masksBase = '/'.trim($cfg['masks_path'], '/');

    $shapes = [];
    foreach ($cfg['shapes'] as $shapeKey => $shape) {
        $hasMask = is_file(public_path(trim($cfg['masks_path'], '/').'/'.$shapeKey.'.png'));
        $shapes[$shapeKey] = [
            'label'   => $shape['label'],
            'rect'    => $shape['rect'],
            'maskUrl' => $hasMask ? $masksBase.'/'.$shapeKey.'.png' : null,
        ];
    }

    $productValues = is_string($product->values) ? json_decode($product->values, true) : ($product->values ?? []);
    $productValues = is_array($productValues) ? $productValues : [];
    $productVorm = trim((string) ($productValues['common']['vorm'] ?? ''));

    // Resolve the current primary-image asset id straight from product values
    // (authoritative; independent of the DAM Vue field's DOM).
    $findAttr = function (array $values, string $code) use (&$findAttr): ?string {
        foreach ($values as $key => $value) {
            if ($key === $code && $value !== null && $value !== '' && $value !== []) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }

            if (is_array($value)) {
                $found = $findAttr($value, $code);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    };

    $currentAsset = $findAttr($productValues, $cfg['primary_attribute']);
    $currentAssetId = $currentAsset ? (int) trim(explode(',', $currentAsset)[0]) : null;

    $productShape = null;
    if ($productVorm !== '') {
        foreach ($cfg['shapes'] as $shapeKey => $shape) {
            foreach (array_merge([$shape['label']], $shape['aliases'] ?? []) as $shapeName) {
                if (mb_strtolower($shapeName) === mb_strtolower($productVorm)) {
                    $productShape = $shapeKey;
                    break 2;
                }
            }
        }
    }

    $editorConfig = [
        'primaryField'   => $cfg['primary_attribute'],
        'iconUrl'        => $iconPath ? Storage::url($iconPath) : '',
        'sourceBase'     => '/'.trim(config('app.admin_url'), '/').'/product-image-editor/source',
        'output'         => $cfg['output'],
        'rugRect'        => $cfg['rug_rect'],
        'icon'           => $cfg['icon'],
        'shapes'         => $shapes,
        'defaultShape'   => $cfg['default_shape'],
        'productShape'   => $productShape,
        'currentAssetId' => $currentAssetId,
        'masksBase'      => $masksBase,
        'outline'        => $cfg['outline'],
        'saved'          => $product->additional['primary_image_editor'] ?? null,
    ];
@endphp

<div class="relative p-4 bg-white dark:bg-cherry-900 rounded box-shadow mt-4" id="hw-image-editor">
    <script type="application/json" id="hw-image-editor-config">@json($editorConfig)</script>

    <div class="flex items-center justify-between">
        <div>
            <p class="text-base text-gray-800 dark:text-white font-semibold">Hoofdafbeelding bewerken</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Plaats de afbeelding in het witte kader, voeg het HW-icoon toe en schaal naar 917×1094.
                De bewerking wordt toegepast bij het opslaan.
            </p>
        </div>

        <button
            type="button"
            id="hw-image-editor-open"
            class="px-3 py-1.5 text-xs font-medium rounded border border-blue-200 text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900"
        >
            Bewerk hoofdafbeelding
        </button>
    </div>

    <p id="hw-image-editor-status" class="hidden mt-3 p-2 text-xs rounded bg-green-50 text-green-800 dark:bg-green-900 dark:text-green-100 border border-green-200 dark:border-green-700"></p>

    <!-- Modal -->
    <div id="hw-image-editor-modal" style="display:none; position:fixed; inset:0; z-index:99999; align-items:center; justify-content:center; background:rgba(17,24,39,0.55); padding:16px;">
        <div class="bg-white dark:bg-cherry-900" style="border-radius:12px; box-shadow:0 20px 50px rgba(0,0,0,0.35); width:100%; max-width:840px; max-height:92vh; display:flex; flex-direction:column; overflow:hidden;">
            <!-- Header -->
            <div style="flex-shrink:0; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:18px 22px; border-bottom:1px solid #eef0f2;">
                <div>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">Hoofdafbeelding bewerken</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400" style="margin-top:3px;">Sleep en schaal de afbeelding binnen de vorm. De bewerking wordt toegepast bij het opslaan.</p>
                </div>
                <button type="button" id="hw-image-editor-close" class="icon-cross text-2xl text-gray-400 hover:text-gray-700 dark:hover:text-white" style="line-height:1; flex-shrink:0;"></button>
            </div>

            <!-- Body -->
            <div style="flex:1 1 auto; min-height:0; overflow:auto; padding:22px;">
                <div class="flex flex-col md:flex-row gap-6">
                    <div class="flex-shrink-0">
                        <div style="background:#f3f4f6; border:1px solid #eef0f2; border-radius:10px; padding:16px; display:flex; justify-content:center;">
                            <div
                                id="hw-image-editor-stage"
                                style="position:relative; background:#fff; border:1px solid #e5e7eb; box-shadow:0 1px 3px rgba(0,0,0,0.08); cursor:grab; user-select:none; touch-action:none;"
                            >
                                <div id="hw-image-editor-outline" style="position:absolute; inset:0; pointer-events:none; display:none;"></div>
                                <div id="hw-image-editor-maskwrap" style="position:absolute; inset:0; pointer-events:none;">
                                    <div id="hw-image-editor-clip" style="position:absolute; overflow:hidden;">
                                        <img id="hw-image-editor-rug" alt="" draggable="false" style="position:absolute; top:0; left:0; will-change:transform;" />
                                    </div>
                                </div>
                                <div id="hw-image-editor-guide" style="position:absolute; border:1px dashed #9ca3af; pointer-events:none;"></div>
                                <img id="hw-image-editor-icon" alt="" draggable="false" style="position:absolute; pointer-events:none;" />
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mt-2 text-center">Sleep om te verplaatsen · scroll of schuif om te schalen</p>
                    </div>

                    <div class="flex-1 space-y-5" style="min-width:230px;">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1" for="hw-shape">Vorm</label>
                            <select id="hw-shape" class="w-full text-sm rounded border border-gray-300 dark:border-gray-600 dark:bg-cherry-800 dark:text-gray-200" style="padding:8px 10px;">
                                @foreach ($shapes as $shapeKey => $shape)
                                    <option value="{{ $shapeKey }}">{{ $shape['label'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div style="border:1px solid #eef0f2; border-radius:10px; padding:14px 16px;">
                            <p class="text-gray-500 dark:text-gray-400" style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:10px;">Bewerkingen</p>
                            <div class="space-y-3">
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200" style="cursor:pointer;">
                                    <input type="checkbox" id="hw-toggle-resize" checked style="accent-color:#7c3aed; width:15px; height:15px;" /> Formaat 917×1094
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200" style="cursor:pointer;">
                                    <input type="checkbox" id="hw-toggle-padding" checked style="accent-color:#7c3aed; width:15px; height:15px;" /> In wit kader plaatsen
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200" style="cursor:pointer;">
                                    <input type="checkbox" id="hw-toggle-icon" checked style="accent-color:#7c3aed; width:15px; height:15px;" /> HW-icoon toevoegen
                                </label>
                                <label id="hw-toggle-outline-wrap" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200" style="cursor:pointer;">
                                    <input type="checkbox" id="hw-toggle-outline" checked style="accent-color:#7c3aed; width:15px; height:15px;" /> Rand om vorm
                                </label>
                            </div>
                        </div>

                        <div id="hw-scale-wrap">
                            <label class="flex items-center justify-between text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                                <span>Schaal</span><span id="hw-scale-label" class="text-gray-500 dark:text-gray-400">1.00×</span>
                            </label>
                            <input type="range" id="hw-scale" min="0.5" max="3" step="0.01" value="1" class="w-full" style="accent-color:#7c3aed;" />
                        </div>

                        <button type="button" id="hw-image-editor-reset" class="text-xs text-blue-600 hover:underline">Herstel naar standaard</button>

                        <p id="hw-image-editor-warning" class="hidden text-xs text-red-600" style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:8px 10px;"></p>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div style="flex-shrink:0; display:flex; justify-content:flex-end; gap:12px; padding:16px 22px; border-top:1px solid #eef0f2;">
                <button type="button" id="hw-image-editor-cancel" class="text-sm text-gray-700 dark:text-gray-200" style="padding:9px 18px; border:1px solid #d1d5db; border-radius:8px; background:transparent;">Annuleren</button>
                <button type="button" id="hw-image-editor-apply" class="text-sm text-white" style="padding:9px 20px; border-radius:8px; background:#2563eb; box-shadow:0 1px 2px rgba(37,99,235,0.4);">Toepassen</button>
            </div>
        </div>
    </div>
</div>

@verbatim
<script>
(function () {
    var root = document.getElementById('hw-image-editor');
    if (!root || root.dataset.booted) { return; }
    root.dataset.booted = '1';

    var cfgEl = document.getElementById('hw-image-editor-config');
    var cfg = JSON.parse(cfgEl.textContent);
    cfgEl.remove();
    var DISPLAY_WIDTH = 380;
    var displayScale = DISPLAY_WIDTH / cfg.output.width;

    // The admin mounts a Vue app on #app with the runtime compiler, which
    // recompiles the server-rendered DOM (dropping <script> tags and replacing
    // nodes). Move the modal out to <body> before that happens so its listeners
    // survive, and bind the trigger via event delegation on document.
    var modalEl = document.getElementById('hw-image-editor-modal');
    if (modalEl && modalEl.parentNode !== document.body) {
        document.body.appendChild(modalEl);
    }

    var el = {
        open:    document.getElementById('hw-image-editor-open'),
        modal:   document.getElementById('hw-image-editor-modal'),
        close:   document.getElementById('hw-image-editor-close'),
        cancel:  document.getElementById('hw-image-editor-cancel'),
        apply:   document.getElementById('hw-image-editor-apply'),
        reset:   document.getElementById('hw-image-editor-reset'),
        stage:   document.getElementById('hw-image-editor-stage'),
        maskwrap: document.getElementById('hw-image-editor-maskwrap'),
        clip:    document.getElementById('hw-image-editor-clip'),
        rug:     document.getElementById('hw-image-editor-rug'),
        guide:   document.getElementById('hw-image-editor-guide'),
        icon:    document.getElementById('hw-image-editor-icon'),
        status:  document.getElementById('hw-image-editor-status'),
        warning: document.getElementById('hw-image-editor-warning'),
        scale:   document.getElementById('hw-scale'),
        scaleLbl:document.getElementById('hw-scale-label'),
        scaleWrap: document.getElementById('hw-scale-wrap'),
        tResize: document.getElementById('hw-toggle-resize'),
        tPadding:document.getElementById('hw-toggle-padding'),
        tIcon:   document.getElementById('hw-toggle-icon'),
        tOutline:document.getElementById('hw-toggle-outline'),
        outlineWrap: document.getElementById('hw-toggle-outline-wrap'),
        outlineLayer: document.getElementById('hw-image-editor-outline'),
        shape:   document.getElementById('hw-shape'),
    };

    var state = defaults();
    var natural = { w: 0, h: 0 };
    var iconAspect = null;

    function defaultShape() {
        return cfg.productShape || cfg.defaultShape || 'rechthoek';
    }

    function defaults() {
        return { sourceAssetId: null, shape: defaultShape(), scale: 1, offsetX: 0, offsetY: 0, resize: true, padding: true, icon: true, outline: cfg.outline.enabled !== false };
    }

    function activeShape() {
        return cfg.shapes[state.shape] || null;
    }

    function activeRect() {
        var shape = activeShape();
        return shape ? shape.rect : cfg.rugRect;
    }

    function activeMaskUrl() {
        var shape = activeShape();
        return (state.padding && shape && shape.maskUrl) ? shape.maskUrl : null;
    }

    // Asset field inputs are named like `values[common][afbeelding][0]`, so match
    // the attribute code wrapped in brackets (robust to channel/locale wrappers).
    var FIELD_SELECTOR = '[name*="[' + cfg.primaryField + ']["]';

    function currentAssetId() {
        var inputs = Array.prototype.slice.call(
            document.querySelectorAll(FIELD_SELECTOR)
        ).filter(function (i) { return i.value; });
        return inputs.length ? inputs[0].value : null;
    }

    function getForm() {
        var inp = document.querySelector(FIELD_SELECTOR);
        return inp ? inp.closest('form') : document.querySelector('form');
    }

    function setToggles() {
        el.tResize.checked = state.resize;
        el.tPadding.checked = state.padding;
        el.tIcon.checked = state.icon;
        el.tOutline.checked = state.outline;
        el.shape.value = state.shape;
        el.scale.value = state.scale;
        el.scaleLbl.textContent = Number(state.scale).toFixed(2) + '×';
    }

    function openModal() {
        el.warning.classList.add('hidden');
        var current = currentAssetId() || (cfg.currentAssetId ? String(cfg.currentAssetId) : null);
        if (!current) {
            el.warning.textContent = 'Geen hoofdafbeelding gevonden bij "' + cfg.primaryField + '". Selecteer of upload eerst een afbeelding en sla op.';
            el.warning.classList.remove('hidden');
            showModal();
            return;
        }

        if (cfg.saved && String(cfg.saved.edited_asset_id) === String(current)) {
            state = {
                sourceAssetId: cfg.saved.source_asset_id,
                shape: cfg.shapes[cfg.saved.shape] ? cfg.saved.shape : defaultShape(),
                scale: Number(cfg.saved.scale) || 1,
                offsetX: Number(cfg.saved.offset_x) || 0,
                offsetY: Number(cfg.saved.offset_y) || 0,
                resize: cfg.saved.resize !== false,
                padding: cfg.saved.padding !== false,
                icon: cfg.saved.icon !== false,
                outline: cfg.saved.outline !== false,
            };
        } else {
            state = defaults();
            state.sourceAssetId = current;
        }

        setToggles();
        loadSource(state.sourceAssetId);
        showModal();
    }

    function showModal() {
        el.modal.style.display = 'flex';
    }

    function hideModal() {
        el.modal.style.display = 'none';
    }

    function loadSource(assetId) {
        fetch(cfg.sourceBase + '/' + assetId, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (data) {
                natural.w = data.width;
                natural.h = data.height;
                el.rug.onload = render;
                el.rug.src = data.url;
                if (cfg.iconUrl) {
                    el.icon.onload = function () {
                        iconAspect = el.icon.naturalHeight / el.icon.naturalWidth;
                        render();
                    };
                    el.icon.src = cfg.iconUrl;
                }
                render();
            })
            .catch(function () {
                el.warning.textContent = 'Kon de bronafbeelding niet laden.';
                el.warning.classList.remove('hidden');
            });
    }

    function render() {
        var dW = cfg.output.width * displayScale;
        var dH = cfg.output.height * displayScale;
        el.stage.style.width = dW + 'px';
        el.stage.style.height = dH + 'px';

        var rect = activeRect();
        var maskUrl = activeMaskUrl();
        el.scaleWrap.style.display = state.padding ? '' : 'none';
        el.outlineWrap.style.display = maskUrl ? '' : 'none';

        if (state.padding) {
            var cover = Math.max(rect.width / natural.w, rect.height / natural.h);
            var drawScale = cover * Math.max(state.scale, 0.01);
            var dw = natural.w * drawScale;
            var dh = natural.h * drawScale;
            var px = rect.x + rect.width / 2 + state.offsetX - dw / 2;
            var py = rect.y + rect.height / 2 + state.offsetY - dh / 2;

            if (maskUrl) {
                // Masked shape: full-frame clip + CSS silhouette mask. The outline
                // is drawn with stacked drop-shadows that follow the masked rug's
                // alpha silhouette (self-aligning, unlike scaling the mask).
                el.guide.style.display = 'none';
                el.outlineLayer.style.display = 'none';
                el.clip.style.overflow = 'visible';
                placeClip(0, 0, cfg.output.width, cfg.output.height);
                applyMask(el.clip, maskUrl, '100% 100%', '0 0');
                // Outline goes on the wrapper (parent of the masked clip) so the
                // drop-shadows follow the silhouette instead of being clipped by it.
                el.maskwrap.style.filter = (state.outline && cfg.outline.width > 0)
                    ? outlineFilter(cfg.outline.width, cfg.outline.color)
                    : '';

                sizeRug(dw, dh, px, py);
            } else {
                // Rectangular shape (rechthoek): overflow-clipped rect.
                el.outlineLayer.style.display = 'none';
                clearMask(el.clip);
                el.maskwrap.style.filter = '';
                el.clip.style.overflow = 'hidden';
                placeClip(rect.x, rect.y, rect.width, rect.height);
                el.guide.style.display = '';
                el.guide.style.left = rect.x * displayScale + 'px';
                el.guide.style.top = rect.y * displayScale + 'px';
                el.guide.style.width = rect.width * displayScale + 'px';
                el.guide.style.height = rect.height * displayScale + 'px';
                sizeRug(dw, dh, px - rect.x, py - rect.y);
            }
        } else {
            el.guide.style.display = 'none';
            el.outlineLayer.style.display = 'none';
            clearMask(el.clip);
            el.maskwrap.style.filter = '';
            el.clip.style.overflow = 'hidden';
            placeClip(0, 0, cfg.output.width, cfg.output.height);
            var fit = Math.min(cfg.output.width / natural.w, cfg.output.height / natural.h, state.resize ? Infinity : 1);
            var fw = natural.w * fit, fh = natural.h * fit;
            sizeRug(fw, fh, (cfg.output.width - fw) / 2, (cfg.output.height - fh) / 2);
        }

        renderIcon(dH);
    }

    function applyMask(elm, url, size, pos) {
        var u = 'url("' + url + '")';
        elm.style.webkitMaskImage = u;
        elm.style.maskImage = u;
        elm.style.webkitMaskRepeat = 'no-repeat';
        elm.style.maskRepeat = 'no-repeat';
        elm.style.webkitMaskSize = size;
        elm.style.maskSize = size;
        elm.style.webkitMaskPosition = pos;
        elm.style.maskPosition = pos;
    }

    function clearMask(elm) {
        elm.style.webkitMaskImage = '';
        elm.style.maskImage = '';
    }

    // Build an outline by stacking zero-blur drop-shadows around a circle; each
    // follows the masked element's alpha edge, so together they form a ring.
    function outlineFilter(widthPx, color) {
        var w = Math.max(1, widthPx * displayScale);
        var parts = [];
        for (var a = 0; a < 360; a += 45) {
            var r = a * Math.PI / 180;
            parts.push('drop-shadow(' + (Math.cos(r) * w).toFixed(2) + 'px ' + (Math.sin(r) * w).toFixed(2) + 'px 0 ' + color + ')');
        }
        return parts.join(' ');
    }

    function placeClip(x, y, w, h) {
        el.clip.style.left = x * displayScale + 'px';
        el.clip.style.top = y * displayScale + 'px';
        el.clip.style.width = w * displayScale + 'px';
        el.clip.style.height = h * displayScale + 'px';
    }

    function sizeRug(dw, dh, offX, offY) {
        el.rug.style.width = dw * displayScale + 'px';
        el.rug.style.height = dh * displayScale + 'px';
        el.rug.style.left = offX * displayScale + 'px';
        el.rug.style.top = offY * displayScale + 'px';
    }

    function renderIcon(stageHeight) {
        if (!cfg.iconUrl || !state.icon) {
            el.icon.style.display = 'none';
            return;
        }
        el.icon.style.display = '';
        var iconW = cfg.icon.width * displayScale;
        var iconH = iconW * (iconAspect || 1);
        el.icon.style.width = iconW + 'px';
        el.icon.style.height = iconH + 'px';
        el.icon.style.left = cfg.icon.margin * displayScale + 'px';
        el.icon.style.top = (stageHeight - cfg.icon.margin * displayScale - iconH) + 'px';
    }

    // Dragging
    var dragging = false, startX = 0, startY = 0, startOX = 0, startOY = 0;
    el.stage.addEventListener('pointerdown', function (e) {
        if (!state.padding) { return; }
        dragging = true;
        startX = e.clientX; startY = e.clientY;
        startOX = state.offsetX; startOY = state.offsetY;
        el.stage.style.cursor = 'grabbing';
        el.stage.setPointerCapture(e.pointerId);
    });
    el.stage.addEventListener('pointermove', function (e) {
        if (!dragging) { return; }
        state.offsetX = startOX + (e.clientX - startX) / displayScale;
        state.offsetY = startOY + (e.clientY - startY) / displayScale;
        render();
    });
    function endDrag() { dragging = false; el.stage.style.cursor = 'grab'; }
    el.stage.addEventListener('pointerup', endDrag);
    el.stage.addEventListener('pointercancel', endDrag);

    el.stage.addEventListener('wheel', function (e) {
        if (!state.padding) { return; }
        e.preventDefault();
        var next = state.scale * (e.deltaY < 0 ? 1.05 : 0.95);
        state.scale = Math.min(3, Math.max(0.5, next));
        setScale();
    }, { passive: false });

    function setScale() {
        el.scale.value = state.scale;
        el.scaleLbl.textContent = Number(state.scale).toFixed(2) + '×';
        render();
    }

    el.scale.addEventListener('input', function () {
        state.scale = Number(el.scale.value);
        el.scaleLbl.textContent = state.scale.toFixed(2) + '×';
        render();
    });

    el.tResize.addEventListener('change', function () { state.resize = el.tResize.checked; render(); });
    el.tPadding.addEventListener('change', function () { state.padding = el.tPadding.checked; render(); });
    el.tIcon.addEventListener('change', function () { state.icon = el.tIcon.checked; render(); });
    el.tOutline.addEventListener('change', function () { state.outline = el.tOutline.checked; render(); });
    el.shape.addEventListener('change', function () {
        state.shape = el.shape.value;
        // Re-center when switching shapes so the rug fits the new rectangle.
        state.offsetX = 0;
        state.offsetY = 0;
        state.scale = 1;
        el.scale.value = 1;
        el.scaleLbl.textContent = '1.00×';
        render();
    });

    el.reset.addEventListener('click', function () {
        var src = state.sourceAssetId;
        var shape = state.shape;
        state = defaults();
        state.sourceAssetId = src;
        state.shape = shape;
        setToggles();
        render();
    });

    // The trigger button lives inside #app and is re-created by Vue, so bind it
    // via delegation on document (which survives Vue re-renders).
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('#hw-image-editor-open')) {
            openModal();
        }
    });
    el.close.addEventListener('click', hideModal);
    el.cancel.addEventListener('click', hideModal);

    var pendingEdit = null;
    var submitHooked = false;

    function writeEditFields(form) {
        if (!form || !pendingEdit) { return; }

        var container = form.querySelector('#__image_edit_fields');
        if (!container) {
            container = document.createElement('div');
            container.id = '__image_edit_fields';
            form.appendChild(container);
        }

        container.innerHTML = '';
        Object.keys(pendingEdit).forEach(function (key) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = '__image_edit[' + key + ']';
            input.value = pendingEdit[key];
            container.appendChild(input);
        });
    }

    el.apply.addEventListener('click', function () {
        if (!state.sourceAssetId) { hideModal(); return; }
        var form = getForm();
        if (!form) { hideModal(); return; }

        pendingEdit = {
            source_asset_id: state.sourceAssetId,
            shape: state.shape,
            scale: Number(state.scale).toFixed(4),
            offset_x: Math.round(state.offsetX),
            offset_y: Math.round(state.offsetY),
            resize: state.resize ? '1' : '0',
            padding: state.padding ? '1' : '0',
            icon: state.icon ? '1' : '0',
            outline: state.outline ? '1' : '0',
        };

        writeEditFields(form);

        // Re-inject right before submit in case Vue re-rendered the form and
        // dropped the appended inputs.
        if (!submitHooked) {
            form.addEventListener('submit', function () { writeEditFields(getForm()); }, true);
            submitHooked = true;
        }

        var status = document.getElementById('hw-image-editor-status');
        if (status) {
            status.textContent = 'Bewerking ingesteld. Wordt toegepast zodra je het product opslaat.';
            status.classList.remove('hidden');
        }
        hideModal();
    });
})();
</script>
@endverbatim
