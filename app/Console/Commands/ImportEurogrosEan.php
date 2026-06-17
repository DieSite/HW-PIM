<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\EurogrosOmschrParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Storage;

class ImportEurogrosEan extends Command
{
    const FILE = 'Voorraad/Voorraadlijst/Voorraad_Eurogros.csv';

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

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
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
            $ean = $definition['EAN'];

            $match = EurogrosOmschrParser::resolveMatch($fullname);

            if ($match === null) {
                $progressBar->advance();
                Storage::disk('local')->append('eurogros-ean.log', "Skipping $fullname ($ean): could not resolve productnaam/maat{$this->findByEan($ean)}\n");

                continue;
            }

            $rugs = Product::whereNotNull('parent_id')
                ->where('values->common->productnaam', '=', $match['productnaam'])
                ->where('values->common->maat', '=', $match['maat'])
                ->get();

            if ($rugs->isEmpty()) {
                $progressBar->advance();
                Storage::disk('local')->append('eurogros-ean.log', "Skipping $fullname ($ean, {$match['productnaam']}, {$match['maat']}): 0 rugs found{$this->findByEan($ean)}\n");

                continue;
            }

            if ($rugs->count() !== 2) {
                Storage::disk('local')->append('eurogros-ean.log', "Note $fullname ($ean, {$match['productnaam']}, {$match['maat']}): {$rugs->count()} rug(s) found, expected 2 — writing EAN to all\n");
            }

            foreach ($rugs as $rug) {
                $values = $rug->values;
                $values['common']['ean'] = $ean;
                $rug->values = $values;
                $rug->save();
                Event::dispatch('catalog.product.update.after', $rug);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
    }

    private function findByEan(string $ean): string
    {
        $product = Product::where('values->common->ean', $ean)->first();
        if (is_null($product)) {
            return '';
        }

        $values = $product->values;

        $maat = $values['common']['maat'] ?? '';

        return " | Product {$values['common']['productnaam']} - ($maat) gevonden voor EAN";
    }
}
