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

        <div class="bg-white dark:bg-cherry-800 rounded-lg shadow-sm p-4 flex flex-wrap items-center gap-3">
            @foreach (['pending' => 'Te beoordelen', 'approved' => 'Goedgekeurd', 'rejected' => 'Afgekeurd', 'applied' => 'Gepubliceerd', 'failed' => 'Mislukt', 'all' => 'Alles'] as $key => $label)
                <a
                    href="{{ route('admin.tools.ai-descriptions.review', array_filter(['run' => $run?->id, 'status' => $key])) }}"
                    class="text-sm px-3 py-1.5 rounded-md {{ $status === $key ? 'bg-violet-400 text-white' : 'bg-gray-100 dark:bg-cherry-900 text-gray-700 dark:text-slate-50' }}"
                >
                    {{ $label }}@if (isset($counts[$key])) ({{ $counts[$key] }}) @endif
                </a>
            @endforeach

            <form
                action="{{ route('admin.tools.ai-descriptions.apply') }}"
                method="POST"
                class="flex-1 flex justify-end"
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
                        <p class="font-semibold uppercase tracking-wide">{{ $draft->status }}</p>
                    </div>
                </div>

                @if ($draft->status === 'failed')
                    <p class="text-sm text-red-600">{{ $draft->error }}</p>
                @else
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
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div class="text-gray-500">
                                    <p class="text-xs mb-1">Nu op de webshop</p>
                                    <div>{!! $common[$code] ?? '<em>leeg</em>' !!}</div>
                                </div>
                                <div class="text-gray-800 dark:text-slate-50">
                                    <p class="text-xs mb-1">Voorstel</p>
                                    <div>{!! $text !!}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif

                <div class="flex items-center gap-2.5 pt-3 border-t dark:border-gray-800">
                    @if ($draft->status !== 'applied')
                        <button type="button" class="primary-button" onclick="aiDraftDecide({{ $draft->id }}, 'approve', this)">Goedkeuren</button>
                        <button type="button" class="secondary-button" onclick="aiDraftDecide({{ $draft->id }}, 'reject', this)">Afkeuren</button>
                    @endif
                    <button type="button" class="secondary-button" onclick="aiDraftRegenerate({{ $draft->id }}, this)">Opnieuw schrijven</button>
                    @if ($draft->isRevertible())
                        <button type="button" class="transparent-button" onclick="aiDraftRevert({{ $draft->id }}, this)">Terugdraaien</button>
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
        <script>
            function aiDraftFeedback(button, message) {
                const feedback = button.closest('[data-draft]').querySelector('[data-feedback]');
                feedback.textContent = message;
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
                    .then((json) => aiDraftFeedback(button, json.message))
                    .catch(() => {});
            }

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
