<?php

namespace App\Console\Commands;

use App\Models\BolComCredential;
use App\Services\BolComProductService;
use Illuminate\Console\Command;

class FetchBolUploadReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-bol-upload-report {id}';

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
        $id = $this->argument('id');
        $response = app(BolComProductService::class)->fetchUploadReport($id, BolComCredential::find(1));
        $this->info(json_encode($response));
    }
}
