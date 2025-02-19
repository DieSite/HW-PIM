<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Category;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithProgressBar;
use Maatwebsite\Excel\Concerns\WithSkipDuplicates;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithUpserts;
use Illuminate\Support\Facades\DB;
use Storage;

class ProductsImport implements ToModel, WithHeadingRow, WithChunkReading, WithProgressBar, WithSkipDuplicates, WithCalculatedFormulas, WithUpserts
{

    use Importable;

    public function model(array $row)
    {

        $type = 'simple';
        $parent = null;
        $parentId = null;
        $categories = [];
        $processedImages = [];

        if (!isset($row['code'])) {
            return null;
        }

        if (!isset($row['maat'])) {
            $type = 'configurable';
        }

        if (isset($row['categorie'])) {
            $categories = explode('|', $row['categorie']);
            foreach ($categories as $category) {
                $category = Category::firstOrCreate([
                    'code' => $category,
                ]);
            }
        }

        if (isset($row['merk'])) {
            $category = Category::firstOrCreate([
                'code' => $row['merk']
            ]);
        }

        if ($type === 'simple') {
            $parent = DB::table('products')
                ->whereJsonContains('values->common->productnaam', $row['productnaam'])
                ->where('type', 'configurable')
                ->first();
        }

        if ($parent) {
            $parentId = $parent->id;
        }

        $processedImages = $this->processImages($row['afbeelding']);

        $common = [
            'sku' => $row['code'],
            'ean' => $row['ean'] ?? null,
            'merk' => $row['merk'] ?? null,
            'productnaam' => $row['productnaam'] ?? null,
            'collectie' => $row['collectie'] ?? null,
            'kwaliteit' => $row['kwaliteit'] ?? null,
            'maat' => $this->formatMaat($row['maat']) ?? null,
            'onderkleed' => strtolower(explode(' ', $row['onderkleed'])[0]) ?? null,
            'voorraad_eurogros' => $row['voorraad_eurogros'] ?? null,
            'voorraad_5_korting_handmatig' => $row['voorraad_5_korting_handmatig'] ?? null,
            'voorraad_5_korting' => $row['voorraad_5_korting'] ?? null,
            'voorraad_hw_5_korting' => $row['voorraad_hw_5_korting'] ?? null,
            'uitverkoop_15_korting' => $row['uitverkoop_15_korting'] ?? null,
            'dob' => $row['dob'] ?? null,
            'in_collectie' => $row['in_collectie'] ?? null,
            'afwerking' => strtolower($row['afwerking']) ?? null,
            'maximale_breedte' => $row['maximale_breedte'] ?? null,
            'maximale_breedte_cm' => $row['maximale_breedte_met_cm'] ?? null,
            'maximale_lengte' => $row['maximale_lengte'] ?? null,
            'maximale_lengte_cm' => $row['maximale_lengte_met_cm'] ?? null,
            'maximale_diameter' => $row['maximale_diameter'] ?? null,
            'maximale_diameter_cm' => $row['maximale_diameter_met_cm'] ?? null,
            'vorm' => $row['vorm'] ?? null,
            'levertijd_voorradig' => $row['levertijd_voorradig'] ?? null,
            'levertijd_niet_voorradig' => $row['levertijd_niet_voorradig'] ?? null,
            'beschrijving_l' => $row['beschrijving_l'] ?? null,
            'beschrijving_k' => $row['beschrijving_k'] ?? null,
            'prijs' => [
                'EUR' => $row['prijs'] ?? null,
            ],
            'prijs2' => [
                'EUR' => $row['prijs2'] ?? null,
            ],
            'sale_prijs' => [
                'EUR' => $row['sale_prijs'] ?? null,
            ],
            'prijs_per_m2' => [
                'EUR' => $row['prijs_per_m2'] ?? null,
            ],
            'sale_prijs_per_m2' => [
                'EUR' => $row['sale_prijs_per_m2'] ?? null,
            ],
            'minimale_prijs' => [
                'EUR' => $row['minimale_prijs'] ?? null,
            ],
            'prijs_rond_m2' => [
                'EUR' => $row['Prijs_rond_m2'] ?? null,
            ],
            'sale_prijs_rond_m2' => [
                'EUR' => $row['sale_prijs_rond_m2'] ?? null,
            ],
            'afbeelding' => $processedImages ?? null,
            'afbeelding_zonder_logo' => $row['afbeelding_zonder_logo'] ?? null,
            'materiaal' => $row['materiaal'] ?? null,
            'loopvlak' => $row['loopvlak'] ?? null,
            'poolhoogte' => $row['poolhoogte'] ?? null,
            'productie_techniek' => $row['productie_techniek'] ?? null,
            'randafwerking' => $row['randafwerking'] ?? null,
            'productieland' => $row['productieland'] ?? null,
            'garantie' => $row['garantie'] ?? null,
            'kleuren' => $row['kleuren'] ?? null,
            'patroon' => $row['patroon'] ?? null,
            'gewicht' => $row['gewicht'] ?? null,
            'onderhoudsadvies' => $row['onderhoudsadvies'] ?? null,
            'gebruik' => $row['gebruik'] ?? null,
            'sorteer_volgorde' => $row['sorteer_volgorde'] ?? null,
            'meta_titel' => $row['meta_titel'] ?? null,
            'meta_beschrijving' => $row['meta_beschrijving'] ?? null,
        ];

        $associations = [
            'cross_sells' => $row['cross_sells'] ? explode(',', $row['cross_sells']) : []
        ];

        return new Product([
            'sku' => $row['code'],
            'status' => 1,
            'type' => $type,
            'parent_id' => $parentId,
            'attribute_family_id' => 2,
            'values' => json_encode([
                'common' => $common,
                'associations' => $associations,
                'categories' => $categories
            ])
        ]);
    }

    public function uniqueBy()
    {
        return 'code';
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    private function formatMaat($value)
    {
        if (!$value) {
            return null;
        }

        $value = strtolower($value);
        $value = preg_replace('/\s+cm/i', '', $value);
        $value = preg_replace('/\s*x\s*/', 'x', $value);

        return trim($value);
    }

    private function processImages($images)
    {
        if (!$images) {
            return [];
        }

        $processedImages = [];
        $imageArray = explode('|', $images);

        foreach ($imageArray as $image) {
            $cleanedImage = trim($image);
            $cleanedImage = str_replace([' ', '&-'], ['-', ''], $cleanedImage);
            $cleanedImage = preg_replace('/-+/', '-', $cleanedImage);
            $cleanedImage = str_replace(['.-', '-.'], '-', $cleanedImage);
            $cleanedImage = 'product-images/' . $cleanedImage;

            if (Storage::disk('public')->exists($cleanedImage)) {
                $processedImages[] = $cleanedImage;
            }
        }

        return $processedImages;
    }
}