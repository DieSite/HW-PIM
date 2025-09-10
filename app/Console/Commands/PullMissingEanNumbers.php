<?php

namespace App\Console\Commands;

use App\Mail\NewEurogrosEanNumbers;
use App\Models\EurogrosMissingEanNumber;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PullMissingEanNumbers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:pull-missing-ean-numbers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    const EAN_NUMBER_REDIS_KEY = 'already_found_missing_ean_numbers';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $remotePath = '/Voorraad/Voorraadlijst/Voorraad_Eurogros.csv';
        $content = Storage::disk('sftp')->get($remotePath);
        $count = substr_count($content, "\n");
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $header = null;
        $progressBar = $this->output->createProgressBar($count);

        $missingEANs = [];

        while (($row = fgetcsv($stream, separator: ';')) !== false) {
            if ($header === null) {
                $header = $row;
                $progressBar->advance();

                continue;
            }

            $definition = array_combine($header, $row);
            $ean = $definition['EAN'];
            $exists = Product::where('values->common->ean', $ean)->exists();

            if ( $exists ) {
                $progressBar->advance();
                continue;
            }

            if ( EurogrosMissingEanNumber::whereEan($ean)->exists() ) {
                $progressBar->advance();
                continue;
            }

            EurogrosMissingEanNumber::create(['ean' => $ean]);
            $missingEANs[] = $ean;
            $progressBar->advance();
        }

        if (count($missingEANs) > 0) {
            \Mail::send(new NewEurogrosEanNumbers($missingEANs));
        }

        $progressBar->finish();

        $this->info('Found ' . count($missingEANs) . ' missing EAN numbers.');
    }
}
