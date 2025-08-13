<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Storage;
use Webkul\Product\Repositories\ProductRepository;

class ImportEurogrosEan extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-eurogros-ean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    const FILE = 'Voorraad/Voorraadlijst/Voorraad_Eurogros.csv';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $productRepository = app(ProductRepository::class);
        $content = Storage::disk('sftp')->get(self::FILE);
        $count = substr_count($content, "\n");
        // Convert string to a stream so fgetcsv can read it
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = null;
        $progressBar = $this->output->createProgressBar($count);
        Storage::disk('local')->put('eurogros-ean.log', '');
        while (($data = fgetcsv($stream, separator: ';')) !== false) {
            if ($header === null) {
                $header = $data;
                $progressBar->advance();

                continue;
            }

            $definition = array_combine($header, $data);

            $fullname = $definition['OMSCHR'];
            $explodedName = explode(' ', $fullname);
            $maat = array_pop($explodedName);

            $ean = $definition['EAN'];
            $kleur = implode(' ', $explodedName);
            $onzeMaat = $this->maatMap($maat);

            if (is_null($onzeMaat)) {
                $progressBar->advance();
                Storage::disk('local')->append('eurogros-ean.log', "Skipping $fullname ($ean): unknown maat $maat{$this->findByEan($ean)}");

                continue;
            }

            $rugs = $productRepository->select([
                'id',
            ])->whereNotNull('parent_id')
                ->where('values->common->productnaam', '=', $kleur)
                ->where('values->common->maat', '=', $onzeMaat)
                ->get();

            if ($rugs->count() != 2) {
                $progressBar->advance();
                Storage::disk('local')->append('eurogros-ean.log', "Skipping $fullname ($ean, $kleur, $onzeMaat): {$rugs->count()} rugs found{$this->findByEan($ean)}");

                continue;
            }

            $rugs = $productRepository->findWhereIn('id', [$rugs[0]->id, $rugs[1]->id]);
            foreach ($rugs as $rug) {
                $values = $rug->values;
                $values['common']['ean'] = $ean;
                $rug->values = $values;
                $rug->save();
                Event::dispatch('catalog.product.update.after', $rug);
            }

            $progressBar->advance();
        }
    }

    private function findByEan(string $ean): string
    {
        $productRepository = app(ProductRepository::class);
        $product = $productRepository->findOneByField('values->common->ean', $ean);
        if (is_null($product)) {
            return '';
        }

        $values = $product->values;

        $maat = $values['common']['maat'] ?? '';
        return " | Product {$values['common']['productnaam']} - ($maat) gevonden voor EAN";
    }

    private function maatMap(string $eurgrosMaat): ?string
    {
        return config('eurogros.maat_map')[$eurgrosMaat] ?? null;
    }
}
