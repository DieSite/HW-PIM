<?php

namespace Webkul\DataTransfer\Buffer;

use Maatwebsite\Excel\Files\TemporaryFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Webkul\DataTransfer\Jobs\Export\File\SpoutWriterFactory;
use Webkul\DataTransfer\Jobs\Export\File\TemporaryFileFactory;

class FileBuffer
{
    const FOLDER_PREFIX = 'uno-pim';

    const PUBLIC_STORAGE_PATH = 'app/public/';

    const PRIVATE_STORAGE_PATH = 'app/private/';

    protected $highestRow;

    /**
     * @var Spreadsheet
     */
    protected $spreadsheet;

    /** @var array */
    protected $headers = [];

    protected $filePath;

    protected $fileHandle;

    protected array $columnMap = [
        'sku'                          => 'Code',
        'ean'                          => 'EAN',
        'categories'                   => 'Categorie',
        'productnaam'                  => 'Productnaam',
        'collectie'                    => 'Collectie',
        'kwaliteit'                    => 'Kwaliteit',
        'maat'                         => 'Maat',
        'onderkleed'                   => 'Onderkleed',
        'voorraad_eurogros'            => 'Voorraad Eurogros',
        'voorraad_5_korting_handmatig' => 'voorraad 5% korting handmatig',
        'voorraad_5_korting'           => 'voorraad 5% korting',
        'voorraad_hw_5_korting'        => 'voorraad HW 5% korting',
        'uitverkoop_15_korting'        => 'uitverkoop 15% korting',
        'dob'                          => 'DOB',
        'in_collectie'                 => 'In Collectie',
        'afwerking'                    => 'Afwerking',
        'maximale_breedte'             => 'Maximale breedte',
        'maximale_breedte_cm'          => 'Maximale breedte met cm',
        'maximale_lengte'              => 'Maximale lengte',
        'maximale_lengte_cm'           => 'Maximale lengte met cm',
        'maximale_diameter'            => 'Maximale diameter',
        'maximale_diameter_cm'         => 'Maximale diameter met cm',
        'vorm'                         => 'Vorm',
        'levertijd_voorradig'          => 'Levertijd voorradig',
        'levertijd_niet_voorradig'     => 'Levertijd niet voorradig',
        'beschrijving_l'               => 'Beschrijving L',
        'beschrijving_k'               => 'Beschrijving K',
        'prijs (EUR)'                  => 'Prijs',
        'prijs2 (EUR)'                 => 'Prijs2',
        'sale_prijs (EUR)'             => 'Sale Prijs',
        'prijs_per_m2 (EUR)'           => 'Prijs per m2',
        'sale_prijs_per_m2 (EUR)'      => 'Sale Prijs per m2',
        'minimale_prijs (EUR)'         => 'Minimale Prijs',
        'prijs_rond_m2 (EUR)'          => 'Prijs rond m2',
        'sale_prijs_rond_m2 (EUR)'     => 'Sale Prijs rond m2',
        'afbeelding'                   => 'Afbeelding',
        'afbeelding_zonder_logo'       => 'Afbeelding zonder logo',
        'materiaal'                    => 'Materiaal',
        'loopvlak'                     => 'Loopvlak',
        'poolhoogte'                   => 'Poolhoogte',
        'productie_techniek'           => 'Productie techniek',
        'randafwerking'                => 'Randafwerking',
        'productieland'                => 'Productieland',
        'garantie'                     => 'Garantie',
        'kleuren'                      => 'Kleuren',
        'patroon'                      => 'Patroon',
        'gewicht'                      => 'Gewicht',
        'onderhoudsadvies'             => 'Onderhoudsadvies',
        'gebruik'                      => 'Gebruik',
        'sorteer_volgorde'             => 'Sorteer volgorde',
        'meta_titel'                   => 'Meta titel',
        'meta_beschrijving'            => 'Meta beschrijving',
    ];

    /**
     * @return TemporaryFile
     */
    public function make($directory, ?string $fileExtension = null, ?string $fileName = null)
    {
        $temporaryFileFactory = new TemporaryFileFactory($directory);

        return $temporaryFileFactory->make($fileExtension, $fileName);
    }

    /**
     * Close and delete file at buffer destruction
     */
    public function __destruct()
    {
        unset($this->fileHandle);
        if (is_file($this->filePath)) {
            unlink($this->filePath);
        }
    }

    public function appendRows(array $item, $sheet)
    {
        $column = 'A';
        foreach ($item as $cellValue) {
            $sheet->setCellValue($column.$this->highestRow, $cellValue);
            $column++;
        }
    }

    /**
     * @return Writer
     *
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function reopen(TemporaryFile $tempFile, string $writerType, $options)
    {
        $reader = IOFactory::createReader($writerType);
        try {
            $this->spreadsheet = $reader->load($tempFile->sync()->getLocalPath());
        } catch (\Exception $e) {
            $this->spreadsheet = SpoutWriterFactory::createSpreadSheet();
            $writer = SpoutWriterFactory::createWriter($writerType, $this->spreadsheet, $options);
            $writer->save($tempFile->sync()->getLocalPath());
        }

        return $this;
    }

    public function setHeaders()
    {
        $sheet = $this->spreadsheet->getActiveSheet();

        $headers = $this->getHeaders();
        $column = 'A';

        foreach ($headers as $header) {
            $cell = $column.'1';

            $displayHeader = $this->columnMap[$header] ?? $header;
            $sheet->setCellValue($cell, $displayHeader);

            $sheet->getStyle($cell)->applyFromArray([
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '9c9c9c'],
                ],
            ]);

            $column++;
        }
    }

    /**
     * Return the headers of every columns
     *
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Add the specified keys to the list of headers
     */
    public function addToHeaders(array $keys)
    {
        $headers = array_merge($this->headers, $keys);
        $headers = array_unique($headers);

        $this->headers = $headers;
    }

    public function current(): mixed
    {
        $rawLine = $this->fileHandle->current();

        return json_decode($rawLine, true);
    }

    public function next(): void
    {
        $this->fileHandle->next();
    }

    public function key(): int
    {
        return $this->fileHandle->key();
    }

    public function valid(): bool
    {
        return $this->fileHandle->valid();
    }

    public function rewind(): void
    {
        $this->fileHandle->rewind();
    }
}
