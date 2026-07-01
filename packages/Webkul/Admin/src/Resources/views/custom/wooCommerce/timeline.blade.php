@php
    use App\Enums\WooCommerceSyncEventStatus;

    $latest = $events->first();
    $state = $latest?->status ?? null;

    $stateBadgeClasses = match ($state?->badge()) {
        'success' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'danger'  => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'warning' => 'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
        default   => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
    };
@endphp

<div class="relative p-4 bg-white dark:bg-cherry-900 rounded box-shadow mt-4" id="woocommerce-timeline">
    <div class="flex items-center justify-between mb-4">
        <div>
            <p class="text-base text-gray-800 dark:text-white font-semibold">
                WooCommerce synchronisatiestatus
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Hier zie je wat er gebeurt wanneer dit product naar WooCommerce wordt verstuurd.
            </p>
        </div>

        <div class="flex items-center gap-3">
            @if ($state)
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ $stateBadgeClasses }}">
                    {{ $state->label() }}
                </span>
            @endif

            @if ($latest)
                <span class="text-xs text-gray-400">
                    bijgewerkt {{ $latest->created_at->diffForHumans() }}
                </span>
            @endif

            <form
                method="POST"
                action="{{ route('admin.custom.wooCommerce.product.retry', $product->id) }}"
                onsubmit="return confirm('Synchronisatie met WooCommerce opnieuw starten?');"
            >
                @csrf
                <button
                    type="submit"
                    class="px-3 py-1.5 text-xs font-medium rounded border border-blue-200 text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-900"
                >
                    Sync opnieuw proberen
                </button>
            </form>
        </div>
    </div>

    @if ($product->additional['product_sync_error'] ?? null)
        <div class="p-3 mb-4 text-sm rounded bg-red-50 text-red-800 dark:bg-red-900 dark:text-red-100 border border-red-200 dark:border-red-700">
            {{ $product->additional['product_sync_error'] }}
        </div>
    @endif

    @if ($events->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Er is nog geen synchronisatie geprobeerd voor dit product.
        </p>
    @else
        <ol class="space-y-3">
            @foreach ($events as $event)
                @php
                    $eventStatus = $event->status instanceof WooCommerceSyncEventStatus
                        ? $event->status
                        : WooCommerceSyncEventStatus::tryFrom((string) $event->status);

                    $eventBadgeClasses = match ($eventStatus) {
                        WooCommerceSyncEventStatus::Success => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
                        WooCommerceSyncEventStatus::Failed  => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
                        WooCommerceSyncEventStatus::Started => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100',
                        WooCommerceSyncEventStatus::Skipped => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
                        default                             => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200',
                    };
                @endphp

                <li class="border border-gray-200 dark:border-gray-700 rounded p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-800 dark:text-gray-100">
                                    {{ $eventStatus?->label() ?? $event->status }}
                                </span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide {{ $eventBadgeClasses }}">
                                    {{ $eventStatus?->value ?? $event->status }}
                                </span>
                            </div>

                            @if ($event->customer_message)
                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">
                                    {{ $event->customer_message }}
                                </p>
                            @endif

                            @if ($event->message && $event->message !== $event->customer_message)
                                <details class="mt-2">
                                    <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-700 dark:text-gray-400">
                                        Technische details
                                    </summary>
                                    <pre class="mt-2 p-2 text-[11px] bg-gray-50 dark:bg-gray-800 dark:text-gray-200 rounded overflow-x-auto whitespace-pre-wrap">{{ $event->message }}</pre>
                                </details>
                            @endif
                        </div>

                        <div class="text-right text-xs text-gray-400 whitespace-nowrap">
                            <div>{{ $event->created_at->format('d-m-Y H:i:s') }}</div>
                            @if ($event->external_id)
                                <div class="mt-0.5 font-mono text-[10px]">WC #{{ $event->external_id }}</div>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
