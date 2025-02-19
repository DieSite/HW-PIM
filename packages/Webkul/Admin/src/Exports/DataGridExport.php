<?php

namespace Webkul\Admin\Exports;

use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class DataGridExport implements FromGenerator, WithCustomCsvSettings, WithStyles
{
    protected array $skipColumns = [
        'channel',
        'locale',
        'product_id',
        'status',
        'type',
        'attribute_family',
        'parent'
    ];

    protected array $columnMap = [
        'sku' => 'Code',
        'ean' => 'EAN',
        'categories' => 'Categorie',
        'productnaam' => 'Productnaam',
        'collectie' => 'Collectie',
        'kwaliteit' => 'Kwaliteit',
        'maat' => 'Maat',
        'onderkleed' => 'Onderkleed',
        'voorraad_eurogros' => 'Voorraad Eurogros',
        'voorraad_5_korting_handmatig' => 'voorraad 5% korting handmatig',
        'voorraad_5_korting' => 'voorraad 5% korting',
        'voorraad_hw_5_korting' => 'voorraad HW 5% korting',
        'uitverkoop_15_korting' => 'uitverkoop 15% korting',
        'dob' => 'DOB',
        'in_collectie' => 'In Collectie',
        'afwerking' => 'Afwerking',
        'maximale_breedte' => 'Maximale breedte',
        'maximale_breedte_cm' => 'Maximale breedte met cm',
        'maximale_lengte' => 'Maximale lengte',
        'maximale_lengte_cm' => 'Maximale lengte met cm',
        'maximale_diameter' => 'Maximale diameter',
        'maximale_diameter_cm' => 'Maximale diameter met cm',
        'vorm' => 'Vorm',
        'levertijd_voorradig' => 'Levertijd voorradig',
        'levertijd_niet_voorradig' => 'Levertijd niet voorradig',
        'beschrijving_l' => 'Beschrijving L',
        'beschrijving_k' => 'Beschrijving K',
        'prijs (EUR)' => 'Prijs',
        'prijs2 (EUR)' => 'Prijs2',
        'sale_prijs (EUR)' => 'Sale Prijs',
        'prijs_per_m2 (EUR)' => 'Prijs per m2',
        'sale_prijs_per_m2 (EUR)' => 'Sale Prijs per m2',
        'minimale_prijs (EUR)' => 'Minimale Prijs',
        'prijs_rond_m2 (EUR)' => 'Prijs rond m2',
        'sale_prijs_rond_m2 (EUR)' => 'Sale Prijs rond m2',
        'afbeelding' => 'Afbeelding',
        'afbeelding_zonder_logo' => 'Afbeelding zonder logo',
        'materiaal' => 'Materiaal',
        'loopvlak' => 'Loopvlak',
        'poolhoogte' => 'Poolhoogte',
        'productie_techniek' => 'Productie techniek',
        'randafwerking' => 'Randafwerking',
        'productieland' => 'Productieland',
        'garantie' => 'Garantie',
        'kleuren' => 'Kleuren',
        'patroon' => 'Patroon',
        'gewicht' => 'Gewicht',
        'onderhoudsadvies' => 'Onderhoudsadvies',
        'gebruik' => 'Gebruik',
        'sorteer_volgorde' => 'Sorteer volgorde',
        'meta_titel' => 'Meta titel',
        'meta_beschrijving' => 'Meta beschrijving'
    ];

    /**
     * Create a new instance.
     *
     * @param mixed DataGrid
     * @return void
     */
    public function __construct(protected $gridData = []) {}

    /**
     * generator to create large excel files without loading everything in memory at once with generator
     */
    public function generator(): \Generator
    {
        [$columns, $records] = $this->getColumnsAndRecords();

        yield $columns;

        foreach ($records as $record) {
            yield $this->getRecordData($record, $columns);
        }
    }

    /**
     * return columns and records from grid data
     */
    protected function getColumnsAndRecords(): array
    {
        if (isset($this->gridData['columns']) && is_array($this->gridData['columns'])) {
            $columns = array_filter($this->gridData['columns'], function($column) {
                return !in_array($column, $this->skipColumns);
            });

            $mappedColumns = array_map(function($column) {
                return $this->columnMap[$column] ?? $column;
            }, $columns);

            return [
                array_values($mappedColumns),
                $this->gridData['records'],
            ];
        }

        $columns = [];
        $records = $this->gridData;

        foreach ($this->gridData as $key => $gridData) {
            $columns = array_filter(
                array_keys((array) $gridData),
                function($column) {
                    return !in_array($column, $this->skipColumns);
                }
            );
            break;
        }

        return [array_values($columns), $records];
    }

    /**
     * format record data in sort order of columns to display correct data for each column
     */
    protected function getRecordData(mixed $record, array $columns): array
    {
        $record = (array) $record;
        $recordData = [];

        $reverseMap = array_flip($this->columnMap);

        foreach ($columns as $column) {
            if (in_array($column, $this->skipColumns)) {
                continue;
            }

            $originalColumn = $reverseMap[$column] ?? $column;
            $recordData[$column] = $record[$originalColumn] ?? '';
        }

        return $recordData;
    }

    /**
     * Settings for csv export
     */
    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
        ];
    }

    public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)  
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '9c9c9c']
                ]
            ],
        ];
    }
}
