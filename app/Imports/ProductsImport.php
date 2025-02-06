<?php

namespace App\Imports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithProgressBar;
use Maatwebsite\Excel\Concerns\WithSkipDuplicates;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

class ProductsImport implements ToModel, WithHeadingRow, WithChunkReading, WithBatchInserts, WithProgressBar, WithSkipDuplicates, WithCalculatedFormulas
{

    use Importable;

    public function model(array $row)
    {

        if (!isset($row['code'])) {
            return null;
        }

        $common = [
            'sku' => $row['code'],
            'ean' => $row['ean'] ?? null,
            'merk' => $row['merk'] ?? null,
            'categorie' => $row['categorie'] ?? null,
            'productnaam' => $row['productnaam'] ?? null,
            'collectie' => $row['collectie'] ?? null,
            'cross_sell' => $row['cross_sell'] ?? null,
            'kwaliteit' => $row['kwaliteit'] ?? null,
            'maat' => $row['maat'] ?? null,
            'onderkleed' => $row['onderkleed'] ?? null,
            'voorraad_eurogros' => $row['voorraad_eurogros'] ?? null,
            'voorraad_5_korting_handmatig' => $row['voorraad_5_korting_handmatig'] ?? null,
            'voorraad_5_korting' => $row['voorraad_5_korting'] ?? null,
            'voorraad_hw_5_korting' => $row['voorraad_hw_5_korting'] ?? null,
            'uitverkoop_15_korting' => $row['uitverkoop_15_korting'] ?? null,
            'dob' => $row['dob'] ?? null,
            'in_collectie' => $row['in_collectie'] ?? null,
            'afwerking' => $row['afwerking'] ?? null,
            'maximale_breedte' => $row['maximale_breedte'] ?? null,
            'maximale_breedte_cm' => $row['maximale_breedte_cm'] ?? null,
            'maximale_lengte' => $row['maximale_lengte'] ?? null,
            'maximale_lengte_cm' => $row['maximale_lengte_cm'] ?? null,
            'maximale_diameter' => $row['maximale_diameter'] ?? null,
            'maximale_diameter_cm' => $row['maximale_diameter_cm'] ?? null,
            'vorm' => $row['vorm'] ?? null,
            'levertijd_voorradig' => $row['levertijd_voorradig'] ?? null,
            'levertijd_niet_voorradig' => $row['levertijd_niet_voorradig'] ?? null,
            'beschrijving_l' => $row['beschrijving_l'] ?? null,
            'beschrijving_k' => $row['beschrijving_k'] ?? null,
            'prijs' => [
                'USD' => $row['prijs'] ?? null,
            ],
            'prijs2' => [
                'USD' => $row['prijs2'] ?? null,
            ],
            'sale_prijs' => [
                'USD' => $row['sale_prijs'] ?? null,
            ],
            'prijs_per_m2' => [
                'USD' => $row['prijs_per_m2'] ?? null,
            ],
            'sale_prijs_per_m2' => [
                'USD' => $row['sale_prijs_per_m2'] ?? null,
            ],
            'minimale_prijs' => [
                'USD' => $row['minimale_prijs'] ?? null,
            ],
            'prijs_rond_m2' => [
                'USD' => $row['prijs_rond_m2'] ?? null,
            ],
            'sale_prijs_rond_m2' => [
                'USD' => $row['sale_prijs_rond_m2'] ?? null,
            ],
            'afbeelding' => $row['afbeelding'] ?? null,
            'afbeelding_zonder_logo' => $row['afbeelding_zonder_logo'] ?? null,
            'materiaal' => $row['materiaal'] ?? null,
            'loopvlak' => $row['loopvlak'] ?? null,
            'poolhoogte' => $row['poolhoogte'] ?? null,
            'product_techniek' => $row['product_techniek'] ?? null,
            'randafwerking' => $row['randafwerking'] ?? null,
            'productieland' => $row['productieland'] ?? null,
            'garantie' => $row['garantie'] ?? null,
            'kleuren' => $row['kleuren'] ?? null,
            'patroon' => $row['patroon'] ?? null,
            'gewicht' => $row['gewicht'] ?? null,
            'onderhoudsadvies' => $row['onderhoudsadvies'] ?? null,
            'gebruik' => $row['gebruik'] ?? null,
            'sorteer_volgorde' => $row['sorteer_volgorde'] ?? null,
            'meta_titel' => $row['meta_title'] ?? null,
            'meta_beschrijving' => $row['meta_omschrijving'] ?? null,
        ];
        
        return new Product([
            'sku' => $row['code'],
            'status' => 1,
            'type' => 'simple',
            'attribute_family_id' => 2,
            'values' => json_encode(['common' => $common])
        ]);
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function batchSize(): int
    {
        return 1000;
    }
}