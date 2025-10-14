<?php

namespace App\Console\Commands;

use App\Models\BolComCredential;
use App\Services\BolComProductService;
use Illuminate\Console\Command;

class FetchBolCatalogProductDetails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-bol-catalog-product-details {ean} {--assets}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $response = app(BolComProductService::class)->fetchCatalogProductDetails(BolComCredential::find(1), $this->argument('ean'), $this->option('assets'));
        $this->info(json_encode($response));
    }
}
