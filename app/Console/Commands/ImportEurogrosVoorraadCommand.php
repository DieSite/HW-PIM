<?php

namespace App\Console\Commands;

use App\Jobs\ImportVoorraadEurogrosJob;
use Illuminate\Console\Command;

class ImportEurogrosVoorraadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:eurogros';

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
        ImportVoorraadEurogrosJob::dispatch();
    }
}
