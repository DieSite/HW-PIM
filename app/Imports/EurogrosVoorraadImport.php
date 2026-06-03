<?php

namespace App\Imports;

use App\Jobs\SyncWooCommerceStockJob;
use App\Models\BolComCredential;
use App\Models\EurogrosMissingEanNumber;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\WooCommerceStockSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithProgressBar;
use Maatwebsite\Excel\Concerns\WithUpserts;

class EurogrosVoorraadImport implements ShouldQueue, ToModel, WithChunkReading, WithHeadingRow, WithProgressBar, WithUpserts
{
    use Importable;

    public function model(array $row)
    {
        if (! isset($row['ean']) || ! isset($row['vrd'])) {
            return null;
        }

        $ean = (string) $row['ean'];

        $products = Product::whereRaw("JSON_EXTRACT(`values`, '$.common.ean') = ?", [$ean])
            ->orWhereRaw("JSON_EXTRACT(`values`, '$.common.ean') = ?", [(int) $ean])
            ->get();

        if ($products->isEmpty()) {
            EurogrosMissingEanNumber::firstOrCreate(['ean' => $ean]);

            return null;
        }

        $productService = app(ProductService::class);

        $bolCredentials = BolComCredential::all()->all();

        $stockUpdates = [];

        foreach ($products as $product) {
            $values = $product->values;

            if (isset($values['common'])) {
                $values['common']['voorraad_eurogros'] = $row['vrd'];
                $product->values = $values;
                $product->save();

                $stockUpdates[] = WooCommerceStockSyncService::buildStockUpdate($product);

                $productService->triggerBolSync($product, $bolCredentials, [], true);
            }
        }

        if (! empty($stockUpdates)) {
            SyncWooCommerceStockJob::dispatch($stockUpdates);
        }

        return null;
    }

    public function chunkSize(): int
    {
        return 60;
    }

    public function uniqueBy()
    {
        return 'ean';
    }
}
