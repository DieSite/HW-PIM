<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryTimesRequest;
use App\Jobs\ApplyDeliveryTimesJob;
use App\Models\DeliveryTimeRule;
use App\Models\Product;
use App\Services\DeliveryTimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Webkul\Core\Models\CoreConfig;

/**
 * Tools → Levertijden: the delivery time of every rug per brand, plus the one
 * for a rug in the HW showroom. Saving recomputes every variant in the
 * background ({@see ApplyDeliveryTimesJob}).
 */
class DeliveryTimesController extends Controller
{
    public function __construct(private DeliveryTimeService $deliveryTimeService) {}

    public function index(): View
    {
        $rules = DeliveryTimeRule::query()->get()->keyBy('brand');
        $rugCounts = $this->rugCountsByBrand();

        $brands = collect($rugCounts->keys())
            ->merge($rules->keys())
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return view('admin::tools.delivery-times', [
            'showroom' => $this->deliveryTimeService->rules()['showroom'],
            'rows'     => $brands->map(fn (string $brand): array => [
                'brand'        => $brand,
                'rugs'         => $rugCounts->get($brand, 0),
                'in_stock'     => $rules->get($brand)?->in_stock,
                'out_of_stock' => $rules->get($brand)?->out_of_stock,
            ]),
        ]);
    }

    public function update(DeliveryTimesRequest $request): RedirectResponse
    {
        CoreConfig::query()->updateOrCreate(
            ['code' => DeliveryTimeService::SHOWROOM_CONFIG_CODE],
            ['value' => trim((string) $request->validated('showroom'))],
        );

        foreach ($request->validated('rules', []) as $row) {
            $brand = trim((string) ($row['brand'] ?? ''));
            $inStock = trim((string) ($row['in_stock'] ?? ''));
            $outOfStock = trim((string) ($row['out_of_stock'] ?? ''));

            if ($brand === '') {
                continue;
            }

            if ($inStock === '' && $outOfStock === '') {
                DeliveryTimeRule::query()->where('brand', $brand)->delete();

                continue;
            }

            DeliveryTimeRule::query()->updateOrCreate(['brand' => $brand], [
                'in_stock'     => $inStock ?: null,
                'out_of_stock' => $outOfStock ?: null,
            ]);
        }

        $this->deliveryTimeService->forgetRules();

        ApplyDeliveryTimesJob::dispatch();

        session()->flash('success', 'Levertijden opgeslagen. Alle varianten worden op de achtergrond bijgewerkt en naar de webshop gestuurd.');

        return redirect()->route('admin.tools.delivery-times.index');
    }

    /**
     * The number of rugs (parents) per `merk` value.
     *
     * @return Collection<string, int>
     */
    private function rugCountsByBrand(): Collection
    {
        return Product::query()
            ->whereNull('parent_id')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(`values`, '$.common.merk')) AS brand, COUNT(*) AS rugs")
            ->groupBy('brand')
            ->pluck('rugs', 'brand')
            ->reject(fn (mixed $rugs, mixed $brand): bool => trim((string) $brand) === '' || $brand === 'null')
            ->map(fn (mixed $rugs): int => (int) $rugs);
    }
}
