@php
    $priceHistoryProductIds = $product->type === 'configurable'
        ? $product->variants->pluck('id')->push($product->id)->all()
        : [$product->id];

    $priceHistory = \App\Models\ProductPriceHistory::query()
        ->whereIn('product_id', $priceHistoryProductIds)
        ->orderByDesc('changed_at')
        ->limit(20)
        ->get();
@endphp

<div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
    <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
        Prijshistorie
    </p>

    @if($priceHistory->isEmpty())
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Nog geen prijswijzigingen door de concurrentie-analyse.
        </p>
    @else
        <div class="flex flex-col gap-3 max-h-96 overflow-y-auto">
            @foreach($priceHistory as $entry)
                <div class="pb-3 border-b border-gray-200 dark:border-gray-700 last:border-b-0 last:pb-0">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $entry->changed_at?->format('d-m-Y H:i') }}
                        </span>

                        <span class="text-sm font-medium">
                            @if($entry->old_price !== null)
                                <span class="text-gray-500 dark:text-gray-400 line-through">
                                    €{{ number_format((float) $entry->old_price, 2, ',', '.') }}
                                </span>
                            @endif
                            <span class="{{ $entry->old_price !== null && (float) $entry->new_price < (float) $entry->old_price ? 'text-green-600 dark:text-green-400' : 'text-gray-800 dark:text-white' }}">
                                €{{ number_format((float) $entry->new_price, 2, ',', '.') }}
                            </span>
                        </span>
                    </div>

                    @if($product->type === 'configurable')
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">
                            SKU: {{ $entry->sku }}
                        </p>
                    @endif

                    <p class="text-xs text-gray-600 dark:text-gray-300">
                        {{ $entry->reason }}
                    </p>

                    @if($entry->competitor_shop)
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Concurrent: {{ $entry->competitor_shop }}
                            @if($entry->competitor_price !== null)
                                (€{{ number_format((float) $entry->competitor_price, 2, ',', '.') }})
                            @endif
                            @if($entry->competitor_url)
                                <a href="{{ $entry->competitor_url }}" target="_blank" rel="noopener"
                                   class="text-violet-600 dark:text-violet-400 underline">
                                    bekijk
                                </a>
                            @endif
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
