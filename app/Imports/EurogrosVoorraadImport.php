<?php

namespace App\Imports;

use App\Models\BolComCredential;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithProgressBar;
use Maatwebsite\Excel\Concerns\WithUpserts;
use Webkul\Product\Repositories\ProductRepository;

class EurogrosVoorraadImport implements ShouldQueue, ToModel, WithChunkReading, WithHeadingRow, WithProgressBar, WithUpserts
{
    use Importable;

    public function model(array $row)
    {
        if (!isset($row['ean']) || !isset($row['vrd'])) {
            return null;
        }

        $ean = (string)$row['ean'];

        $products = Product::whereRaw("JSON_EXTRACT(`values`, '$.common.ean') = ?", [$ean])
            ->orWhereRaw("JSON_EXTRACT(`values`, '$.common.ean') = ?", [(int)$ean])
            ->get();

        $productService = app(ProductService::class);
        $productRepository = app(ProductRepository::class);

        $bolCredentials = BolComCredential::all();

        foreach ($products as $product) {
            $values = json_decode($product->values, true);

            if (isset($values['common'])) {
                $values['common']['voorraad_eurogros'] = $row['vrd'];
                $product->values = json_encode($values);
                $product->save();

                $webkulProduct = $productRepository->find($product->id);

                $productService->triggerFullExternalSync($webkulProduct, $bolCredentials);
            }
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
