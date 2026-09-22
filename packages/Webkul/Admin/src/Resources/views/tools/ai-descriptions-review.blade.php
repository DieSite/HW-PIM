<x-admin::layouts>
    <x-slot:title>AI-teksten beoordelen</x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">AI-teksten beoordelen</p>
                @if ($run)
                    <p class="text-sm text-gray-500 dark:text-slate-300">
                        Reeks #{{ $run->id }} — {{ $run->status }}, {{ $run->generated_count }} van {{ $run->matched_count }} geschreven
                        @if ($run->failed_count) , {{ $run->failed_count }} mislukt @endif
                    </p>
                @endif
            </div>

            <a href="{{ route('admin.tools.ai-descriptions.index') }}" class="secondary-button">Nieuwe reeks</a>
        </div>

        <x-admin::flash-group />

        @php
            $statusLabels = ['pending' => 'Te beoordelen', 'approved' => 'Goedgekeurd', 'rejected' => 'Afgekeurd', 'applied' => 'Gepubliceerd', 'failed' => 'Mislukt', 'regenerating' => 'Wordt herschreven', 'publishing' => 'Wordt gepubliceerd'];
            $regeneratingCount = $counts['regenerating'] ?? 0;
            $publishingCount = $counts['publishing'] ?? 0;
            $processingCount = $regeneratingCount + $publishingCount;
            $counts['processing'] = $processingCount;
        @endphp

        @if ($processingCount > 0)
            <div class="rounded-lg border border-violet-200 bg-violet-50 dark:bg-cherry-800 dark:border-gray-800 p-4 text-sm text-gray-700 dark:text-slate-50" data-processing-banner>
                <span class="font-semibold">Bezig met {{ $processingCount }} {{ $processingCount === 1 ? 'tekst' : 'teksten' }}:</span>
                {{ $regeneratingCount }} {{ $regeneratingCount === 1 ? 'wordt' : 'worden' }} opnieuw geschreven, {{ $publishingCount }} {{ $publishingCount === 1 ? 'wordt' : 'worden' }} gepubliceerd.
                Deze pagina ververst elke 15 seconden.
                <a href="{{ route('admin.tools.ai-descriptions.review', array_filter(['run' => $run?->id, 'status' => 'processing'])) }}" class="text-violet-600 hover:underline">Bekijken</a>
            </div>
        @endif

        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-4 flex flex-wrap items-center gap-3">
            @foreach (['pending' => 'Te beoordelen', 'approved' => 'Goedgekeurd', 'rejected' => 'Afgekeurd', 'applied' => 'Gepubliceerd', 'failed' => 'Mislukt', 'processing' => 'Bezig', 'all' => 'Alles'] as $key => $label)
                <a
                    href="{{ route('admin.tools.ai-descriptions.review', array_filter(['run' => $run?->id, 'status' => $key])) }}"
                    class="text-sm px-3 py-1.5 rounded-md {{ $status === $key ? 'bg-violet-400 text-white' : 'bg-gray-100 dark:bg-cherry-900 text-gray-700 dark:text-slate-50' }}"
                >
                    {{ $label }}@if (isset($counts[$key])) ({{ $counts[$key] }}) @endif
                </a>
            @endforeach

            <div class="flex-1"></div>

            @if (! in_array($status, ['approved', 'applied', 'processing'], true))
                <form
                    action="{{ route('admin.tools.ai-descriptions.regenerate-all') }}"
                    method="POST"
                    onsubmit="return confirm('Alle {{ $rewritableCount }} teksten in deze weergave opnieuw laten schrijven? Dit kost een AI-aanroep per product. Goedgekeurde en gepubliceerde teksten blijven ongemoeid.');"
                >
                    @csrf
                    @if ($run)
                        <input type="hidden" name="run" value="{{ $run->id }}">
                    @endif
                    <input type="hidden" name="status" value="{{ $status }}">
                    <button type="submit" class="secondary-button" @disabled($rewritableCount === 0)>
                        Alles opnieuw schrijven ({{ $rewritableCount }})
                    </button>
                </form>
            @endif

            @if (! in_array($status, ['applied', 'processing'], true))
                <form
                    action="{{ route('admin.tools.ai-descriptions.discard-all') }}"
                    method="POST"
                    onsubmit="return confirm('Alle {{ $discardableCount }} concepten in deze weergave weggooien? Dit kan niet ongedaan worden gemaakt. Gepubliceerde teksten blijven staan.');"
                >
                    @csrf
                    @if ($run)
                        <input type="hidden" name="run" value="{{ $run->id }}">
                    @endif
                    <input type="hidden" name="status" value="{{ $status }}">
                    <button type="submit" class="secondary-button" @disabled($discardableCount === 0)>
                        Alle concepten weggooien ({{ $discardableCount }})
                    </button>
                </form>
            @endif

            <form
                action="{{ route('admin.tools.ai-descriptions.apply') }}"
                method="POST"
                onsubmit="return confirm('Alle goedgekeurde teksten publiceren en naar de webshop sturen?');"
            >
                @csrf
                @if ($run)
                    <input type="hidden" name="run" value="{{ $run->id }}">
                @endif
                <input type="hidden" name="sync_woo" value="1">
                <button type="submit" class="primary-button" @disabled(($counts['approved'] ?? 0) === 0)>
                    Goedgekeurde teksten publiceren ({{ $counts['approved'] ?? 0 }})
                </button>
            </form>

            <form
                action="{{ route('admin.tools.ai-descriptions.approve-and-apply-all') }}"
                method="POST"
                onsubmit="return confirm('Alle {{ $publishableCount }} te beoordelen en goedgekeurde teksten{{ $run ? ' van deze reeks' : '' }} goedkeuren, publiceren en naar de webshop sturen?{{ $flaggedCount ? ' Let op: '.$flaggedCount.' daarvan hebben nog opmerkingen van de controle.' : '' }}');"
            >
                @csrf
                @if ($run)
                    <input type="hidden" name="run" value="{{ $run->id }}">
                @endif
                <button type="submit" class="primary-button" @disabled($publishableCount === 0)>
                    Alles goedkeuren en doorsturen ({{ $publishableCount }})
                </button>
            </form>
        </div>

        @forelse ($drafts as $draft)
            @php
                $product = $draft->product;
                $values = $product?->values;
                $values = is_string($values) ? json_decode($values, true) : $values;
                $common = is_array($values) ? ($values['common'] ?? []) : [];
                $fieldConfig = config('ai.fields');
            @endphp

            <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6" id="draft-{{ $draft->id }}" data-draft="{{ $draft->id }}">
                <div class="flex justify-between items-start gap-4 mb-4">
                    <div>
                        <p class="font-bold text-gray-800 dark:text-slate-50">
                            {{ $common['productnaam'] ?? $product?->sku }}
                            <span class="font-normal text-gray-400 text-sm">{{ $common['merk'] ?? '' }} · {{ $common['collectie'] ?? '' }}</span>
                        </p>
                        <p class="font-mono text-xs text-gray-500">
                            {{ $product?->sku }}
                            @if ($product)
                                · <a href="{{ route('admin.catalog.products.edit', ['id' => $product->id]) }}" class="text-violet-600 hover:underline" target="_blank">product openen</a>
                            @endif
                        </p>
                    </div>

                    <div class="text-right text-xs text-gray-400 shrink-0">
                        <p>Gelijkenis {{ round(($draft->similarity ?? 0) * 100) }}%</p>
                        <p>{{ $draft->model }}</p>
                        <p class="font-semibold uppercase tracking-wide">{{ $statusLabels[$draft->status] ?? $draft->status }}</p>
                    </div>
                </div>

                @if ($draft->status === 'failed')
                    <p class="text-sm text-red-600">{{ $draft->error }}</p>
                @else
                    @if ($draft->error)
                        <p class="mb-4 text-sm text-red-600">{{ $draft->error }}</p>
                    @endif

                    @if (! empty($draft->problems))
                        <div class="mb-4 rounded-md bg-orange-50 border border-orange-200 p-3 text-sm text-orange-600">
                            @foreach ($draft->problems as $problem)
                                <p>{{ $problem['message'] }}</p>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($draft->fields ?? [] as $code => $text)
                        <div class="mb-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">
                                {{ $fieldConfig[$code]['label'] ?? $code }}
                            </p>
                            <div class="grid grid-cols-2 gap-4 text-sm" data-compare>
                                <div class="text-gray-500" data-current>
                                    <p class="text-xs mb-1">Nu op de webshop</p>
                                    <div>{!! $common[$code] ?? '<em>leeg</em>' !!}</div>
                                </div>
                                <div class="text-gray-800 dark:text-slate-50">
                                    <p class="text-xs mb-1">Voorstel</p>
                                    <div data-proposal="{{ $code }}">{!! $text !!}</div>
                                    <textarea
                                        id="ai-draft-{{ $draft->id }}-{{ $code }}"
                                        data-proposal-input
                                        data-field="{{ $code }}"
                                        rows="10"
                                        style="display: none;"
                                        class="w-full py-2 px-3 border rounded-md text-sm dark:bg-cherry-800 dark:border-gray-800"
                                    >{{ $text }}</textarea>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif

                <div class="flex items-center gap-2.5 pt-3 border-t dark:border-gray-800">
                    @if ($draft->isInProgress())
                        <span class="text-sm text-gray-500">{{ $statusLabels[$draft->status] }}…</span>
                    @else
                        @if ($draft->status !== 'applied')
                            <button type="button" class="primary-button" data-review-action onclick="aiDraftDecide({{ $draft->id }}, 'approve', this)">Goedkeuren</button>
                            @if (! empty($draft->fields))
                                <button type="button" class="secondary-button" data-review-action onclick="aiDraftEditStart({{ $draft->id }}, this)">Aanpassen</button>
                                <button type="button" class="primary-button" data-edit-action style="display: none;" onclick="aiDraftEditSave({{ $draft->id }}, this)">Opslaan en doorzetten</button>
                                <button type="button" class="transparent-button" data-edit-action style="display: none;" onclick="aiDraftEditCancel(this)">Annuleren</button>
                            @endif
                        @endif
                        <button type="button" class="secondary-button" data-review-action onclick="aiDraftRegenerate({{ $draft->id }}, this)">Opnieuw schrijven</button>
                        @if ($draft->isRevertible())
                            <button type="button" class="transparent-button" data-review-action onclick="aiDraftRevert({{ $draft->id }}, this)">Terugdraaien</button>
                        @endif
                    @endif
                    <span class="text-sm text-gray-500" data-feedback></span>
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-6 text-sm text-gray-500">
                Geen concepten in deze weergave.
            </div>
        @endforelse

        {{ $drafts->links() }}
    </div>

    @pushOnce('scripts')
        {{-- The same TinyMCE build the product edit form loads through x-admin::tinymce. --}}
        <script
            src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.6.2/tinymce.min.js"
            crossorigin="anonymous"
            referrerpolicy="no-referrer"
        ></script>

        <script>
            @if ($processingCount > 0)
                setTimeout(() => window.location.reload(), 15000);
            @endif

            function aiDraftFeedback(button, message) {
                const feedback = button.closest('[data-draft]').querySelector('[data-feedback]');
                feedback.textContent = message;
            }

            /**
             * The button classes set display: flex, which wins over the hidden
             * attribute, so visibility is toggled through the style attribute.
             */
            function aiDraftShow(elements, visible) {
                elements.forEach((element) => { element.style.display = visible ? '' : 'none'; });
            }

            /**
             * While editing, the editor takes the full width of the card: the
             * shop's current text is what the reviewer just read, and TinyMCE's
             * toolbar does not fit in half a column.
             */
            function aiDraftEditing(card, editing) {
                aiDraftShow(card.querySelectorAll('[data-review-action]'), !editing);
                aiDraftShow(card.querySelectorAll('[data-edit-action]'), editing);
                aiDraftShow(card.querySelectorAll('[data-proposal]'), !editing);
                aiDraftShow(card.querySelectorAll('[data-current]'), !editing);

                card.querySelectorAll('[data-compare]').forEach((grid) => {
                    grid.classList.toggle('grid-cols-2', !editing);
                    grid.classList.toggle('grid-cols-1', editing);
                });
            }

            function aiDraftUploadImage(blobInfo, progress) {
                return new Promise((resolve, reject) => {
                    const request = new XMLHttpRequest();

                    request.open('POST', '{{ route('admin.tinymce.upload') }}');
                    request.upload.onprogress = (event) => progress((event.loaded / event.total) * 100);
                    request.onerror = () => reject('Uploaden van de afbeelding is mislukt.');

                    request.onload = () => {
                        if (request.status < 200 || request.status >= 300) {
                            reject('Uploaden van de afbeelding is mislukt.');
                            return;
                        }

                        const json = JSON.parse(request.responseText);

                        if (!json || typeof json.location !== 'string') {
                            reject('Uploaden van de afbeelding gaf een onverwacht antwoord.');
                            return;
                        }

                        resolve(json.location);
                    };

                    const formData = new FormData();
                    formData.append('_token', '{{ csrf_token() }}');
                    formData.append('file', blobInfo.blob(), blobInfo.filename());

                    request.send(formData);
                });
            }

            function aiDraftPickFile(callback) {
                const input = document.createElement('input');

                input.setAttribute('type', 'file');
                input.setAttribute('accept', 'image/*');

                input.onchange = function () {
                    const file = this.files[0];
                    const reader = new FileReader();

                    reader.readAsDataURL(file);

                    reader.onload = () => {
                        const blobCache = tinymce.activeEditor.editorUpload.blobCache;
                        const blobInfo = blobCache.create('blobid' + new Date().getTime(), file, reader.result.split(',')[1]);

                        blobCache.add(blobInfo);

                        callback(blobInfo.blobUri(), { title: file.name });
                    };
                };

                input.click();
            }

            /**
             * Mirrors the configuration x-admin::tinymce uses on the product
             * edit form, so a text is corrected in the editor people know.
             */
            function aiDraftEditorConfig() {
                const dark = document.documentElement.classList.contains('dark');

                return {
                    menubar: false,
                    relative_urls: false,
                    remove_script_host: false,
                    document_base_url: '{{ asset('/') }}',
                    plugins: 'image media wordcount save fullscreen code table lists link',
                    // The admin stylesheet accents the last toolbar group, which on the
                    // product form holds the Magic AI button; here it holds fullscreen.
                    toolbar1: 'formatselect | fontsize bold italic strikethrough forecolor backcolor image alignleft aligncenter alignright alignjustify | link hr numlist bullist outdent indent removeformat code table | fullscreen',
                    image_advtab: true,
                    directionality: 'ltr',
                    skin: dark ? 'oxide-dark' : 'oxide',
                    content_css: dark ? 'dark' : 'default',
                    images_upload_handler: aiDraftUploadImage,
                    file_picker_callback: (callback) => aiDraftPickFile(callback),
                };
            }

            /**
             * TinyMCE copies the display of the element it replaces, so a
             * textarea that is still hidden yields an invisible editor. It is
             * shown first; TinyMCE then hides it again itself.
             */
            function aiDraftAddEditors(card, id) {
                card.querySelectorAll('[data-proposal-input]').forEach((input) => { input.style.display = ''; });

                tinymce.init({
                    ...aiDraftEditorConfig(),
                    selector: '#draft-' + id + ' textarea[data-proposal-input]',
                });
            }

            function aiDraftRemoveEditors(card) {
                card.querySelectorAll('[data-proposal-input]').forEach((input) => {
                    const editor = tinymce.get(input.id);

                    if (editor) {
                        editor.remove();
                    }

                    input.style.display = 'none';
                });
            }

            function aiDraftPost(url, button, body) {
                button.disabled = true;

                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify(body || {}),
                })
                    .then(async (response) => {
                        const json = await response.json();
                        if (!response.ok) {
                            throw new Error(json.message || 'Actie mislukt.');
                        }
                        return json;
                    })
                    .catch((error) => {
                        aiDraftFeedback(button, error.message);
                        throw error;
                    })
                    .finally(() => { button.disabled = false; });
            }

            function aiDraftDecide(id, decision, button) {
                const url = '{{ route('admin.tools.ai-descriptions.decide', ['draft' => '__ID__']) }}'.replace('__ID__', id);

                aiDraftPost(url, button, { decision })
                    .then((json) => aiDraftFeedback(button, json.status === 'approved' ? 'Goedgekeurd.' : 'Afgekeurd.'))
                    .catch(() => {});
            }

            function aiDraftRegenerate(id, button) {
                const url = '{{ route('admin.tools.ai-descriptions.regenerate', ['draft' => '__ID__']) }}'.replace('__ID__', id);

                aiDraftPost(url, button)
                    .then((json) => {
                        aiDraftFeedback(button, json.message);
                        aiDraftShow(button.closest('[data-draft]').querySelectorAll('[data-review-action], [data-edit-action]'), false);
                    })
                    .catch(() => {});
            }

            function aiDraftEditStart(id, button) {
                const card = button.closest('[data-draft]');

                aiDraftEditing(card, true);
                aiDraftAddEditors(card, id);
            }

            function aiDraftEditCancel(button) {
                const card = button.closest('[data-draft]');

                aiDraftRemoveEditors(card);

                card.querySelectorAll('[data-proposal-input]').forEach((input) => { input.value = input.defaultValue; });

                aiDraftEditing(card, false);
            }

            /**
             * Saving is the approval: the texts go straight to publishing.
             */
            function aiDraftEditSave(id, button) {
                const card = button.closest('[data-draft]');
                const fields = {};

                card.querySelectorAll('[data-proposal-input]').forEach((input) => {
                    const editor = tinymce.get(input.id);

                    fields[input.dataset.field] = editor ? editor.getContent() : input.value;
                });

                const url = '{{ route('admin.tools.ai-descriptions.edit', ['draft' => '__ID__']) }}'.replace('__ID__', id);

                aiDraftPost(url, button, { fields })
                    .then((json) => {
                        aiDraftRemoveEditors(card);

                        Object.entries(json.fields || {}).forEach(([code, text]) => {
                            const proposal = card.querySelector('[data-proposal="' + code + '"]');

                            if (proposal) {
                                proposal.innerHTML = text;
                            }
                        });

                        aiDraftEditing(card, false);
                        aiDraftShow(card.querySelectorAll('[data-review-action], [data-edit-action]'), false);
                        aiDraftFeedback(button, json.message);
                    })
                    .catch(() => {});
            }

            /**
             * The skin is chosen when an editor opens, so an editor that is
             * already open has to be rebuilt when the admin theme flips.
             * editor.remove() writes its content back into the textarea, which
             * the new editor then picks up, so nothing typed is lost.
             */
            function aiDraftReskinEditors() {
                document.querySelectorAll('[data-draft]').forEach((card) => {
                    if (! card.querySelector('.tox-tinymce')) {
                        return;
                    }

                    aiDraftRemoveEditors(card);
                    aiDraftAddEditors(card, card.dataset.draft);
                });
            }

            new MutationObserver(aiDraftReskinEditors)
                .observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

            function aiDraftRevert(id, button) {
                if (!confirm('De oude tekst terugzetten op dit product?')) {
                    return;
                }

                const url = '{{ route('admin.tools.ai-descriptions.revert', ['draft' => '__ID__']) }}'.replace('__ID__', id);

                aiDraftPost(url, button)
                    .then((json) => aiDraftFeedback(button, json.message))
                    .catch(() => {});
            }
        </script>
    @endPushOnce
</x-admin::layouts>
