<?php

namespace App\Console\Commands;

use App\Models\BolComCredential;
use App\Services\BolComProductService;
use Illuminate\Console\Command;

class FetchBolCategories extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-bol-categories';

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
        $response = app(BolComProductService::class)->fetchCategories(BolComCredential::find(1));
        \File::put(storage_path('app/bol-categories.json'), json_encode($response));
        $this->info(json_encode($response));
    }
}
