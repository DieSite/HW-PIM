<?php

namespace App\Imports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithProgressBar;
use Maatwebsite\Excel\Concerns\WithUpserts;

class EurogrosVoorraadImport implements ToModel, WithChunkReading, WithHeadingRow, WithProgressBar, WithUpserts
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

        foreach ($products as $product) {
            $values = json_decode($product->values, true);

            if (isset($values['common'])) {
                $values['common']['voorraad_eurogros'] = $row['vrd'];
                $product->values = json_encode($values);
                $product->save();
            }
        }

        return null;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function uniqueBy()
    {
        return 'ean';
    }
}
